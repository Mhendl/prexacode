<?php
/**
 * Esquema de base y migraciones.
 * Se versiona con PRAGMA user_version para no ejecutar los CREATE TABLE
 * en cada request: solo corren cuando la version subio.
 */

const DB_SCHEMA_VERSION = 2;

function db_migrate(PDO $db): void {
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version >= DB_SCHEMA_VERSION) return;

    // ── v1: captacion por chat (tablas originales) ──
    $db->exec("CREATE TABLE IF NOT EXISTS tickets (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at   TEXT    DEFAULT (datetime('now', 'localtime')),
        nombre       TEXT    NOT NULL,
        email        TEXT,
        telefono     TEXT,
        empresa      TEXT,
        servicio     TEXT,
        resumen      TEXT,
        conversacion TEXT,
        estado       TEXT    DEFAULT 'nuevo',
        notas        TEXT,
        ip           TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS conversations (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id   TEXT    NOT NULL UNIQUE,
        started_at   TEXT    DEFAULT (datetime('now', 'localtime')),
        last_active  TEXT    DEFAULT (datetime('now', 'localtime')),
        messages     TEXT,
        ip           TEXT,
        user_agent   TEXT,
        is_lead      INTEGER DEFAULT 0,
        msg_count    INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        bucket TEXT NOT NULL,
        ip     TEXT NOT NULL,
        ts     TEXT NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_lookup ON rate_limits (bucket, ip, ts)");

    // ── v2: blog autonomo, analitica y motor de decision ──

    // Configuracion editable desde el panel (la API key va cifrada)
    $db->exec("CREATE TABLE IF NOT EXISTS blog_settings (
        clave      TEXT PRIMARY KEY,
        valor      TEXT,
        cifrado    INTEGER DEFAULT 0,
        updated_at TEXT DEFAULT (datetime('now', 'localtime'))
    )");

    // Clusters tematicos: el peso es lo que el motor de decision ajusta
    $db->exec("CREATE TABLE IF NOT EXISTS blog_clusters (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre      TEXT NOT NULL UNIQUE,
        etiqueta    TEXT NOT NULL,
        servicio    TEXT,
        peso        REAL    DEFAULT 1.0,
        activo      INTEGER DEFAULT 1,
        updated_at  TEXT DEFAULT (datetime('now', 'localtime'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS blog_keywords (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        keyword           TEXT NOT NULL UNIQUE,
        cluster_id        INTEGER REFERENCES blog_clusters(id),
        estado            TEXT    DEFAULT 'sugerida',
        score             REAL    DEFAULT 0,
        visitas           INTEGER DEFAULT 0,
        visitas_organicas INTEGER DEFAULT 0,
        origen            TEXT    DEFAULT 'semilla',
        post_id           INTEGER,
        created_at        TEXT DEFAULT (datetime('now', 'localtime')),
        last_scored_at    TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_kw_estado ON blog_keywords (estado, score)");

    $db->exec("CREATE TABLE IF NOT EXISTS blog_posts (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        slug             TEXT NOT NULL UNIQUE,
        titulo           TEXT NOT NULL,
        meta_title       TEXT,
        meta_description TEXT,
        keyword          TEXT,
        cluster_id       INTEGER REFERENCES blog_clusters(id),
        extracto         TEXT,
        cuerpo_html      TEXT,
        imagen_path      TEXT,
        imagen_alt       TEXT,
        estado           TEXT DEFAULT 'programado',
        programado_para  TEXT,
        publicado_at     TEXT,
        created_at       TEXT DEFAULT (datetime('now', 'localtime')),
        modelo           TEXT,
        tokens_entrada   INTEGER DEFAULT 0,
        tokens_salida    INTEGER DEFAULT 0,
        costo_usd        REAL    DEFAULT 0,
        palabras         INTEGER DEFAULT 0,
        error            TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_posts_estado ON blog_posts (estado, publicado_at)");

    // Analitica propia: una fila por visita, sin cookies de terceros
    $db->exec("CREATE TABLE IF NOT EXISTS hits (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        ts            TEXT DEFAULT (datetime('now', 'localtime')),
        fecha         TEXT,
        path          TEXT,
        post_id       INTEGER,
        referrer      TEXT,
        referrer_host TEXT,
        origen        TEXT,
        ip_hash       TEXT,
        visitante     TEXT,
        user_agent    TEXT,
        es_bot        INTEGER DEFAULT 0,
        segundos      INTEGER DEFAULT 0
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hits_fecha   ON hits (fecha)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hits_post    ON hits (post_id, fecha)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hits_visita  ON hits (visitante)");

    // Bitacora de las tareas automaticas (cron)
    $db->exec("CREATE TABLE IF NOT EXISTS blog_runs (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        tarea     TEXT,
        inicio    TEXT DEFAULT (datetime('now', 'localtime')),
        fin       TEXT,
        resultado TEXT,
        detalle   TEXT
    )");

    $db->exec('PRAGMA user_version = ' . DB_SCHEMA_VERSION);
}
