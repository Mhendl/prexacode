<?php
/**
 * Cliente minimo de OpenAI sobre cURL (sin dependencias, igual que el resto
 * del proyecto). Devuelve siempre un array con exito/error en vez de lanzar,
 * porque estas llamadas corren desatendidas desde cron.
 */

// Precios por millon de tokens (USD). Ajustar si cambian las tarifas.
const OPENAI_PRECIOS = [
    'gpt-4o-mini' => ['entrada' => 0.15, 'salida' => 0.60],
    'gpt-4o'      => ['entrada' => 2.50, 'salida' => 10.00],
];

/**
 * Precio aproximado por imagen (USD), segun modelo, tamano y calidad.
 *
 * OJO: dall-e-3 ya no esta disponible en esta cuenta ("The model 'dall-e-3'
 * does not exist"), quedo reemplazado por la familia gpt-image-*. Esa familia
 * usa tamanos distintos (1536x1024, no 1792x1024) y calidad low/medium/high
 * en vez de standard/hd.
 */
const OPENAI_PRECIO_IMAGEN = [
    'gpt-image-1' => [
        '1024x1024' => ['low' => 0.011, 'medium' => 0.042, 'high' => 0.167],
        '1536x1024' => ['low' => 0.016, 'medium' => 0.063, 'high' => 0.250],
        '1024x1536' => ['low' => 0.016, 'medium' => 0.063, 'high' => 0.250],
    ],
    'gpt-image-1-mini' => [
        '1024x1024' => ['low' => 0.005, 'medium' => 0.015, 'high' => 0.060],
        '1536x1024' => ['low' => 0.008, 'medium' => 0.022, 'high' => 0.090],
    ],
];

function precio_imagen(string $modelo, string $tamano, string $calidad): float {
    return OPENAI_PRECIO_IMAGEN[$modelo][$tamano][$calidad] ?? 0.0;
}

function openai_request(string $key, string $url, array $payload, int $timeout = 120): array {
    if ($key === '') {
        return ['ok' => false, 'error' => 'No hay API key de OpenAI configurada'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return ['ok' => false, 'error' => 'Fallo de conexion: ' . $curlErr];
    }

    $data = json_decode((string)$resp, true);

    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        return ['ok' => false, 'error' => $msg, 'http' => $httpCode];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Respuesta ilegible de OpenAI'];
    }

    return ['ok' => true, 'data' => $data];
}

/** Chat completion. Con $json=true fuerza salida JSON parseable. */
function openai_chat(string $key, array $mensajes, string $modelo = 'gpt-4o-mini', bool $json = false, int $maxTokens = 4000): array {
    $payload = [
        'model'       => $modelo,
        'messages'    => $mensajes,
        'temperature' => 0.7,
        'max_tokens'  => $maxTokens,
    ];
    if ($json) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $r = openai_request($key, 'https://api.openai.com/v1/chat/completions', $payload);
    if (!$r['ok']) return $r;

    $contenido = $r['data']['choices'][0]['message']['content'] ?? null;
    if ($contenido === null) {
        return ['ok' => false, 'error' => 'OpenAI no devolvio contenido'];
    }

    $entrada = (int)($r['data']['usage']['prompt_tokens'] ?? 0);
    $salida  = (int)($r['data']['usage']['completion_tokens'] ?? 0);

    return [
        'ok'             => true,
        'contenido'      => $contenido,
        'tokens_entrada' => $entrada,
        'tokens_salida'  => $salida,
        'costo_usd'      => openai_costo($modelo, $entrada, $salida),
        'modelo'         => $modelo,
    ];
}

function openai_costo(string $modelo, int $entrada, int $salida): float {
    $p = OPENAI_PRECIOS[$modelo] ?? null;
    if (!$p) return 0.0;
    return round(($entrada / 1000000) * $p['entrada'] + ($salida / 1000000) * $p['salida'], 6);
}

/**
 * Genera una imagen y devuelve los bytes crudos.
 *
 * No se manda response_format: la API actual lo rechaza con
 * "Unknown parameter". Segun el modelo, la respuesta trae la imagen en
 * base64 o una URL temporal, asi que se contemplan las dos formas.
 */
function openai_imagen(string $key, string $prompt, string $modelo = 'gpt-image-1', string $tamano = '1536x1024', string $calidad = 'medium'): array {
    $payload = [
        'model'   => $modelo,
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => $tamano,
        'quality' => $calidad,
    ];

    // La familia gpt-image entrega WebP directamente. Sin esto llega un PNG de
    // ~1.8MB, que en una cabecera de blog arruina la velocidad de carga (y con
    // ella el SEO). El servidor no tiene GD, asi que convertir despues no es
    // opcion: hay que pedirlo ya comprimido.
    if (str_starts_with($modelo, 'gpt-image')) {
        $payload['output_format']      = 'webp';
        $payload['output_compression'] = 80;
    }

    $r = openai_request($key, 'https://api.openai.com/v1/images/generations', $payload, 180);
    if (!$r['ok']) return $r;

    $item = $r['data']['data'][0] ?? [];
    $bytes = null;

    if (!empty($item['b64_json'])) {
        $bytes = base64_decode($item['b64_json'], true);
    } elseif (!empty($item['url'])) {
        // Las URLs de OpenAI caducan, hay que bajar la imagen ahora
        $bytes = descargar($item['url']);
    }

    if ($bytes === null || $bytes === false || strlen($bytes) < 1000) {
        return ['ok' => false, 'error' => 'OpenAI no devolvio una imagen utilizable'];
    }

    return [
        'ok'        => true,
        'bytes'     => $bytes,
        'costo_usd' => precio_imagen($modelo, $tamano, $calidad),
        'revisado'  => $item['revised_prompt'] ?? null,
    ];
}

function descargar(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $datos = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($datos === false || $code !== 200) ? null : $datos;
}

/**
 * Prueba de conexion del panel: la llamada mas barata posible que igual
 * valida credencial, permisos y salida de red.
 */
function openai_test(string $key): array {
    $inicio = microtime(true);
    $r = openai_chat($key, [
        ['role' => 'user', 'content' => 'Responde unicamente: ok'],
    ], 'gpt-4o-mini', false, 5);
    $ms = (int)round((microtime(true) - $inicio) * 1000);

    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'], 'ms' => $ms];
    }

    return [
        'ok'        => true,
        'respuesta' => trim($r['contenido']),
        'ms'        => $ms,
        'costo_usd' => $r['costo_usd'],
    ];
}
