<?php
/* ============================================================
   cron.php — runs once a day to send subscription reminders
   Hostinger cron job example (every day at 9:00):
   0 9 * * * /usr/bin/php /home/USER/public_html/cron.php
============================================================ */
require_once __DIR__ . '/db.php';

$start = microtime(true);
$report = ['checked' => 0, 'emailed' => 0, 'errors' => []];

try {
  // Reminders before expiry: 7 / 3 / 1 days (+ expiry day)
  $triggers = [7, 3, 1, 0];
  $brand = setting('brand', 'DigiNest');

  // Mark expired subscriptions
  db()->exec("UPDATE subscriptions SET status='expired' WHERE status='active' AND end_date < NOW()");

  // Find subscriptions matching trigger days
  $subs = db()->query("
    SELECT s.*, u.email AS user_email, u.name AS user_name, u.whatsapp AS user_whatsapp
    FROM subscriptions s
    JOIN users u ON u.id=s.user_id
    WHERE s.status IN ('active','expired')
  ")->fetchAll();

  foreach ($subs as $sub) {
    $report['checked']++;
    $daysLeft = (int)round((strtotime($sub['end_date']) - time()) / 86400);

    foreach ($triggers as $trigger) {
      if ($daysLeft !== $trigger) continue;

      // Check if already sent
      $check = db()->prepare("SELECT 1 FROM expiry_log WHERE subscription_id=? AND days_before=?");
      $check->execute([$sub['id'], $trigger]);
      if ($check->fetch()) continue;

      // Create notification
      $title = $trigger === 0
        ? "Votre abonnement {$sub['product_name']} a expiré"
        : ($trigger === 1
            ? "Votre abonnement {$sub['product_name']} expire demain"
            : "Votre abonnement {$sub['product_name']} expire dans {$trigger} jours");

      $body = $trigger === 0
        ? "Votre abonnement a expiré. Renouvelez-le dès maintenant pour continuer à en profiter"
        : "Renouvelez votre abonnement à temps pour éviter toute interruption";

      db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type, email_sent) VALUES (?,?,?,?,?,'auto-expiry', 1)")
          ->execute([uid('n'), $sub['user_id'], $title, $body, "expire-{$trigger}"]);

      // Send email
      $emailBody = "
        <p>Bonjour {$sub['user_name']},</p>
        <p>".($trigger === 0
              ? "Nous vous informons que votre abonnement <b>{$sub['product_name']}</b> est arrivé à expiration."
              : "Nous vous rappelons que votre abonnement <b>{$sub['product_name']}</b> expire ".($trigger === 1 ? "<b>demain</b>" : "dans <b>{$trigger} jours</b>")."."
            )."</p>
        <p>Date d’expiration de l’abonnement : <b>".date('d/m/Y', strtotime($sub['end_date']))."</b></p>
        ".($trigger === 0
            ? "<p>Pour continuer, renouvelez votre abonnement depuis votre espace client.</p>"
            : "<p>Pour éviter toute interruption de service, vous pouvez renouveler votre abonnement dès maintenant depuis votre espace client.</p>")."
      ";

      $sent = send_email($sub['user_email'], $title, email_template($title, $emailBody, "Renouveler l’abonnement", get_site_url_cli()));

      // WhatsApp reminder to the customer (7/3/1 days and on expiry)
      if (!empty($sub['user_whatsapp'])) {
        $waMsg = $trigger === 0
          ? "⛔ Votre abonnement {$sub['product_name']} a expiré\nRenouvelez-le maintenant pour continuer à profiter du service."
          : "⏳ Rappel : votre abonnement {$sub['product_name']} " . ($trigger === 1 ? "expire demain" : "expire dans {$trigger} jours") . "\nDate d’expiration : " . date('d/m/Y', strtotime($sub['end_date'])) . "\nRenouvelez à temps pour éviter toute interruption.";
        send_whatsapp('expiry', $waMsg, $sub['user_whatsapp']);
      }

      // ===== Email the admins when a subscription expires / is about to expire =====
      // Recipients: all admin accounts + extra emails from settings (notifyEmails)
      $adminRecipients = [];
      foreach (db()->query("SELECT email FROM users WHERE role='admin'")->fetchAll() as $ad) {
        if (!empty($ad['email'])) $adminRecipients[] = trim($ad['email']);
      }
      $extra = setting('notifyEmails', '');
      if (is_string($extra) && trim($extra) !== '') {
        foreach (preg_split('/[,;\s]+/', $extra) as $e) { if (trim($e) !== '') $adminRecipients[] = trim($e); }
      } elseif (is_array($extra)) {
        foreach ($extra as $e) { if (trim($e) !== '') $adminRecipients[] = trim($e); }
      }
      $adminRecipients = array_values(array_unique(array_filter($adminRecipients)));

      $adminTitle = $trigger === 0
        ? "Abonnement client expiré : {$sub['product_name']}"
        : "Abonnement client bientôt expiré : {$sub['product_name']}";
      $adminBody = "
        <p>Alerte administrateur concernant l’abonnement d’un client :</p>
        <p>Client : <b>{$sub['user_name']}</b> ({$sub['user_email']})</p>
        <p>Produit : <b>{$sub['product_name']}</b></p>
        <p>Statut : <b>".($trigger === 0 ? "expiré" : ("{$trigger} ".($trigger === 1 ? "jour restant" : "jours restants")))."</b></p>
        <p>Date d’expiration : <b>".date('d/m/Y', strtotime($sub['end_date']))."</b></p>
      ";
      foreach ($adminRecipients as $rcpt) {
        send_email($rcpt, $adminTitle, email_template($adminTitle, $adminBody, "Ouvrir l’administration", get_site_url_cli()));
      }
      // Extra WhatsApp alert to admin (optional — uses the same expiry event)
      send_whatsapp('expiry', "📌 ".$adminTitle."\nClient : {$sub['user_email']}\nDate d’expiration : ".date('d/m/Y', strtotime($sub['end_date'])));

      // Log it
      db()->prepare("INSERT INTO expiry_log (subscription_id, days_before) VALUES (?,?)")
          ->execute([$sub['id'], $trigger]);

      if ($sent) $report['emailed']++;
      else $report['errors'][] = "Email failed for {$sub['user_email']}";
    }
  }

  // Cleanup old sessions
  db()->exec("DELETE FROM sessions WHERE expires_at < NOW()");

} catch (Exception $e) {
  $report['errors'][] = $e->getMessage();
}

$report['duration_ms'] = round((microtime(true) - $start) * 1000);
$logLine = date('Y-m-d H:i:s') . ' — ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";

@file_put_contents(__DIR__ . '/cron.log', $logLine, FILE_APPEND);

if (php_sapi_name() === 'cli') {
  echo $logLine;
} else {
  // Allow manual trigger from browser with secret
  $secret = $_GET['key'] ?? '';
  if ($secret !== substr(JWT_SECRET, 0, 16)) {
    http_response_code(403);
    die('Forbidden');
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($report, JSON_UNESCAPED_UNICODE);
}

function get_site_url_cli() {
  $domain = setting('site_domain', $_SERVER['HTTP_HOST'] ?? 'localhost');
  return 'https://' . $domain;
}
