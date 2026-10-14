<?php
/* ============================================================
   install.php — DigiNest one-time installer
   Usage : https://votre-domaine.tn/install.php?key=LES_16_PREMIERS_CARACTERES_DE_JWT_SECRET
   Crée les tables, le compte administrateur et les données de départ.
   Protégé par une clé + un fichier verrou (.installed).
============================================================ */
require_once __DIR__ . '/db.php';

function install_page($title, $html, $ok = true) {
  $c = $ok ? '#1A5FD4' : '#B91C1C';
  echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.htmlspecialchars($title).' — DigiNest</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;background:#F0F4FF;padding:24px;color:#0A1628;">
<div style="max-width:620px;margin:40px auto;background:#fff;padding:36px;border-radius:24px;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
<h1 style="color:'.$c.';margin-top:0;">'.htmlspecialchars($title).'</h1>'.$html.'</div></body></html>';
  exit;
}

$lock = __DIR__ . '/.installed';
if (file_exists($lock)) {
  install_page('Installation déjà effectuée', '<p>DigiNest est déjà installé. Pour réinstaller, supprimez le fichier <code>.installed</code> du serveur (Gestionnaire de fichiers Hostinger).</p>', false);
}

$expectedKey = substr(JWT_SECRET, 0, 16);
if (strpos(JWT_SECRET, 'CHANGE') === 0 || strlen(JWT_SECRET) < 32) {
  install_page('Configuration incomplète', '<p>Définissez une valeur longue et aléatoire pour <code>JWT_SECRET</code> dans <code>config.local.php</code> avant d’installer.</p>', false);
}
if (DEFAULT_ADMIN_PASS === 'CHANGE-ME' || strlen(DEFAULT_ADMIN_PASS) < 8) {
  install_page('Mot de passe admin manquant', '<p>Définissez <code>DEFAULT_ADMIN_PASS</code> (8 caractères minimum) dans <code>config.local.php</code>.</p>', false);
}
if (!hash_equals($expectedKey, (string)($_GET['key'] ?? ''))) {
  install_page('Clé d’installation requise', '<p>Ouvrez cette page avec la clé : <code>install.php?key=XXXXXXXXXXXXXXXX</code><br>(les 16 premiers caractères de <code>JWT_SECRET</code> définis dans <code>config.local.php</code>).</p>', false);
}

