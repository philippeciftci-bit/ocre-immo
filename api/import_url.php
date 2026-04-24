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

    // V18.11 — champs enrichis via regex sur le texte complet.
    $fullTxt = strip_tags($html);
    // Nettoyage texte pour description complète (espaces multiples, paragraphes).
    $cleanTxt = preg_replace('/\s+/', ' ', $fullTxt);
    $description_complete = mb_substr($cleanTxt, 0, 5000);
    $surface_terrain = null;
    if (preg_match('/(?:terrain|jardin|lot)\s+(?:de\s+)?(\d{2,5})\s*m\s*[²2]/iu', $fullTxt, $tm)) $surface_terrain = (int)$tm[1];
    $nombre_pieces = null;
    if (preg_match('/\bT(\d)\b/u', $title . ' ' . $description, $pm)) $nombre_pieces = (int)$pm[1];
    elseif (preg_match('/(\d+)\s*pi[eè]ces?\b/iu', $fullTxt, $pm2)) $nombre_pieces = (int)$pm2[1];
    $nombre_sdb = null;
    if (preg_match('/(\d+)\s*(salles?\s+de\s+bains?|sdb)/iu', $fullTxt, $sdm)) $nombre_sdb = (int)$sdm[1];
    $etage = null;
    if (preg_match('/au\s+(\d+)\s*[eè]me?\s*[ée]tage/iu', $fullTxt, $em)) $etage = (int)$em[1];
    elseif (preg_match('/(\d+)\s*[eè]me?\s+[ée]tage/iu', $fullTxt, $em2)) $etage = (int)$em2[1];
    $dpe_class = null;
    if (preg_match('/\bDPE\s*[:\-]?\s*([A-G])\b/u', $fullTxt, $dm)) $dpe_class = $dm[1];
    $dpe_ges = null;
    if (preg_match('/\bGES\s*[:\-]?\s*([A-G])\b/u', $fullTxt, $gm)) $dpe_ges = $gm[1];
    $annee_construction = null;
    if (preg_match('/(?:construit[e]?\s+en|ann[ée]e\s+(?:de\s+)?construction|construction)\s*[:\-]?\s*(\d{4})/iu', $fullTxt, $am)) $annee_construction = (int)$am[1];
    $ascenseur = (bool)preg_match('/\bascenseur\b/iu', $fullTxt);
    $parking = (bool)preg_match('/\bparking|garage|stationnement\b/iu', $fullTxt);
    $cave = (bool)preg_match('/\bcave\b/iu', $fullTxt);
    $balcon_terrasse = (bool)preg_match('/\bbalcon|terrasse\b/iu', $fullTxt);
    $neuf_ancien = preg_match('/\b(vefa|neuf|construction r[ée]cente|programme neuf)\b/iu', $fullTxt) ? 'neuf' : null;
    $code_postal = null;
    if (preg_match('/\b(\d{5})\b/u', $fullTxt, $cpm)) $code_postal = $cpm[1];
    // Annonceur (JSON-LD seller).
    $annonceur_type = null; $annonceur_nom = null;
    foreach ($jld as $g) {
        if (!empty($g['offers']['seller']['@type'])) {
            $annonceur_type = strtolower($g['offers']['seller']['@type']) === 'organization' ? 'professionnel' : 'particulier';
        }
        if (!empty($g['offers']['seller']['name'])) {
            $annonceur_nom = $g['offers']['seller']['name'];
        }
        if (!$annonceur_nom && !empty($g['author']['name'])) $annonceur_nom = $g['author']['name'];
    }
    if (!$annonceur_type && $annonceur_nom) {
        $annonceur_type = preg_match('/\b(SARL|SAS|SCI|EURL|agence|immobili[èe]re?|groupe)\b/iu', $annonceur_nom) ? 'professionnel' : 'particulier';
    }
    // Tel/email DANS la description (pas derrière bouton).
    $annonceur_tel_mentionne = null;
    if (preg_match('/(?:(?:\+|00)33[\s.-]?|0)\s*[1-9](?:[\s.-]*\d{2}){4}/u', $description_complete, $tm2)) {
        $digits = preg_replace('/\D/', '', $tm2[0]);
        if (strpos($digits, '33') === 0) $digits = substr($digits, 2);
        if ($digits[0] === '0') $digits = substr($digits, 1);
        if (strlen($digits) === 9) $annonceur_tel_mentionne = '+33' . $digits;
    }
    if (!$annonceur_tel_mentionne && preg_match('/(?:(?:\+|00)212[\s.-]?|0)\s*[5-7](?:[\s.-]*\d){8}/u', $description_complete, $tm3)) {
        $digits = preg_replace('/\D/', '', $tm3[0]);
        if (strpos($digits, '212') === 0) $digits = substr($digits, 3);
        if ($digits[0] === '0') $digits = substr($digits, 1);
        if (strlen($digits) === 9) $annonceur_tel_mentionne = '+212' . $digits;
    }
    $annonceur_email_mentionne = null;
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $description_complete, $em3)) {
        $annonceur_email_mentionne = $em3[0];
    }

    return [
        'title' => $title,
        'titre' => $title,
        'description' => mb_substr($description, 0, 800),
        'description_complete' => $description_complete,
        'photos' => $photos,
        'prix' => $prix,
        'devise' => $devise,
        'surface' => $surface,
        'surface_habitable' => $surface,
        'surface_terrain' => $surface_terrain,
        'nombre_pieces' => $nombre_pieces,
        'chambres' => $chambres,
        'nombre_chambres' => $chambres,
        'nombre_sdb' => $nombre_sdb,
        'etage' => $etage,
        'ascenseur' => $ascenseur,
        'parking' => $parking,
        'cave' => $cave,
        'balcon_terrasse' => $balcon_terrasse,
        'dpe_class' => $dpe_class,
        'dpe_ges' => $dpe_ges,
        'annee_construction' => $annee_construction,
        'neuf_ancien' => $neuf_ancien,
        'code_postal' => $code_postal,
        'types_bien' => $type ? [$type] : null,
        'ville_bien' => $ville,
        'pays_bien' => $pays_bien,
        'annonceur_type' => $annonceur_type,
        'annonceur_nom' => $annonceur_nom,
        'annonceur_tel_mentionne' => $annonceur_tel_mentionne,
        'annonceur_email_mentionne' => $annonceur_email_mentionne,
    ];
}

