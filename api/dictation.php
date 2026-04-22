<?php
// V17.12 — extraction structurée d'une note vocale via Claude Haiku.
// Clé API lue depuis settings.anthropic_api_key ou env ANTHROPIC_API_KEY.
// Fallback {raw_text} si clé absente ou erreur API.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? '';
$input = getInput();

const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';
const SYSTEM_PROMPT = <<<'PROMPT'
Tu extrais des infos structurées d'une note vocale d'un agent immobilier en français. Réponds UNIQUEMENT en JSON valide, null si non mentionné, PAS de texte avant ou après. Schéma :
{
  "prenom": string|null,
  "nom": string|null,
  "societe_nom": string|null,
  "profil": "Acheteur"|"Vendeur"|"Investisseur"|"Bailleur"|"Locataire"|"Curieux"|null,
  "types_bien": [string]|null,   // Villa/Appartement/Riad/Maison/Terrain/Commerce/Ferme/Bureau / plateau/Bâtiment industriel/Terrain industriel
  "pays_bien": "MA"|"FR"|"ES"|"BE"|string|null,
  "ville_bien": string|null,
  "quartier_bien": string|null,
  "budget_min": number|null,
  "budget_max": number|null,
  "devise": "MAD"|"EUR"|"USD"|string|null,
  "pays_residence": string|null,
  "tel": string|null,
  "email": string|null,
  "notes_libres": string|null
}
Convertis "500k" → 500000, "1,8M" → 1800000, "5 millions" → 5000000. Pour budget non-fourchette (ex "budget 500k€"), remplis budget_max=500000 et laisse budget_min=null.
PROMPT;

function getAnthropicKey() {
    $k = getSetting('anthropic_api_key', '');
    if (!$k) $k = getenv('ANTHROPIC_API_KEY') ?: '';
    if (!$k) {
        $f = '/root/.secrets/anthropic_api_key';
        if (is_readable($f)) $k = trim((string)@file_get_contents($f));
    }
    return $k ?: null;
}

function callClaude($transcript) {
    $key = getAnthropicKey();
    if (!$key) return ['error' => 'no_key'];
    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 800,
        'system' => SYSTEM_PROMPT,
        'messages' => [
            ['role' => 'user', 'content' => $transcript],
        ],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'curl: ' . $err];
    if ($code >= 400) return ['error' => 'http ' . $code . ': ' . substr($resp, 0, 200)];
    $j = json_decode($resp, true);
    if (!$j || empty($j['content'])) return ['error' => 'no_content'];
    $text = '';
    foreach ($j['content'] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    // On cherche le premier {...} JSON dans la réponse.
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $extracted = json_decode($m[0], true);
        if (is_array($extracted)) return ['extracted' => $extracted, 'raw' => $text];
    }
    return ['error' => 'parse_failed', 'raw' => $text];
}

switch ($action) {
    case 'extract': {
        $transcript = trim((string)($input['transcript'] ?? ''));
        if (!$transcript) jsonError('transcript requis');
        if (mb_strlen($transcript) > 4000) $transcript = mb_substr($transcript, 0, 4000);
        $r = callClaude($transcript);
        if (isset($r['extracted'])) {
            jsonOk(['extracted' => $r['extracted'], 'transcript' => $transcript, 'mode' => 'ai']);
        }
        // Fallback : pas de clé OU API KO → renvoie raw_text pour brouillon manuel.
        jsonOk([
            'extracted' => null,
            'raw_text' => $transcript,
            'transcript' => $transcript,
            'mode' => 'raw',
            'error' => $r['error'] ?? 'extract_failed',
        ]);
    }

    case 'has_key': {
        // Debug : l'utilisateur peut check si la clé est configurée.
        jsonOk(['has_key' => (bool)getAnthropicKey()]);
    }

    default:
        jsonError('Action inconnue', 404);
}
