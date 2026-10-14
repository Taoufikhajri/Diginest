# DigiNest 🪺

Boutique en ligne d’abonnements numériques (ChatGPT, Canva, Adobe…) pour la Tunisie — en français, prix en dinars (DT).

- Vitrine + catalogue, calculateur de prix ChatGPT Business, assistant chat
- Comptes clients : abonnements, commandes, tickets de support, remboursements, points de fidélité et parrainage, avis
- Administration : commandes, produits, clients, abonnements, notifications, e-mails, rédacteur d’articles IA, paramètres
- E-mails automatiques (SMTP), alertes WhatsApp (UltraMsg), rappels d’expiration (tâche Cron)
- Installable comme application (PWA)

**Technique :** `index.html` (React, sans build) + `api.php` (PHP 8 + MySQL). Fonctionne sur n’importe quel hébergement Hostinger.

---

## Structure

```
index.html                  ← le site (interface)
api.php                     ← toutes les routes de l’API
db.php                      ← connexion MySQL + e-mails + WhatsApp
config.php                  ← lit vos réglages privés (ne pas modifier)
config.local.example.php    ← modèle de réglages → à copier en config.local.php SUR LE SERVEUR
install.php                 ← installation (une seule fois)
cron.php                    ← rappels quotidiens d’expiration
manifest.webmanifest, sw.js ← application installable (PWA)
assets/                     ← logo, icônes, image de partage
PHPMailer/                  ← envoi d’e-mails SMTP
uploads/                    ← images envoyées (créé automatiquement)
```

> 🔒 `config.local.php` est ignoré par Git : vos mots de passe ne vont **jamais** sur GitHub.

---

## 1. Mettre le projet sur GitHub

1. Créez un dépôt sur GitHub (ex. `diginest`), **privé** de préférence.
2. Envoyez les fichiers, au choix :
   - **Sans ligne de commande :** sur la page du dépôt → *Add file → Upload files* → glissez **tout le contenu** du dossier (y compris `.htaccess`, `.gitignore` et les dossiers `assets`, `PHPMailer`, `uploads`) → *Commit changes*.
     > Les fichiers commençant par un point peuvent être masqués sur votre ordinateur : activez l’affichage des fichiers cachés avant de glisser.
   - **En ligne de commande :**
     ```bash
     cd diginest
     git init && git add . && git commit -m "DigiNest"
     git branch -M main
     git remote add origin https://github.com/VOTRE-COMPTE/diginest.git
     git push -u origin main
     ```

## 2. Déployer sur Hostinger depuis GitHub

1. **hPanel → Sites web → Gérer → Avancé → Git**.
2. Si le dépôt est privé : copiez la **clé SSH** affichée par Hostinger, puis sur GitHub : *Settings du dépôt → Deploy keys → Add deploy key* et collez-la.
3. Dans Hostinger, renseignez :
   - Dépôt : `git@github.com:VOTRE-COMPTE/diginest.git` (ou l’URL https si le dépôt est public)
   - Branche : `main`
   - Répertoire : laissez vide (= `public_html`)
4. **Créer**, puis **Déployer**.
   > ⚠️ Hostinger exige un `public_html` **vide** pour le premier déploiement. Sauvegardez puis supprimez son contenu actuel si besoin.
5. (Optionnel) Activez le **déploiement automatique** : Hostinger affiche une URL de *webhook* → GitHub : *Settings → Webhooks → Add webhook* → collez l’URL. Chaque `git push` mettra le site à jour.

## 3. Créer la base de données

**hPanel → Bases de données → Gestion MySQL** → créez une base + un utilisateur. Notez :
`nom de la base` (ex. `u123456789_diginest`), `utilisateur`, `mot de passe`.

## 4. Créer `config.local.php` sur le serveur

1. **hPanel → Gestionnaire de fichiers → public_html**
2. Copiez `config.local.example.php` → renommez la copie en **`config.local.php`**
3. Modifiez-la :
   - accès MySQL (étape 3)
   - e-mail d’envoi + mot de passe SMTP (*hPanel → E-mails → créez `noreply@votre-domaine`*)
   - `JWT_SECRET` : une longue chaîne aléatoire (50+ caractères)
   - `DEFAULT_ADMIN_EMAIL` / `DEFAULT_ADMIN_PASS` : votre compte administrateur

Ce fichier n’est jamais écrasé par les déploiements Git.

## 5. Lancer l’installation

Ouvrez : `https://votre-domaine.tn/install.php?key=XXXXXXXXXXXXXXXX`
(`XXXXXXXXXXXXXXXX` = les **16 premiers caractères** de votre `JWT_SECRET`)

Cela crée les tables, le compte administrateur, 12 produits d’exemple et les paramètres par défaut. Un fichier `.installed` bloque ensuite toute réinstallation.

## 6. Tâche Cron (rappels d’expiration)

**hPanel → Avancé → Tâches Cron** → une fois par jour (ex. 9 h) :

```
/usr/bin/php /home/VOTRE_UTILISATEUR/public_html/cron.php
```

Test manuel : `https://votre-domaine.tn/cron.php?key=XXXXXXXXXXXXXXXX`

## 7. Personnaliser

Connectez-vous avec le compte admin → **⚙️ Administration → Paramètres** :
nom, logo, numéro WhatsApp, e-mail, textes de la page d’accueil, FAQ, avis, groupes WhatsApp, bandeau d’annonces, valeur des points, **moyens de paiement (remplacez le RIB / numéro D17 d’exemple !)**, notifications WhatsApp (UltraMsg), clé OpenAI pour l’assistant et le rédacteur IA.

Puis **Produits** pour ajuster le catalogue et les prix.

---

## Mettre à jour le site

Modifiez les fichiers → `git push` (ou *Upload files* sur GitHub) → Hostinger redéploie (automatiquement avec le webhook, sinon bouton **Déployer**). Vos réglages (`config.local.php`), images (`uploads/`) et données MySQL sont conservés.

## Dépannage

| Problème | Solution |
|---|---|
| « Connexion à la base de données impossible » | Vérifiez `DB_NAME`, `DB_USER`, `DB_PASS` dans `config.local.php` |
| Les e-mails n’arrivent pas | Vérifiez SMTP (port 465 + `ssl`), et les spams |
| L’admin ne reste pas connecté | Le `.htaccess` doit être présent dans `public_html` |
| Erreur à l’envoi d’image | Le dossier `uploads/` doit être accessible en écriture (755) |
| Voir l’erreur exacte | Mettez temporairement `DEBUG_MODE` à `true` dans `config.local.php` |
