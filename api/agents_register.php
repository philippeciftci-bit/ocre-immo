<?php
// M/2026/05/06/83.2.1 — Endpoint inscription publique agent.
// Cible : ocre_meta.users (M83.1 superseded, plus de table agents).
// POST JSON {prenom, nom, email, password, siret, agence, ville, cp, carte_pro, tel, whatsapp, sensibility_preset, channels_enabled}
// Retours :
//   201 {ok:true, user_id, redirect}      (insertion neuve)
//   200 {ok:true, user_id, redirect, resent:true}  (idempotence pending_activation -> regen token)
//   422 {ok:false, errors:{champ:msg}}
//   409 {ok:false, error:"Email deja utilise"}     (status='active' ou autre)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/email_sender.php';
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Methode non autorisee', 405);
}

$input = getInput();

// M/2026/05/08/53 — DOUBLE VERROU EN HEAD : refus immediat si cgu_accepted ou rgpd_accepted manquants/false.
// Garde-fou anti-bypass frontend, anti-attaque, anti-bug. AUCUN INSERT, AUCUN MAIL avant ce check.
$_cguHead = filter_var($input['cgu_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
$_rgpdHead = filter_var($input['rgpd_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$_cguHead || !$_rgpdHead) {
    @file_put_contents('/var/log/ocre-signup-errors.log',
        '[' . date('c') . '] WARN agents_register POST sans CGU/RGPD valides : ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?')
        . ' email=' . ($input['email'] ?? '?') . ' cgu=' . var_export($_cguHead, true) . ' rgpd=' . var_export($_rgpdHead, true)
        . ' ua=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100) . "\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'CGU_RGPD_REQUIRED',
        'detail' => 'Acceptation CGU et RGPD obligatoires avant toute action serveur.',
    ]);
    exit;
}

$errors = [];

function _trim_str($v, $max = 255) {
    if ($v === null) return '';
    $s = trim((string)$v);
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s;
}
function _validate_siret($siret) {
    $cleaned = preg_replace('/\D/', '', (string)$siret);
    if (strlen($cleaned) !== 14) return false;
    $sum = 0;
    for ($i = 0; $i < 14; $i++) {
        $d = (int) $cleaned[$i];
        if ($i % 2 === 0) {
            $d *= 2;
            if ($d > 9) $d -= 9;
        }
        $sum += $d;
    }
    return $sum % 10 === 0;
}
function _send_activation_email(string $email, string $prenom, string $token, string $slug = ''): bool {
    // M/2026/05/08/57 — magic link direct vers <slug>.ocre.immo/?activate=<token>.
    // Suppression /set-password : le password est défini à l'inscription, le lien active + auto-login.
    if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug)) {
        $url = 'https://' . $slug . '.ocre.immo/?activate=' . $token;
    } else {
        $url = 'https://app.ocre.immo/?activate=' . $token;
    }
    $subject = 'Bienvenue sur Oi Agent — accédez à votre espace';
    $safePrenom = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $html = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#3a2e22;background:#FAF6EC;margin:0;padding:0;">'
        . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(60,40,20,0.08);">'
        . '<h1 style="font-family:\'Cormorant Garamond\',Georgia,serif;font-style:italic;color:#8B5E3C;font-weight:500;margin:0 0 12px;font-size:28px;">Bienvenue sur Oi Agent</h1>'
        . '<p style="font-size:15px;line-height:1.5;">Bonjour <b>' . $safePrenom . '</b>,</p>'
        . '<p style="font-size:15px;line-height:1.5;">Votre compte est créé. Cliquez sur le bouton ci-dessous pour accéder directement à votre espace de travail.</p>'
        . '<p style="font-size:13px;color:#6B5E4A;line-height:1.5;font-style:italic;">Lien valide 48 heures.</p>'
        . '<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center" style="margin:28px auto;">'
        . '<tr><td bgcolor="#10B981" style="border-radius:10px;background-color:#10B981;mso-padding-alt:14px 24px;">'
        . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:14px 24px;font-family:\'DM Sans\',-apple-system,BlinkMacSystemFont,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;border:1px solid #10B981;line-height:1.2;">Accéder à mon espace</a>'
        . '</td></tr></table>'
        . '<p style="font-size:12px;color:#999;line-height:1.5;">Si le bouton ne fonctionne pas, copiez-collez ce lien :<br><span style="word-break:break-all;">' . $url . '</span></p>'
        . '<p style="font-size:11px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:16px;">Oi Agent — un produit Ocre · contact@ocre.immo</p>'
        . '</div></body></html>';
    if (function_exists('ocre_send_email')) {
        return ocre_send_email($email, $subject, $html);
    }
    return false;
}

