<?php
/* ============================================================
   api.php — all API endpoints in one file
   Pattern: /api.php?action=ACTION
============================================================ */
require_once __DIR__ . '/db.php';
cors();

// Auto-migration v24 — add missing product columns (runs once, errors ignored)
try {
  $cols = db()->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('image_url', $cols)) db()->exec("ALTER TABLE products ADD COLUMN image_url VARCHAR(500) DEFAULT NULL");
  if (!in_array('sort_order', $cols)) db()->exec("ALTER TABLE products ADD COLUMN sort_order INT DEFAULT 0");
  if (!in_array('cost_price', $cols)) db()->exec("ALTER TABLE products ADD COLUMN cost_price DECIMAL(10,2) DEFAULT 0");
  if (!in_array('has_calc', $cols))   db()->exec("ALTER TABLE products ADD COLUMN has_calc TINYINT(1) DEFAULT 0");
} catch (Exception $e) { /* ignore if products table doesn't exist yet */ }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body = read_body();

try {
  switch ($action) {

    // ========== AUTH ==========
    case 'register':
      $email = trim($body['email'] ?? '');
      $whatsapp = trim($body['whatsapp'] ?? '');
      $password = $body['password'] ?? '';
      $referral = trim($body['referral'] ?? '');

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error' => 'Adresse e-mail invalide'], 400);
      if (!preg_match('/^[+\d\s\-]{8,}$/', $whatsapp)) json_response(['error' => 'Numéro WhatsApp invalide'], 400);
      if (strlen($password) < 6) json_response(['error' => 'Le mot de passe doit contenir au moins 6 caractères'], 400);

      $exists = db()->prepare("SELECT id FROM users WHERE email=?");
      $exists->execute([$email]);
      if ($exists->fetch()) json_response(['error' => 'Cette adresse e-mail est déjà utilisée'], 400);

      $referredBy = null;
      if ($referral) {
        $r = db()->prepare("SELECT id FROM users WHERE email=? OR id=?");
        $r->execute([$referral, $referral]);
        $ref = $r->fetch();
        if ($ref) $referredBy = $ref['id'];
      }

      $name = explode('@', $email)[0];
      $signupPoints = setting('pointsOnSignup', 50);
      $id = uid('u');
      $hash = password_hash($password, PASSWORD_BCRYPT);

      db()->prepare("INSERT INTO users (id, email, whatsapp, password_hash, name, points, referred_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
          ->execute([$id, $email, $whatsapp, $hash, $name, $signupPoints, $referredBy]);

      // WhatsApp notify admin (v30)
      send_whatsapp('register', "👤 Nouveau client inscrit\nE-mail : {$email}\nWhatsApp : {$whatsapp}");

      // Referral — store pending, points given on first purchase
      if ($referredBy) {
        // Just store the referral — points given when referred user first purchases
        db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?, ?, ?, ?, 'referral', 'auto')")
            ->execute([uid('n'), $referredBy, "🎉 Votre ami a rejoint via votre invitation", "Votre ami {$email} s’est inscrit — vous recevrez vos points lors de son premier achat"]);
      }

      // Welcome notification
      $brand = setting('brand', 'DigiNest');
      db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?, ?, ?, ?, 'welcome', 'auto')")
          ->execute([uid('n'), $id, "Bienvenue sur {$brand} !", "Vous avez reçu {$signupPoints} points offerts pour bien démarrer"]);

      // Welcome email
      send_email($email, "Bienvenue sur {$brand} !", email_template(
        "Bienvenue sur {$brand}",
        "<p>Bonjour et bienvenue,</p><p>Vous avez reçu <b>{$signupPoints} points</b> offerts pour bien démarrer. Utilisez-les comme réduction sur n’importe quel abonnement.</p><p>Commencez dès maintenant à découvrir nos abonnements.</p>",
        "Voir les produits",
        get_site_url()
      ));

      $token = make_token($id);
      $user = db()->query("SELECT id,email,whatsapp,name,role,points,created_at FROM users WHERE id='{$id}'")->fetch();
      json_response(['token' => $token, 'user' => $user]);

    case 'login':
      $email = trim($body['email'] ?? '');
      $password = $body['password'] ?? '';
      $stmt = db()->prepare("SELECT * FROM users WHERE email=?");
      $stmt->execute([$email]);
      $u = $stmt->fetch();
      if (!$u || !password_verify($password, $u['password_hash'])) json_response(['error' => 'Identifiants incorrects'], 401);
      $token = make_token($u['id']);
      unset($u['password_hash']);
      json_response(['token' => $token, 'user' => $u]);

    case 'me':
      $u = require_auth();
      json_response(['user' => $u]);

    case 'logout':
      $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
      if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
        db()->prepare("DELETE FROM sessions WHERE token=?")->execute([trim($m[1])]);
      }
      json_response(['ok' => true]);

    case 'update-profile':
      $u = require_auth();
      $email = trim($body['email'] ?? $u['email']);
      $whatsapp = trim($body['whatsapp'] ?? $u['whatsapp']);
      $password = $body['password'] ?? '';

      $sql = "UPDATE users SET email=?, whatsapp=?";
      $params = [$email, $whatsapp];
      if ($password) { $sql .= ", password_hash=?"; $params[] = password_hash($password, PASSWORD_BCRYPT); }
      $sql .= " WHERE id=?";
      $params[] = $u['id'];
      db()->prepare($sql)->execute($params);
      json_response(['ok' => true]);

    // ========== PRODUCTS ==========
    case 'products':
      $list = db()->query("SELECT * FROM products ORDER BY COALESCE(sort_order,0) ASC, featured DESC, created_at DESC")->fetchAll();
      foreach ($list as &$p) $p['durations'] = json_decode($p['durations'], true);
      json_response(['products' => $list]);

    case 'product-save':
      require_admin();
      $id = $body['id'] ?? uid('p');
      $exists = db()->prepare("SELECT id FROM products WHERE id=?");
      $exists->execute([$id]);
      if ($exists->fetch()) {
        db()->prepare("UPDATE products SET name=?, category=?, description=?, icon=?, color=?, durations=?, featured=?, stock=?, has_calc=?, cost_price=?, image_url=?, sort_order=? WHERE id=?")
            ->execute([$body['name'], $body['category']??'', $body['description']??'', $body['icon']??'⭐', $body['color']??'#0F4C3A', json_encode($body['durations']??[]), !empty($body['featured'])?1:0, !empty($body['stock'])?1:0, !empty($body['has_calc'])?1:0, floatval($body['cost_price']??0), $body['image_url']??'', intval($body['sort_order']??0), $id]);
      } else {
        db()->prepare("INSERT INTO products (id, name, category, description, icon, color, durations, featured, stock, has_calc, cost_price, image_url, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, $body['name'], $body['category']??'', $body['description']??'', $body['icon']??'⭐', $body['color']??'#0F4C3A', json_encode($body['durations']??[]), !empty($body['featured'])?1:0, !empty($body['stock'])?1:0, !empty($body['has_calc'])?1:0, floatval($body['cost_price']??0), $body['image_url']??'', intval($body['sort_order']??0)]);
      }
      json_response(['ok' => true, 'id' => $id]);

    case 'product-delete':
      require_admin();
      db()->prepare("DELETE FROM products WHERE id=?")->execute([$body['id']]);
      json_response(['ok' => true]);

    // ========== ORDERS ==========
    case 'orders':
      $u = require_auth();
      $where = $u['role'] === 'admin' ? "1=1" : "user_id=" . db()->quote($u['id']);
      $list = db()->query("SELECT o.*, u.name AS user_name, u.email AS user_email FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE {$where} ORDER BY o.created_at DESC")->fetchAll();
      json_response(['orders' => $list]);

    case 'order-create':
      $u = require_auth();
      $id = uid('o');
      $points_used = (int)($body['pointsUsed'] ?? 0);
      $pointValue = (float)setting('pointValue', 0.05);
      $points_discount = $points_used * $pointValue;

      if ($points_used > 0) {
        $check = db()->prepare("SELECT points FROM users WHERE id=?");
        $check->execute([$u['id']]);
        $userPoints = $check->fetchColumn();
        if ($points_used > $userPoints) json_response(['error' => 'Points insuffisants'], 400);
        db()->prepare("UPDATE users SET points=points-? WHERE id=?")->execute([$points_used, $u['id']]);
      }

      db()->prepare("INSERT INTO orders (id, user_id, product_id, product_name, product_icon, qty, duration_m, duration_label, unit_price, subtotal, points_used, points_discount, total, note, payment_method, proof_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$id, $u['id'], $body['productId'], $body['productName'], $body['productIcon']??'📦', $body['qty']??1, $body['duration']??1, $body['durationLabel']??'', $body['unitPrice']??0, $body['subtotal']??0, $points_used, $points_discount, $body['total']??0, $body['note']??'', $body['paymentMethod']??'', $body['proofUrl']??'']);

      // Notify admin
      $admins = db()->query("SELECT email FROM users WHERE role='admin'")->fetchAll();
      foreach ($admins as $a) {
        send_email($a['email'], "Nouvelle commande de {$u['name']}", email_template(
          "Nouvelle commande",
          "<p>Client : <b>{$u['email']}</b></p><p>Produit : <b>{$body['productName']}</b> × {$body['qty']}</p><p>Montant : <b>{$body['total']} DT</b></p>",
          "Ouvrir l’administration",
          get_site_url()
        ));
      }
      // WhatsApp notify admin (v30)
      send_whatsapp('order', "🛒 Nouvelle commande\nClient : {$u['email']}\nProduit : {$body['productName']} × ".($body['qty']??1)."\nMontant : ".($body['total']??0)." DT\nMoyen de paiement : ".($body['paymentMethod']??'-'));
      json_response(['ok' => true, 'id' => $id]);

    case 'order-process':
      require_admin();
      $id = $body['id'];
      $decision = $body['decision']; // 'approve' or 'reject'

      $o = db()->prepare("SELECT * FROM orders WHERE id=?");
      $o->execute([$id]);
      $order = $o->fetch();
      if (!$order) json_response(['error' => 'Commande introuvable'], 404);

      $user = db()->prepare("SELECT * FROM users WHERE id=?");
      $user->execute([$order['user_id']]);
      $customer = $user->fetch();

      if ($decision === 'approve') {
        // Create subscription
        $subId = uid('s');
        $start = time();
        $end = $order['duration_m'] == 999 ? $start + 100*365*86400 : $start + $order['duration_m'] * 30 * 86400;

        $product = db()->prepare("SELECT color FROM products WHERE id=?");
        $product->execute([$order['product_id']]);
        $color = $product->fetchColumn() ?: '#0F4C3A';

        db()->prepare("INSERT INTO subscriptions (id, user_id, order_id, product_id, product_name, product_icon, product_color, duration_label, start_date, end_date, amount_paid) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$subId, $order['user_id'], $id, $order['product_id'], $order['product_name'], $order['product_icon'], $color, $order['duration_label'], date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end), $order['total']]);

        // Update order
        db()->prepare("UPDATE orders SET status='active', activated_at=NOW() WHERE id=?")->execute([$id]);

        // Award purchase points
        $purchasePoints = setting('pointsPerPurchase', 100);
        db()->prepare("UPDATE users SET points=points+? WHERE id=?")->execute([$purchasePoints, $order['user_id']]);

        // Notify customer
        db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?, ?, ?, ?, 'order-approved', 'auto')")
            ->execute([uid('n'), $order['user_id'], "Abonnement {$order['product_name']} activé", "Votre abonnement est actif et vous avez gagné {$purchasePoints} points"]);

        // Referral bonus — give points to referrer on first purchase
        $buyerRef = db()->query("SELECT referred_by FROM users WHERE id='{$order['user_id']}'")->fetchColumn();
        if ($buyerRef) {
          $prevActive = (int)db()->query("SELECT COUNT(*) FROM orders WHERE user_id='{$order['user_id']}' AND status='active'")->fetchColumn();
          if ($prevActive <= 1) {
            $refPoints = setting('pointsOnReferral', 200);
            db()->prepare("UPDATE users SET points=points+? WHERE id=?")->execute([$refPoints, $buyerRef]);
            db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?, ?, ?, ?, 'referral', 'auto')")
                ->execute([uid('n'), $buyerRef, "+{$refPoints} points de parrainage ! 🎉", "L’ami que vous avez invité a effectué son premier achat — félicitations !"]);
          }
        }

        // Email customer
        send_email($customer['email'], "✅ Votre abonnement est activé", email_template(
          "Votre abonnement est activé",
          "<p>Votre abonnement <b>{$order['product_name']}</b> a bien été activé.</p><p>Date d’expiration : <b>".date('d/m/Y', $end)."</b></p><p>Vous avez gagné <b>{$purchasePoints} points</b> supplémentaires.</p>",
          "Ouvrir mon espace",
          get_site_url()
        ));
        // WhatsApp notify customer if they provided a number
        if (!empty($customer['whatsapp'])) {
          send_whatsapp('approve', "✅ Votre abonnement {$order['product_name']} est activé\nDate d’expiration : ".date('d/m/Y', $end)."\nMerci pour votre confiance 💚", $customer['whatsapp']);
        }
        // WhatsApp notify admin of the new subscription (from a customer order)
        send_whatsapp('subscription', "🆕 Nouvel abonnement (client)\nClient : {$customer['email']}\nProduit : {$order['product_name']}\nDate d’expiration : ".date('d/m/Y', $end)."\nMontant : {$order['total']} DT");
      } else {
        // Restore points
        if ($order['points_used'] > 0) {
          db()->prepare("UPDATE users SET points=points+? WHERE id=?")->execute([$order['points_used'], $order['user_id']]);
        }
        db()->prepare("UPDATE orders SET status='rejected' WHERE id=?")->execute([$id]);
        db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?, ?, ?, ?, 'order-rejected', 'auto')")
            ->execute([uid('n'), $order['user_id'], "Commande {$order['product_name']} refusée", $body['note'] ?? 'Veuillez vérifier votre commande ou contacter le support']);

        send_email($customer['email'], "Votre commande a été refusée", email_template(
          "Votre commande a été refusée",
          "<p>Malheureusement, votre commande <b>{$order['product_name']}</b> a été refusée.</p><p>".htmlspecialchars($body['note'] ?? 'Veuillez contacter le support pour plus d’informations')."</p>"
        ));
      }
      json_response(['ok' => true]);

    // ========== SUBSCRIPTIONS ==========
    case 'order-delete':
      require_admin();
      $b = read_body();
      db()->prepare("DELETE FROM orders WHERE id=?")->execute([$b['id']]);
      json_response(['ok' => true]);

    case 'subscriptions':
      $u = require_auth();
      $where = $u['role'] === 'admin' ? "1=1" : "s.user_id=" . db()->quote($u['id']);
      $list = db()->query("SELECT s.*, u.name AS user_name, u.email AS user_email FROM subscriptions s LEFT JOIN users u ON u.id=s.user_id WHERE {$where} ORDER BY s.end_date DESC")->fetchAll();
      json_response(['subscriptions' => $list]);

    case 'sub-create-manual':
      require_admin();
      $b = read_body();
      if (empty($b['product_id']) || empty($b['start_date']) || empty($b['end_date'])) {
        json_response(['error' => 'Champs manquants'], 400);
      }
      $p = db()->prepare("SELECT * FROM products WHERE id=?");
      $p->execute([$b['product_id']]);
      $prod = $p->fetch();
      if (!$prod) json_response(['error' => 'Produit introuvable'], 404);

      // Resolve customer: by email (auto-create account if needed) or by user_id
      $userId = null;
      $userEmail = '';
      $userWhatsapp = trim($b['whatsapp'] ?? '');
      $userCreated = false;
      $emailInput = trim($b['email'] ?? '');

      if ($emailInput !== '') {
        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) json_response(['error' => 'Adresse e-mail invalide'], 400);
        $find = db()->prepare("SELECT id, email, whatsapp FROM users WHERE email=?");
        $find->execute([$emailInput]);
        $existing = $find->fetch();
        if ($existing) {
          $userId = $existing['id'];
          $userEmail = $existing['email'];
          if ($userWhatsapp === '') $userWhatsapp = $existing['whatsapp'] ?? '';
          // Update WhatsApp number if provided and missing
          if (!empty($b['whatsapp']) && empty($existing['whatsapp'])) {
            db()->prepare("UPDATE users SET whatsapp=? WHERE id=?")->execute([$userWhatsapp, $userId]);
          }
        } else {
          // Auto-create a new account and link the subscription
          $newId = uid('u');
          $name = explode('@', $emailInput)[0];
          $tempPass = bin2hex(random_bytes(5)); // random temporary password
          $hash = password_hash($tempPass, PASSWORD_BCRYPT);
          $signupPoints = setting('pointsOnSignup', 50);
          db()->prepare("INSERT INTO users (id, email, whatsapp, password_hash, name, points) VALUES (?,?,?,?,?,?)")
              ->execute([$newId, $emailInput, $userWhatsapp, $hash, $name, $signupPoints]);
          $userId = $newId;
          $userEmail = $emailInput;
          $userCreated = true;
          // Email the new customer their login details
          send_email($emailInput, "Votre compte a été créé sur " . setting('brand', 'DigiNest'), email_template(
            "Bienvenue 👋",
            "<p>Un compte a été créé pour vous par l’administration afin d’y rattacher votre abonnement.</p>".
            "<p>Vos identifiants :</p><p>E-mail : <b>".htmlspecialchars($emailInput)."</b><br>Mot de passe temporaire : <b>{$tempPass}</b></p>".
            "<p>Nous vous conseillons de changer votre mot de passe dès la première connexion.</p>",
            "Se connecter",
            get_site_url()
          ));
        }
      } elseif (!empty($b['user_id'])) {
        $u2 = db()->prepare("SELECT id, email, whatsapp FROM users WHERE id=?");
        $u2->execute([$b['user_id']]);
        $row = $u2->fetch();
        if (!$row) json_response(['error' => 'Utilisateur introuvable'], 404);
        $userId = $row['id'];
        $userEmail = $row['email'];
        $userWhatsapp = $userWhatsapp !== '' ? $userWhatsapp : ($row['whatsapp'] ?? '');
      } else {
        json_response(['error' => 'Choisissez un client ou saisissez une adresse e-mail'], 400);
      }

      $subId = uid('s');
      $start = date('Y-m-d H:i:s', strtotime($b['start_date']));
      $end   = date('Y-m-d H:i:s', strtotime($b['end_date']));
      $amount = isset($b['amount_paid']) ? (float)$b['amount_paid'] : 0;
      $durLabel = $b['duration_label'] ?? 'Manuel';
      db()->prepare("INSERT INTO subscriptions (id, user_id, order_id, product_id, product_name, product_icon, product_color, duration_label, start_date, end_date, amount_paid, status) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active')")
          ->execute([$subId, $userId, null, $prod['id'], $prod['name'], $prod['icon'] ?? '📦', $prod['color'] ?? '#1A5FD4', $durLabel, $start, $end, $amount]);
      db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?, ?, ?, ?, 'order-approved', 'auto')")
          ->execute([uid('n'), $userId, "Abonnement {$prod['name']} activé", "Un nouvel abonnement vous a été ajouté par l’administration"]);

      // WhatsApp notification to admin for each new (manual) subscription
      send_whatsapp('subscription', "🆕 Nouvel abonnement manuel\nClient : {$userEmail}\nProduit : {$prod['name']}\nDébut : ".date('d/m/Y', strtotime($start))."\nFin : ".date('d/m/Y', strtotime($end))."\nMontant : {$amount} DT".($userCreated ? "\n(nouveau compte client créé)" : ""));
      // WhatsApp notification to the customer if a number is known
      if (!empty($userWhatsapp)) {
        send_whatsapp('subscription', "✅ Votre abonnement {$prod['name']} est activé\nDate d’expiration : ".date('d/m/Y', strtotime($end))."\nMerci pour votre confiance 💚", $userWhatsapp);
      }

      json_response(['ok' => true, 'id' => $subId, 'user_id' => $userId, 'user_created' => $userCreated]);

    case 'sub-update':
      require_admin();
      $b = read_body();
      if (empty($b['id'])) json_response(['error' => 'id manquant'], 400);
      $fields = []; $params = [];
      if (!empty($b['start_date'])) { $fields[] = "start_date=?"; $params[] = date('Y-m-d H:i:s', strtotime($b['start_date'])); }
      if (!empty($b['end_date']))   { $fields[] = "end_date=?";   $params[] = date('Y-m-d H:i:s', strtotime($b['end_date'])); }
      if (!empty($b['status']))     { $fields[] = "status=?";     $params[] = $b['status']; }
      if (empty($fields)) json_response(['error' => 'Aucune modification'], 400);
      $params[] = $b['id'];
      db()->prepare("UPDATE subscriptions SET ".implode(',', $fields)." WHERE id=?")->execute($params);
      json_response(['ok' => true]);

    case 'sub-delete':
      require_admin();
      $b = read_body();
      if (empty($b['id'])) json_response(['error' => 'id manquant'], 400);
      db()->prepare("DELETE FROM subscriptions WHERE id=?")->execute([$b['id']]);
      json_response(['ok' => true]);

    // ========== TICKETS ==========
    case 'tickets':
      $u = require_auth();
      $where = $u['role'] === 'admin' ? "1=1" : "t.user_id=" . db()->quote($u['id']);
      $list = db()->query("SELECT t.*, u.name AS user_name, u.email AS user_email FROM tickets t LEFT JOIN users u ON u.id=t.user_id WHERE {$where} ORDER BY t.created_at DESC")->fetchAll();
      foreach ($list as &$t) $t['messages'] = json_decode($t['messages'], true) ?: [];
      json_response(['tickets' => $list]);

    case 'ticket-create':
      $u = require_auth();
      $id = uid('t');
      $messages = [['from'=>'user', 'body'=>$body['body'], 'at'=>time()*1000]];
      db()->prepare("INSERT INTO tickets (id, user_id, subject, category, status, messages) VALUES (?,?,?,?,'open',?)")
          ->execute([$id, $u['id'], $body['subject'], $body['category']??'general', json_encode($messages, JSON_UNESCAPED_UNICODE)]);

      // Notify admin
      $admins = db()->query("SELECT email FROM users WHERE role='admin'")->fetchAll();
      foreach ($admins as $a) {
        send_email($a['email'], "Nouveau ticket de support", email_template(
          "Nouveau ticket de support",
          "<p>Client : <b>{$u['email']}</b></p><p>Objet : <b>".htmlspecialchars($body['subject'])."</b></p><blockquote>".htmlspecialchars($body['body'])."</blockquote>"
        ));
      }
      send_whatsapp('ticket', "📩 Nouveau ticket de support\nClient : {$u['email']}\nObjet : ".$body['subject']);
      json_response(['ok' => true, 'id' => $id]);

    case 'ticket-reply':
      $u = require_auth();
      $id = $body['id'];
      $stmt = db()->prepare("SELECT * FROM tickets WHERE id=?");
      $stmt->execute([$id]);
      $ticket = $stmt->fetch();
      if (!$ticket) json_response(['error' => 'Ticket introuvable'], 404);
      if ($u['role'] !== 'admin' && $ticket['user_id'] !== $u['id']) json_response(['error' => 'Non autorisé'], 403);

      $messages = json_decode($ticket['messages'], true) ?: [];
      $messages[] = ['from' => $u['role'] === 'admin' ? 'admin' : 'user', 'body' => $body['body'], 'at' => time()*1000];

      $newStatus = $u['role'] === 'admin' ? 'replied' : 'open';
      db()->prepare("UPDATE tickets SET messages=?, status=? WHERE id=?")
          ->execute([json_encode($messages, JSON_UNESCAPED_UNICODE), $newStatus, $id]);

      // Email the other party
      if ($u['role'] === 'admin') {
        $cust = db()->prepare("SELECT email FROM users WHERE id=?");
        $cust->execute([$ticket['user_id']]);
        $custEmail = $cust->fetchColumn();
        if ($custEmail) {
          send_email($custEmail, "Réponse à votre ticket : ".$ticket['subject'], email_template(
            "Nous avons répondu à votre ticket",
            "<p>Réponse du support :</p><blockquote>".nl2br(htmlspecialchars($body['body']))."</blockquote>",
            "Ouvrir le ticket",
            get_site_url()
          ));
        }
      }
      json_response(['ok' => true]);

    case 'ticket-close':
      require_admin();
      db()->prepare("UPDATE tickets SET status='closed' WHERE id=?")->execute([$body['id']]);
      json_response(['ok' => true]);

    // ========== REFUNDS ==========
    case 'refunds':
      $u = require_auth();
      $where = $u['role'] === 'admin' ? "1=1" : "r.user_id=" . db()->quote($u['id']);
      $list = db()->query("SELECT r.*, u.name AS user_name, u.email AS user_email FROM refunds r LEFT JOIN users u ON u.id=r.user_id WHERE {$where} ORDER BY r.created_at DESC")->fetchAll();
      json_response(['refunds' => $list]);

    case 'refund-create':
      $u = require_auth();
      $id = uid('r');
      $sub = db()->prepare("SELECT * FROM subscriptions WHERE id=? AND user_id=?");
      $sub->execute([$body['subId'], $u['id']]);
      $subscription = $sub->fetch();
      if (!$subscription) json_response(['error' => 'Abonnement introuvable'], 404);

      db()->prepare("INSERT INTO refunds (id, user_id, subscription_id, product_name, method, reason, close_date, proof_url) VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$id, $u['id'], $body['subId'], $subscription['product_name'], $body['method'], $body['reason'], date('Y-m-d', ($body['closeDate']??time()*1000)/1000), $body['proofUrl']??'']);
      json_response(['ok' => true, 'id' => $id]);

    case 'refund-process':
      require_admin();
      $id = $body['id'];
      $decision = $body['decision']; // approve | reject
      $adminNote = $body['note'] ?? '';

      $r = db()->prepare("SELECT * FROM refunds WHERE id=?");
      $r->execute([$id]);
      $refund = $r->fetch();
      if (!$refund) json_response(['error' => 'Demande introuvable'], 404);

      $cust = db()->prepare("SELECT * FROM users WHERE id=?");
      $cust->execute([$refund['user_id']]);
      $customer = $cust->fetch();

      if ($decision === 'approve') {
        // Calc refund amount
        $sub = db()->prepare("SELECT * FROM subscriptions WHERE id=?");
        $sub->execute([$refund['subscription_id']]);
        $subData = $sub->fetch();

        $amount = 0;
        if ($subData) {
          $total = (strtotime($subData['end_date']) - strtotime($subData['start_date'])) / 86400;
          $used = max(0, (strtotime($refund['close_date']) - strtotime($subData['start_date'])) / 86400);
          $remaining = max(0, $total - $used);
          $amount = round(($subData['amount_paid'] * $remaining / $total), 2);
        }

        if ($refund['method'] === 'points') {
          $pointValue = (float)setting('pointValue', 0.05);
          $points = round($amount / $pointValue);
          db()->prepare("UPDATE users SET points=points+? WHERE id=?")->execute([$points, $refund['user_id']]);
          $notifBody = "{$points} points (d’une valeur de {$amount} DT) ont été ajoutés à votre solde";
        } else if ($refund['method'] === 'payment') {
          $notifBody = "Le montant de {$amount} DT vous sera versé sous 48 heures";
        } else {
          $notifBody = "Votre abonnement sera remplacé. Notre équipe vous contactera (valeur : {$amount} DT)";
        }

        if ($subData) {
          db()->prepare("UPDATE subscriptions SET status='refunded' WHERE id=?")->execute([$refund['subscription_id']]);
        }
        db()->prepare("UPDATE refunds SET status='approved', admin_note=?, refund_amount=?, processed_at=NOW() WHERE id=?")->execute([$adminNote, $amount, $id]);

        db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?,?,?,?,'refund-approved','auto')")
            ->execute([uid('n'), $refund['user_id'], "Remboursement accepté ✅", $notifBody]);

        send_email($customer['email'], "Votre demande de remboursement a été acceptée", email_template(
          "Votre demande de remboursement a été acceptée",
          "<p>{$notifBody}</p>".($adminNote?"<p>Remarque : ".htmlspecialchars($adminNote)."</p>":"")
        ));
      } else {
        db()->prepare("UPDATE refunds SET status='rejected', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$adminNote, $id]);
        db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type) VALUES (?,?,?,?,'refund-rejected','auto')")
            ->execute([uid('n'), $refund['user_id'], "Demande de remboursement refusée", $adminNote ?: 'Contactez le support pour plus d’informations']);
        send_email($customer['email'], "Demande de remboursement refusée", email_template("Demande de remboursement refusée", "<p>".htmlspecialchars($adminNote ?: 'Veuillez contacter le support')."</p>"));
      }
      json_response(['ok' => true]);

    // ========== NOTIFICATIONS ==========
    case 'notifications':
      $u = require_auth();
      $where = $u['role'] === 'admin' ? "1=1" : "user_id=" . db()->quote($u['id']);
      $list = db()->query("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT 200")->fetchAll();
      json_response(['notifications' => $list]);

    case 'notifications-mark-read':
      $u = require_auth();
      db()->prepare("UPDATE notifications SET read_status=1 WHERE user_id=?")->execute([$u['id']]);
      json_response(['ok' => true]);

    case 'notification-send':
      require_admin();
      $title = $body['title'] ?? '';
      $message = $body['body'] ?? '';
      $target = $body['target'] ?? 'all'; // all | userId
      $sendEmail = !empty($body['sendEmail']);

      if ($target === 'all') {
        $users = db()->query("SELECT id, email FROM users WHERE role='customer'")->fetchAll();
      } else {
        $u = db()->prepare("SELECT id, email FROM users WHERE id=?");
        $u->execute([$target]);
        $users = [$u->fetch()];
      }

      $sent = 0;
      foreach ($users as $usr) {
        if (!$usr) continue;
        db()->prepare("INSERT INTO notifications (id, user_id, title, body, kind, type, email_sent) VALUES (?,?,?,?,'manual','broadcast',?)")
            ->execute([uid('n'), $usr['id'], $title, $message, $sendEmail?1:0]);
        if ($sendEmail) send_email($usr['email'], $title, email_template($title, "<p>".nl2br(htmlspecialchars($message))."</p>", "Ouvrir mon compte", get_site_url()));
        $sent++;
      }
      json_response(['ok' => true, 'sent' => $sent]);

    // ========== USERS (ADMIN) ==========
    case 'users':
      require_admin();
      $list = db()->query("SELECT id, email, whatsapp, name, role, points, referred_by, created_at FROM users ORDER BY created_at DESC")->fetchAll();
      json_response(['users' => $list]);

    case 'user-update':
      require_admin();
      $id = $body['id'];
      $fields = []; $params = [];
      foreach (['email','whatsapp','name','points','role'] as $f) {
        if (isset($body[$f])) { $fields[] = "$f=?"; $params[] = $body[$f]; }
      }
      if (!empty($body['password'])) { $fields[] = "password_hash=?"; $params[] = password_hash($body['password'], PASSWORD_BCRYPT); }
      if (empty($fields)) json_response(['error' => 'Aucune modification'], 400);
      $params[] = $id;
      db()->prepare("UPDATE users SET ".implode(',', $fields)." WHERE id=?")->execute($params);
      json_response(['ok' => true]);

    case 'user-delete':
      require_admin();
      db()->prepare("DELETE FROM users WHERE id=? AND role!='admin'")->execute([$body['id']]);
      json_response(['ok' => true]);

    case 'user-create':
      require_admin();
      $email = trim($body['email'] ?? '');
      $name  = trim($body['name'] ?? '');
      $wa    = trim($body['whatsapp'] ?? '');
      $pw    = $body['password'] ?? '';
      $points = intval($body['points'] ?? 0);
      if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error'=>'E-mail invalide'], 400);
      if (strlen($pw) < 6) json_response(['error'=>'Mot de passe trop court'], 400);
      $exists = db()->prepare("SELECT id FROM users WHERE email=?"); $exists->execute([$email]);
      if ($exists->fetch()) json_response(['error'=>'E-mail déjà utilisé'], 400);
      $hash = password_hash($pw, PASSWORD_BCRYPT);
      db()->prepare("INSERT INTO users (email, password_hash, name, whatsapp, points, role, created_at) VALUES (?,?,?,?,?, 'user', NOW())")
          ->execute([$email, $hash, $name, $wa, $points]);
      json_response(['ok' => true, 'id' => db()->lastInsertId()]);

    // ========== SETTINGS ==========
    case 'settings':
      $u = current_user();
      $rows = db()->query("SELECT key_name, value FROM settings")->fetchAll();
      $out = [];
      foreach ($rows as $r) $out[$r['key_name']] = json_decode($r['value'], true);
      json_response(['settings' => $out]);

    case 'settings-save':
      require_admin();
      foreach ($body as $k => $v) {
        if ($k === 'action') continue;
        save_setting($k, $v);
      }
      json_response(['ok' => true]);

    // ========== STATS ==========
    case 'stats':
      require_admin();
      $today = date('Y-m-d');
      $monthAgo = date('Y-m-d', strtotime('-30 days'));

      // Calculate costs from orders joined with products cost_price
      $total_cost_q = db()->query("
        SELECT COALESCE(SUM(p.cost_price * o.qty), 0) 
        FROM orders o 
        LEFT JOIN products p ON o.product_id = p.id 
        WHERE o.status='active'
      ");
      $total_cost = (float)$total_cost_q->fetchColumn();

      $month_cost_q = db()->query("
        SELECT COALESCE(SUM(p.cost_price * o.qty), 0) 
        FROM orders o 
        LEFT JOIN products p ON o.product_id = p.id 
        WHERE o.status='active' AND o.activated_at >= '{$monthAgo}'
      ");
      $month_cost = (float)$month_cost_q->fetchColumn();

      $today_cost_q = db()->query("
        SELECT COALESCE(SUM(p.cost_price * o.qty), 0) 
        FROM orders o 
        LEFT JOIN products p ON o.product_id = p.id 
        WHERE o.status='active' AND DATE(o.activated_at)='{$today}'
      ");
      $today_cost = (float)$today_cost_q->fetchColumn();

      $total_revenue_val = (float)(db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='active'")->fetchColumn());
      $month_revenue_val = (float)(db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='active' AND activated_at >= '{$monthAgo}'")->fetchColumn());
      $today_revenue_val = (float)(db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='active' AND DATE(activated_at)='{$today}'")->fetchColumn());

      $stats = [
        'today_revenue'   => $today_revenue_val,
        'month_revenue'   => $month_revenue_val,
        'total_revenue'   => $total_revenue_val,
        'today_cost'      => $today_cost,
        'month_cost'      => $month_cost,
        'total_cost'      => $total_cost,
        'today_profit'    => $today_revenue_val - $today_cost,
        'month_profit'    => $month_revenue_val - $month_cost,
        'total_profit'    => $total_revenue_val - $total_cost,
        'customers_count' => (int)(db()->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn()),
        'pending_orders'  => (int)(db()->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn()),
        'open_tickets'    => (int)(db()->query("SELECT COUNT(*) FROM tickets WHERE status!='closed'")->fetchColumn()),
        'pending_refunds' => (int)(db()->query("SELECT COUNT(*) FROM refunds WHERE status='pending'")->fetchColumn()),
      ];

      // 7-day revenue + profit
      $chart = [];
      for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $r = (float)(db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='active' AND DATE(activated_at)='{$d}'")->fetchColumn());
        $c = (float)(db()->query("SELECT COALESCE(SUM(p.cost_price*o.qty),0) FROM orders o LEFT JOIN products p ON o.product_id=p.id WHERE o.status='active' AND DATE(o.activated_at)='{$d}'")->fetchColumn());
        $chart[] = ['date' => $d, 'revenue' => $r, 'cost' => $c, 'profit' => $r - $c];
      }
      $stats['chart_7d'] = $chart;

      json_response(['stats' => $stats]);

    // ========== FILE SERVE (fallback for hosts that block /uploads/) ==========
    case 'file':
      $name = basename($_GET['name'] ?? '');
      if (!$name || !preg_match('/^[A-Za-z0-9_\-\.]+$/', $name)) { http_response_code(400); exit('bad name'); }
      $path = __DIR__ . '/uploads/' . $name;
      if (!is_file($path)) { http_response_code(404); exit('not found'); }
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
      header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
      header('Cache-Control: public, max-age=2592000');
      header('Content-Length: ' . filesize($path));
      readfile($path);
      exit;

    // ========== UPLOAD ==========
    case 'upload':
      require_auth();
      if (empty($_FILES['file'])) json_response(['error' => 'Aucun fichier'], 400);
      $f = $_FILES['file'];
      if ($f['error'] !== UPLOAD_ERR_OK) json_response(['error' => 'Erreur de téléversement : ' . $f['error']], 400);
      if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) json_response(['error' => 'Le fichier dépasse ' . MAX_UPLOAD_MB . 'MB'], 400);

      // Detect MIME
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = finfo_file($finfo, $f['tmp_name']);
      finfo_close($finfo);
      $allowed_mime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/jpg'=>'jpg'];
      if (!isset($allowed_mime[$mime])) json_response(['error' => 'Type de fichier non pris en charge : '.$mime], 400);

      $ext = $allowed_mime[$mime];
      $name = uid('img') . '.' . $ext;
      $upload_dir = __DIR__ . '/uploads/';

      if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
      if (!is_writable($upload_dir)) json_response(['error' => 'Le dossier uploads n’est pas accessible en écriture'], 500);

      $dest = $upload_dir . $name;
      if (!move_uploaded_file($f['tmp_name'], $dest)) {
        json_response(['error' => 'Échec du déplacement du fichier'], 500);
      }

      // Build absolute URL — most reliable method
      $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
      }
      $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
      // Get base directory from SCRIPT_NAME
      $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api.php'), '/\\');
      // Full URL to uploads folder
      $full_url = $proto . '://' . $host . $script_dir . '/uploads/' . $name;

      // Serve URL via api.php — works even if /uploads/ is blocked by host config
      $serve_url = $proto . '://' . $host . ($script_dir ? $script_dir : '') . '/api.php?action=file&name=' . urlencode($name);
      json_response(['url' => $serve_url, 'full_url' => $serve_url, 'direct_url' => $full_url, 'rel' => 'uploads/' . $name, 'name' => $name]);



    case 'ai-chat':
      $b = read_body();
      $userMsg = trim($b['message'] ?? '');
      $history = $b['history'] ?? [];
      if (!$userMsg) json_response(['error' => 'Message vide'], 400);

      $products = db()->query("SELECT name, category, durations FROM products WHERE stock=1")->fetchAll();
      $stgs = db()->query("SELECT key_name, value FROM settings")->fetchAll();
      $smap = [];
      foreach ($stgs as $row) $smap[$row['key_name']] = json_decode($row['value'], true);

      $brand     = $smap['brand']     ?? 'DigiNest';
      $whatsapp  = $smap['whatsapp']  ?? '';
      $openaiKey = $smap['openaiKey'] ?? '';

      $product_list = '';
      foreach ($products as $p) {
        $durs  = json_decode($p['durations'] ?? '[]', true);
        $min_p = $durs ? min(array_column($durs, 'p')) : 0;
        $product_list .= "{$p['name']} ({$p['category']}) : à partir de {$min_p} DT\n";
      }

      $system = "Tu es l’assistant support intelligent de {$brand}, une plateforme tunisienne de vente d’abonnements numériques.\nRéponds en français, de façon amicale et professionnelle (si le client écrit en arabe ou en derja, réponds dans sa langue).\nLes prix sont en dinars tunisiens (DT).\nProduits :\n{$product_list}\nMoyens de paiement : virement bancaire, D17, recharge mobile.\nWhatsApp : {$whatsapp}\nActivation entre 15 minutes et 1 heure.\nRéponds en 2 à 3 phrases.";

      // Try OpenAI if key exists
      if (!empty($openaiKey)) {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $h) {
          $messages[] = ['role' => ($h['role'] === 'assistant' ? 'assistant' : 'user'), 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
          CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openaiKey],
          CURLOPT_POSTFIELDS => json_encode(['model' => 'gpt-4o-mini', 'max_tokens' => 300, 'temperature' => 0.7, 'messages' => $messages]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
          $data = json_decode($resp, true);
          $reply = $data['choices'][0]['message']['content'] ?? '';
          if ($reply) json_response(['reply' => $reply, 'model' => 'gpt-4o-mini']);
        }
      }

      // Smart keyword fallback
      $lower = mb_strtolower($userMsg);
      if (str_contains($lower, 'prix') || str_contains($lower, 'combien') || str_contains($lower, 'tarif')) {
        $reply = "Nos produits commencent à 25 DT. Choisissez dans le catalogue pour voir le prix complet 😊";
      } elseif (str_contains($lower, 'paie') || str_contains($lower, 'd17') || str_contains($lower, 'virement')) {
        $reply = "Nous acceptons : virement bancaire, D17 et recharge mobile. Téléversez le reçu, activation en 15 minutes ✅";
      } elseif (str_contains($lower, 'activ') || str_contains($lower, 'quand') || str_contains($lower, 'délai')) {
        $reply = "L’activation se fait entre 15 minutes et 1 heure après confirmation du paiement ⚡";
      } elseif (str_contains($lower, 'whatsapp') || str_contains($lower, 'contact')) {
        $reply = !empty($whatsapp) ? "Contactez-nous : {$whatsapp} 💬" : "Cliquez sur le bouton WhatsApp du site 💬";
      } elseif (str_contains($lower, 'rembours') || str_contains($lower, 'refund')) {
        $reply = "Vous pouvez demander un remboursement directement depuis votre espace. Le système calcule automatiquement le montant restant 🔄";
      } elseif (str_contains($lower, 'bonjour') || str_contains($lower, 'salut') || str_contains($lower, 'salam')) {
        $reply = "Bienvenue sur {$brand} ! 👋 Nous proposons ChatGPT, Canva, Adobe et plus, à prix tunisiens. Comment pouvons-nous vous aider ?";
      } elseif (str_contains($lower, 'chatgpt') || str_contains($lower, 'gpt')) {
        $reply = "ChatGPT Business est disponible ! Un calculateur de prix selon le jour de début est à votre disposition 🤖";
      } elseif (str_contains($lower, 'point') || str_contains($lower, 'réduc')) {
        $reply = "Vous gagnez des points à chaque achat et à chaque ami parrainé. Utilisez-les comme réduction sur votre prochain achat ⭐";
      } else {
        $reply = "Merci ! Pour une aide immédiate, contactez-nous sur WhatsApp ou parcourez le catalogue 😊";
      }
      json_response(['reply' => $reply, 'model' => 'fallback']);

    case 'ai-article':
      require_auth();
      $b = read_body();
      $prompt   = trim($b['prompt'] ?? '');
      $maxTok   = min((int)($b['maxTokens'] ?? 1000), 2000);
      if (!$prompt) json_response(['error' => 'Sujet vide'], 400);

      $stgs = db()->query("SELECT key_name, value FROM settings")->fetchAll();
      $smap = [];
      foreach ($stgs as $row) $smap[$row['key_name']] = json_decode($row['value'], true);
      $openaiKey = $smap['openaiKey'] ?? '';

      if (empty($openaiKey)) {
        json_response(['error' => 'Aucune clé API OpenAI configurée — allez dans Paramètres → Rédacteur IA'], 400);
      }

      $ch = curl_init('https://api.openai.com/v1/chat/completions');
      curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
          'Content-Type: application/json',
          'Authorization: Bearer ' . $openaiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
          'model'      => 'gpt-4o-mini',
          'max_tokens' => $maxTok,
          'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
      ]);
      $resp = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      curl_close($ch);

      if ($err) json_response(['error' => 'Erreur de connexion : ' . $err], 500);
      $data = json_decode($resp, true);
      if ($code !== 200) {
        $msg = $data['error']['message'] ?? 'Erreur OpenAI (code ' . $code . ')';
        json_response(['error' => $msg], 400);
      }
      $text = $data['choices'][0]['message']['content'] ?? '';
      json_response(['text' => $text]);

    case 'ai-rewrite':
      require_auth();
      $b = read_body();
      $text = trim($b['text'] ?? '');
      $kind = trim($b['kind'] ?? 'general'); // 'question' | 'answer' | 'general'
      if (!$text) json_response(['error' => 'Texte vide'], 400);

      $stgs = db()->query("SELECT key_name, value FROM settings")->fetchAll();
      $smap = [];
      foreach ($stgs as $row) $smap[$row['key_name']] = json_decode($row['value'], true);
      $openaiKey = $smap['openaiKey'] ?? '';
      if (empty($openaiKey)) {
        json_response(['error' => 'Aucune clé API OpenAI configurée — allez dans Paramètres → Rédacteur IA'], 400);
      }

      if ($kind === 'question') {
        $sys = "Tu es un rédacteur francophone professionnel. Reformule la question suivante de façon claire, structurée et concise, adaptée à la FAQ d’un site tunisien. Garde le même sens. Renvoie uniquement la question finale, sans introduction ni guillemets.";
      } elseif ($kind === 'answer') {
        $sys = "Tu es un rédacteur francophone professionnel. Reformule la réponse suivante sur un ton amical, structuré et clair, adapté à la FAQ d’un site tunisien. Garde le même sens et les mêmes informations. Renvoie uniquement la réponse finale, sans introduction ni guillemets.";
      } else {
        $sys = "Tu es un rédacteur francophone professionnel. Reformule le texte suivant de façon structurée, claire et améliorée. Garde le même sens. Renvoie uniquement le texte final, sans introduction.";
      }

      $ch = curl_init('https://api.openai.com/v1/chat/completions');
      curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
          'Content-Type: application/json',
          'Authorization: Bearer ' . $openaiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
          'model'       => 'gpt-4o-mini',
          'max_tokens'  => 500,
          'temperature' => 0.5,
          'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $text],
          ],
        ]),
      ]);
      $resp = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      curl_close($ch);
      if ($err) json_response(['error' => 'Erreur de connexion : ' . $err], 500);
      $data = json_decode($resp, true);
      if ($code !== 200) {
        $msg = $data['error']['message'] ?? 'Erreur OpenAI (code ' . $code . ')';
        json_response(['error' => $msg], 400);
      }
      $out = trim($data['choices'][0]['message']['content'] ?? '');
      $out = trim($out, "\"'«»“”");
      json_response(['text' => $out]);

    case 'review-create':
      $u = require_auth();
      $b = read_body();
      $id = uid('rev');
      db()->prepare("INSERT INTO reviews (id, user_id, reviewer_name, product_name, rating, review_text, status, created_at) VALUES (?,?,?,?,?,?,'pending',NOW())")
         ->execute([$id, $u['id'], trim($b['name']??''), trim($b['product']??''), (int)($b['rating']??5), trim($b['text']??'')]);
      send_whatsapp('review', "⭐ Nouvel avis en attente de modération\nDe : ".($b['name']??$u['email'])."\nNote : ".((int)($b['rating']??5))."/5\nProduit : ".($b['product']??'-')."\n\"".mb_substr(trim($b['text']??''),0,200)."\"");
      json_response(['ok'=>true, 'id'=>$id]);

    // ========== WHATSAPP TEST (v30) ==========
    case 'whatsapp-test':
      require_admin();
      $b = read_body();
      // Apply pending settings first if provided
      if (!empty($b['settings']) && is_array($b['settings'])) {
        foreach ($b['settings'] as $k=>$v) save_setting($k, $v);
      }
      $r = send_whatsapp(null, "✅ Test de connexion DigiNest — UltraMsg fonctionne correctement !");
      if ($r === false) json_response(['error' => 'Échec de l’envoi — vérifiez l’Instance ID, le Token et le numéro de l’admin, et assurez-vous que la fonction est activée'], 400);
      json_response(['ok' => true, 'response' => $r]);

    case 'reviews-admin':
      require_admin();
      $rows = db()->query("SELECT * FROM reviews ORDER BY created_at DESC")->fetchAll();
      json_response(['reviews'=>$rows]);

    case 'review-update':
      require_admin();
      $b = read_body();
      db()->prepare("UPDATE reviews SET status=? WHERE id=?")->execute([$b['status'], $b['id']]);
      json_response(['ok'=>true]);

    case 'reviews-public':
      $rows = db()->query("SELECT id,reviewer_name,product_name,rating,review_text,created_at FROM reviews WHERE status='approved' ORDER BY created_at DESC LIMIT 20")->fetchAll();
      json_response(['reviews'=>$rows]);

    default:
      json_response(['error' => 'Action inconnue : ' . $action], 404);
  }
} catch (Exception $e) {
  json_response(['error' => 'Erreur serveur', 'detail' => DEBUG_MODE ? $e->getMessage() : ''], 500);
}

function get_site_url() {
  // Check X-Forwarded-Proto for proxies/CDN
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
  } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $proto = 'https';
  } else {
    $proto = 'http';
  }
  $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
  return $proto . '://' . $host;
}

// AI Chat proxy endpoint (prevents CORS issues)
// Add this to the switch statement - handled separately here for clarity