try {
  $pdo = db();

  $schemas = [
    "CREATE TABLE IF NOT EXISTS users (
      id VARCHAR(20) PRIMARY KEY,
      email VARCHAR(191) UNIQUE NOT NULL,
      whatsapp VARCHAR(30),
      password_hash VARCHAR(255) NOT NULL,
      name VARCHAR(100),
      role ENUM('customer','admin') DEFAULT 'customer',
      points INT DEFAULT 0,
      referred_by VARCHAR(20) NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_email(email),
      INDEX idx_role(role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS sessions (
      token VARCHAR(64) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      expires_at DATETIME NOT NULL,
      INDEX idx_user(user_id),
      INDEX idx_exp(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS products (
      id VARCHAR(20) PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      category VARCHAR(50),
      description TEXT,
      icon VARCHAR(10),
      color VARCHAR(20),
      durations JSON,
      featured TINYINT(1) DEFAULT 0,
      stock TINYINT(1) DEFAULT 1,
      sort_order INT DEFAULT 0,
      image_url VARCHAR(500) DEFAULT NULL,
      cost_price DECIMAL(10,2) DEFAULT 0,
      has_calc TINYINT(1) DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS orders (
      id VARCHAR(20) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      product_id VARCHAR(20),
      product_name VARCHAR(150),
      product_icon VARCHAR(10),
      qty INT DEFAULT 1,
      duration_m INT,
      duration_label VARCHAR(50),
      unit_price DECIMAL(10,2),
      subtotal DECIMAL(10,2),
      points_used INT DEFAULT 0,
      points_discount DECIMAL(10,2) DEFAULT 0,
      total DECIMAL(10,2),
      note TEXT,
      payment_method VARCHAR(100),
      proof_url VARCHAR(255),
      status ENUM('pending','active','rejected') DEFAULT 'pending',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      activated_at DATETIME NULL,
      INDEX idx_user(user_id),
      INDEX idx_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS subscriptions (
      id VARCHAR(20) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      order_id VARCHAR(20),
      product_id VARCHAR(20),
      product_name VARCHAR(150),
      product_icon VARCHAR(10),
      product_color VARCHAR(20),
      duration_label VARCHAR(50),
      start_date DATETIME,
      end_date DATETIME,
      amount_paid DECIMAL(10,2),
      status ENUM('active','expired','refunded','cancelled') DEFAULT 'active',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user(user_id),
      INDEX idx_end(end_date),
      INDEX idx_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS tickets (
      id VARCHAR(20) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      subject VARCHAR(200),
      category VARCHAR(50),
      status ENUM('open','replied','closed') DEFAULT 'open',
      messages JSON,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user(user_id),
      INDEX idx_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS refunds (
      id VARCHAR(20) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      subscription_id VARCHAR(20),
      product_name VARCHAR(150),
      method ENUM('points','payment','replace'),
      reason TEXT,
      close_date DATE,
      proof_url VARCHAR(255),
      status ENUM('pending','approved','rejected') DEFAULT 'pending',
      admin_note TEXT,
      refund_amount DECIMAL(10,2),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      processed_at DATETIME NULL,
      INDEX idx_user(user_id),
      INDEX idx_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS notifications (
      id VARCHAR(20) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      title VARCHAR(200),
      body TEXT,
      kind VARCHAR(50),
      type VARCHAR(50),
      read_status TINYINT(1) DEFAULT 0,
      email_sent TINYINT(1) DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user(user_id),
      INDEX idx_read(read_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS reviews (
      id VARCHAR(20) PRIMARY KEY,
      user_id VARCHAR(20) NOT NULL,
      reviewer_name VARCHAR(100),
      product_name VARCHAR(100),
      rating TINYINT DEFAULT 5,
      review_text TEXT NOT NULL,
      status ENUM('pending','approved','rejected') DEFAULT 'pending',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_status(status),
      INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS settings (
      key_name VARCHAR(50) PRIMARY KEY,
      value JSON
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS expiry_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      subscription_id VARCHAR(20),
      days_before INT,
      sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_sub_days(subscription_id, days_before)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  ];

  foreach ($schemas as $sql) $pdo->exec($sql);

  // Default admin
  $existsAdmin = $pdo->query("SELECT id FROM users WHERE email='".DEFAULT_ADMIN_EMAIL."'")->fetch();
  if (!$existsAdmin) {
    $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, name, role, points) VALUES (?, ?, ?, ?, 'admin', 0)");
    $stmt->execute([uid('a'), DEFAULT_ADMIN_EMAIL, password_hash(DEFAULT_ADMIN_PASS, PASSWORD_BCRYPT), 'Administrateur']);
  }

  // Default products
  $defaultProducts = [
    ['ChatGPT Plus','IA','Accès complet à GPT et aux fonctionnalités avancées de ChatGPT','🤖','#10A37F',[['m'=>1,'p'=>45],['m'=>3,'p'=>120],['m'=>12,'p'=>420]],1],
    ['Canva Pro','Design','Modèles professionnels illimités et suppression automatique de l’arrière-plan','🎨','#00C4CC',[['m'=>1,'p'=>25],['m'=>12,'p'=>220]],1],
    ['Gemini Advanced','IA','Le modèle le plus puissant de Google avec 2 To de stockage cloud','✨','#4285F4',[['m'=>1,'p'=>55],['m'=>12,'p'=>550]],0],
    ['Claude Pro','IA','Conversations plus longues, Projects et Artifacts','🧠','#D97706',[['m'=>1,'p'=>50],['m'=>12,'p'=>480]],1],
    ['Grok Premium','IA','Grok de xAI — accès complet aux dernières fonctionnalités','⚡','#000000',[['m'=>1,'p'=>65],['m'=>12,'p'=>650]],0],
    ['Adobe Creative Cloud','Design','Toutes les applications Adobe : Photoshop, Illustrator, Premiere...','🎬','#FF0000',[['m'=>1,'p'=>85],['m'=>12,'p'=>850]],1],
    ['CapCut Pro','Vidéo','Montage professionnel sans filigrane','🎥','#000000',[['m'=>1,'p'=>20],['m'=>12,'p'=>180]],0],
    ['Microsoft Office 365','Productivité','Word, Excel, PowerPoint + 1 To OneDrive','📊','#D83B01',[['m'=>12,'p'=>120]],0],
    ['Windows 11 Pro','Systèmes','Clé authentique à vie','💻','#0078D4',[['m'=>999,'p'=>90]],0],
    ['NordVPN','VPN','Protection complète et vitesses élevées dans plus de 60 pays','🛡️','#4687FF',[['m'=>1,'p'=>30],['m'=>12,'p'=>240]],0],
    ['Kaspersky Antivirus','Sécurité','Protection complète contre les virus et le piratage','🔒','#00A88E',[['m'=>12,'p'=>75]],0],
    ['Netflix Premium','Divertissement','4K + 4 écrans simultanés','🎬','#E50914',[['m'=>1,'p'=>35],['m'=>12,'p'=>380]],0],
  ];

  $existsProducts = $pdo->query("SELECT COUNT(*) c FROM products")->fetch();
  if ($existsProducts['c'] == 0) {
    $stmt = $pdo->prepare("INSERT INTO products (id, name, category, description, icon, color, durations, featured, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $i = 0;
    foreach ($defaultProducts as $p) {
      $stmt->execute([uid('p'), $p[0], $p[1], $p[2], $p[3], $p[4], json_encode($p[5]), $p[6], $i++]);
    }
  }

  // Default settings
  $defaults = [
    'brand' => 'DigiNest',
    'tagline' => 'Votre nid pour l’IA et les abonnements numériques',
    'whatsapp' => '+216 50 000 000',
    'email' => 'contact@diginest.tn',
    'pointValue' => 0.05,
    'pointsOnSignup' => 50,
    'pointsPerPurchase' => 100,
    'pointsOnReferral' => 200,
    'expireNotifyDays' => 4,
    'heroTitle' => 'Vos abonnements numériques au juste prix',
    'heroSub' => 'Une plateforme tunisienne de confiance pour tout ce dont vous avez besoin : ChatGPT, Adobe, Canva, Office et plus — activation rapide et support continu.',
    'footerAbout' => 'DigiNest est une plateforme tunisienne spécialisée dans la vente d’abonnements IA et de logiciels professionnels, à prix justes et en toute confiance.',
    'paymentMethods' => [
      ['id'=>'pm1','name'=>'Virement bancaire - Attijari Bank','info'=>"RIB : 04 123 0123456789 12\nAu nom de : DigiNest",'active'=>true],
      ['id'=>'pm2','name'=>'D17 / Edinar','info'=>"Numéro : 12345678\nAu nom de : DigiNest",'active'=>true],
      ['id'=>'pm3','name'=>'Recharge Ooredoo / Orange','info'=>"Numéro : 50 000 000",'active'=>true],
    ],
  ];

  foreach ($defaults as $k => $v) {
    $existsSetting = $pdo->prepare("SELECT 1 FROM settings WHERE key_name=?");
    $existsSetting->execute([$k]);
    if (!$existsSetting->fetch()) save_setting($k, $v);
  }

  file_put_contents($lock, date('c'));

  install_page('Installation réussie ✅', '
  <div style="background:#F0F4FF;padding:18px 20px;border-radius:12px;margin:20px 0;">
    <p style="margin-top:0;"><b>Identifiants administrateur :</b></p>
    <p>E-mail : <code>'.htmlspecialchars(DEFAULT_ADMIN_EMAIL).'</code></p>
    <p style="margin-bottom:0;">Mot de passe : celui défini dans <code>DEFAULT_ADMIN_PASS</code> (config.local.php)</p>
  </div>
  <div style="background:#E0F7F4;padding:18px 20px;border-radius:12px;margin:20px 0;border-left:4px solid #0FA896;">
    <p style="margin-top:0;"><b>Étapes suivantes :</b></p>
    <ol style="line-height:1.8;margin-bottom:0;">
      <li>Connectez-vous au site avec le compte administrateur</li>
      <li>Changez le mot de passe depuis « Mon compte »</li>
      <li>Renseignez vos infos dans Administration › Paramètres (WhatsApp, moyens de paiement…)</li>
      <li>Créez la tâche Cron quotidienne (voir README.md)</li>
    </ol>
  </div>
  <a href="./" style="display:inline-block;background:#1A5FD4;color:#fff;padding:14px 28px;border-radius:12px;text-decoration:none;font-weight:bold;">Aller sur le site →</a>');

} catch (Exception $e) {
  install_page('Erreur pendant l’installation', '<pre style="background:#FEF2F2;padding:16px;border-radius:12px;white-space:pre-wrap;">'.htmlspecialchars($e->getMessage()).'</pre><p>Vérifiez les accès à la base de données dans <code>config.local.php</code>.</p>', false);
}