function claudeStructure($html, $url, $apiKey) {
    // Excerpt HTML : strip scripts/styles, limit 8000 chars.
    $excerpt = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
    $excerpt = preg_replace('#<style[^>]*>.*?</style>#is', '', $excerpt);
    $excerpt = preg_replace('/\s+/', ' ', strip_tags($excerpt));
    $excerpt = mb_substr($excerpt, 0, 8000);
    // V18.11 — schéma enrichi : surfaces, pièces/SDB/étage, DPE/GES, équipements, annonceur, description complète.
    $sys = "Tu extrais d'une page HTML d'annonce immobilier (FR/MA) les infos en JSON valide uniquement.\n"
         . "Schéma complet :\n"
         . "{titre, description_complete (texte intégral non-tronqué, max 5000 chars),\n"
         . " prix (nombre), devise (EUR|MAD|USD),\n"
         . " surface_habitable (m²), surface_terrain (m², distinct de surface_habitable),\n"
         . " nombre_pieces (T2→2, T3→3, sinon nb explicite), nombre_chambres, nombre_sdb,\n"
         . " etage (number, ex 3 pour 'au 3e étage'), ascenseur (bool), parking (bool), cave (bool), balcon_terrasse (bool),\n"
         . " dpe_class (A-G), dpe_ges (A-G), annee_construction (4 digits), neuf_ancien ('neuf' si vefa/neuf/récent, sinon 'ancien'),\n"
         . " types_bien (array : Villa|Appartement|Riad|Maison|Terrain|Commerce|Ferme|Bureau / plateau|Bâtiment industriel),\n"
         . " pays_bien (MA|FR|ES), ville_bien, quartier_bien, code_postal,\n"
         . " photos (array d'URL absolues https://… directement utilisables en <img src>, max 10 ; privilégier les versions HD et non-lazyload ; exclure placeholders/logos/bannières publicitaires),\n"
         . " annonceur_type ('professionnel' si JSON-LD offers.seller.@type=Organization OU agence/SARL/SAS dans nom, sinon 'particulier'),\n"
         . " annonceur_nom (raison sociale agence ou nom particulier),\n"
         . " annonceur_tel_mentionne (numéro tel UNIQUEMENT si écrit dans la description ou texte de l'annonce, format E.164 +33/+212 ; null si caché derrière bouton 'Contacter'),\n"
         . " annonceur_email_mentionne (email UNIQUEMENT si écrit dans le texte, sinon null),\n"
         . " annonceur_ville (si mentionnée pour l'annonceur)}\n\n"
         . "Règles :\n"
         . "- null si non trouvé. Convertis '500k' → 500000, '1,8M' → 1800000.\n"
         . "- description_complete = texte intégral du paragraphe descriptif, sans tronquer, sans markdown.\n"
         . "- annonceur_tel_mentionne / annonceur_email_mentionne : SEULEMENT si visible dans le texte de l'annonce. Beaucoup d'annonceurs mettent leur numéro dans la description pour contourner les boutons 'Contacter' anti-bot. Ne pas inventer.\n"
         . "- Pour pieces/chambres/sdb : un T3 = 3 pièces (séjour + 2 chambres typique), retourne 3 dans nombre_pieces.";
    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 2400,
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
        require_once __DIR__ . '/_security.php';
        // V18.39 — rate limit 30 / heure par user.
        checkRateLimit('import_url', 30, 3600, (int) $user['id']);
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
