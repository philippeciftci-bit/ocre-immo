<?php
// M/2026/05/07/93 — Helper WhatsApp Cloud API Meta v21.0.
// Envoi de messages templates pre-valides. Mode stub si credentials absents
// (utile dev local + tests E2E sans facturation Meta).
//
// Credentials attendus dans /root/.secrets/whatsapp-meta.env (mode 600) :
//   WHATSAPP_TOKEN=EAAxxxxxxxxx (access token permanent Meta)
//   WHATSAPP_PHONE_ID=1234567890123 (Phone Number ID WABA)
//   WHATSAPP_WEBHOOK_VERIFY_TOKEN=<random hex 32 chars>
//   WHATSAPP_WABA_ID=987654321 (WhatsApp Business Account ID)
//
// Pattern utilisation :
//   require_once '/opt/ocre-app/api/lib/whatsapp_sender.php';
//   $r = ocre_whatsapp_send('+33651325177', 'inscription_confirmee', ['nom' => 'Phil', 'url' => 'https://...']);
//   if ($r['ok']) echo "msg_id=" . $r['provider_message_id'];

if (!function_exists('_ocre_wa_load_creds')) {

function _ocre_wa_load_creds(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $envFile = '/root/.secrets/whatsapp-meta.env';
    $cache = ['token' => '', 'phone_id' => '', 'verify_token' => '', 'waba_id' => ''];
    if (!is_readable($envFile)) return $cache;
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k); $v = trim($v, "\"' \t");
        if ($k === 'WHATSAPP_TOKEN')                $cache['token'] = $v;
        elseif ($k === 'WHATSAPP_PHONE_ID')         $cache['phone_id'] = $v;
        elseif ($k === 'WHATSAPP_WEBHOOK_VERIFY_TOKEN') $cache['verify_token'] = $v;
        elseif ($k === 'WHATSAPP_WABA_ID')          $cache['waba_id'] = $v;
    }
    return $cache;
}

function _ocre_wa_pdo(): PDO {
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $user = defined('DB_USER') ? DB_USER : 'ocre_app';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    return new PDO(
        "mysql:host=$host;dbname=ocre_meta;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function _ocre_wa_log(string $line): void {
    @file_put_contents('/var/log/ocre/whatsapp.log', '[' . date('c') . '] ' . $line . "\n", FILE_APPEND);
}

function _ocre_wa_normalize_phone(string $phone): string {
    $p = preg_replace('/[^\d+]/', '', $phone);
    if ($p === '' || $p[0] === '+') return $p;
    if (strlen($p) === 10 && $p[0] === '0') return '+33' . substr($p, 1);
    return '+' . $p;
}

/**
 * Envoie un message WhatsApp via Meta Cloud API.
 *
 * @param string $phone   Numero E.164 ou format FR (auto-normalise)
 * @param string $template Nom du template Meta (doit etre approuve cote Business Manager)
 * @param array  $params   Parametres positionnels (ordre du template)
 * @param int|null $userId  Optionnel : user_id pour log + tracking
 * @return array {ok:bool, status:string, provider_message_id?:string, error?:string, stub?:bool}
 */
function ocre_whatsapp_send(string $phone, string $template, array $params = [], ?int $userId = null): array {
    $phoneE164 = _ocre_wa_normalize_phone($phone);
    if ($phoneE164 === '' || !preg_match('/^\+\d{8,15}$/', $phoneE164)) {
        return ['ok' => false, 'status' => 'failed', 'error' => 'phone_invalid'];
    }

    $creds = _ocre_wa_load_creds();
    $stubMode = ($creds['token'] === '' || $creds['phone_id'] === '');

    try {
        $pdo = _ocre_wa_pdo();
        $ins = $pdo->prepare(
            "INSERT INTO whatsapp_events (user_id, phone, template_name, params_json, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $ins->execute([$userId, $phoneE164, $template, json_encode($params, JSON_UNESCAPED_UNICODE), $stubMode ? 'stub' : 'queued']);
        $eventId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        _ocre_wa_log("DB_FAIL phone=$phoneE164 template=$template msg=" . $e->getMessage());
        return ['ok' => false, 'status' => 'failed', 'error' => 'db_error'];
    }

    if ($stubMode) {
        _ocre_wa_log(sprintf('STUB event_id=%d phone=%s template=%s params=%s', $eventId, $phoneE164, $template, json_encode($params)));
        return ['ok' => true, 'status' => 'stub', 'event_id' => $eventId, 'stub' => true];
    }

    $url = 'https://graph.facebook.com/v21.0/' . urlencode($creds['phone_id']) . '/messages';
    $components = [];
    if (!empty($params)) {
        $bodyParams = [];
        foreach ($params as $p) {
            $bodyParams[] = ['type' => 'text', 'text' => (string)$p];
        }
        $components[] = ['type' => 'body', 'parameters' => $bodyParams];
    }
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => substr($phoneE164, 1),
        'type' => 'template',
        'template' => [
            'name' => $template,
            'language' => ['code' => 'fr'],
        ],
    ];
    if (!empty($components)) $payload['template']['components'] = $components;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $creds['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($http >= 200 && $http < 300 && $resp !== false) {
        $j = json_decode($resp, true);
        $msgId = $j['messages'][0]['id'] ?? null;
        try {
            $pdo->prepare("UPDATE whatsapp_events SET status = 'sent', provider_message_id = ?, sent_at = NOW() WHERE id = ?")
                ->execute([$msgId, $eventId]);
        } catch (Throwable $_) {}
        _ocre_wa_log("SENT event_id=$eventId phone=$phoneE164 template=$template msg_id=$msgId");
        return ['ok' => true, 'status' => 'sent', 'provider_message_id' => $msgId, 'event_id' => $eventId];
    }

    $errMsg = $cerr ?: substr((string)$resp, 0, 500);
    try {
        $pdo->prepare("UPDATE whatsapp_events SET status = 'failed', error_message = ? WHERE id = ?")
            ->execute([substr($errMsg, 0, 500), $eventId]);
    } catch (Throwable $_) {}
    _ocre_wa_log("FAIL event_id=$eventId phone=$phoneE164 template=$template http=$http err=" . substr($errMsg, 0, 200));
    return ['ok' => false, 'status' => 'failed', 'error' => $errMsg, 'http' => $http, 'event_id' => $eventId];
}

}
