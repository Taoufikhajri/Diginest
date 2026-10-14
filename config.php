<?php
/* ============================================================
   DigiNest — configuration
   ⚠️ NE METTEZ JAMAIS VOS MOTS DE PASSE DANS CE FICHIER.
   Ce fichier est public sur GitHub. Vos vrais réglages vont dans
   config.local.php (créé directement sur Hostinger, jamais commité).
   Modèle : config.local.example.php
============================================================ */

$__local = __DIR__ . '/config.local.php';
if (file_exists($__local)) {
  require_once $__local;
}

// Valeurs par défaut (remplacées par config.local.php)
defined('DB_HOST')     || define('DB_HOST', 'localhost');
defined('DB_NAME')     || define('DB_NAME', '');
defined('DB_USER')     || define('DB_USER', '');
defined('DB_PASS')     || define('DB_PASS', '');

defined('FROM_EMAIL')  || define('FROM_EMAIL', 'noreply@example.com');
defined('FROM_NAME')   || define('FROM_NAME', 'DigiNest');
defined('ADMIN_EMAIL') || define('ADMIN_EMAIL', '');

defined('SMTP_HOST')   || define('SMTP_HOST', 'smtp.hostinger.com');
defined('SMTP_PORT')   || define('SMTP_PORT', 465);
defined('SMTP_USER')   || define('SMTP_USER', '');
defined('SMTP_PASS')   || define('SMTP_PASS', '');
defined('SMTP_SECURE') || define('SMTP_SECURE', 'ssl');

defined('JWT_SECRET')     || define('JWT_SECRET', 'CHANGE-ME');
defined('TOKEN_TTL_DAYS') || define('TOKEN_TTL_DAYS', 30);

defined('DEFAULT_ADMIN_EMAIL') || define('DEFAULT_ADMIN_EMAIL', 'admin@diginest.tn');
defined('DEFAULT_ADMIN_PASS')  || define('DEFAULT_ADMIN_PASS', 'CHANGE-ME');

defined('TIMEZONE')      || define('TIMEZONE', 'Africa/Tunis');
defined('MAX_UPLOAD_MB') || define('MAX_UPLOAD_MB', 5);
defined('DEBUG_MODE')    || define('DEBUG_MODE', false);

date_default_timezone_set(TIMEZONE);
if (DEBUG_MODE) { error_reporting(E_ALL); ini_set('display_errors', 1); }
else { error_reporting(0); ini_set('display_errors', 0); }
