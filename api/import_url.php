<?php
// V17.14 — extraction structurée depuis URL annonce immobilier.
// Whitelist domaines, fetch HTML, parse (JSON-LD + OG + regex), structure via Claude Haiku si clé dispo.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? '';
$input = getInput();

const ALLOWED_DOMAINS = [
    'mubawab.ma', 'avito.ma', 'sarouty.ma', 'immomaroc.ma', 'agenceimmo.ma',
    'seloger.com', 'leboncoin.fr', 'pap.fr', 'immoscout.fr', 'bien-ici.com',
    'century21.fr', 'guy-hoquet.com', 'orpi.com',
];
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36';
const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

function getAnthropicKey() {
    $k = getSetting('anthropic_api_key', '');
    if (!$k) $k = getenv('ANTHROPIC_API_KEY') ?: '';
    if (!$k) {
        $f = '/root/.secrets/anthropic_api_key';
        if (is_readable($f)) $k = trim((string)@file_get_contents($f));
    }
    return $k ?: null;
}

function domainFromUrl($url) {
    $h = parse_url($url, PHP_URL_HOST);
    if (!$h) return '';
    $h = strtolower(preg_replace('/^www\./', '', $h));
    return $h;
}

function domainAllowed($url) {
    $h = domainFromUrl($url);
    if (!$h) return false;
    foreach (ALLOWED_DOMAINS as $d) if ($h === $d || str_ends_with($h, '.' . $d)) return true;
    return false;
}

function fetchHtml($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => UA,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $code >= 400 || !$body) return ['error' => 'fetch_failed (http ' . $code . ')'];
    return ['html' => $body, 'http_code' => $code];
}

function parseJsonLd($html) {
    $results = [];
    if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            $j = json_decode($raw, true);
            if ($j === null) continue;
            if (isset($j['@graph']) && is_array($j['@graph'])) { foreach ($j['@graph'] as $g) $results[] = $g; }
            else $results[] = $j;
        }
    }
    return $results;
}

