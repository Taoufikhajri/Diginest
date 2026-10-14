<?php
/* ============================================================
   db.php — database connection + helpers
============================================================ */
require_once __DIR__ . '/config.php';

function db() {
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
    } catch (Exception $e) {
      json_response(['error' => 'Connexion à la base de données impossible', 'detail' => DEBUG_MODE ? $e->getMessage() : ''], 500);
    }
  }
  return $pdo;
}

function json_response($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_body() {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function uid($prefix = '') {
  return $prefix . substr(bin2hex(random_bytes(8)), 0, 12);
}

function make_token($user_id) {
  $token = bin2hex(random_bytes(32));
  $expires = date('Y-m-d H:i:s', time() + TOKEN_TTL_DAYS * 86400);
  db()->prepare("INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)")
      ->execute([$token, $user_id, $expires]);
  return $token;
}

function get_bearer_token() {
  // Custom header that hosts never strip (most reliable)
  if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) return trim($_SERVER['HTTP_X_AUTH_TOKEN']);
  $hdr = '';
  // Several sources because some hosts (e.g. Hostinger/CGI) do not pass HTTP_AUTHORIZATION directly
  if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'];
  } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  } elseif (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
      if (strtolower($k) === 'authorization') { $hdr = $v; break; }
    }
  } elseif (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
      if (strtolower($k) === 'authorization') { $hdr = $v; break; }
    }
  }
  if ($hdr && preg_match('/Bearer\s+(.+)/i', $hdr, $m)) return trim($m[1]);
  // Fallback: token passed as a query parameter
  if (!empty($_GET['token'])) return trim($_GET['token']);
  return null;
}

function current_user() {
  $token = get_bearer_token();
  if (!$token) return null;
  $stmt = db()->prepare("SELECT u.* FROM users u JOIN sessions s ON s.user_id=u.id WHERE s.token=? AND s.expires_at > NOW()");
  $stmt->execute([$token]);
  $u = $stmt->fetch();
  if ($u) unset($u['password_hash']);
  return $u;
}

function require_auth() {
  $u = current_user();
  if (!$u) json_response(['error' => 'Connexion requise'], 401);
  return $u;
}

function require_admin() {
  $u = require_auth();
  if ($u['role'] !== 'admin') json_response(['error' => 'Droits administrateur requis'], 403);
  return $u;
}

function setting($key, $default = null) {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    $rows = db()->query("SELECT key_name, value FROM settings")->fetchAll();
    foreach ($rows as $r) $cache[$r['key_name']] = json_decode($r['value'], true);
  }
  return isset($cache[$key]) ? $cache[$key] : $default;
}

function save_setting($key, $value) {
  db()->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
      ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function send_email($to, $subject, $html_body) {
  // Use PHPMailer when available and SMTP is configured
  $phpmailer_path = __DIR__ . '/PHPMailer/PHPMailer.php';
  if (SMTP_USER && SMTP_PASS && file_exists($phpmailer_path)) {
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
      // Server settings
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->Password   = SMTP_PASS;
      $mail->SMTPSecure = (SMTP_PORT == 465)
                          ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                          : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = SMTP_PORT;
      $mail->CharSet    = 'UTF-8';
      $mail->Encoding   = 'base64';

      // Sender and recipient
      $mail->setFrom(FROM_EMAIL, FROM_NAME);
      $mail->addAddress($to);
      $mail->addReplyTo(FROM_EMAIL, FROM_NAME);

      // Content
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html_body;
      $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html_body));

      $mail->send();
      return true;
    } catch (\Exception $e) {
      if (DEBUG_MODE) error_log('PHPMailer Error: ' . $mail->ErrorInfo);
      return false;
    }
  }

  // fallback: PHP mail() (works on Hostinger when "PHP Mail" is enabled in hPanel)
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
  $headers .= "Reply-To: " . FROM_EMAIL . "\r\n";
  $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
  $encoded_subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
  return @mail($to, $encoded_subject, $html_body, $headers, '-f' . FROM_EMAIL);
}

function email_template($title, $body, $cta_text = null, $cta_url = null) {
  $brand = setting('brand', 'DigiNest');
  $cta_html = '';
  if ($cta_text && $cta_url) {
    $cta_html = '<a href="'.htmlspecialchars($cta_url).'" style="display:inline-block;background:#1A5FD4;color:#FFFFFF;padding:14px 28px;border-radius:12px;text-decoration:none;font-weight:bold;margin-top:20px;">'.htmlspecialchars($cta_text).'</a>';
  }
  return '<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#F0F4FF;font-family:Arial,Helvetica,sans-serif;">
<div style="max-width:600px;margin:40px auto;background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.05);">
  <div style="background:linear-gradient(135deg,#1A5FD4,#051E4B);padding:32px;text-align:center;">
    <h1 style="color:#3ECFBE;margin:0;font-size:24px;font-weight:900;">🪺 '.htmlspecialchars($brand).'</h1>
  </div>
  <div style="padding:32px;">
    <h2 style="color:#0A1628;margin:0 0 16px;font-size:22px;">'.htmlspecialchars($title).'</h2>
    <div style="color:#4A5568;line-height:1.7;font-size:15px;">'.$body.'</div>
    '.$cta_html.'
  </div>
  <div style="background:#F0F4FF;padding:20px;text-align:center;color:#8C9AB0;font-size:12px;">
    © '.date('Y').' '.htmlspecialchars($brand).' — Tous droits réservés
  </div>
</div>
</body></html>';
}

function days_between($from, $to) {
  $d1 = new DateTime($from);
  $d2 = new DateTime($to);
  return (int)$d2->diff($d1)->format('%r%a');
}

// CORS
function cors() {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

// ============================================================
// UltraMsg WhatsApp notifications (v30)
// Sends a WhatsApp message to the admin via UltraMsg API.
// Settings keys: ultramsgEnabled, ultramsgInstance, ultramsgToken,
//                ultramsgAdminNumber, ultramsgEvents (array of event keys)
// ============================================================
function send_whatsapp($event, $message, $to = null) {
  if (!setting('ultramsgEnabled', false)) return false;
  $events = setting('ultramsgEvents', ['register','order','approve','subscription','expiry','ticket','review','refund']);
  if (!is_array($events)) $events = [];
  if ($event && !in_array($event, $events)) return false;

  $instance = trim((string)setting('ultramsgInstance', ''));
  $token    = trim((string)setting('ultramsgToken', ''));
  $admin    = trim((string)setting('ultramsgAdminNumber', ''));
  $to = $to ?: $admin;
  if (!$instance || !$token || !$to) return false;

  // Normalize number: digits only, no +
  $to = preg_replace('/\D+/', '', $to);
  if (!$to) return false;

  $url = "https://api.ultramsg.com/{$instance}/messages/chat";
  $body = http_build_query([
    'token' => $token,
    'to'    => $to,
    'body'  => $message,
    'priority' => 10
  ]);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  curl_close($ch);
  return $err ? false : $resp;
}
