<?php
/**
 * SEO on-page: slugs, metas, saneado del HTML generado, enlazado interno
 * y datos estructurados.
 *
 * Ojo: el servidor NO tiene la extension dom (php-xml), asi que todo el
 * manejo de HTML se hace tokenizando por etiquetas a mano. No reemplazar
 * por DOMDocument sin instalar php8.3-xml primero.
 */

/** Etiquetas permitidas en el cuerpo. h1 queda fuera a proposito: lo pone la plantilla. */
const HTML_PERMITIDO = '<h2><h3><p><ul><ol><li><strong><em><a><blockquote><code><pre><table><thead><tbody><tr><th><td>';

const FRASES_PROHIBIDAS = [
    'en el mundo digital actual',
    'en la era digital',
    'en el mundo actual',
    'en un mundo cada vez mas',
    'hoy en dia, las empresas',
    'no es un secreto que',
    'sin lugar a dudas',
    'en resumen, podemos decir',
    'como todos sabemos',
    'el objetivo de este articulo',
    'en este articulo vamos a',
    'la transformacion digital ha llegado',
];

function slugify(string $texto): string {
    $t = mb_strtolower(trim($texto), 'UTF-8');
    $t = strtr($t, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
    ]);
    $t = (string)preg_replace('/[^a-z0-9]+/u', '-', $t);
    $t = trim($t, '-');
    $t = (string)preg_replace('/-{2,}/', '-', $t);

    // Slugs muy largos se cortan en el ultimo guion entero
    if (strlen($t) > 70) {
        $t = substr($t, 0, 70);
        $corte = strrpos($t, '-');
        if ($corte !== false && $corte > 30) $t = substr($t, 0, $corte);
    }
    return $t !== '' ? $t : 'articulo-' . date('YmdHis');
}

/** Corta respetando palabras completas, para title (60) y description (155). */
function recortar(string $texto, int $max): string {
    $t = trim((string)preg_replace('/\s+/', ' ', $texto));
    if (mb_strlen($t) <= $max) return $t;

    $corte = mb_substr($t, 0, $max);
    $sp    = mb_strrpos($corte, ' ');
    if ($sp !== false && $sp > $max * 0.6) $corte = mb_substr($corte, 0, $sp);

    return rtrim($corte, ' ,.;:-');
}

/** Quita bloques peligrosos enteros y deja solo la lista blanca de etiquetas. */
function sanear_html(string $html): string {
    $html = (string)preg_replace('#<(script|style|iframe|object|embed|form|input)\b[^>]*>.*?</\1>#is', '', $html);
    $html = (string)preg_replace('#<(script|style|iframe|object|embed|form|input)\b[^>]*/?>#i', '', $html);

    // Un h1 dentro del cuerpo romperia la jerarquia: se degrada a h2
    $html = (string)preg_replace('#<h1\b[^>]*>(.*?)</h1>#is', '<h2>$1</h2>', $html);

    $html = strip_tags($html, HTML_PERMITIDO);

    // De los atributos solo sobrevive href, y solo si apunta a un destino seguro
    $html = (string)preg_replace_callback('#<([a-z0-9]+)\b([^>]*)>#i', function (array $m): string {
        $tag   = strtolower($m[1]);
        $attrs = '';
        if ($tag === 'a' && preg_match('#href\s*=\s*["\']([^"\']+)["\']#i', $m[2], $h)) {
            $href = trim($h[1]);
            $ok = str_starts_with($href, '/')
               || str_starts_with($href, 'https://')
               || str_starts_with($href, '#');
            if ($ok) {
                $attrs = ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
                if (str_starts_with($href, 'https://') && !str_contains($href, 'prexacode.com')) {
                    $attrs .= ' rel="nofollow noopener" target="_blank"';
                }
            }
        }
        return '<' . $tag . $attrs . '>';
    }, $html);

    return trim($html);
}

/**
 * Recorre el HTML separando etiquetas de texto y aplica $fn solo al texto
 * que esta fuera de enlaces, titulos y bloques de codigo.
 */
function mapear_texto_visible(string $html, callable $fn): string {
    $partes = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($partes === false) return $html;

    $bloqueado = 0;
    $salida    = '';

    foreach ($partes as $parte) {
        if ($parte === '') continue;

        if ($parte[0] === '<') {
            if (preg_match('#^<(a|h1|h2|h3|h4|code|pre)\b#i', $parte))  $bloqueado++;
            if (preg_match('#^</(a|h1|h2|h3|h4|code|pre)>#i', $parte))  $bloqueado = max(0, $bloqueado - 1);
            $salida .= $parte;
            continue;
        }

        $salida .= $bloqueado > 0 ? $parte : $fn($parte);
    }
    return $salida;
}

