<?php
// PREXAcode - Configuración de credenciales
// INSTRUCCIONES: Copiar este archivo como config.php y completar con tus credenciales reales
// IMPORTANTE: config.php NO debe subirse al repositorio (.gitignore lo excluye)

define('OPENAI_API_KEY', 'sk-proj-XXXXXXXXXXXXXXXXXX');

define('WA_TOKEN', 'EAAP...');        // Token de WhatsApp Business API (Meta Business Manager)
define('WA_PHONE_NUMBER_ID', '');     // ID del número de WhatsApp Business
define('WA_TO', '');                  // Número destino para notificaciones (sin +, ej: 5491133679492)

define('COMPANY_EMAIL', 'info@prexacode.com');
define('COMPANY_DOMAIN', 'https://prexacode.com');

// Panel de administración
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', '');        // Generar con: password_hash('tuContraseña', PASSWORD_BCRYPT)

// SQLite - ruta al archivo de base de datos
define('DB_PATH', __DIR__ . '/data/tickets.db');
