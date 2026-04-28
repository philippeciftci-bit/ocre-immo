<?php
// M/2026/04/29/8 — AI assistant via Anthropic Claude (Haiku rapide + economique).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/feature_flags.php';
require_once __DIR__ . '/lib/billing.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? '';
$input = getInput();

if (!ff_enabled('beta_ai_assistant', $uid)) {
    jsonError('AI assistant non activé pour ce compte (flag beta_ai_assistant)', 403);
}

function ai_ensure_schema(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            input_tokens INT NULL,
            output_tokens INT NULL,
            cost_usd DECIMAL(10,6) NULL,
            cache_key CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_month (user_id, created_at),
            INDEX idx_cache (cache_key, created_at)
        ) CHARACTER SET utf8mb4");
    } catch (Throwable $e) {}
    return $pdo;
}

function ai_check_quota(int $uid): array {
    $limits = billing_get_user_plan_limits($uid);
    $plan = $limits['plan'];
    $monthly = ['decouverte' => 0, 'pro' => 100, 'equipe' => 500][$plan] ?? 0;
    if ($monthly === 0) return ['ok' => false, 'plan' => $plan, 'reason' => 'plan ne permet pas l\'IA'];
    $pdo = ai_ensure_schema();
    $st = $pdo->prepare("SELECT COUNT(*) FROM ai_usage WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $st->execute([$uid]);
    $used = (int) $st->fetchColumn();
    $remaining = max(0, $monthly - $used);
    return ['ok' => $remaining > 0, 'plan' => $plan, 'monthly' => $monthly, 'used' => $used, 'remaining' => $remaining];
}

function ai_call_claude(string $systemPrompt, string $userPrompt, int $maxTokens = 600): array {
    $keyFile = '/root/.secrets/anthropic_api_key';
    if (!is_readable($keyFile)) return ['_error' => 'no_api_key'];
    $apiKey = trim((string) file_get_contents($keyFile));
    if (!$apiKey) return ['_error' => 'empty_api_key'];

    $payload = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
        'messages' => [['role' => 'user', 'content' => $userPrompt]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['_error' => 'http_' . $code, 'body' => substr($resp, 0, 500)];
    $data = json_decode($resp, true) ?: [];
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return [
        'text' => $text,
        'input_tokens' => $data['usage']['input_tokens'] ?? 0,
        'output_tokens' => $data['usage']['output_tokens'] ?? 0,
    ];
}

function ai_log(int $uid, string $action, array $resp, ?string $cacheKey = null): void {
    try {
        ai_ensure_schema()->prepare(
            "INSERT INTO ai_usage (user_id, action, input_tokens, output_tokens, cost_usd, cache_key) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $uid, $action,
            (int) ($resp['input_tokens'] ?? 0),
            (int) ($resp['output_tokens'] ?? 0),
            // Haiku 4.5 pricing approx: $1/$5 per Mtoken
            round(((($resp['input_tokens'] ?? 0) * 1) + (($resp['output_tokens'] ?? 0) * 5)) / 1000000, 6),
            $cacheKey,
        ]);
    } catch (Throwable $e) {}
}

function ai_load_client_context(int $clientId, int $uid): array {
    try {
        $st = db()->prepare("SELECT prenom, nom, societe_nom, projet, vertical, data FROM clients WHERE id = ? AND user_id = ?");
        $st->execute([$clientId, $uid]);
        $c = $st->fetch();
        if (!$c) return [];
        $data = json_decode($c['data'] ?? '{}', true) ?: [];
        $bien = $data['bien'] ?? [];
        return [
            'name' => trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')) ?: ($c['societe_nom'] ?? ''),
            'profil' => $c['projet'] ?? '',
            'vertical' => $c['vertical'] ?? '',
            'type_bien' => $bien['type'] ?? '',
            'ville' => $bien['ville'] ?? '',
            'tel' => '',
            'email' => '',
        ];
    } catch (Throwable $e) { return []; }
}

$quota = ai_check_quota($uid);
if (!$quota['ok'] && $action !== 'quota_status') {
    jsonError('Quota IA atteint ce mois-ci (' . $quota['used'] . '/' . $quota['monthly'] . ' générations sur plan ' . $quota['plan'] . ')', 429);
}

switch ($action) {

case 'quota_status':
    jsonOk($quota);

case 'draft_note': {
    $brief = trim($input['brief'] ?? '');
    $tone = $input['tone'] ?? 'professionnel';
    $clientId = (int) ($input['client_id'] ?? 0);
    if (!$brief) jsonError('brief requis', 400);
    $context = $clientId ? ai_load_client_context($clientId, $uid) : [];
    $cacheKey = hash('sha256', "draft_note:{$tone}:{$brief}:" . json_encode($context));
    // Cache 5 min
    $cacheCheck = ai_ensure_schema()->prepare("SELECT COUNT(*) FROM ai_usage WHERE cache_key = ? AND created_at > NOW() - INTERVAL 5 MINUTE");
    $cacheCheck->execute([$cacheKey]);
    // Pas de stockage du résultat dans cache — juste rate limit double-clic. Si vraiment cache, étendre table.
    $sys = "Tu es l'assistant rédactionnel d'un agent immobilier. Transforme les notes brutes en compte-rendu structuré professionnel. Format : paragraphes courts (3-5 lignes max), pas de bullet points, max 200 mots. Pas de signature. Français impeccable.";
    $ctxStr = $context ? "Contexte dossier : " . ($context['name'] ?? '') . " · " . ($context['profil'] ?? '') . " · " . ($context['type_bien'] ?? '') . " " . ($context['ville'] ?? '') . "\n\n" : '';
    $usr = $ctxStr . "Brut : " . $brief . "\n\nTon souhaité : " . $tone;
    $resp = ai_call_claude($sys, $usr, 600);
    if (isset($resp['_error'])) jsonError('IA indisponible : ' . $resp['_error'], 502);
    ai_log($uid, 'draft_note', $resp, $cacheKey);
    jsonOk(['text' => $resp['text'], 'tokens' => ($resp['input_tokens'] + $resp['output_tokens'])]);
}

case 'draft_message': {
    $channel = $input['channel'] ?? 'email';
    $intent = $input['intent'] ?? 'autre';
    $custom = trim($input['custom'] ?? '');
    $clientId = (int) ($input['client_id'] ?? 0);
    $context = $clientId ? ai_load_client_context($clientId, $uid) : [];
    $sys = "Tu es l'assistant rédactionnel d'un agent immobilier. Rédige des messages clients professionnels en français. Adapte le ton selon le canal : email (formel, paragraphes), SMS (court max 160 caractères, direct), WhatsApp (informel mais respectueux, court). Inclus toujours signature avec '— Ocre Immo'. Français impeccable.";
    $intentMap = [
        'confirmer_rdv' => 'Confirmer le RDV à venir',
        'relancer_visite' => 'Relancer après une visite',
        'demander_document' => 'Demander un document (pièce d identité / justif domicile / pré-accord banque)',
        'nouveau_bien_matchant' => 'Annoncer un nouveau bien matchant ses critères',
        'confirmer_compromis' => 'Confirmer l envoi du compromis',
        'autre' => 'Autre',
    ];
    $intentStr = $intentMap[$intent] ?? $intent;
    $usr = "Canal : {$channel}\nIntent : {$intentStr}\n";
    if ($context) $usr .= "Client : " . ($context['name'] ?? '') . " (" . ($context['profil'] ?? '') . ")\nBien : " . ($context['type_bien'] ?? '') . " à " . ($context['ville'] ?? '') . "\n";
    if ($custom) $usr .= "Précisions : " . $custom;
    $resp = ai_call_claude($sys, $usr, 400);
    if (isset($resp['_error'])) jsonError('IA indisponible : ' . $resp['_error'], 502);
    ai_log($uid, 'draft_message', $resp);
    jsonOk(['text' => $resp['text']]);
}

case 'summarize_dossier': {
    $clientId = (int) ($input['client_id'] ?? 0);
    if (!$clientId) jsonError('client_id requis', 400);
    $st = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ?");
    $st->execute([$clientId, $uid]);
    $c = $st->fetch();
    if (!$c) jsonError('dossier introuvable', 404);
    $data = json_decode($c['data'] ?? '{}', true) ?: [];
    $sys = "Tu es l'assistant d'un agent immobilier. Résume le dossier client en 3-5 phrases courtes pour briefer rapidement un collègue avant un appel. Phrases factuelles, pas de fluff. Français impeccable.";
    $usr = "Dossier : " . trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')) . "\n"
        . "Profil : " . ($c['projet'] ?? '') . "\n"
        . "Données : " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $resp = ai_call_claude($sys, $usr, 400);
    if (isset($resp['_error'])) jsonError('IA indisponible : ' . $resp['_error'], 502);
    ai_log($uid, 'summarize_dossier', $resp);
    jsonOk(['text' => $resp['text']]);
}

default:
    jsonError('Action inconnue (quota_status | draft_note | draft_message | summarize_dossier)', 400);
}
