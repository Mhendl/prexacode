<?php
/**
 * Pipeline de generacion de un articulo, de punta a punta.
 *
 * Se publica sin revision humana, asi que el filtro de calidad es la unica
 * barrera: si un articulo no pasa, NO se publica y queda en estado 'error'
 * para revisarlo desde el panel. Preferimos no publicar antes que publicar
 * relleno, porque contenido pobre indexado hace mas dano que no tener nada.
 */

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/planner.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/settings.php';

/** Paleta institucional, obligatoria en las imagenes destacadas. */
const PALETA = [
    '#302C6F', '#4C4C9B', '#1D1D1B',
    '#5B4596', '#937EB9', '#266AB2',
    '#62BAE2', '#4C9DD4', '#3FB2DC', '#FFFFFF',
];

function prompt_sistema(string $cluster): string {
    return <<<TXT
Sos el redactor tecnico de PREXAcode, una software house argentina que desarrolla
software a medida, agentes de IA, automatizacion de procesos e integraciones para
PYMES y empresas B2B.

COMO ESCRIBIS:
- Español rioplatense profesional (voseo natural, sin exagerar). Tecnico pero accesible:
  escribis para un dueño de PYME o un gerente de operaciones, no para developers.
- Concreto y verificable. Ejemplos operativos reales (un taller que pierde pedidos en
  WhatsApp, una administracion que carga facturas a mano). Nada de humo.
- Frases cortas. Cero relleno.

PROHIBIDO (esto invalida el articulo):
- Aperturas genericas tipo "En el mundo digital actual", "En la era digital",
  "Hoy en dia las empresas", "No es un secreto que", "Sin lugar a dudas".
- Inventar datos, estadisticas, porcentajes, estudios o citas. Si no lo podes afirmar
  con certeza, escribilo en terminos cualitativos.
- Inventar precios, plazos o nombres de clientes de PREXAcode.
- Prometer resultados garantizados.
- Lenguaje de vendedor ("solucion integral", "sinergia", "revolucionario").

ENFOQUE COMERCIAL (obligatorio):
El tema de este articulo pertenece al eje "{$cluster}". El articulo tiene que terminar
conectando explicitamente el problema tratado con como lo resuelve el desarrollo a
medida de PREXAcode. Si el tema es tangencial, ese cierre es todavia mas importante.
El cierre debe ser util, no un aviso publicitario.

FORMATO DEL CUERPO:
- HTML simple: <p>, <h2>, <h3>, <ul>/<li>, <strong>. Nada de <h1> (lo pone la plantilla).
- Entre 700 y 1100 palabras.
- Arranca directo con el problema concreto, sin preambulo.
- 4 a 6 <h2>. Usa <h3> solo si un <h2> lo necesita.
- Un <h2> final de cierre que conecte con desarrollo a medida.
TXT;
}

function prompt_articulo(string $keyword, string $cluster): string {
    return "Escribi un articulo optimizado para la keyword objetivo: \"{$keyword}\".\n\n"
        . "La keyword tiene que aparecer de forma natural en el titulo, en el primer parrafo "
        . "y en al menos un <h2>. Nunca forzada ni repetida de mas.\n\n"
        . "Devolvé unicamente JSON con esta forma exacta:\n"
        . "{\n"
        . "  \"titulo\": \"titulo del articulo, atractivo y con la keyword, max 70 caracteres\",\n"
        . "  \"meta_title\": \"title tag para Google, MAXIMO 60 caracteres, con la keyword\",\n"
        . "  \"meta_description\": \"descripcion para Google, MAXIMO 155 caracteres, con la keyword, que invite a hacer clic\",\n"
        . "  \"extracto\": \"resumen de 1 o 2 frases, max 200 caracteres\",\n"
        . "  \"cuerpo_html\": \"el articulo completo en HTML\",\n"
        . "  \"imagen_concepto\": \"en INGLES, describi en una frase el concepto visual abstracto que representa el articulo (sin texto ni palabras en la imagen)\"\n"
        . "}";
}