// M/2026/05/08/30 — Validation préventive délivrabilité email.
// Retourne ['mx' => bool, 'disposable' => bool, 'typo_suggestion' => ?string].
function _email_predelivery_checks(string $email): array {
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    // 1. DNS MX
    $hasMx = false;
    if ($domain) {
        @$hasMx = checkdnsrr($domain, 'MX');
    }
    // 2. Pattern temp-mail
    static $disposable = [
        'mailinator.com', 'tempmail.com', '10minutemail.com', 'guerrillamail.com',
        'sharklasers.com', 'trashmail.com', 'yopmail.com', 'temp-mail.org',
        'throwaway.email', 'maildrop.cc', 'fakeinbox.com', 'getnada.com',
    ];
    $isDisposable = in_array($domain, $disposable, true);
    // 3. Typo classique
    static $typos = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gnail.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.fr' => 'gmail.com',
        'yaho.fr' => 'yahoo.fr', 'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com',
        'hotmial.com' => 'hotmail.com', 'hotnail.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com',
        'oultook.com' => 'outlook.com', 'outloo.com' => 'outlook.com',
        'orage.fr' => 'orange.fr',
    ];
    $typoSuggestion = null;
    if (isset($typos[$domain])) {
        $local = substr($email, 0, strrpos($email, '@'));
        $typoSuggestion = $local . '@' . $typos[$domain];
    }
    return [
        'mx' => (bool)$hasMx,
        'disposable' => $isDisposable,
        'typo_suggestion' => $typoSuggestion,
    ];
}

// M/2026/05/08/30 — Alerte super-admin enrichie : architecture résiliente
// (réinscription pending, attempts > seuil, etc.). Niveau orange (1-3 attempts) ou
// rouge (>3 attempts ou >24h sans confirmation). Format Telegram standardise.
function _alert_signup_resilience(string $level, array $payload): void {
    // $level = 'orange' | 'red'
    $logFile = '/var/log/ocre-activation-attempts.log';
    @file_put_contents($logFile, "[" . date('c') . "] " . $level . " " . json_encode($payload) . "\n", FILE_APPEND);

    $emoji = ($level === 'red') ? '🚨' : '⚠️';
    $priority = ($level === 'red') ? 'high' : 'warning';
    $titleSuffix = ($level === 'red') ? 'Agent BLOQUÉ activation' : 'Réinscription pending détectée';
    $title = '[OCRE] ' . $titleSuffix;

    $lines = [];
    $lines[] = $emoji . ' ' . $titleSuffix;
    $lines[] = 'Agent: ' . ($payload['prenom'] ?? '?') . ' ' . ($payload['nom'] ?? '?') . ' (' . ($payload['email'] ?? '?') . ')';
    if (!empty($payload['agence'])) $lines[] = 'Agence: ' . $payload['agence'];
    $lines[] = 'Tentatives: ' . ((int)($payload['attempts'] ?? 0));
    if (!empty($payload['first_attempt'])) $lines[] = '1ère: ' . $payload['first_attempt'];
    if (!empty($payload['last_attempt']))  $lines[] = 'Dernière: ' . $payload['last_attempt'];
    if (!empty($payload['provider']))       $lines[] = 'Provider précédent: ' . $payload['provider'] . ' | Statut: ' . ($payload['status'] ?? 'inconnu');
    if (!empty($payload['diagnostic']))     $lines[] = 'Diagnostic: ' . $payload['diagnostic'];
    $lines[] = 'Action: https://app.ocre.immo/superadmin/#pending-activations';

    $body = implode("\n", $lines);
    @shell_exec(
        '/root/bin/notify --project ocre --priority ' . escapeshellarg($priority) . ' --phase error '
        . '--mission-id ' . escapeshellarg('SIGNUP-RESILIENCE/' . time())
        . ' --title ' . escapeshellarg($title)
        . ' --body ' . escapeshellarg($body)
        . ' >/dev/null 2>&1 &'
    );
}

