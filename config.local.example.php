<?php
/* ============================================================
   DigiNest — réglages privés
   1. Sur Hostinger, copiez ce fichier sous le nom config.local.php
      (Gestionnaire de fichiers → public_html → Copier / Renommer)
   2. Remplacez les valeurs ci-dessous par les vôtres
   config.local.php est ignoré par Git : vos secrets ne vont jamais sur GitHub.
============================================================ */

// === Base de données (hPanel → Bases de données → MySQL) ===
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_diginest');
define('DB_USER', 'u000000000_diginest');
define('DB_PASS', 'votre-mot-de-passe-mysql');

// === E-mails (hPanel → E-mails → Comptes e-mail) ===
define('FROM_EMAIL',  'noreply@votre-domaine.tn');   // adresse d'envoi
define('FROM_NAME',   'DigiNest');
define('ADMIN_EMAIL', 'vous@gmail.com');             // reçoit les alertes

// === SMTP Hostinger (hPanel → E-mails → Configuration) ===
define('SMTP_HOST',   'smtp.hostinger.com');
define('SMTP_PORT',   465);                          // 465 = SSL, 587 = TLS
define('SMTP_USER',   'noreply@votre-domaine.tn');   // identique à FROM_EMAIL
define('SMTP_PASS',   'mot-de-passe-de-la-boite-mail');
define('SMTP_SECURE', 'ssl');                        // 'ssl' avec 465, 'tls' avec 587

// === Sécurité ===
// Chaîne aléatoire d'au moins 50 caractères (lettres + chiffres).
// Ses 16 premiers caractères servent de clé pour install.php et cron.php.
define('JWT_SECRET', 'remplacez-par-une-longue-chaine-aleatoire-de-50-caracteres-minimum');
define('TOKEN_TTL_DAYS', 30);

// === Compte administrateur créé à l'installation ===
define('DEFAULT_ADMIN_EMAIL', 'admin@votre-domaine.tn');
define('DEFAULT_ADMIN_PASS',  'un-mot-de-passe-solide');  // changez-le ensuite depuis le site

// === Général ===
define('TIMEZONE', 'Africa/Tunis');
define('MAX_UPLOAD_MB', 5);
define('DEBUG_MODE', false);  // true uniquement pour diagnostiquer
