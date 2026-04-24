<?php
// V18.36 — Cross-referencing Option A : trouve une annonce similaire sur d'autres sites
// (Mubawab, Vaneau, Leboncoin, SeLoger, Facebook Marketplace, agences locales) via le tool
// server-side `web_search` d'Anthropic. Manuel (bouton dans ClientForm sous Section II) pour
// v18.36 ; auto-déclenchement et flux enrichi prévus v18.37+.
//
// Endpoint :
//   POST /api/cross_search.php?action=search&id=<client_id>   (auth user session)

require_once __DIR__ . '/db.php';
setCorsHeaders();

const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';
const CLAUDE_MAX_TOKENS = 3000;

function anthropicKey(): ?string {
    $k = getSetting('anthropic_api_key', '');
    if (!$k) $k = getenv('ANTHROPIC_API_KEY') ?: '';
    if (!$k) {
        $f = '/root/.secrets/anthropic_api_key';
        if (is_readable($f)) $k = trim((string) @file_get_contents($f));
    }
    return $k ?: null;
}

function serializeCriteria(array $d): string {
    $bien = $d['bien'] ?? [];
    $types = Array_isArray($bien['types'] ?? null)
        ? implode(' ou ', array_filter((array) $bien['types']))
        : ($bien['type'] ?? '');
    $parts = [];
    if ($types) $parts[] = 'Type : ' . $types;
    if ($bien['ville'] ?? '') $parts[] = 'Ville : ' . $bien['ville'];
    if ($bien['quartier'] ?? '') $parts[] = 'Quartier : ' . $bien['quartier'];
    if ($bien['pays'] ?? '') $parts[] = 'Pays : ' . $bien['pays'];
    $surf = $bien['surface'] ?? $bien['surface_habitable'] ?? null;
    if ($surf) $parts[] = 'Surface : ' . $surf . ' m²';
    if ($bien['pieces'] ?? '') $parts[] = 'Pièces : ' . $bien['pieces'];
    if ($bien['chambres'] ?? '') $parts[] = 'Chambres : ' . $bien['chambres'];
    $fin = $d['financement'] ?? [];
    $budget = $fin['budget_total'] ?? $fin['budget_max'] ?? $d['budget_max'] ?? $d['prix_affiche'] ?? null;
    if ($budget) $parts[] = 'Budget/prix réf : ' . $budget;
    if ($bien['titre'] ?? '') $parts[] = 'Titre : ' . $bien['titre'];
    return implode(' · ', $parts);
}

/** Compat wrapper (Array_isArray peut ne pas exister selon version PHP minuscule). */
function Array_isArray($v) { return is_array($v); }

function callClaudeWebSearch(string $apiKey, string $criteria): ?array {
    $sys = "Tu es un assistant immobilier. Cherche sur internet (Mubawab, Vaneau, "
         . "Leboncoin, SeLoger, Facebook Marketplace, agences locales) des annonces "
         . "similaires à celle décrite. "
         . "Retourne UNIQUEMENT un JSON array brut (pas de markdown, pas de texte avant/après). "
         . "Max 5 matches. Schéma par match : "
         . "{source_url (URL absolue), source_domain (ex mubawab.ma), title_found, "
         . "price_found (nombre), currency_found (EUR|MAD|USD), "
         . "characteristics_match_score (0-100, subjectif mais honnête), "
         . "price_delta_pct (écart % vs budget réf, + si plus cher, - si moins cher)}. "
         . "Filtre : ignore les biens qui ne correspondent pas (wrong ville, wrong type). "
         . "Pas d'invention — si tu ne trouves rien, retourne [].";

    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => CLAUDE_MAX_TOKENS,
        'system' => $sys,
        'tools' => [
            ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5],
        ],
        'messages' => [
            ['role' => 'user', 'content' => "Cherche cette annonce sur d'autres sites :\n" . $criteria],
        ],
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
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code >= 400 || !$resp) {
        return ['_error' => 'API Claude HTTP ' . $code . ($err ? ' · ' . $err : '')];
    }
    $j = json_decode($resp, true);
    if (!$j) return ['_error' => 'Réponse Claude non-JSON'];
    if (empty($j['content'])) return ['_error' => 'Réponse Claude vide'];

    // La réponse contient plusieurs content items (tool_use / tool_result server-side /
    // text final). On cherche le dernier bloc type=text.
    $text = '';
    foreach ($j['content'] as $c) {
        if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    }
    if (!$text) return ['_error' => 'Pas de texte final dans la réponse Claude'];

    // Parse JSON array (le prompt demande un array brut, mais on est défensif).
    if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) return ['matches' => $parsed];
    }
    // Fallback : essai objet unique.
    if (preg_match('/\{[\s\S]*\}/', $text, $m2)) {
        $parsed = json_decode($m2[0], true);
        if (is_array($parsed)) return ['matches' => [$parsed]];
    }
    return ['_error' => 'Impossible de parser le JSON Claude', '_raw' => substr($text, 0, 400)];
}

function sanitizeMatches(array $matches): array {
    $out = [];
    foreach ($matches as $m) {
        if (!is_array($m)) continue;
        $url = trim((string) ($m['source_url'] ?? ''));
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;
        $score = (int) ($m['characteristics_match_score'] ?? 0);
        if ($score < 60) continue;
        $out[] = [
            'source_url' => $url,
            'source_domain' => (string) ($m['source_domain'] ?? parse_url($url, PHP_URL_HOST) ?: ''),
            'title_found' => mb_substr((string) ($m['title_found'] ?? ''), 0, 300),
            'price_found' => is_numeric($m['price_found'] ?? null) ? (float) $m['price_found'] : null,
            'currency_found' => strtoupper(mb_substr((string) ($m['currency_found'] ?? ''), 0, 6)),
            'characteristics_match_score' => max(0, min(100, $score)),
            'price_delta_pct' => is_numeric($m['price_delta_pct'] ?? null) ? (float) $m['price_delta_pct'] : null,
            'detected_at' => date('c'),
        ];
    }
    // Tri par score décroissant.
    usort($out, fn($a, $b) => $b['characteristics_match_score'] - $a['characteristics_match_score']);
    return array_slice($out, 0, 5);
}

$user = requireAuth();
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'search': {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) jsonError('id requis', 400);

        $stmt = db()->prepare("SELECT id, data FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $user['id']]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Dossier introuvable', 404);

        $d = json_decode($row['data'] ?? '{}', true) ?: [];
        $criteria = serializeCriteria($d);
        if (!$criteria) jsonError('Dossier trop vide pour une recherche (renseigne au moins type + ville)', 400);

        $key = anthropicKey();
        if (!$key) jsonError('Clé Anthropic non configurée', 503);

        $r = callClaudeWebSearch($key, $criteria);
        if (isset($r['_error'])) jsonError($r['_error'], 502, ['detail' => $r['_raw'] ?? null]);

        $matches = sanitizeMatches($r['matches'] ?? []);

        // Persister dans d.cross_matches + d.cross_search_last_at.
        $d['cross_matches'] = $matches;
        $d['cross_search_last_at'] = date('c');
        $newData = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $u = db()->prepare("UPDATE clients SET data = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $u->execute([$newData, $id, $user['id']]);

        logAction((int) $user['id'], 'cross_search', 'id=' . $id . ' matches=' . count($matches));
        jsonOk(['matches' => $matches, 'searched_at' => $d['cross_search_last_at']]);
    }

    default:
        jsonError('action inconnue : ' . $action, 400);
}
