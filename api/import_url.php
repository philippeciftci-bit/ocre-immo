<?php
// V17.14 — extraction structurée depuis URL annonce immobilier.
// Whitelist domaines, fetch HTML, parse (JSON-LD + OG + regex), structure via Claude Haiku si clé dispo.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? '';
$input = getInput();

// V17.15 I3 : plus de whitelist — toute URL https valide acceptée (domaine FQDN).
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
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

function urlValid($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) return false;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !preg_match('/\.[a-z]{2,}$/i', $host)) return false;
    return true;
}

function fetchHtml($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => UA,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'Erreur réseau : ' . $err];
    if ($code === 403 || $code === 429) return ['error' => 'Site bloque l\'extraction automatique (HTTP ' . $code . '), copier-coller manuel nécessaire', 'http_code' => $code];
    if ($code >= 400) return ['error' => 'Page indisponible (HTTP ' . $code . ')', 'http_code' => $code];
    if (!$body) return ['error' => 'Contenu vide'];
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
    // OpenGraph
    if (preg_match_all('#<meta[^>]+property=["\'](og:[a-z_:]+|product:[a-z_:]+)["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $k = preg_replace('/^og:|^product:/', '', $row[1]);
            if (!isset($out[$k])) $out[$k] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    // Twitter Card
    if (preg_match_all('#<meta[^>]+name=["\'](twitter:[a-z_:]+)["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $k = preg_replace('/^twitter:/', '', $row[1]);
            if (!isset($out[$k])) $out[$k] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    // Meta standard
    if (preg_match_all('#<meta[^>]+name=["\']([a-z_:]+)["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $k = $row[1];
            if (!isset($out[$k])) $out[$k] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    // <title> fallback
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $mt)) $out['_title'] = trim(html_entity_decode(strip_tags($mt[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // <h1> fallback
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $mh)) $out['_h1'] = trim(html_entity_decode(strip_tags($mh[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $out;
}

// V17.15 I3 : extrait jusqu'à 10 images depuis le HTML (og + <img> significatifs).
function extractPhotos($html, $og, $jld) {
    $photos = [];
    foreach (['image','image:url','image:secure_url'] as $k) if (!empty($og[$k])) $photos[] = $og[$k];
    foreach ($jld as $g) {
        if (!empty($g['image'])) {
            $im = is_array($g['image']) ? $g['image'] : [$g['image']];
            foreach ($im as $u) {
                if (is_string($u)) $photos[] = $u;
                else if (is_array($u) && !empty($u['url'])) $photos[] = $u['url'];
            }
        }
    }
    // Images significatives : classes contenant photo/gallery/image ou width >= 300
    if (preg_match_all('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#i', $html, $mm)) {
        foreach ($mm[1] as $src) {
            if (!preg_match('#^https?://#i', $src)) continue;
            if (preg_match('#\.(svg|gif)(\?|$)#i', $src)) continue;
            if (preg_match('#(logo|icon|favicon|avatar|sprite|placeholder)#i', $src)) continue;
            $photos[] = $src;
        }
    }
    $photos = array_values(array_unique(array_filter($photos)));
    return array_slice($photos, 0, 10);
}

function heuristicExtract($og, $jld, $html) {
    $title = $og['title'] ?? $og['_title'] ?? $og['_h1'] ?? '';
    $description = $og['description'] ?? '';
    $photos = extractPhotos($html, $og, $jld);

    // Prix
    $prix = null; $devise = null;
    foreach ($jld as $g) {
        if (!empty($g['offers']['price'])) { $prix = (float)$g['offers']['price']; }
        if (!empty($g['offers']['priceCurrency'])) { $devise = $g['offers']['priceCurrency']; }
    }
    // Meta price amount
    if (!$prix && !empty($og['price:amount'])) { $prix = (float)$og['price:amount']; }
    if (!$devise && !empty($og['price:currency'])) { $devise = $og['price:currency']; }
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
        // V17.15 I3 : plus de whitelist, toute URL http/https FQDN valide acceptée.
        if (!$url || !urlValid($url)) jsonError('URL invalide');
        $f = fetchHtml($url);
        if (!empty($f['error'])) jsonError($f['error'], $f['http_code'] ?? 502);
        $html = $f['html'];
        $og = parseMetaOG($html);
        $jld = parseJsonLd($html);
        $heur = heuristicExtract($og, $jld, $html);
        $hasAny = array_filter($heur, fn($v) => $v !== null && $v !== '' && $v !== []);
        // Si clé Claude dispo, structure via Haiku (plus précis).
        $key = getAnthropicKey();
        $ai = null;
        if ($key) {
            $ai = claudeStructure($html, $url, $key);
        }
        // Merge : Claude a priorité, heuristique comble.
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
        // Si rien d'intéressant extrait → prévenir l'user
        $interestingKeys = ['title','prix','types_bien','ville_bien'];
        $hasAny2 = false;
        foreach ($interestingKeys as $k) if (!empty($merged[$k])) { $hasAny2 = true; break; }
        if (!$hasAny2) {
            $merged['warning'] = 'Aucune info significative détectée sur cette page. Copier-coller manuel recommandé.';
        }
        jsonOk(['extracted' => $merged]);
    }

    default:
        jsonError('Action inconnue', 404);
}
