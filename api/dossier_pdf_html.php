<?php
// M/2026/04/29/24 — Refonte complete maquette C 3 pages (cover mosaïque + descriptif
// editorial + fiche technique). Cormorant Garamond + DM Sans. Indicateur test si mode test.
// Cible : DossierPdfView (in-app viewer) + impression A4 (Philippe declenche manuellement
// via bouton Partager / Imprimer dans le viewer).
require_once __DIR__ . '/db.php';
$_isTestMode = (isset($_v20_mode) && $_v20_mode === 'test');

// M/2026/05/01/7 — Mode public via ?token=<32hex> : bypass auth, lookup shared_links V20,
// flags hide_* lus depuis DB (anti-contournement URL). Sinon flow agent authentifie standard.
$shareToken = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$_isShareView = false;
$_dbShareFlags = ['hide_price' => 0, 'hide_address' => 0, 'hide_identity' => 0];

if ($shareToken && strlen($shareToken) >= 32) {
    require_once __DIR__ . '/lib/router.php';
    try {
        $st = pdo_meta()->prepare(
            "SELECT * FROM shared_links WHERE token = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
        );
        $st->execute([$shareToken]);
        $linkRow = $st->fetch();
    } catch (Throwable $e) { $linkRow = null; }
    // M/2026/05/01/13 — single_use : si lien marque usage unique ET deja consomme, refuser.
    $isSingleUseConsumed = $linkRow && (int)($linkRow['single_use'] ?? 0) === 1 && !empty($linkRow['consumed_at']);
    if (!$linkRow || $isSingleUseConsumed) {
        http_response_code(410);
        $reason = $isSingleUseConsumed
            ? "Ce lien a usage unique a deja ete ouvert. Demandez un nouveau lien a l'agent."
            : "Ce lien n'est plus valide. Demandez un nouveau lien a l'agent.";
        echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1'><title>Lien expiré</title><link href='https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=DM+Sans:wght@400;600&display=swap' rel='stylesheet'></head>";
        echo "<body style='font-family:DM Sans,sans-serif;padding:60px 20px;text-align:center;background:#F0E8D8;color:#5C3B1E;margin:0;min-height:100vh'>";
        echo "<h1 style=\"font-family:'Cormorant Garamond',serif;font-size:36px;color:#8B5E3C;margin:0 0 12px;font-weight:700\">Lien expiré</h1>";
        echo "<p style='color:#8B7F6E;font-size:14px'>" . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</p>";
        echo "</body></html>";
        exit;
    }
    // Charge dossier dans la WSp source (mode agent puis fallback test).
    $slug_clean = preg_replace('/[^a-z0-9_-]/', '', $linkRow['wsp_slug']);
    $dossier = null;
    foreach (['', '_test'] as $suffix) {
        try {
            $pdoWsp = pdo_workspace('ocre_wsp_' . $slug_clean . $suffix);
            $d = $pdoWsp->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $d->execute([(int)$linkRow['dossier_id']]);
            $r = $d->fetch();
            if ($r) { $dossier = $r; if ($suffix === '_test') $_isTestMode = true; break; }
        } catch (Throwable $e) {}
    }
    if (!$dossier) { http_response_code(404); echo 'Dossier introuvable'; exit; }
    $dossierId = (int)$linkRow['dossier_id'];
    $_isShareView = true;
    $_dbShareFlags = [
        'hide_price'    => (int)($linkRow['hide_price'] ?? 0),
        'hide_address'  => (int)($linkRow['hide_address'] ?? 0),
        'hide_identity' => (int)($linkRow['hide_identity'] ?? 0),
    ];
    // Increment viewed_count + marque consumed_at au premier hit si single_use.
    try {
        if ((int)($linkRow['single_use'] ?? 0) === 1 && empty($linkRow['consumed_at'])) {
            pdo_meta()->prepare("UPDATE shared_links SET viewed_count = viewed_count + 1, last_viewed_at = NOW(), consumed_at = NOW() WHERE id = ?")->execute([$linkRow['id']]);
        } else {
            pdo_meta()->prepare("UPDATE shared_links SET viewed_count = viewed_count + 1, last_viewed_at = NOW() WHERE id = ?")->execute([$linkRow['id']]);
        }
    } catch (Throwable $e) {}
    // M/2026/05/01/13 — token court (8 premiers chars) pour watermark dynamique anti-fuite.
    $_shareTokenShort = substr($shareToken, 0, 8);
    $_shareCreatedAt = $linkRow['created_at'] ?? '';
    // Identite agent par defaut (lookup createur du lien).
    try {
        $au = pdo_meta()->prepare("SELECT email, display_name, telephone FROM users WHERE id = ? LIMIT 1");
        $au->execute([(int)$linkRow['created_by_user_id']]);
        $user = $au->fetch() ?: ['display_name' => '', 'email' => '', 'telephone' => ''];
    } catch (Throwable $e) {
        $user = ['display_name' => '', 'email' => '', 'telephone' => ''];
    }
} else {
    $user = requireAuth();
    $uid = (int) $user['id'];
    $dossierId = (int) ($_GET['dossier_id'] ?? 0);
    if (!$dossierId) { http_response_code(400); echo 'dossier_id requis'; exit; }
    $st = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$dossierId, $uid]);
    $dossier = $st->fetch();
    if (!$dossier) { http_response_code(404); echo 'Dossier introuvable'; exit; }
}

$data = json_decode($dossier['data'] ?? '{}', true) ?: [];
$bien = $data['bien'] ?? [];