/** Prompt de imagen: estetica corporativa y paleta institucional obligatoria. */
function prompt_imagen(string $concepto): string {
    $paleta = implode(', ', PALETA);

    return "Minimalist corporate technology illustration for a B2B software company blog header. "
        . "Concept: {$concepto}. "
        . "Style: clean, modern, geometric, flat vector with subtle depth and soft gradients. "
        . "Abstract representation using shapes, nodes, connections, layered planes and negative space. "
        . "STRICT color palette, use only these colors harmoniously: {$paleta}. "
        . "Deep indigo and navy as the dominant background, cyan and light blue as accents for highlights. "
        . "Professional, sober, high-end enterprise aesthetic. "
        . "ABSOLUTELY NO text, no letters, no numbers, no words, no logos, no watermarks, no user interface mockups. "
        . "No people, no faces, no hands. Wide composition with balanced empty space.";
}

/**
 * Genera un articulo completo. Devuelve ['ok'=>bool, ...].
 * No lanza excepciones: corre desatendido desde cron.
 */
function generar_articulo(PDO $db, ?array $tema = null): array {
    $key = openai_key($db);
    if ($key === '') return ['ok' => false, 'error' => 'No hay API key de OpenAI configurada'];

    sembrar_si_hace_falta($db);
    $tema = $tema ?? elegir_siguiente($db);
    if (!$tema) {
        return ['ok' => false, 'error' => 'No quedan keywords sugeridas: corré la replanificación'];
    }

    $modelo   = setting_get($db, 'modelo_texto', 'gpt-4o-mini') ?: 'gpt-4o-mini';
    $costo    = 0.0;
    $problemas = [];
    $art      = null;

    // Hasta 2 intentos: si el primero no pasa el filtro, se le devuelven los
    // problemas concretos al modelo en vez de reintentar a ciegas.
    for ($intento = 1; $intento <= 2; $intento++) {
        $mensajes = [
            ['role' => 'system', 'content' => prompt_sistema($tema['cluster'])],
            ['role' => 'user',   'content' => prompt_articulo($tema['keyword'], $tema['cluster'])],
        ];

        if ($intento === 2 && $problemas) {
            $mensajes[] = ['role' => 'user', 'content' =>
                "El intento anterior fue rechazado por el control de calidad:\n- "
                . implode("\n- ", $problemas)
                . "\n\nCorregí exactamente eso y devolvé el JSON de nuevo."];
        }

        $r = openai_chat($key, $mensajes, $modelo, true, 5000);
        if (!$r['ok']) return $r;

        $costo += (float)$r['costo_usd'];
        $datos  = json_decode($r['contenido'], true);

        if (!is_array($datos) || empty($datos['titulo']) || empty($datos['cuerpo_html'])) {
            $problemas = ['La respuesta no trajo titulo o cuerpo'];
            continue;
        }

        $cuerpo    = sanear_html((string)$datos['cuerpo_html']);
        $problemas = validar_calidad((string)$datos['titulo'], $cuerpo, $tema['keyword']);

        if (!$problemas) {
            $art = [
                'titulo'           => trim((string)$datos['titulo']),
                'meta_title'       => recortar((string)($datos['meta_title'] ?? $datos['titulo']), 60),
                'meta_description' => recortar((string)($datos['meta_description'] ?? ''), 155),
                'extracto'         => recortar((string)($datos['extracto'] ?? ''), 200),
                'cuerpo_html'      => $cuerpo,
                'imagen_concepto'  => trim((string)($datos['imagen_concepto'] ?? $tema['keyword'])),
                'tokens_entrada'   => (int)$r['tokens_entrada'],
                'tokens_salida'    => (int)$r['tokens_salida'],
                'modelo'           => $modelo,
            ];
            break;
        }
    }

    if (!$art) {
        registrar_fallo($db, $tema, implode(' | ', $problemas), $costo);
        return ['ok' => false, 'error' => 'No paso el filtro de calidad: ' . implode(' | ', $problemas)];
    }

    // Enlazado interno hacia la landing y sus secciones
    $art['cuerpo_html'] = inyectar_enlaces_internos($art['cuerpo_html'], 4);

    $slug = slug_unico($db, $art['titulo']);

    // Imagen destacada (Modulo 4). Si falla, el articulo igual se publica:
    // es preferible un post sin imagen que perder el post entero.
    $imagenPath = null;
    $imagenAlt  = null;
    if (setting_bool($db, 'generar_imagenes', true)) {
        $img = generar_imagen_destacada($db, $key, $art['imagen_concepto'], $slug);
        if ($img['ok']) {
            $imagenPath = $img['path'];
            $costo     += (float)$img['costo_usd'];
        }
    }
    $imagenAlt = recortar($tema['keyword'] . ' - ' . $art['titulo'], 120);

    $publicar = setting_bool($db, 'publicar_automatico', true);
    $estado   = $publicar ? 'publicado' : 'programado';

    $stmt = $db->prepare("INSERT INTO blog_posts
        (slug, titulo, meta_title, meta_description, keyword, cluster_id, extracto, cuerpo_html,
         imagen_path, imagen_alt, estado, publicado_at, modelo, tokens_entrada, tokens_salida,
         costo_usd, palabras)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $slug,
        $art['titulo'],
        $art['meta_title'],
        $art['meta_description'],
        $tema['keyword'],
        $tema['cluster_id'],
        $art['extracto'],
        $art['cuerpo_html'],
        $imagenPath,
        $imagenAlt,
        $estado,
        $publicar ? date('Y-m-d H:i:s') : null,
        $art['modelo'],
        $art['tokens_entrada'],
        $art['tokens_salida'],
        round($costo, 6),
        contar_palabras(strip_tags($art['cuerpo_html'])),
    ]);

    $postId = (int)$db->lastInsertId();

    $db->prepare("UPDATE blog_keywords SET estado = 'publicada', post_id = ? WHERE id = ?")
       ->execute([$postId, $tema['keyword_id']]);

    return [
        'ok'        => true,
        'post_id'   => $postId,
        'slug'      => $slug,
        'titulo'    => $art['titulo'],
        'keyword'   => $tema['keyword'],
        'cluster'   => $tema['cluster'],
        'estado'    => $estado,
        'imagen'    => $imagenPath,
        'costo_usd' => round($costo, 4),
        'palabras'  => contar_palabras(strip_tags($art['cuerpo_html'])),
    ];
}