/**
 * Enlazado interno automatico: primera aparicion de cada ancla, una sola vez
 * por destino y con tope, para no caer en sobreoptimizacion.
 */
function enlaces_internos(): array {
    $base = defined('COMPANY_DOMAIN') ? rtrim((string)COMPANY_DOMAIN, '/') : '';
    return [
        'software a medida'          => $base . '/#servicios',
        'desarrollo a medida'        => $base . '/#servicios',
        'agentes de IA'              => $base . '/#servicios',
        'agente de IA'               => $base . '/#servicios',
        'automatización de procesos' => $base . '/#servicios',
        'automatizacion de procesos' => $base . '/#servicios',
        'consultoría tecnológica'    => $base . '/#servicios',
        'CRM a medida'               => $base . '/#servicios',
        'cómo trabajamos'            => $base . '/#proceso',
        'consulta gratuita'          => $base . '/#contacto',
    ];
}

function inyectar_enlaces_internos(string $html, int $maximo = 4): string {
    $mapa       = enlaces_internos();
    $insertados = 0;
    $usados     = [];

    foreach ($mapa as $ancla => $url) {
        if ($insertados >= $maximo) break;
        if (isset($usados[$url])) continue;

        $hecho = false;
        $html  = mapear_texto_visible($html, function (string $texto) use ($ancla, $url, &$hecho): string {
            if ($hecho) return $texto;

            $patron = '/\b(' . preg_quote($ancla, '/') . ')\b/iu';
            $nuevo  = preg_replace($patron, '<a href="' . $url . '">$1</a>', $texto, 1, $cuenta);
            if ($cuenta > 0) {
                $hecho = true;
                return (string)$nuevo;
            }
            return $texto;
        });

        if ($hecho) {
            $insertados++;
            $usados[$url] = true;
        }
    }
    return $html;
}

/** Control de calidad previo a publicar sin revision humana. */
function validar_calidad(string $titulo, string $html, string $keyword): array {
    $problemas = [];
    $texto     = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    $palabras  = contar_palabras($texto);

    if ($palabras < 500) {
        $problemas[] = "Muy corto: {$palabras} palabras (minimo 500)";
    }

    $plano = normalizar($texto);
    foreach (FRASES_PROHIBIDAS as $frase) {
        if (str_contains($plano, $frase)) {
            $problemas[] = "Contiene relleno generico: \"{$frase}\"";
        }
    }

    if (!preg_match('/<h2\b/i', $html)) {
        $problemas[] = 'No tiene ningun h2: falta jerarquia de subtitulos';
    }
    if (preg_match('/<h1\b/i', $html)) {
        $problemas[] = 'El cuerpo trae un h1 (debe haber uno solo, el del titulo)';
    }

    $kw = normalizar($keyword);
    if ($kw !== '' && !str_contains($plano, mb_substr($kw, 0, 12))) {
        $problemas[] = 'La keyword objetivo no aparece en el cuerpo';
    }

    return $problemas;
}

function normalizar(string $texto): string {
    $t = mb_strtolower($texto, 'UTF-8');
    return strtr($t, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
}

/** str_word_count no entiende UTF-8: contamos por separadores de espacio. */
function contar_palabras(string $texto): int {
    $t = trim((string)preg_replace('/\s+/u', ' ', $texto));
    if ($t === '') return 0;
    return count(explode(' ', $t));
}

/** Datos estructurados BlogPosting para Google. */
function schema_blogposting(array $post): string {
    $base = defined('COMPANY_DOMAIN') ? rtrim((string)COMPANY_DOMAIN, '/') : '';
    $url  = $base . '/blog/' . $post['slug'];

    $datos = [
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => recortar((string)$post['titulo'], 110),
        'description'      => (string)($post['meta_description'] ?? ''),
        'datePublished'    => fecha_iso((string)($post['publicado_at'] ?? $post['created_at'])),
        'dateModified'     => fecha_iso((string)($post['publicado_at'] ?? $post['created_at'])),
        'inLanguage'       => 'es-AR',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'author'           => ['@type' => 'Organization', 'name' => 'PREXAcode', 'url' => $base . '/'],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => 'PREXAcode',
            'logo'  => ['@type' => 'ImageObject', 'url' => $base . '/images/logo-navbar.png'],
        ],
    ];

    if (!empty($post['imagen_path'])) {
        $datos['image'] = [$base . '/' . ltrim((string)$post['imagen_path'], '/')];
    }

    return (string)json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function fecha_iso(string $fecha): string {
    $ts = strtotime($fecha);
    return date('c', $ts !== false ? $ts : time());
}
