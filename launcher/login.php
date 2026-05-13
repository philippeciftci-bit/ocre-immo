<?php
// M/2026/05/13/26 — Launcher app.ocre.immo : page /login.
// GET = form email+pwd. POST = forward vers /api/sso/login.php (meme host, MEME process).
require_once __DIR__ . '/_lib.php';
launcher_security_headers();

// Si deja loggue, redirect /.
if (launcher_current_user()) {
    header('Location: /');
    exit;
}

$err = null;
$emailPrefill = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pwd = (string)($_POST['password'] ?? '');
    $emailPrefill = $email;

    // Reuse loopback : on appelle l'endpoint SSO via cURL meme host (cookie sera pose dans le User-Agent navigateur via la reponse loopback ? NON :
    // setcookie() emis par /api/sso/login.php arrive dans la reponse cURL et n'est PAS automatiquement re-emis vers le navigateur.
    // Solution : on importe la logique localement via include + on capture/re-emet le cookie.
    // Approche simple : forward via require_once + global $_POST -> on require login.php du SSO directement.
    // /api/sso/login.php attend Content-Type: application/json (file_get_contents('php://input')).
    // Donc on simule en peuplant php://input via stream_wrapper_register OU on POST en interne.
    // Solution propre : appeler la logique en duplication minimale via une fonction sso_lib_login_internal.
    // PRAGMATIQUE : on fait la verification ici (DB + bcrypt + rate-limit + sso_set_cookie) — duplique 20 lignes pour avoir le cookie pose dans la reponse browser.

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    try {
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Rate-limit 5/min/IP.
        try {
            $rl = $meta->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
            $rl->execute([$ip]);
            if ((int)($rl->fetch()['c'] ?? 0) >= 5) {
                $err = 'Trop de tentatives. Réessaie dans une minute.';
                throw new RuntimeException('rate_limited');
            }
        } catch (PDOException $e) { /* table missing : ignore */ }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$pwd) {
            $err = 'Email ou mot de passe manquant.';
        } else {
            $st = $meta->prepare("SELECT id, email, password_hash, status, prenom, nom, slug, archived_at, anonymized_at FROM users WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch();

            $valid = $u && empty($u['archived_at']) && empty($u['anonymized_at']) && password_verify($pwd, (string)$u['password_hash']);

            try {
                $meta->prepare("INSERT INTO login_attempts (ip_address, email, success, reason) VALUES (?,?,?,?)")
                    ->execute([$ip, $email, $valid ? 1 : 0, $valid ? 'launcher_ok' : 'invalid_credentials']);
            } catch (PDOException $e) {}

            if (!$valid) {
                $err = 'Identifiants invalides.';
            } elseif (($u['status'] ?? '') === 'pending_activation') {
                $err = 'Compte non activé. Consulte ton email pour le lien d\'activation.';
            } elseif (($u['status'] ?? '') === 'suspended') {
                $err = 'Compte suspendu. Contacte le support.';
            } else {
                $uid = (int)$u['id'];

                // Lazy populate user_tenants.
                $existsSt = $meta->prepare("SELECT COUNT(*) c FROM user_tenants WHERE user_id = ?");
                $existsSt->execute([$uid]);
                if ((int)($existsSt->fetch()['c'] ?? 0) === 0) {
                    $ins = $meta->prepare("INSERT IGNORE INTO user_tenants (user_id, tenant_slug, role) VALUES (?,?,?)");
                    if (!empty($u['slug'])) $ins->execute([$uid, (string)$u['slug'], 'owner']);
                }

                $tSt = $meta->prepare("SELECT tenant_slug FROM user_tenants WHERE user_id = ? ORDER BY tenant_slug");
                $tSt->execute([$uid]);
                $tenants = array_column($tSt->fetchAll(), 'tenant_slug');
                $currentTenant = $tenants[0] ?? ($u['slug'] ?? null);

                $sessionToken = bin2hex(random_bytes(32));
                $meta->prepare(
                    "INSERT INTO sso_sessions (session_token, user_id, ip_address, user_agent, expires_at, last_seen_at)
                     VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())"
                )->execute([$sessionToken, $uid, $ip, $ua]);

                sso_set_cookie([
                    'session_token' => $sessionToken,
                    'user_id' => $uid,
                    'email' => $u['email'],
                    'tenants' => $tenants,
                    'current_tenant' => $currentTenant,
                    'iat' => time(),
                ], 7 * 86400);

                try { $meta->prepare("UPDATE users SET last_login_at = NOW(), last_login = NOW() WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}

                header('Location: /');
                exit;
            }
        }
    } catch (RuntimeException $e) {
        // Rate-limit deja signale.
    } catch (Throwable $e) {
        $err = 'Erreur serveur. Réessaie dans un instant.';
    }
}

echo launcher_render_head('Connexion');
?>
<main class="launcher-root launcher-auth">
  <div class="launcher-card-auth">
    <div class="launcher-brand launcher-brand-auth">
      <span class="launcher-brand-wordmark">Ocre</span>
    </div>
    <h1 class="launcher-auth-title">Connexion</h1>
    <p class="launcher-auth-sub">Accède à toutes tes apps Oi.</p>

    <?php if ($err): ?>
      <div class="launcher-error" role="alert"><?= launcher_h($err) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="on" novalidate>
      <label class="launcher-field">
        <span class="launcher-field-label">Email</span>
        <input type="email" name="email" required autocomplete="email" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="email" value="<?= launcher_h($emailPrefill) ?>" autofocus>
      </label>
      <label class="launcher-field">
        <span class="launcher-field-label">Mot de passe</span>
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit" class="launcher-submit">Se connecter</button>
    </form>
  </div>
</main>
<?= launcher_render_foot() ?>