function registrar_fallo(PDO $db, array $tema, string $motivo, float $costo): void {
    $db->prepare("INSERT INTO blog_posts (slug, titulo, keyword, cluster_id, estado, error, costo_usd)
                  VALUES (?, ?, ?, ?, 'error', ?, ?)")
       ->execute([
           'error-' . date('YmdHis') . '-' . mt_rand(100, 999),
           'Fallo al generar: ' . $tema['keyword'],
           $tema['keyword'],
           $tema['cluster_id'],
           $motivo,
           round($costo, 6),
       ]);
}

function slug_unico(PDO $db, string $titulo): string {
    $base  = slugify($titulo);
    $slug  = $base;
    $n     = 2;
    $stmt  = $db->prepare("SELECT 1 FROM blog_posts WHERE slug = ?");

    $stmt->execute([$slug]);
    while ($stmt->fetchColumn()) {
        $slug = $base . '-' . $n++;
        $stmt->execute([$slug]);
    }
    return $slug;
}

/** Genera, descarga y guarda la imagen destacada. */
function generar_imagen_destacada(PDO $db, string $key, string $concepto, string $slug): array {
    $modelo = setting_get($db, 'modelo_imagen', 'dall-e-3') ?: 'dall-e-3';
    $tamano = setting_get($db, 'tamano_imagen', '1792x1024') ?: '1792x1024';

    $r = openai_imagen($key, prompt_imagen($concepto), $modelo, $tamano);
    if (!$r['ok']) return $r;

    $dir = dirname(__DIR__) . '/images/blog';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $nombre  = $slug . '.png';
    $destino = $dir . '/' . $nombre;

    if (file_put_contents($destino, $r['bytes']) === false) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen'];
    }
    @chmod($destino, 0644);

    // GD no esta instalado en el servidor. Si algun dia se instala php8.3-gd,
    // esto convierte a WebP y baja el peso de ~2MB a ~150KB, que es la mayor
    // ganancia de velocidad disponible para estas paginas.
    if (function_exists('imagecreatefrompng') && function_exists('imagewebp')) {
        $im = @imagecreatefrompng($destino);
        if ($im !== false) {
            $webp = $dir . '/' . $slug . '.webp';
            if (@imagewebp($im, $webp, 82)) {
                @chmod($webp, 0644);
                @unlink($destino);
                $nombre = $slug . '.webp';
            }
            imagedestroy($im);
        }
    }

    return ['ok' => true, 'path' => 'images/blog/' . $nombre, 'costo_usd' => $r['costo_usd']];
}