// M/2026/05/08/27 — Alerting super-admin si echec email confirmation signup.
// Canaux : log persistant + Telegram (notify --phase error) + email Philippe best-effort.
// PWA push super-admin : TODO M+1 (pas encore implemente).
function _alert_email_failure(int $userId, string $email, string $prenom, string $errorMsg): void {
    $ts = date('c');
    $logLine = "[$ts] user_id=$userId email=$email prenom=$prenom error=" . preg_replace('/\s+/', ' ', $errorMsg) . "\n";
    @error_log($logLine, 3, '/var/log/ocre-signup-errors.log');

    $title = 'Email confirmation signup ECHEC';
    $body = "Agent $prenom ($email) inscrit (user_id=$userId) mais email d'activation NON ENVOYE. Erreur: $errorMsg. Action requise : envoi manuel ou diagnostic SMTP.";
    @shell_exec(
        '/root/bin/notify --project ocre --priority high --phase error '
        . '--mission-id ' . escapeshellarg('SIGNUP-ERROR/' . time())
        . ' --title ' . escapeshellarg($title)
        . ' --body ' . escapeshellarg($body)
        . ' >/dev/null 2>&1 &'
    );

    // Email super-admin best-effort (si SMTP cassé global, ce mail échouera aussi — Telegram passe).
    $adminEmail = 'philippe.ciftci@gmail.com';
    $adminSubject = '[Oi Agent] ECHEC email confirmation signup';
    $adminBody = "<p>Inscription enregistree mais email d'activation NON ENVOYE.</p>"
        . '<ul><li>user_id: ' . $userId . '</li>'
        . '<li>email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>prenom: ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>error: ' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '</li></ul>'
        . "<p>Action : renvoyer manuellement l'email d'activation ou diagnostiquer SMTP.</p>";
    if (function_exists('ocre_send_email')) {
        @ocre_send_email($adminEmail, $adminSubject, $adminBody);
    }
    // TODO M+1 : PWA push super-admin si la PWA admin est connectee (best-effort, pas bloquant).
}