function parseMetaOG($html) {
    $out = [];
    if (preg_match_all('#<meta[^>]+property=["\']og:([a-z_:]+)["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) $out[$row[1]] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match_all('#<meta[^>]+name=["\']([a-z_:]+)["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $k = $row[1];
            if (!isset($out[$k])) $out[$k] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    // <title> fallback
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $mt)) $out['_title'] = trim(html_entity_decode(strip_tags($mt[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $out;
}

function heuristicExtract($og, $jld, $html) {
    $title = $og['title'] ?? $og['_title'] ?? '';
    $description = $og['description'] ?? '';
    // Photos : og:image + jsonld.image
    $photos = [];
    foreach (['image','image:url','image:secure_url'] as $k) if (!empty($og[$k])) $photos[] = $og[$k];
    foreach ($jld as $g) {
        if (!empty($g['image'])) {
            $im = is_array($g['image']) ? $g['image'] : [$g['image']];
            foreach ($im as $u) if (is_string($u)) $photos[] = $u;
            else if (is_array($u) && !empty($u['url'])) $photos[] = $u['url'];
        }
    }
    $photos = array_values(array_unique(array_filter($photos)));
    $photos = array_slice($photos, 0, 5);

    // Prix
    $prix = null; $devise = null;
    foreach ($jld as $g) {
        if (!empty($g['offers']['price'])) { $prix = (float)$g['offers']['price']; }
        if (!empty($g['offers']['priceCurrency'])) { $devise = $g['offers']['priceCurrency']; }
    }
    if (!$prix) {
        $txt = strip_tags($html);
        if (preg_match('/(\d[\d\s\.\,]{3,})\s*(€|EUR|euros?|DH|MAD|dirhams?)/iu', $txt, $pm)) {
            $raw = preg_replace('/[^\d]/', '', $pm[1]);
            if ($raw) $prix = (float)$raw;
            $cur = strtolower($pm[2]);
            if (str_contains($cur, 'eur') || str_contains($cur, '€')) $devise = 'EUR';
            else if (str_contains($cur, 'dh') || str_contains($cur, 'mad') || str_contains($cur, 'dirh')) $devise = 'MAD';
        }
    }
    // Surface
    $surface = null;
    $txt = strip_tags($title . ' ' . $description . ' ' . $html);
    if (preg_match('/(\d+)\s*m\s*[²2]/u', $txt, $sm)) $surface = (int)$sm[1];
    // Chambres
    $chambres = null;
    if (preg_match('/(\d+)\s*(chambre|bedroom|pi[eè]ce)/iu', $txt, $cm)) $chambres = (int)$cm[1];
    // Type bien — heuristique title + description
    $type = null;
    $lowerTxt = mb_strtolower($title . ' ' . $description);
    $typeMap = ['villa'=>'Villa', 'appartement'=>'Appartement', 'riad'=>'Riad', 'maison'=>'Maison', 'terrain'=>'Terrain', 'bureau'=>'Bureau / plateau', 'commerce'=>'Commerce', 'ferme'=>'Ferme'];
    foreach ($typeMap as $kw => $t) if (str_contains($lowerTxt, $kw)) { $type = $t; break; }
    // Ville
    $ville = null;
    foreach ($jld as $g) {
        if (!empty($g['address']['addressLocality'])) { $ville = $g['address']['addressLocality']; break; }
    }
    if (!$ville) {
        foreach (['Marrakech','Marrakesh','Casablanca','Rabat','Tanger','Agadir','Fès','Fez','Essaouira','Paris','Lyon','Nantes','Bordeaux','Marseille','Nice','Toulouse','Lille','Strasbourg'] as $v) {
            if (stripos($lowerTxt, mb_strtolower($v)) !== false) { $ville = $v; break; }
        }
    }
    $pays_bien = null;
    if ($ville) {
        $ma_cities = ['marrakech','marrakesh','casablanca','rabat','tanger','agadir','fès','fez','essaouira'];
        $pays_bien = in_array(mb_strtolower($ville), $ma_cities, true) ? 'MA' : 'FR';
    }

    return [
        'title' => $title,
        'description' => mb_substr($description, 0, 800),
        'photos' => $photos,
        'prix' => $prix,
        'devise' => $devise,
        'surface' => $surface,
        'chambres' => $chambres,
        'types_bien' => $type ? [$type] : null,
        'ville_bien' => $ville,
        'pays_bien' => $pays_bien,
    ];
}

function claudeStructure($html, $url, $apiKey) {
    // Excerpt HTML : strip scripts/styles, limit 8000 chars.
    $excerpt = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
    $excerpt = preg_replace('#<style[^>]*>.*?</style>#is', '', $excerpt);
    $excerpt = preg_replace('/\s+/', ' ', strip_tags($excerpt));
    $excerpt = mb_substr($excerpt, 0, 8000);
    $sys = "Tu extrais d'une page HTML d'annonce immobilier (FR/MA) les infos en JSON valide uniquement. Schéma : {title, description, prix (nombre), devise (EUR|MAD|USD), surface (m²), chambres, sdb, types_bien ([Villa|Appartement|Riad|Maison|Terrain|Commerce|Ferme|Bureau / plateau|Bâtiment industriel]), pays_bien (MA|FR|ES), ville_bien, quartier_bien, photos (URL array, max 5), description_clean (résumé 200 chars max)}. null si non trouvé. Convertis '500k' → 500000, '1,8M' → 1800000.";
    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 1200,
        'system' => $sys,
        'messages' => [
            ['role' => 'user', 'content' => "URL: $url\n\n" . $excerpt],
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
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || !$resp) return null;
    $j = json_decode($resp, true);
    if (!$j || empty($j['content'])) return null;
    $text = '';
    foreach ($j['content'] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) return $parsed;
    }
    return null;
}

switch ($action) {
    case 'extract': {
        $url = trim((string)($input['url'] ?? ''));
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) jsonError('URL invalide');
        if (!domainAllowed($url)) jsonError('Domaine non autorisé : ' . domainFromUrl($url), 400);
        $f = fetchHtml($url);
        if (!empty($f['error'])) jsonError($f['error'], 502);
        $html = $f['html'];
        $og = parseMetaOG($html);
        $jld = parseJsonLd($html);
        $heur = heuristicExtract($og, $jld, $html);
        // Si clé Claude dispo, structure via Haiku (plus précis).
        $key = getAnthropicKey();
        $ai = null;
        if ($key) {
            $ai = claudeStructure($html, $url, $key);
        }
        // Merge : Claude a priorité sur les champs qu'il remplit, heuristique comble les trous.
        $merged = is_array($ai) ? array_filter($ai, fn($v) => $v !== null && $v !== '') : [];
        foreach ($heur as $k => $v) {
            if ($v === null || $v === '' || (is_array($v) && !$v)) continue;
            if (!isset($merged[$k]) || $merged[$k] === null || $merged[$k] === '' || (is_array($merged[$k]) && !$merged[$k])) {
                $merged[$k] = $v;
            }
        }
        $merged['source_url'] = $url;
        $merged['source_domain'] = domainFromUrl($url);
        $merged['extraction_mode'] = $key && $ai ? 'ai' : 'heuristic';
        jsonOk(['extracted' => $merged]);
    }

    default:
        jsonError('Action inconnue', 404);
}