// Identite agent
$agentName = trim($user['display_name'] ?? ($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
if (!$agentName) $agentName = $user['email'] ?? 'Agent Ocre';
$agentTel = $user['telephone'] ?? '';
$agentEmail = $user['email'] ?? '';

// M/2026/05/05/58 — destinataire personnalise du PDF (optionnel par bien). Dormant depuis M/63.
$destinataireNom = trim((string)($dossier['destinataire_nom'] ?? ''));
$destinataireEmail = trim((string)($dossier['destinataire_email'] ?? ''));
$_hasDestinataire = ($destinataireNom !== '' || $destinataireEmail !== '');

// M/2026/05/05/63 — bloc droite PDF P1+P2 = coordonnees du CLIENT (proprietaire/vendeur/bailleur).
// Source : colonnes structurees prenom/nom/societe_nom/tel/email de la table clients.
$_clientPrenom  = trim((string)($dossier['prenom'] ?? ''));
$_clientNom     = trim((string)($dossier['nom'] ?? ''));
$_clientSociete = trim((string)($dossier['societe_nom'] ?? ''));
$_clientTel     = trim((string)($dossier['tel'] ?? ''));
$_clientEmail   = trim((string)($dossier['email'] ?? ''));
$_clientFullName = trim($_clientPrenom . ' ' . $_clientNom);
$_hasClient = ($_clientFullName !== '' || $_clientSociete !== '' || $_clientTel !== '' || $_clientEmail !== '');
function _renderClientLines(string $name, string $societe, string $tel, string $email): string {
    $lines = [];
    if ($name !== '' && $societe !== '') {
        $lines[] = htmlspecialchars($name);
        $lines[] = htmlspecialchars($societe);
    } elseif ($name !== '') {
        $lines[] = htmlspecialchars($name);
    } elseif ($societe !== '') {
        $lines[] = htmlspecialchars($societe);
    }
    if ($tel !== '')   $lines[] = htmlspecialchars($tel);
    if ($email !== '') $lines[] = htmlspecialchars($email);
    return implode('<br>', $lines);
}

// Photos : 1) liste depuis bien.photos JSON (URLs externes Unsplash etc.), 2) sinon glob /uploads/<id>/.
$photos = [];
if (!empty($bien['photos']) && is_array($bien['photos'])) {
    foreach ($bien['photos'] as $p) {
        if (is_string($p) && $p !== '') $photos[] = $p;
        elseif (is_array($p) && !empty($p['url'])) $photos[] = $p['url'];
    }
}
if (!$photos) {
    $photosDir = '/opt/ocre-app/uploads/' . $dossierId;
    if (is_dir($photosDir)) {
        foreach (glob($photosDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $p) {
            if (strpos($p, '_thumb.') !== false) continue;
            $photos[] = '/uploads/' . $dossierId . '/' . basename($p);
        }
    }
}
// M/2026/05/05/15 — M-Photos-Modal-Live : filtre hide_photos[] (URLs ou UUIDs masques par l agent dans PhotosSheet).
$_hidePhotos = [];
if (!empty($_GET['hide_photos'])) {
    $raw = $_GET['hide_photos'];
    if (is_array($raw)) $_hidePhotos = array_values(array_filter(array_map('strval', $raw)));
    elseif (is_string($raw) && $raw !== '') $_hidePhotos = array_values(array_filter(array_map('trim', explode(',', $raw))));
}
if ($_hidePhotos) {
    $photos = array_values(array_filter($photos, fn($p) => !in_array($p, $_hidePhotos, true)));
}
// M/2026/04/29/29 — pas de doublon : page 1 cover utilise photos[0..4], page 2 editorial utilise photos[5..7].
// Si moins de 5 photos : cases vides en placeholder. Si moins de 8 photos : column photos page 2 retrecit ou disparait.
$photoMain = $photos[0] ?? null;
$photoSmall = array_slice($photos, 1, 4);
while (count($photoSmall) < 4) $photoSmall[] = null;
$photoEditorial = array_slice($photos, 5, 3);  // photos 5,6,7 (uniquement si dispos, pas de doublon avec cover)
$_hasEditorialPhotos = count($photoEditorial) > 0;
while (count($photoEditorial) < 3) $photoEditorial[] = null;

// Helpers
function h($v): string {
    if ($v === null || $v === '' || $v === []) return '—';
    if (is_array($v)) return htmlspecialchars(implode(', ', array_map('strval', $v)));
    return htmlspecialchars((string) $v);
}
function hOrEmpty($v): string {
    if ($v === null || $v === '' || $v === []) return '';
    if (is_array($v)) return htmlspecialchars(implode(', ', array_map('strval', $v)));
    return htmlspecialchars((string) $v);
}
function fmtNum($v): string {
    if ($v === null || $v === '') return '—';
    $n = (float) preg_replace('/[^\d.,-]/', '', str_replace(',', '.', (string) $v));
    if ($n == 0) return htmlspecialchars((string) $v);
    return number_format($n, 0, ',', ' ');
}
function bullets(array $arr, string $sep = ' · '): string {
    $arr = array_filter($arr, fn($x) => $x !== null && $x !== '');
    if (!$arr) return '—';
    return implode($sep, array_map('htmlspecialchars', $arr));
}

// M/2026/04/29/29 — Schema-fallback : tolere ancien (surface, chambres, etat) ET nouveau
// (surface_hab, chambres_v2, etat_general). Lecture chainee, premier non-vide.
function _pick($arr, ...$keys) {
    foreach ($keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null) return $arr[$k];
    }
    return null;
}

// Type bien (multi-types support : prend le premier de types[] ou le legacy 'type').
$typeBien = _pick($bien, 'type');
if (!$typeBien && !empty($bien['types']) && is_array($bien['types'])) $typeBien = $bien['types'][0] ?? null;

// Donnees bien
$titreBien = $bien['titre'] ?? '';
$chambres = _pick($bien, 'chambres_v2', 'chambres');
$piscine = _pick($bien, 'type_piscine');
// Schema legacy : equipements peut etre un objet (Riad : patio/plunge_pool/...) plutot qu un array de strings.
$equipementsRaw = $bien['equipements'] ?? null;
$_hasPiscineLegacy = is_array($equipementsRaw) && !empty($equipementsRaw['plunge_pool']);
if (!$titreBien) {
    $piecesPart = $chambres ? ($chambres . ' chambres') : '';
    $piscinePart = (($piscine && $piscine !== 'Aucune') || $_hasPiscineLegacy) ? '& piscine' : '';
    $titreBien = trim(($typeBien ?: 'Bien') . ' ' . $piecesPart . ' ' . $piscinePart);
}
$ville = trim(($bien['ville'] ?? '') . (!empty($bien['quartier']) ? ' — ' . $bien['quartier'] : ''));

$prix = $data['prix_affiche'] ?? $data['prix'] ?? null;
// M/2026/05/04/11 — devise = code ISO 3 lettres texte ('EUR'/'MAD'/'USD'/'GBP'/'AED'/'CHF').
// Glyph SVG/Unicode supprime franchement. Format : "{montant} {ISO}".
// Mapping legacy : si data['devise'] vaut un glyph, normaliser en code ISO.
$devise = $data['devise'] ?? 'EUR';
if ($devise === 'EUR')  { $devise = 'EUR'; }
if ($devise === 'MAD' || $devise === 'د.م.') { $devise = 'MAD'; }
if ($devise === '$')  { $devise = 'USD'; }
if ($devise === '£')  { $devise = 'GBP'; }
if ($devise === 'د.إ') { $devise = 'AED'; }
$_nativeDevise = $devise;
// M/2026/05/04/13 — override devise via GET ?currency=XXX (preview agent live picker).
$_devOverride = preg_replace('/[^A-Z]/', '', strtoupper((string)($_GET['currency'] ?? '')));
if (in_array($_devOverride, ['EUR','MAD','USD','GBP','AED','CHF'], true)) { $devise = $_devOverride; }
// M/2026/05/04/15 — conversion FX EUR pivot. Taux alignes sur RATES_OFFICIAL_V5 (index.html ligne 7298).
$_FX_VS_EUR = ['EUR' => 1.00, 'MAD' => 10.84, 'USD' => 1.08, 'GBP' => 0.857, 'AED' => 3.97, 'CHF' => 0.93];
function _ocre_fx_convert($amount, $from, $to, $rates) {
    if ($from === $to || !is_numeric($amount)) return $amount;
    if (!isset($rates[$from]) || !isset($rates[$to])) return $amount;
    $eur = ((float)$amount) / $rates[$from];
    return $eur * $rates[$to];
}
if ($prix !== null && is_numeric($prix) && $devise !== $_nativeDevise) {
    $prix = _ocre_fx_convert((float)$prix, $_nativeDevise, $devise, $_FX_VS_EUR);
    $prix = (int) round($prix);
}
$honoraires = $data['honoraires_inclus'] ?? true;
// M/2026/04/29/38 — toggle Prix / Sur demande embedded URL ?mode=price|demand.
$shareMode = ($_GET['mode'] ?? 'price') === 'demand' ? 'demand' : 'price';
$prixDemand = ($shareMode === 'demand');
// M/2026/04/30/8 — masquages selectifs partage : prix / adresse / identite agent referent.
// M/2026/05/01/7 — en mode share view (?token=), les flags viennent de DB row (anti-contournement
// URL). Sinon mode preview agent : URL params autorises.
if ($_isShareView) {
    $hidePrice    = !empty($_dbShareFlags['hide_price']);
    $hideAddress  = !empty($_dbShareFlags['hide_address']);
    $hideIdentity = !empty($_dbShareFlags['hide_identity']);
} else {
    $hidePrice    = !empty($_GET['hide_price']);
    $hideAddress  = !empty($_GET['hide_address']);
    $hideIdentity = !empty($_GET['hide_identity']);
}
if ($hidePrice) $prixDemand = true;

$ref = sprintf('OCR-%06d', (int) $dossier['id']);
$shareBase = 'https://app.ocre.immo/share/';

// Surfaces (legacy : 'surface'/'surface_terrain' ; nouveau : 'surface_hab'/'surface_terrain_v2').
$surfaceHab = _pick($bien, 'surface_hab', 'surface');
$surfaceTerrain = _pick($bien, 'surface_terrain_v2', 'surface_terrain');
$surfaceTerrasse = _pick($bien, 'surface_terrasse');
$surfaceJardin = _pick($bien, 'surface_jardin');
$surfaceGarage = _pick($bien, 'surface_garage');
// Si equipements legacy contient roof_terrasse/patio numerique, l'inclure dans terrasses.
if ($surfaceTerrasse === null && is_array($equipementsRaw)) {
    $tt = (float) ($equipementsRaw['roof_terrasse'] ?? 0) + (float) ($equipementsRaw['patio'] ?? 0);
    if ($tt > 0) $surfaceTerrasse = $tt;
}

// Caracteristiques (legacy : 'sdb', 'etat', 'etage' ; nouveau : 'sdb_v2', 'etat_general', 'etage_v2').
$pieces = _pick($bien, 'pieces_count', 'pieces');
$sdb = _pick($bien, 'sdb_v2', 'sdb');
$etages = _pick($bien, 'etage_v2', 'etage', 'etages');
$exposition = _pick($bien, 'exposition');
if (!$exposition && is_array($equipementsRaw) && !empty($equipementsRaw['exposition'])) {
    $exposition = $equipementsRaw['exposition'];
}
$vues = is_array($bien['vue'] ?? null) ? $bien['vue'] : [];
if (!$vues && is_array($equipementsRaw) && !empty($equipementsRaw['vue'])) {
    $vues = is_array($equipementsRaw['vue']) ? $equipementsRaw['vue'] : [$equipementsRaw['vue']];
}
$etat = _pick($bien, 'etat_general', 'etat');
$dispoStatut = $bien['dispo_statut'] ?? null;
$dispoDate = $bien['dispo_date'] ?? null;

// Equipements : array nouveau OU object legacy a aplatir en chaines lisibles.
$equipementsConfort = is_array($bien['equipements_confort'] ?? null) ? $bien['equipements_confort'] : [];
if (!$equipementsConfort && is_array($equipementsRaw)) {
    $labelsLegacy = [
        'climatisation' => 'Climatisation',
        'meuble' => 'Meublé',
        'plunge_pool' => 'Plunge pool',
        'roof_terrasse' => 'Terrasse en toit',
        'patio' => 'Patio',
        'cheminee' => 'Cheminée',
        'fibre' => 'Internet fibre',
        'ascenseur' => 'Ascenseur',
        'hammam' => 'Hammam',
        'spa' => 'Spa',
    ];
    foreach ($equipementsRaw as $k => $v) {
        if ($v === false || $v === null || $v === '' || $v === 0) continue;
        $lbl = $labelsLegacy[$k] ?? ucfirst(str_replace('_', ' ', (string) $k));
        if (is_numeric($v) && !in_array($k, ['plunge_pool', 'climatisation', 'meuble'], true)) {
            $lbl .= ' (' . $v . ')';
        }
        $equipementsConfort[] = $lbl;
    }
}
$amenagementsExt = is_array($bien['amenagements_ext'] ?? null) ? $bien['amenagements_ext'] : [];
$securite = is_array($bien['securite'] ?? null) ? $bien['securite'] : [];
$proximites = is_array($bien['proximites'] ?? null) ? $bien['proximites'] : [];

$adresse = trim(($bien['adresse'] ?? '') . ' ' . ($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));

$descriptifLead = $bien['descriptif_lead'] ?? $bien['lead'] ?? '';
$descriptifTexte = $bien['descriptif'] ?? $bien['descriptif_texte'] ?? $bien['remarques_bien'] ?? '';

// Honoraires : si legacy 'charges' ou 'commission' présents, surface dans une note.
$chargesNote = _pick($data, 'charges') ?: _pick($bien, 'charges');

// M/2026/05/05/52 — pdf_editor_state : override valeurs + visibility par bloc.
// Structure : data.bien.pdf_editor_state.blocks.{key}.{visible,value,...}.
$_pdfEditorState = $bien['pdf_editor_state']['blocks'] ?? [];
function _pdfBlockVisible(array $state, string $key): bool {
    if (!isset($state[$key])) return true;
    $v = $state[$key]['visible'] ?? true;
    return $v !== false;
}
function _pdfBlockValue(array $state, string $key, string $default): string {
    if (!isset($state[$key])) return $default;
    $v = $state[$key]['value'] ?? null;
    if (is_string($v) && $v !== '') return $v;
    return $default;
}
// Override les variables de rendu si pdf_editor_state contient une value.
$titreBien       = _pdfBlockValue($_pdfEditorState, 'title', $titreBien);
$descriptifLead  = _pdfBlockValue($_pdfEditorState, 'descriptif_lead', (string)$descriptifLead);
$descriptifTexte = _pdfBlockValue($_pdfEditorState, 'descriptif_texte', (string)$descriptifTexte);
// Agent : si bloc explicite override.
if (isset($_pdfEditorState['agent'])) {
    $a = $_pdfEditorState['agent'];
    if (!empty($a['name']))  $agentName  = (string) $a['name'];
    if (isset($a['phone']))  $agentTel   = (string) $a['phone'];
    if (isset($a['email']))  $agentEmail = (string) $a['email'];
}
// Prix : override amount + currency.
if (isset($_pdfEditorState['price'])) {
    $pr = $_pdfEditorState['price'];
    if (isset($pr['amount']) && is_numeric($pr['amount'])) $prix = (float) $pr['amount'];
    if (!empty($pr['currency']) && is_string($pr['currency'])) $devise = (string) $pr['currency'];
}
function _pdfEdAttr(string $key, array $state): string {
    $hidden = !_pdfBlockVisible($state, $key) ? ' data-pdf-hidden="1"' : '';
    return ' data-editable="' . h($key) . '"' . $hidden;
}

// M/2026/05/05/61 — selection pages PDF (P1/P2/P3 toggle persistant).
$_pdfPagesState = $bien['pdf_editor_state']['pages'] ?? ['p1' => true, 'p2' => true, 'p3' => true];
$_pdfPagesState = ['p1' => !isset($_pdfPagesState['p1']) || !empty($_pdfPagesState['p1']),
                   'p2' => !isset($_pdfPagesState['p2']) || !empty($_pdfPagesState['p2']),
                   'p3' => !isset($_pdfPagesState['p3']) || !empty($_pdfPagesState['p3'])];
$_isPdfPreview = !empty($_GET['preview']);
$_realActivePages = array_keys(array_filter($_pdfPagesState));
if (count($_realActivePages) === 0) $_realActivePages = ['p1'];
$_totalActivePages = count($_realActivePages);
function _pdfPageActive(array $state, string $key): bool { return ($state[$key] ?? true) !== false; }
function _pdfPageNum(array $real, string $key): int {
    $i = array_search($key, $real, true);
    return $i === false ? 0 : (int)$i + 1;
}
function _pdfPageDisabledAttr(array $state, string $key, bool $isPreview): string {
    if (!$isPreview) return '';
    return _pdfPageActive($state, $key) ? '' : ' data-page-disabled="1"';
}
// Helper : numero affiche (ou tiret) selon active.
function _pdfPageNumLabel(array $real, string $key): string {
    $n = _pdfPageNum($real, $key);
    return $n > 0 ? (string)$n : '—';
}

// M/2026/05/05/62 — helpers gracieux : skip rows + sections vides en P2.
function _isEmptyVal($v): bool {
    if ($v === null || $v === '' || $v === '—') return true;
    if (is_array($v)) return count($v) === 0;
    if (is_string($v) && trim($v) === '') return true;
    return false;
}
function _renderRow(string $lab, $val): string {
    if (_isEmptyVal($val)) return '';
    if (is_array($val)) $val = bullets($val, ', ');
    return '<div class="row"><span class="lab">' . htmlspecialchars($lab) . '</span><b>' . htmlspecialchars((string)$val) . '</b></div>';
}
// Variante : valeur deja formatee HTML (ex: nombre + unite avec htmlspecialchars).
function _renderRowHtml(string $lab, string $valHtml): string {
    if (trim($valHtml) === '' || trim($valHtml) === '—') return '';
    return '<div class="row"><span class="lab">' . htmlspecialchars($lab) . '</span><b>' . $valHtml . '</b></div>';
}
function _renderSection(string $title, string $rowsHtml): string {
    if (trim($rowsHtml) === '') return '';
    return '<div class="blk"><h5>' . htmlspecialchars($title) . '</h5>' . $rowsHtml . '</div>';
}
// Yes-only helper (ne retourne null si absent pour pouvoir skip la row).
function _yesIfIn(array $arr, string $needle): ?string {
    return in_array($needle, $arr, true) ? 'Oui' : null;
}

// M/2026/05/05/65 — col3 P1 adaptative selon type bien. Retourne [title, rowsHtml] graceful empty.
function _pdfP1Col3(array $bien, $typeBien): array {
    $type = strtolower(trim((string) $typeBien));
    $rows = [];
    if (in_array($type, ['villa', 'maison', 'riad'], true)) {
        $rows = [
            ['Chambres',       _pick($bien, 'chambres_v2', 'chambres')],
            ['Salles de bain', _pick($bien, 'sdb_v2', 'sdb')],
            ['Parking',        _pick($bien, 'nombre_places_parking', 'parking')],
        ];
        return ['ESPACES', $rows];
    }
    if ($type === 'appartement') {
        $rows = [
            ['Chambres',       _pick($bien, 'chambres_v2', 'chambres')],
            ['Salles de bain', _pick($bien, 'sdb_v2', 'sdb')],
            ['Étage',          _pick($bien, 'etage_v2', 'etage', 'etages')],
        ];
        return ['ESPACES', $rows];
    }
    if ($type === 'terrain') {
        $surfT = _pick($bien, 'surface_terrain_v2', 'surface_terrain');
        $rows = [
            ['Surface',       $surfT ? fmtNum($surfT) . ' m²' : null],
            ['Constructible', _pick($bien, 'terrain_constructible', 'constructible')],
            ['Façade rue',    _pick($bien, 'facade_rue')],
        ];
        return ['TERRAIN', $rows];
    }
    if (in_array($type, ['local', 'bureau', 'commerce', 'commercial'], true)) {
        $surfL = _pick($bien, 'surface_local', 'surface_hab', 'surface');
        $rows = [
            ['Surface', $surfL ? fmtNum($surfL) . ' m²' : null],
            ['Pièces',  _pick($bien, 'pieces_count', 'pieces')],
            ['Parking', _pick($bien, 'nombre_places_parking', 'parking')],
        ];
        return ['LOCAL', $rows];
    }
    // Fallback : CONFORT (comportement legacy)
    $climOk = is_array($bien['equipements_confort'] ?? null) && in_array('Climatisation', $bien['equipements_confort'], true);
    $climOk = $climOk || (is_array($bien['equipements'] ?? null) && !empty($bien['equipements']['climatisation']));
    $secList = is_array($bien['securite'] ?? null) ? $bien['securite'] : [];
    $rows = [
        ['Clim.',    $climOk ? 'Oui' : null],
        ['Sécurité', !empty($secList) ? bullets(array_slice($secList, 0, 1), '') : null],
        ['Cuisine',  _pick($bien, 'cuisine')],
    ];
    return ['CONFORT', $rows];
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Carnet de bien · <?= h($titreBien) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&family=Cormorant+Garamond:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500;700&family=Caveat:wght@400;500&display=swap" rel="stylesheet">
<style>
  /* M/2026/05/05/59 — Belles Demeures Variante A. Palette or + ivoire. */
  :root {
    --gold: #C9A961;
    --gold-deep: #8B6F35;
    --ivory: #F8F2E4;
    --cream-warm: #EAD9B6;
    --ink: #2A2018;
    --mute: #9A8B7C;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: linear-gradient(180deg, #F8F2E4 0%, #EAD9B6 100%);
    color: var(--ink);
    font-family: "Bodoni Moda", "Cormorant Garamond", Georgia, serif;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
  }
  .micro { font-family: "DM Sans", "Helvetica Neue", Arial, sans-serif; }

  /* Pages container : aspect A4 portrait. Print : page break apres chaque section. */
  .pages { display: flex; flex-direction: column; align-items: center; padding: 18px 0; gap: 22px; }
  .page {
    background: linear-gradient(180deg, #F8F2E4 0%, #EAD9B6 100%);
    width: 210mm;
    min-height: 297mm;
    max-height: 297mm;
    box-shadow: 0 6px 24px rgba(60, 40, 20, .14), 0 0 0 .5px rgba(139, 111, 53, .25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    page-break-after: always;
    position: relative;
  }
  @media print {
    body { background: #fff; }
    .pages { padding: 0; gap: 0; }
    .page { box-shadow: none; margin: 0; }
  }

  /* Ribbon header commun */
  .ribbon { padding: 11px 0 8px; border-bottom: .5px solid var(--gold-deep); text-align: center; flex: none; }
  .ribbon .b { font-style: italic; font-size: 14.5px; letter-spacing: 5px; text-transform: uppercase; color: var(--gold-deep); font-weight: 500; }
  .ribbon .small { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--gold-deep); margin-top: 3px; font-weight: 500; }
  .ribbon .ref { font-style: italic; font-size: 9.5px; letter-spacing: 1.5px; color: var(--gold-deep); margin-top: 3px; }

  /* Photos style commun */
  .ph {
    aspect-ratio: 3/2;
    border-radius: 5px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(60, 40, 20, .18), 0 0 0 .75px rgba(139, 111, 53, .55);
    background: #2A2018;
    position: relative;
  }
  .ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .ph::after {
    content: ""; position: absolute; inset: 0; pointer-events: none;
    border: 1px solid rgba(255, 255, 255, .18); border-radius: 5px;
  }

  /* ============================ PAGE 1 ============================ */
  .pg {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    grid-template-rows: 1fr 1fr 1fr;
    gap: 5px;
    padding: 7px 16px 0;
    aspect-ratio: 3/2;
    flex: none;
  }
  .pg .hero { grid-row: 1 / span 2; grid-column: 1 / span 2; }

  .body { padding: 11px 22px 12px; display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
  .kicker { font-style: italic; font-size: 11.5px; letter-spacing: 3px; text-transform: uppercase; color: var(--gold-deep); text-align: center; margin: 0 0 3px; }
  .title { font-style: italic; font-size: 29px; line-height: 1.05; color: var(--ink); text-align: center; margin: 0 0 4px; font-weight: 500; }
  .place { font-size: 12px; letter-spacing: 5px; text-transform: uppercase; color: var(--gold-deep); font-weight: 500; text-align: center; margin: 0 0 8px; }
  .lead { font-style: italic; font-size: 13px; line-height: 1.5; color: var(--ink); text-align: justify; margin: 0 0 9px; }
  .lead::first-letter { font-size: 34px; float: left; line-height: .85; margin: 3px 6px 0 0; font-weight: 500; color: var(--gold-deep); font-style: normal; }

  .cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; border-top: .5px solid var(--gold-deep); border-bottom: .5px solid var(--gold-deep); padding: 7px 0; margin-bottom: 9px; }
  .cols h4 { font-size: 10.5px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--gold-deep); font-weight: 500; text-align: center; margin: 0 0 4px; }
  .cols .row { font-style: italic; font-size: 12px; color: var(--ink); padding: 1.5px 0; display: flex; gap: 6px; align-items: baseline; }
  .cols .row .lab { flex: none; color: var(--mute); font-style: italic; }
  .cols .row b { font-style: normal; font-weight: 500; color: var(--gold-deep); }

  .foot { display: grid; grid-template-columns: 1fr auto 1fr; gap: 14px; align-items: center; margin-top: auto; padding-top: 8px; flex: none; }
  .foot .agent { font-style: italic; font-size: 11px; line-height: 1.4; text-align: left; }
  .foot .agent .lab, .foot .client .lab { display: block; font-style: normal; font-weight: 500; font-size: 9.5px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--gold-deep); margin-bottom: 2px; }
  .foot .price { text-align: center; border: 1.5px solid var(--gold-deep); padding: 8px 14px; background: rgba(232, 197, 117, .1); border-radius: 4px; font-style: italic; }
  .foot .price .pre { font-style: italic; font-size: 8.5px; letter-spacing: 2px; text-transform: uppercase; color: var(--gold-deep); margin-bottom: 3px; }
  .foot .price b { display: block; font-style: normal; font-weight: 500; color: var(--gold-deep); font-size: 24px; line-height: 1; }
  .foot .price small { display: block; font-style: normal; font-size: 8.5px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--gold-deep); margin-top: 3px; }
  .foot .client { font-style: italic; font-size: 11px; line-height: 1.4; text-align: right; }
  /* M/2026/05/05/65 — footer rigide : visibility hidden au lieu de display none pour preserver le grid 1fr auto 1fr. */
  .foot .client.empty { visibility: hidden; }

  /* ============================ PAGE 2 ============================ */
  .body-p2 { padding: 14px 22px 12px; display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
  .h2-title { font-style: italic; font-size: 25px; text-align: center; margin: 0 0 3px; font-weight: 500; }
  .h2-sub { font-size: 11px; letter-spacing: 5px; text-transform: uppercase; color: var(--gold-deep); font-weight: 500; text-align: center; margin: 0 0 8px; }
  .h2-rule { height: .5px; background: var(--gold-deep); width: 50%; margin: 0 auto 10px; }
  .grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 22px; flex: 1 1 auto; align-content: start; }
  .blk h5 { font-size: 11px; letter-spacing: 2.8px; text-transform: uppercase; color: var(--gold-deep); font-weight: 500; margin: 0 0 4px; border-bottom: .5px solid var(--gold-deep); padding-bottom: 3px; }
  .blk .row { font-style: italic; font-size: 11.5px; display: flex; gap: 8px; padding: 1.5px 0; align-items: baseline; }
  .blk .row .lab { flex: none; color: var(--mute); }
  .blk .row b { font-style: normal; font-weight: 500; color: var(--gold-deep); }
  .blk-full { grid-column: 1 / span 2; }

  .foot-p2 { display: grid; grid-template-columns: 1fr auto 1fr; gap: 14px; align-items: center; padding-top: 9px; margin-top: 10px; border-top: .5px solid var(--gold-deep); flex: none; }
  .foot-p2 .agent { font-style: italic; font-size: 11px; line-height: 1.4; text-align: left; }
  .foot-p2 .agent .lab, .foot-p2 .client .lab { display: block; font-style: normal; font-weight: 500; font-size: 9.5px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--gold-deep); margin-bottom: 2px; }
  .foot-p2 .pgnum { font-style: italic; font-size: 10.5px; color: var(--gold-deep); letter-spacing: 2.5px; text-transform: uppercase; font-weight: 500; text-align: center; }
  .foot-p2 .client { font-style: italic; font-size: 11px; line-height: 1.4; text-align: right; }
  .foot-p2 .client.empty { visibility: hidden; }

  /* ============================ PAGE 3 ============================ */
  /* M/2026/05/05/60 — Album P3 : 15 photos en 5 rangees, sans titres intermediaires. Header compacte. */
  .alb-head { padding: 6px 0 4px; text-align: center; flex: none; }
  .alb-head .h { font-style: italic; font-size: 18px; color: var(--ink); letter-spacing: .4px; margin: 0 0 2px; font-weight: 500; }
  .alb-head .s { font-size: 9px; letter-spacing: 4px; text-transform: uppercase; color: var(--gold-deep); font-weight: 500; }

  .body-alb { padding: 5px 14px 8px; display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; gap: 4px; overflow: hidden; }
  .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
  .grid-3 .ph { position: relative; }
  .grid-3 .ph[data-cap]::before {
    content: attr(data-cap); position: absolute; left: 6px; bottom: 5px; color: #fff;
    font-style: italic; font-size: 10px; letter-spacing: 1.2px; text-shadow: 0 1px 3px rgba(0, 0, 0, .5);
    z-index: 2; text-transform: uppercase;
  }
  .footer-alb { padding-top: 7px; margin-top: auto; text-align: center; font-style: italic; font-size: 10.5px; color: var(--gold-deep); border-top: .5px solid var(--gold-deep); letter-spacing: 1px; flex: none; }

  /* Editor inline (M/52, M/56, M/57) — placeholder neutre, styles injectes en JS cote frontend */
  [data-editable] { transition: outline-color .15s, background .15s; }

  /* M/2026/05/05/61 — pages exclues du PDF final : grise + filet barre + label en preview. @media print : display none. */
  .page[data-page-disabled="1"] {
    position: relative;
    opacity: 0.35;
    filter: grayscale(.7);
  }
  .page[data-page-disabled="1"]::after {
    content: "Page exclue du PDF";
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    color: #4B5563; font-style: italic; font-size: 18px; font-weight: 600;
    background: repeating-linear-gradient(45deg, rgba(75,85,99,0.04) 0 12px, rgba(75,85,99,0.10) 12px 24px);
    pointer-events: none; z-index: 100;
  }
  @media print { .page[data-page-disabled="1"] { display: none; } }
  /* M/2026/05/05/64 — blocs masques (visible=false via pastille oeil M/57) absents du PDF final imprime. */
  @media print { [data-pdf-hidden="1"] { display: none !important; } }
  /* M/2026/05/05/65 — footer rigide P1+P2 : preservation flow grid via visibility:hidden. */
  @media print {
    .foot .client[data-pdf-hidden="1"], .foot .agent[data-pdf-hidden="1"],
    .foot-p2 .client[data-pdf-hidden="1"], .foot-p2 .agent[data-pdf-hidden="1"] {
      display: block !important; visibility: hidden !important;
    }
  }
  /* M/2026/05/05/65 — medaille "Prix a la demande" (visible=false) : meme cadre or, contenu different. */
  .price.price-on-request b { font-size: 22px; font-style: italic; }

  /* Banner confidentiel partage (legacy preserve) */
  .ocre-confidential-banner {
    position: fixed; bottom: 0; left: 0; right: 0; padding: 8px 16px;
    background: rgba(42, 32, 24, .92); color: #F4EDDC; text-align: center;
    font-family: "DM Sans", sans-serif; font-size: 10.5px; letter-spacing: .5px; line-height: 1.5;
    z-index: 9999; backdrop-filter: blur(8px);
  }
  @media print { .ocre-confidential-banner { display: none; } }
</style>
</head>
<body>
<div class="pages">

  <?php if ($_isPdfPreview || _pdfPageActive($_pdfPagesState, 'p1')): ?>
  <!-- ====================== PAGE 1 — Belles Demeures Variante A ====================== -->
  <section class="page" data-page="1"<?= _pdfPageDisabledAttr($_pdfPagesState, 'p1', $_isPdfPreview) ?>>
    <header class="ribbon">
      <div class="b">OCRE Immo</div>
      <div class="small">Marrakech &middot; Gueliz</div>
      <div class="ref">Réf. <?= h($ref) ?> &middot; Page de présentation</div>
    </header>

    <div class="pg">
      <div class="ph hero"><?php if (!empty($photos[0])): ?><img src="<?= h($photos[0]) ?>" alt=""><?php endif; ?></div>
      <div class="ph"><?php if (!empty($photos[1])): ?><img src="<?= h($photos[1]) ?>" alt=""><?php endif; ?></div>
      <div class="ph"><?php if (!empty($photos[2])): ?><img src="<?= h($photos[2]) ?>" alt=""><?php endif; ?></div>
      <div class="ph"><?php if (!empty($photos[3])): ?><img src="<?= h($photos[3]) ?>" alt=""><?php endif; ?></div>
      <div class="ph"><?php if (!empty($photos[4])): ?><img src="<?= h($photos[4]) ?>" alt=""><?php endif; ?></div>
      <div class="ph"><?php if (!empty($photos[5])): ?><img src="<?= h($photos[5]) ?>" alt=""><?php endif; ?></div>
    </div>

    <div class="body">
      <div class="kicker">— Une demeure de prestige —</div>
      <h1 class="title"<?= _pdfEdAttr('title', $_pdfEditorState) ?>><?= h($titreBien) ?></h1>
      <div class="place"<?= _pdfEdAttr('subtitle', $_pdfEditorState) ?>><?= h(_pdfBlockValue($_pdfEditorState, 'subtitle', $ville ?: 'Marrakech')) ?></div>

      <p class="lead"<?= _pdfEdAttr('descriptif_lead', $_pdfEditorState) ?>><?php
        $_lead = $descriptifLead;
        if (!$_lead) {
            $_lead = ($titreBien ? $titreBien : 'Cette demeure') . ' niché' . ($titreBien ? 'e' : 'e') . ' à ' . ($ville ?: 'Marrakech') . '. Volumes généreux, prestations de standing, jardin paysager. Une adresse rare pour qui cherche le confort d\'une vie entre médina et palmeraie.';
        }
        echo nl2br(h($_lead));
      ?></p>

      <div class="cols">
        <div class="col">
          <h4>Identité</h4>
          <div class="row"><span class="lab">Type</span><b><?= h($typeBien) ?></b></div>
          <div class="row"><span class="lab">État</span><b><?= h($etat) ?></b></div>
          <div class="row"><span class="lab">Vue</span><b><?= h($vues ? bullets($vues, ', ') : ($exposition ?: '—')) ?></b></div>
        </div>
        <div class="col">
          <h4>Surfaces</h4>
          <div class="row"><span class="lab">Habitable</span><b><?= $surfaceHab ? fmtNum($surfaceHab) . ' m²' : '—' ?></b></div>
          <div class="row"><span class="lab">Terrain</span><b><?= $surfaceTerrain ? fmtNum($surfaceTerrain) . ' m²' : '—' ?></b></div>
          <div class="row"><span class="lab">Terrasses</span><b><?= $surfaceTerrasse ? fmtNum($surfaceTerrasse) . ' m²' : '—' ?></b></div>
        </div>
        <?php
        // M/2026/05/05/65 — col3 P1 adaptative selon type bien (ESPACES/TERRAIN/LOCAL/CONFORT).
        list($_col3Title, $_col3Rows) = _pdfP1Col3($bien, $typeBien);
        ?>
        <div class="col">
          <h4><?= htmlspecialchars($_col3Title) ?></h4>
          <?php foreach ($_col3Rows as $r): list($lab, $val) = $r; if (_isEmptyVal($val)) continue; ?>
          <div class="row"><span class="lab"><?= htmlspecialchars($lab) ?></span><b><?= htmlspecialchars((string) $val) ?></b></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="foot">
        <div class="agent">
          <span class="lab">Agent référent</span>
          <?php if ($hideIdentity): ?>
            <span style="color: var(--mute); font-style: italic;">Coordonnées sur demande</span>
          <?php else: ?>
            <span<?= _pdfEdAttr('agent', $_pdfEditorState) ?>>
              <span data-agent-field="name"><?= h($agentName) ?></span><?php if ($agentTel): ?> &middot; <span data-agent-field="phone"><?= h($agentTel) ?></span><?php endif; ?>
            </span>
          <?php endif; ?>
        </div>

        <?php
        // M/2026/05/05/65 — pastille oeil prix masquee (visible=false) -> medaille "Prix a la demande"
        // au lieu du chiffre. data-editable preserve mais data-pdf-hidden NON ajoute (le bloc reste
        // visible dans le PDF final, juste avec contenu different).
        $_priceVisible = _pdfBlockVisible($_pdfEditorState, 'price');
        ?>
        <div class="price<?= !$_priceVisible ? ' price-on-request' : '' ?>" data-editable="price">
          <?php if (!$_priceVisible): ?>
            <div class="pre">PRIX</div>
            <b>à la demande</b>
          <?php elseif ($prix && !$prixDemand): ?>
            <div class="pre">prix de présentation</div>
            <b><?= htmlspecialchars(fmtNum($prix)) ?> <?= htmlspecialchars((string)$devise, ENT_QUOTES | ENT_HTML5, "UTF-8") ?></b>
            <?php if ($honoraires): ?><small>Honoraires inclus</small><?php endif; ?>
          <?php else: ?>
            <div class="pre">prix de présentation</div>
            <b style="font-size: 18px; font-style: italic;">Sur demande</b>
          <?php endif; ?>
        </div>

        <div class="client<?= $_hasClient ? '' : ' empty' ?>"<?= _pdfEdAttr('client', $_pdfEditorState) ?>>
          <?php if ($_hasClient): ?>
            <span class="lab">Client</span>
            <?= _renderClientLines($_clientFullName, $_clientSociete, $_clientTel, $_clientEmail) ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php /* PAGE 1 fermee dans la balise </section> ci-dessus. */ ?>
  <?php endif; /* P1 active ou preview */ ?>

  <?php if ($_isPdfPreview || _pdfPageActive($_pdfPagesState, 'p2')): ?>
  <!-- ====================== PAGE 2 — Caractéristiques détaillées ====================== -->
  <section class="page" data-page="2"<?= _pdfPageDisabledAttr($_pdfPagesState, 'p2', $_isPdfPreview) ?>>
    <header class="ribbon">
      <div class="b">OCRE Immo</div>
      <div class="small">Marrakech &middot; Gueliz</div>
      <div class="ref">Réf. <?= h($ref) ?> &middot; Page de détails</div>
    </header>

    <div class="body-p2">
      <h2 class="h2-title">Caractéristiques détaillées</h2>
      <div class="h2-sub"><?= h($titreBien) ?></div>
      <div class="h2-rule"></div>

      <?php
      // M/2026/05/05/62 — P2 graceful : skip rows vides + sections vides + suppression Honoraires.
      $_p2sections = '';
      // Identite
      $_rows = '';
      $_rows .= _renderRow('Type', $typeBien);
      $_rows .= _renderRow('Année', _pick($bien, 'annee_construction', 'annee'));
      $_rows .= _renderRow('État', $etat);
      $_rows .= _renderRow('Standing', _pick($bien, 'standing'));
      $_rows .= _renderRow('Orientation', $exposition);
      $_rows .= _renderRow('Vue', $vues ? bullets($vues, ', ') : null);
      $_p2sections .= _renderSection('Identité', $_rows);
      // Surfaces
      $_rows = '';
      $_rows .= _renderRowHtml('Habitable',  $surfaceHab ? htmlspecialchars(fmtNum($surfaceHab)) . ' m²' : '');
      $_rows .= _renderRowHtml('Terrain',    $surfaceTerrain ? htmlspecialchars(fmtNum($surfaceTerrain)) . ' m²' : '');
      $_rows .= _renderRowHtml('Terrasses',  $surfaceTerrasse ? htmlspecialchars(fmtNum($surfaceTerrasse)) . ' m²' : '');
      $_rows .= _renderRow('Piscine', $piscine);
      $_rows .= _renderRowHtml('Garage', $surfaceGarage ? htmlspecialchars(fmtNum($surfaceGarage)) . ' m²' : '');
      $_rows .= _renderRow('Total clos', _pick($bien, 'surface_total_clos'));
      $_p2sections .= _renderSection('Surfaces', $_rows);
      // Pieces
      $_rows = '';
      $_rows .= _renderRow('Pièces', $pieces);
      $_rows .= _renderRow('Chambres', $chambres);
      $_rows .= _renderRow('Sdb', $sdb);
      $_rows .= _renderRow('Salons', _pick($bien, 'salons_count', 'salons'));
      $_rows .= _renderRow('Cuisine', _pick($bien, 'cuisine'));
      $_rows .= _renderRow('Bureau', _pick($bien, 'bureau_count', 'bureau'));
      $_p2sections .= _renderSection('Pièces', $_rows);
      // Confort
      $_rows = '';
      $_climOk = in_array('Climatisation', $equipementsConfort, true) || (is_array($equipementsRaw) && !empty($equipementsRaw['climatisation']));
      $_rows .= _renderRow('Climatisation', $_climOk ? 'Oui' : null);
      $_rows .= _renderRow('Chauffage', _pick($bien, 'chauffage'));
      $_rows .= _renderRow('Vitrage', _pick($bien, 'vitrage'));
      $_rows .= _renderRow('Cuisine', _pick($bien, 'cuisine'));
      $_rows .= _renderRow('Domotique', _pick($bien, 'domotique'));
      $_rows .= _renderRow('Cheminée', _yesIfIn($equipementsConfort, 'Cheminée'));
      $_p2sections .= _renderSection('Confort', $_rows);
      // Exterieurs
      $_rows = '';
      $_rows .= _renderRowHtml('Jardin',    $surfaceJardin ? htmlspecialchars(fmtNum($surfaceJardin)) . ' m²' : '');
      $_rows .= _renderRow('Piscine',       $piscine);
      $_rows .= _renderRowHtml('Terrasses', $surfaceTerrasse ? htmlspecialchars(fmtNum($surfaceTerrasse)) . ' m²' : '');
      $_rows .= _renderRow('Barbecue',      _yesIfIn($amenagementsExt, 'Barbecue'));
      $_rows .= _renderRow('Pool house',    _yesIfIn($amenagementsExt, 'Pool house'));
      $_rows .= _renderRow('Arrosage',      _pick($bien, 'arrosage'));
      $_p2sections .= _renderSection('Extérieurs', $_rows);
      // Securite
      $_rows = '';
      $_rows .= _renderRow('Alarme',      _yesIfIn($securite, 'Alarme'));
      $_rows .= _renderRow('Vidéo',       _yesIfIn($securite, 'Vidéosurveillance'));
      $_rows .= _renderRow('Portail',     _pick($bien, 'portail'));
      $_rows .= _renderRow('Gardien',     _yesIfIn($securite, 'Gardien'));
      $_rows .= _renderRow('Coffre-fort', _yesIfIn($securite, 'Coffre-fort'));
      $_rows .= _renderRow('Quartier',    _pick($bien, 'quartier_securite'));
      $_p2sections .= _renderSection('Sécurité', $_rows);
      // Energie
      $_rows = '';
      $_rows .= _renderRow('Eau',          _pick($bien, 'eau'));
      $_rows .= _renderRow('Électricité',  _pick($bien, 'electricite'));
      $_rows .= _renderRow('Solaire',      _pick($bien, 'solaire'));
      $_rows .= _renderRow('DPE',          _pick($bien, 'dpe'));
      $_rows .= _renderRow('GES',          _pick($bien, 'ges'));
      $_internet = in_array('Internet fibre', $equipementsConfort, true) ? 'Fibre' : _pick($bien, 'internet');
      $_rows .= _renderRow('Internet', $_internet);
      $_p2sections .= _renderSection('Énergie', $_rows);
      // Localisation
      $_rows = '';
      $_rows .= _renderRow('Quartier',   $bien['quartier'] ?? null);
      $_rows .= _renderRow('Commerces',  _pick($bien, 'distance_commerces'));
      $_aeroportKm = _pick($bien, 'distance_aeroport_km');
      $_rows .= _renderRow('Aéroport',   $_aeroportKm ? ($_aeroportKm . ' km') : null);
      $_rows .= _renderRow('Médina',     _pick($bien, 'distance_medina'));
      $_rows .= _renderRow('École',      _pick($bien, 'distance_ecole'));
      $_rows .= _renderRow('Hôpital',    _pick($bien, 'distance_hopital'));
      $_p2sections .= _renderSection('Localisation', $_rows);
      ?>
      <?php if ($_p2sections !== ''): ?>
      <div class="grid-2col"><?= $_p2sections ?></div>
      <?php endif; ?>

      <div class="foot-p2">
        <div class="agent">
          <span class="lab">Agent référent</span>
          <?php if ($hideIdentity): ?>
            <span style="color: var(--mute); font-style: italic;">Coordonnées sur demande</span>
          <?php else: ?>
            <?= h($agentName) ?><?php if ($agentTel): ?> &middot; <?= h($agentTel) ?><?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="pgnum">Page de détails</div>
        <div class="client<?= $_hasClient ? '' : ' empty' ?>"<?= _pdfEdAttr('client', $_pdfEditorState) ?>>
          <?php if ($_hasClient): ?>
            <span class="lab">Client</span>
            <?= _renderClientLines($_clientFullName, $_clientSociete, $_clientTel, $_clientEmail) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php endif; /* P2 active ou preview */ ?>

  <?php if ($_isPdfPreview || _pdfPageActive($_pdfPagesState, 'p3')): ?>
  <!-- ====================== PAGE 3 — Album photo (15 photos · 5 rangees · M/60) ====================== -->
  <section class="page" data-page="3"<?= _pdfPageDisabledAttr($_pdfPagesState, 'p3', $_isPdfPreview) ?>>
    <header class="ribbon">
      <div class="b">OCRE Immo</div>
      <div class="small">Marrakech &middot; Gueliz</div>
      <div class="ref">Réf. <?= h($ref) ?> &middot; Page des photos</div>
    </header>

    <div class="alb-head">
      <div class="h">Album photo</div>
      <div class="s"><?= h($titreBien) ?></div>
    </div>

    <div class="body-alb">
      <?php
      // M/2026/05/05/60 — 5 rangees x 3 photos = 15 photos max, sans titres intermediaires.
      // Si moins de 15 photos disponibles, afficher celles dispo, ne pas remplir avec placeholder vide
      // (rangees totalement vides skip pour eviter trous visuels).
      for ($row = 0; $row < 5; $row++):
          $rowPhotos = array_slice($photos, $row * 3, 3);
          if (empty(array_filter($rowPhotos))) continue;
      ?>
      <div class="grid-3">
        <?php foreach ([0, 1, 2] as $i):
            $p = $rowPhotos[$i] ?? null;
        ?>
        <div class="ph"><?php if ($p): ?><img src="<?= h($p) ?>" alt=""><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>

    <div class="footer-alb">Ocre Immo &middot; Marrakech &middot; Réf. <?= h($ref) ?> &middot; Page des photos</div>
  </section>
  <?php endif; /* P3 active ou preview */ ?>

</div>
<?php if ($_isShareView): ?>
<!-- M/2026/05/01/13 — bandeau confidentialite legal (vue partagee uniquement). -->
<div class="ocre-confidential-banner">
  Ce dossier vous est transmis a titre confidentiel.<br>
  Toute reproduction ou redistribution est interdite.
</div>
<!-- M/2026/05/01/13 — watermark dynamique anti-fuite : token court + timestamp creation lien.
     CSS print conserve le calque. Capture d'ecran iOS/Android non bloquable cote web — limitation
     systeme OS. Le watermark trace l'origine en cas de fuite. -->
<div class="ocre-watermark" aria-hidden="true">
  <div class="wm-grid">
    <?php
    $_wmText = htmlspecialchars(
      'OCRE immo · ' . $_shareTokenShort . ' · ' . substr($_shareCreatedAt, 0, 16) . ' · ne pas redistribuer',
      ENT_QUOTES, 'UTF-8'
    );
    // Grille de 60 cellules (10 col x 6 ligne) — couvre l'inset:-50% en tous sens.
    for ($_i = 0; $_i < 60; $_i++) {
        echo '<div class="wm-cell">' . $_wmText . '</div>';
    }
    ?>
  </div>
</div>
<script>
// M/2026/05/01/13 — desactive copier-coller / glisser / clic droit / impression-export rapide.
// Limites assumees : capture ecran iOS/Android non bloquable cote web, mais watermark trace l'origine.
(function () {
  function block(e) { if (e && e.preventDefault) e.preventDefault(); return false; }
  ['contextmenu','dragstart','copy','cut','selectstart'].forEach(function (ev) {
    document.addEventListener(ev, block, {capture: true});
  });
  // Empeche Cmd/Ctrl+S, Cmd/Ctrl+P (raccourci save/print) — impression A4 reste accessible
  // via menu navigateur si l'utilisateur insiste, mais le watermark s'imprime avec.
  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    if ((e.metaKey || e.ctrlKey) && (k === 's' || k === 'c' || k === 'x' || k === 'a')) {
      // Permettre Cmd/Ctrl+P (print) car le watermark CSS @media print s'applique.
      if (k !== 'p') block(e);
    }
  }, {capture: true});
})();
</script>
<?php endif; ?>
</body>
</html>
