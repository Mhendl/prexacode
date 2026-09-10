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

// Precio por imagen segun modelo y tamano
const OPENAI_PRECIO_IMAGEN = [
    'dall-e-3' => ['1024x1024' => 0.040, '1792x1024' => 0.080, '1024x1792' => 0.080],
];

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
 * Se pide en b64 para evitar una segunda descarga desde el CDN de OpenAI,
 * cuyas URLs expiran.
 */
function openai_imagen(string $key, string $prompt, string $modelo = 'dall-e-3', string $tamano = '1792x1024'): array {
    $r = openai_request($key, 'https://api.openai.com/v1/images/generations', [
        'model'           => $modelo,
        'prompt'          => $prompt,
        'n'               => 1,
        'size'            => $tamano,
        'quality'         => 'standard',
        'response_format' => 'b64_json',
    ], 180);

    if (!$r['ok']) return $r;

    $b64 = $r['data']['data'][0]['b64_json'] ?? null;
    if (!$b64) return ['ok' => false, 'error' => 'OpenAI no devolvio imagen'];

    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 1000) {
        return ['ok' => false, 'error' => 'La imagen recibida es invalida'];
    }

    return [
        'ok'        => true,
        'bytes'     => $bytes,
        'costo_usd' => OPENAI_PRECIO_IMAGEN[$modelo][$tamano] ?? 0.0,
        'revisado'  => $r['data']['data'][0]['revised_prompt'] ?? null,
    ];
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