function _meta_pdo() {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$prenom = _trim_str($input['prenom'] ?? '', 100);
$nom    = _trim_str($input['nom'] ?? '', 100);
$email  = strtolower(_trim_str($input['email'] ?? '', 190));
// M/2026/05/08/57 — password obligatoire à l'inscription (refonte magic link, suppression set-password).
$pwd    = (string)($input['password'] ?? '');
$siretRaw = preg_replace('/\D/', '', (string)($input['siret'] ?? ''));
$agence = _trim_str($input['agence'] ?? '', 150);
$ville  = _trim_str($input['ville'] ?? '', 100);
$cp     = _trim_str($input['cp'] ?? '', 10);
$cartePro = _trim_str($input['carte_pro'] ?? '', 50);
$tel    = _trim_str($input['tel'] ?? '', 30);
$whatsapp = _trim_str($input['whatsapp'] ?? '', 30);
$sensibility = (string)($input['sensibility_preset'] ?? 'equilibre');
$channels = is_array($input['channels_enabled'] ?? null) ? $input['channels_enabled'] : ['email' => true, 'whatsapp' => false];

if ($prenom === '') $errors['prenom'] = 'Prenom requis';
if ($nom === '')    $errors['nom']    = 'Nom requis';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email invalide';
// M/2026/05/08/57 — validation password : 8+ chars, 1 majuscule, 1 chiffre.
if (strlen($pwd) < 8 || !preg_match('/[A-Z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
    $errors['password'] = 'Mot de passe : 8 caractères minimum, 1 majuscule, 1 chiffre';
}
// M90.3 — SIRET optionnel : valide si vide OU 14 chiffres Luhn-valides.
if ($siretRaw !== '' && !_validate_siret($siretRaw)) $errors['siret'] = 'SIRET invalide (Luhn)';
// M/2026/05/08/57 — ville optionnelle (refonte form compact 9 champs essentiels).
if ($tel === '')   $errors['tel']   = 'Telephone requis';
// M/2026/05/08/54 — validation E.164 stricte : + suivi de 7-15 chiffres (premier non-zéro).
if ($tel !== '' && !preg_match('/^\+[1-9]\d{6,14}$/', $tel)) {
    @file_put_contents('/var/log/ocre-signup-errors.log',
        '[' . date('c') . '] WARN agents_register tel format invalide : ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?')
        . ' email=' . ($input['email'] ?? '?') . ' tel=' . substr($tel, 0, 30) . "\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'PHONE_INVALID', 'detail' => 'Numéro téléphone format E.164 attendu (+ suivi de 7 à 15 chiffres).']);
    exit;
}
if (!in_array($sensibility, ['strict','equilibre','large','tres_large'], true)) $sensibility = 'equilibre';

// M86 — validation backend stricte CGU + RGPD (cf audit M85.1, conformite RGPD art.7 + CNIL SAN-2019-001).
$cguAccepted  = filter_var($input['cgu_accepted']  ?? false, FILTER_VALIDATE_BOOLEAN);
$rgpdAccepted = filter_var($input['rgpd_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$cguAccepted)  $errors['cgu_accepted']  = 'Acceptation des CGU obligatoire';
if (!$rgpdAccepted) $errors['rgpd_accepted'] = 'Acceptation du traitement RGPD obligatoire';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

// M90.3 — SIRET optionnel : si vide, siret + siren = NULL.
$siretSql = $siretRaw !== '' ? $siretRaw : null;
$siren = $siretRaw !== '' ? substr($siretRaw, 0, 9) : null;
// M/2026/05/08/57 — hash bcrypt cost 12 directement à l'inscription (suppression PLACEHOLDER M50).
$pwdHash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
$nomUpper = mb_strtoupper($nom, 'UTF-8');
$displayName = trim($prenom . ' ' . $nomUpper);
$prefsJson = json_encode([
    'channels_enabled' => [
        'telegram' => false,
        'email'    => !empty($channels['email']),
        'whatsapp' => !empty($channels['whatsapp']) && $whatsapp !== '',
    ],
], JSON_UNESCAPED_UNICODE);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$activationToken = bin2hex(random_bytes(32));
$cguVersion = '1.0';
$rgpdVersion = '1.0';

// M/2026/05/07/5 — slug auto-genere depuis agence ou prenom-nom. Slugify [a-z0-9-]
// + suffixe random hex 4 chars pour eviter collision (verif post-INSERT optionnelle, scope strict).
function _slugify(string $base): string {
    $s = mb_strtolower(trim($base), 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 32) : 'agent';
}
$_slugBase = $agence !== '' ? $agence : ($prenom . '-' . $nomUpper);
$autoSlug = _slugify($_slugBase) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);

try {
    $meta = _meta_pdo();

    $chk = $meta->prepare("SELECT id, status FROM users WHERE email = ? AND archived_at IS NULL LIMIT 1");
    $chk->execute([$email]);
    $existing = $chk->fetch();

    if ($existing) {
        if ($existing['status'] === 'pending_activation') {
            // M/2026/05/08/30 — re-inscription pending detectee. UX agent transparent,
            // backend incremente attempts + alerte super-admin enrichie + tente
            // re-envoi (rotation provider geree par send_mail wrapper).
            $upd = $meta->prepare(
                "UPDATE users
                    SET prenom = ?, nom = ?, display_name = ?,
                        password_hash = ?, telephone = ?, whatsapp = ?,
                        siret = ?, siren = ?, pro_card_number = ?,
                        societe = ?, ville = ?, cp = ?, country_code = 'FR',
                        sensibility_preset = ?, preferences = ?,
                        activation_token = ?, activation_token_expires_at = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                        cgu_accepted = 1, cgu_accepted_at = NOW(), cgu_version = ?, cgu_version_accepted = ?,
                        cgu_accepted_ip = ?, cgu_accepted_user_agent = ?,
                        rgpd_accepted = 1, rgpd_accepted_at = NOW(), rgpd_version = ?, rgpd_accepted_ip = ?, rgpd_accepted_user_agent = ?,
                        activation_attempts_count = activation_attempts_count + 1,
                        last_activation_attempt_at = NOW()
                  WHERE id = ?"
            );
            $upd->execute([
                $prenom, $nomUpper, $displayName,
                $pwdHash, $tel, ($whatsapp ?: null),
                $siretSql, $siren, ($cartePro ?: null),
                ($agence ?: null), $ville, ($cp ?: null),
                $sensibility, $prefsJson,
                $activationToken,
                $cguVersion, $cguVersion, $ip, $userAgent,
                $rgpdVersion, $ip, $userAgent,
                (int)$existing['id'],
            ]);
            $userId = (int)$existing['id'];

            // Lecture etat post-update pour decider niveau alerte.
            $info = $meta->prepare("SELECT activation_attempts_count, last_activation_provider, last_activation_status, created_at FROM users WHERE id = ?");
            $info->execute([$userId]);
            $userInfo = $info->fetch();
            $attempts = (int)($userInfo['activation_attempts_count'] ?? 1);
            $level = ($attempts > 3) ? 'red' : 'orange';
            _alert_signup_resilience($level, [
                'prenom' => $prenom, 'nom' => $nomUpper, 'email' => $email, 'agence' => $agence,
                'attempts' => $attempts,
                'first_attempt' => (string)($userInfo['created_at'] ?? ''),
                'last_attempt' => date('c'),
                'provider' => (string)($userInfo['last_activation_provider'] ?? ''),
                'status' => (string)($userInfo['last_activation_status'] ?? ''),
            ]);

            // Re-envoi via wrapper send_mail (OVH SMTP exclusif, M/2026/05/08/31).
            // M/2026/05/08/39 — try/catch Throwable : non-bloquant. Si SMTP plante, user reste créé.
            // M/2026/05/08/57 — passe slug pour magic link direct subdomain.
            $existingSlug = (string)($meta->query("SELECT slug FROM users WHERE id = " . $userId)->fetchColumn() ?: '');
            try {
                $emailSent = _send_activation_email($email, $prenom, $activationToken, $existingSlug);
            } catch (Throwable $e) {
                $emailSent = false;
                @error_log('[agents_register] _send_activation_email exception (pending branch): ' . $e->getMessage());
            }
            $emailError = null;
            // Tracer provider/statut utilise pour stats super-admin.
            $providerUsed = $emailSent ? 'ovh_smtp' : null;
            $statusUsed = $emailSent ? 'SENT_OK' : 'SEND_FAILED';
            @($meta->prepare("UPDATE users SET last_activation_provider = ?, last_activation_status = ? WHERE id = ?")
                ->execute([$providerUsed, $statusUsed, $userId]));
            if (!$emailSent) {
                $emailError = 'Echec envoi email activation (re-tentative pending #' . $attempts . ')';
                _alert_email_failure($userId, $email, $prenom, $emailError);
            }

            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'inscription_ok' => true,
                'user_id' => $userId,
                'resent' => true,
                'attempts' => $attempts,
                'email_sent' => $emailSent,
                'email_error' => $emailError,
                'redirect' => '/inscription/confirmee/?prenom=' . rawurlencode($prenom) . '&email=' . rawurlencode($email) . '&email_sent=' . ($emailSent ? '1' : '0'),
            ]);
            exit;
        }
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Cet email est deja utilise']);
        exit;
    }

    $stmt = $meta->prepare(
        "INSERT INTO users
            (email, password_hash, display_name, prenom, nom,
             role, subscription_status, billing_plan, status,
             telephone, whatsapp, ville, cp, country_code,
             pro_card_number, siret, siren, societe, slug,
             sensibility_preset, preferences,
             activation_token, activation_token_expires_at,
             cgu_accepted, cgu_accepted_at, cgu_version, cgu_version_accepted,
             cgu_accepted_ip, cgu_accepted_user_agent,
             rgpd_accepted, rgpd_accepted_at, rgpd_version, rgpd_accepted_ip, rgpd_accepted_user_agent,
             telegram_notifs_enabled, email_notifs_enabled,
             created_at)
         VALUES (?, ?, ?, ?, ?,
                 'agent', 'trial', 'decouverte', 'pending_activation',
                 ?, ?, ?, ?, 'FR',
                 ?, ?, ?, ?, ?,
                 ?, ?,
                 ?, DATE_ADD(NOW(), INTERVAL 48 HOUR),
                 1, NOW(), ?, ?,
                 ?, ?,
                 1, NOW(), ?, ?, ?,
                 0, ?,
                 NOW())"
    );
    $stmt->execute([
        $email, $pwdHash, $displayName, $prenom, $nomUpper,
        $tel, ($whatsapp ?: null), $ville, ($cp ?: null),
        ($cartePro ?: null), $siretSql, $siren, ($agence ?: null), $autoSlug,
        $sensibility, $prefsJson,
        $activationToken,
        $cguVersion, $cguVersion,
        $ip, $userAgent,
        $rgpdVersion, $ip, $userAgent,
        !empty($channels['email']) ? 1 : 0,
    ]);
    $userId = (int) $meta->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
    exit;
}

$body = $prenom . ' ' . $nomUpper . ' . ' . $email . ' . ' . $ville . ' . SIRET ' . $siretRaw;
@shell_exec('/root/bin/notify --project ocre --priority normal --title ' . escapeshellarg('Nouvelle inscription agent') . ' --body ' . escapeshellarg($body) . ' >/dev/null 2>&1 &');

// M/2026/05/08/30 — Validation préventive délivrabilité email (DNS MX + temp-mail + typo).
$preDelivery = _email_predelivery_checks($email);
if (!$preDelivery['mx'] || $preDelivery['disposable'] || $preDelivery['typo_suggestion']) {
    @file_put_contents('/var/log/ocre-activation-attempts.log', sprintf("[%s] PREDELIVERY_FLAG email=%s mx=%d disposable=%d typo=%s\n",
        date('c'), $email, $preDelivery['mx'] ? 1 : 0, $preDelivery['disposable'] ? 1 : 0, $preDelivery['typo_suggestion'] ?? '-'
    ), FILE_APPEND);
    _alert_signup_resilience('orange', [
        'prenom' => $prenom, 'nom' => $nomUpper, 'email' => $email, 'agence' => $agence,
        'attempts' => 1,
        'diagnostic' => 'Email suspect (' . (!$preDelivery['mx'] ? 'no MX' : '') . (!$preDelivery['mx'] && $preDelivery['disposable'] ? '+' : '') . ($preDelivery['disposable'] ? 'disposable' : '') . ($preDelivery['typo_suggestion'] ? ' typo? -> ' . $preDelivery['typo_suggestion'] : '') . ')',
    ]);
    // On envoie quand meme (best effort, mais super-admin alerte).
}

// M/2026/05/08/57 — magic link direct vers <slug>.ocre.immo/?activate=<token>.
$emailSent = _send_activation_email($email, $prenom, $activationToken, $autoSlug);
$emailError = null;
$providerUsed = $emailSent ? 'ovh_smtp' : null;
$statusUsed = $emailSent ? 'SENT_OK' : 'SEND_FAILED';
@($meta->prepare("UPDATE users SET activation_attempts_count = 1, last_activation_attempt_at = NOW(), last_activation_provider = ?, last_activation_status = ? WHERE id = ?")
    ->execute([$providerUsed, $statusUsed, $userId]));
if (!$emailSent) {
    $emailError = 'Echec envoi email activation';
    _alert_email_failure($userId, $email, $prenom, $emailError);
}

http_response_code(201);
echo json_encode([
    'ok' => true,
    'inscription_ok' => true,
    'user_id' => $userId,
    'attempts' => 1,
    'email_sent' => $emailSent,
    'email_error' => $emailError,
    'predelivery' => $preDelivery,
    'redirect' => '/inscription/confirmee/?prenom=' . rawurlencode($prenom) . '&email=' . rawurlencode($email) . '&email_sent=' . ($emailSent ? '1' : '0'),
]);
