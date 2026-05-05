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

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Carnet de bien · <?= h($titreBien) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500;700&family=Caveat:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --ocre: #8B5E3C;
    --ocre-light: #A8896A;
    --ocre-soft: #F5EFE7;
    --bg: #FAF6F0;
    --line: #E8E0D2;
    --ink: #1A1A1A;
    --muted: #7A7167;
  }
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #E8E5E0; color: var(--ink); font-family: 'DM Sans', system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .pages { padding: 20px 16px; }
  .page {
    width: 210mm;
    min-height: 297mm;
    max-width: 210mm;
    margin: 0 auto 18px;
    background: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
    padding: 14mm 14mm 12mm;
    position: relative;
    page-break-after: always;
    overflow: hidden;
  }
  .page:last-child { page-break-after: auto; margin-bottom: 0; }
  @page { size: A4; margin: 0; }
  @media print {
    body, html { background: #fff; }
    .pages { padding: 0; }
    .page { box-shadow: none; margin: 0; width: 210mm; height: 297mm; min-height: 297mm; padding: 14mm 14mm 12mm; }
  }

  /* M/2026/04/30/52 — responsive iPhone/iPad (capture IMG_4026 montrait debordement A4 sur iPhone). */
  @media screen and (max-width: 480px) {
    body, html { background: #fff; }
    .pages { padding: 8px 0; }
    .page {
      width: 100% !important;
      max-width: 100vw !important;
      min-height: auto !important;
      margin: 0 0 12px;
      padding: 14px 14px 16px !important;
      box-shadow: 0 1px 6px rgba(0,0,0,.05);
      overflow-x: hidden;
    }
    .mosaic {
      grid-template-columns: 1fr 1fr !important;
      grid-template-rows: auto !important;
      height: auto !important;
      gap: 6px !important;
      margin: 8mm 0 6mm;
    }
    .mosaic .m1 { grid-column: 1 / span 2 !important; grid-row: auto !important; aspect-ratio: 16 / 10; }
    .mosaic .m2, .mosaic .m3, .mosaic .m4, .mosaic .m5 { grid-column: auto !important; grid-row: auto !important; aspect-ratio: 4 / 3; }
    .cartouche { padding: 14px 12px !important; margin: 0 0 12px; }
    .cartouche-title { font-size: 22px !important; margin-bottom: 12px !important; }
    .cover-foot { grid-template-columns: 1fr !important; gap: 12px !important; padding-top: 12px; }
    .cover-foot .price, .cover-foot .agent { text-align: left !important; }
    .cover-foot .price .amount { font-size: 24px !important; }
    .brand-lg .ocre-mark { font-size: 22px !important; }
    .brand-lg .immo-mark { font-size: 26px !important; }
    .ed-title { font-size: 24px !important; margin: 2mm 0 4mm !important; }
    .runhead, .runfoot { font-size: 7px !important; letter-spacing: 1.5px !important; padding: 0 0 8px; }
    .runfoot { position: static !important; margin-top: 12px; }
  }
  @media screen and (min-width: 481px) and (max-width: 768px) {
    /* iPad portrait : largeur centree confortable, evite scroll horizontal. */
    .pages { padding: 14px 0; }
    .page {
      width: 96vw !important;
      max-width: 640px !important;
      padding: 24px !important;
    }
  }

  /* === Couleurs & typographies utilitaires === */
  .cormorant { font-family: 'Cormorant Garamond', Georgia, serif; }
  .ocre { color: var(--ocre); }
  .ocre-light { color: var(--ocre-light); }
  .uc { text-transform: uppercase; }
  .center { text-align: center; }
  hr.rule { border: 0; height: 0.5px; background: var(--ocre); margin: 8px 0; }

  /* === Test badge === */
  .test-badge { font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500; letter-spacing: 1.5px; color: #DC2626; text-transform: lowercase; line-height: 1; margin-top: 4px; display: block; text-align: center; }

  /* === Logo OCRE immo (charte unifiee, single source of truth) === */
  /* Reference : OcreTitle / OcreLogoButton dans index.html. Cormorant 700 ocre + Caveat 400 noir. */
  .brand { display: inline-flex; align-items: baseline; gap: 3px; line-height: 1; white-space: nowrap; }
  .brand .ocre-mark { font-family: 'Cormorant Garamond', serif; font-weight: 700; color: var(--ocre); letter-spacing: 1.5px; }
  .brand .immo-mark { font-family: 'Caveat', cursive; font-weight: 400; color: var(--ink); margin-left: 2px; }
  .brand-lg .ocre-mark { font-size: 28px; }
  .brand-lg .immo-mark { font-size: 32px; }
  .brand-md .ocre-mark { font-size: 14px; letter-spacing: 1.2px; }
  .brand-md .immo-mark { font-size: 16px; }
  .brand-sm .ocre-mark { font-size: 11px; letter-spacing: 1px; }
  .brand-sm .immo-mark { font-size: 13px; }

  /* === Page 1 — Cover === */
  .cover-head { text-align: center; padding: 0 0 14px; border-bottom: 0.5px solid var(--ocre); }
  .eye { font-family: 'DM Sans', sans-serif; font-size: 9px; font-weight: 500; letter-spacing: 5px; text-transform: uppercase; color: var(--ocre-light); margin-bottom: 12px; }
  .agent-line { font-family: 'DM Sans', sans-serif; font-size: 9px; letter-spacing: 3px; text-transform: uppercase; color: var(--ocre-light); font-weight: 400; margin-top: 10px; }

  /* M/2026/05/05/17 — M-Photos-Mosaic-Adaptive : layouts dynamiques selon nb photos visibles. */
  .mosaic, .mosaic-1, .mosaic-2, .mosaic-3, .mosaic-4 { display: grid; gap: 4mm; height: 105mm; margin: 12mm 0 8mm; }
  .mosaic-1 { grid-template-columns: 1fr; grid-template-rows: 1fr; }
  .mosaic-2 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr; }
  .mosaic-3 { grid-template-columns: 60% 40%; grid-template-rows: 1fr 1fr; }
  .mosaic-3 .m1 { grid-column: 1; grid-row: 1 / span 2; }
  .mosaic-3 .m2 { grid-column: 2; grid-row: 1; }
  .mosaic-3 .m3 { grid-column: 2; grid-row: 2; }
  .mosaic-4 { grid-template-columns: 60% 40%; grid-template-rows: 1fr 1fr 1fr; }
  .mosaic-4 .m1 { grid-column: 1; grid-row: 1 / span 3; }
  .mosaic-4 .m2 { grid-column: 2; grid-row: 1; }
  .mosaic-4 .m3 { grid-column: 2; grid-row: 2; }
  .mosaic-4 .m4 { grid-column: 2; grid-row: 3; }
  /* Layout 5 photos = layout actuel inchange (Philippe a valide). */
  .mosaic { grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; }
  .mosaic .m1 { grid-column: 1; grid-row: 1 / span 2; position: relative; }
  .mosaic .m2 { grid-column: 2; grid-row: 1; }
  .mosaic .m3 { grid-column: 3; grid-row: 1; }
  .mosaic .m4 { grid-column: 2; grid-row: 2; }
  .mosaic .m5 { grid-column: 3; grid-row: 2; }
  .mosaic > div, .mosaic-1 > div, .mosaic-2 > div, .mosaic-3 > div, .mosaic-4 > div { background: var(--ocre-soft); overflow: hidden; position: relative; }
  .mosaic img, .mosaic-1 img, .mosaic-2 img, .mosaic-3 img, .mosaic-4 img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .mosaic .ph-empty, .mosaic-1 .ph-empty, .mosaic-2 .ph-empty, .mosaic-3 .ph-empty, .mosaic-4 .ph-empty { display: flex; align-items: center; justify-content: center; color: var(--ocre-light); font-size: 10px; letter-spacing: 2px; text-transform: uppercase; }
  .mosaic .caption, .mosaic-1 .caption, .mosaic-2 .caption, .mosaic-3 .caption, .mosaic-4 .caption { position: absolute; bottom: 6px; left: 8px; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.7); font-weight: 500; }
  /* M/2026/05/05/17 — section album : photos 6+ en grille de petites vignettes.
     M/2026/05/05/19 — M-Photos-Album-Toast-Fix : titre Album photo + grille STRICTE 4 cols + .album-cell aspect 4/3. */
  .album-title { font-family: 'Cormorant Garamond', 'Playfair Display', serif; font-size: 22px; color: #7A5132; text-align: center; font-weight: 600; margin: 18px 0 12px; letter-spacing: 0.5px; }
  .album { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 0 0 8mm; }
  .album-cell { aspect-ratio: 4 / 3; background: var(--ocre-soft); overflow: hidden; border-radius: 6px; }
  .album-cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
  @media print {
    .mosaic-1, .mosaic-2, .mosaic-3, .mosaic-4 { gap: 4mm; }
    .album { grid-template-columns: repeat(4, 1fr); gap: 4mm; }
    .album-title { margin: 8mm 0 4mm; }
  }

  .cartouche { background: var(--bg); border-top: 0.5px solid var(--ocre); border-bottom: 0.5px solid var(--ocre); padding: 12mm 14mm; text-align: center; margin: 0 0 10mm; }
  .cartouche-title { font-family: 'Cormorant Garamond', serif; font-size: 26px; line-height: 1.2; color: var(--ink); font-weight: 400; margin-bottom: 6mm; }
  .cartouche-title b { font-weight: 700; color: var(--ink); }
  .cartouche-loc { font-family: 'DM Sans', sans-serif; font-size: 10px; letter-spacing: 4px; text-transform: uppercase; color: var(--ocre); font-weight: 500; margin-bottom: 6mm; }
  .cartouche-bullets { font-family: 'DM Sans', sans-serif; font-size: 10px; color: var(--muted); letter-spacing: 0.5px; }
  .cartouche-bullets b { font-family: 'Cormorant Garamond', serif; font-size: 18px; color: var(--ocre); font-weight: 700; letter-spacing: 0; margin: 0 2px; }

  .cover-foot { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6mm; padding-top: 6mm; border-top: 0.5px solid var(--ocre); align-items: end; }
  .cover-foot .price { text-align: left; }
  .cover-foot .price .amount { font-family: 'Cormorant Garamond', serif; font-size: 28px; color: var(--ocre); font-weight: 400; line-height: 1; overflow: visible; white-space: nowrap; padding-right: 4px; }
  .cover-foot .price .amount b { font-weight: 700; }
  .cover-foot .price .hon { font-family: 'DM Sans', sans-serif; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-top: 4px; }
  .cover-foot .center-mark { text-align: center; font-family: 'Cormorant Garamond', serif; font-size: 13px; letter-spacing: 4px; color: var(--ocre); }
  .cover-foot .center-mark .sub { font-family: 'DM Sans', sans-serif; font-size: 8px; letter-spacing: 3px; text-transform: uppercase; color: var(--muted); margin-top: 4px; }
  .cover-foot .agent { text-align: right; font-size: 9px; line-height: 1.6; color: var(--muted); }
  .cover-foot .agent .name { font-weight: 700; color: var(--ink); font-size: 10px; letter-spacing: 1px; text-transform: uppercase; }

  /* === Pages 2 & 3 — Runhead / runfoot === */
  .runhead { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: 4mm; border-bottom: 0.5px solid var(--ocre); margin-bottom: 8mm; font-family: 'DM Sans', sans-serif; font-size: 8px; letter-spacing: 3px; text-transform: uppercase; color: var(--ocre-light); }
  .runhead .left { color: var(--ocre); font-weight: 500; letter-spacing: 4px; }
  .runfoot { position: absolute; bottom: 8mm; left: 14mm; right: 14mm; padding-top: 4mm; border-top: 0.5px solid var(--ocre); display: flex; justify-content: space-between; font-family: 'DM Sans', sans-serif; font-size: 8px; letter-spacing: 3px; text-transform: uppercase; color: var(--ocre-light); }

  /* === Page 2 — Editorial === */
  .ed-title { font-family: 'Cormorant Garamond', serif; font-size: 32px; line-height: 1.15; font-weight: 400; color: var(--ink); margin: 4mm 0 6mm; }
  .ed-title b { font-weight: 700; }
  .ed-lead { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 13px; line-height: 1.55; color: var(--muted); padding: 6mm 0; border-top: 0.5px solid var(--ocre); border-bottom: 0.5px solid var(--ocre); margin-bottom: 8mm; }
  .ed-grid { display: grid; grid-template-columns: 5fr 4fr; gap: 8mm; }
  .ed-text { font-family: 'DM Sans', sans-serif; font-size: 10.5px; line-height: 1.65; text-align: justify; color: var(--ink); }
  .ed-text h4 { font-family: 'DM Sans', sans-serif; font-size: 9px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; color: var(--ocre); margin: 6mm 0 2mm; }
  .ed-text h4:first-child { margin-top: 0; }
  .ed-text p { margin: 0 0 3mm; }
  .ed-photos { display: grid; grid-template-rows: 2fr 1fr 1fr; grid-template-columns: 1fr; gap: 3mm; max-height: 175mm; }
  .ed-photos > div { background: var(--ocre-soft); overflow: hidden; }
  .ed-photos img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .ed-photos .ph-empty { display: flex; align-items: center; justify-content: center; color: var(--ocre-light); font-size: 9px; letter-spacing: 2px; text-transform: uppercase; height: 100%; }

  /* === Page 3 — Technique === */
  .tech-title { font-family: 'Cormorant Garamond', serif; font-size: 26px; line-height: 1.15; font-weight: 400; color: var(--ink); margin: 4mm 0 8mm; }
  .tech-title b { font-weight: 700; }
  .tech-section { display: grid; grid-template-columns: 32mm 1fr; gap: 4mm; padding: 4mm 0; border-bottom: 0.5px solid var(--line); }
  .tech-section:last-of-type { border-bottom: 0; }
  .tech-section h3 { font-family: 'DM Sans', sans-serif; font-size: 9px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; color: var(--ocre); margin: 0; padding-top: 2px; }
  .tech-rows { font-family: 'DM Sans', sans-serif; font-size: 10px; line-height: 1.7; color: var(--ink); }
  .tech-rows .row { display: grid; grid-template-columns: 38mm 1fr; gap: 4mm; padding: 1.2mm 0; }
  .tech-rows .row .k { font-size: 9px; color: var(--muted); letter-spacing: 1px; text-transform: uppercase; }
  .tech-rows .inline { display: flex; flex-wrap: wrap; gap: 2.5mm 4mm; }
  .tech-rows .inline span { font-size: 10px; color: var(--ink); }
  .tech-rows .inline span::before { content: '· '; color: var(--ocre); margin-right: 2px; }

  .contact-block { margin-top: 10mm; padding-top: 6mm; border-top: 0.5px solid var(--ocre); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6mm; }
  .contact-block .col h5 { font-family: 'DM Sans', sans-serif; font-size: 8px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; color: var(--ocre-light); margin: 0 0 3mm; }
  .contact-block .col .body { font-family: 'DM Sans', sans-serif; font-size: 10px; line-height: 1.6; color: var(--ink); }
  .contact-block .col .body .name { font-weight: 700; font-size: 11px; }
  .contact-block .col .price-final { font-family: 'Cormorant Garamond', serif; font-size: 28px; color: var(--ocre); font-weight: 400; line-height: 1; overflow: visible; white-space: nowrap; padding-right: 4px; }
  .contact-block .col .price-final b { font-weight: 700; }
  .contact-block .col .price-final .hon { font-family: 'DM Sans', sans-serif; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-top: 4px; }
<?php if ($_isShareView): ?>
  /* M/2026/05/01/13 — protections anti-fuite vue partagee : no-copy CSS, watermark calque, bandeau legal. */
  .pages, .pages * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
    -webkit-tap-highlight-color: transparent;
  }
  .pages a { -webkit-touch-callout: default; }
  .ocre-watermark {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 9999;
    overflow: hidden;
    background-image: repeating-linear-gradient(
      -20deg,
      transparent 0,
      transparent 90px,
      rgba(102, 78, 55, 0.04) 90px,
      rgba(102, 78, 55, 0.04) 91px
    );
  }
  .ocre-watermark .wm-grid {
    position: absolute;
    inset: -50%;
    transform: rotate(-20deg);
    transform-origin: center;
    display: grid;
    grid-template-columns: repeat(auto-fill, 200px);
    grid-auto-rows: 120px;
    align-items: center;
    justify-content: start;
  }
  .ocre-watermark .wm-cell {
    font-family: 'Cormorant Garamond', Georgia, serif;
    font-style: italic;
    font-size: 13px;
    color: rgba(102, 78, 55, 0.085);
    text-align: center;
    line-height: 1.4;
    padding: 0 14px;
    user-select: none;
    white-space: nowrap;
  }
  @media print {
    .ocre-watermark { display: block !important; }
  }
  .ocre-confidential-banner {
    max-width: 720px;
    margin: 24px auto 32px;
    padding: 0 18px;
    font-family: 'DM Sans', sans-serif;
    font-size: 11px;
    font-style: italic;
    color: #8B7F6E;
    text-align: center;
    line-height: 1.5;
  }
<?php endif; ?>
</style>
</head>
<body>
<div class="pages">

  <!-- ====================== PAGE 1 — COVER ====================== -->
  <section class="page">
    <div class="cover-head">
      <div class="eye">Carnet de bien</div>
      <span class="brand brand-lg"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span>
      <?php if ($_isTestMode): ?><div class="test-badge">test</div><?php endif; ?>
      <hr class="rule" style="margin: 8px auto 6px; max-width: 60%;">
      <div class="agent-line"><?= h($agentName) ?><?php if ($agentTel): ?> · <?= h($agentTel) ?><?php endif; ?></div>
    </div>

<?php /* M/2026/05/05/17 — M-Photos-Mosaic-Adaptive : layouts dynamiques selon nb photos visibles.
       Captions ['Photo principale', 'Salon', 'Cuisine', 'Suite parentale', 'Terrasse'] mappees
       dans l ORDRE des photos visibles (apres filtrage hide_photos[]), pas selon label original.
       Photos 6+ = section album (grille 4 cols vignettes carrees, sans caption). */ ?>
    <?php
    $_mosaicCaptions = ['Photo principale', 'Salon', 'Cuisine', 'Suite parentale', 'Terrasse'];
    $_mosaicTop = array_slice($photos, 0, 5);
    $_albumExtra = array_slice($photos, 5, 25);
    $_topCount = count($_mosaicTop);
    $_mosaicCls = ['', 'mosaic-1', 'mosaic-2', 'mosaic-3', 'mosaic-4', 'mosaic'][$_topCount];
    ?>
    <?php if ($_topCount > 0): ?>
    <div class="<?= $_mosaicCls ?>">
      <?php foreach ($_mosaicTop as $i => $p): $cls = 'm' . ($i + 1); ?>
      <div class="<?= $cls ?>">
        <img src="<?= h($p) ?>" alt="">
        <span class="caption"><?= h($_mosaicCaptions[$i]) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php /* M/2026/05/05/51 — Album photo deplace en TOUTE FIN du PDF, juste avant footer page 3. */ ?>
    <?php endif; /* /_topCount > 0 */ ?>

    <div class="cartouche">
      <div class="cartouche-title"><b><?= h($titreBien) ?></b></div>
      <?php if ($hideAddress): ?>
        <div class="cartouche-loc" style="color: var(--muted); font-style: italic;">Adresse sur demande</div>
      <?php else: ?>
        <div class="cartouche-loc"><?= h($ville) ?></div>
      <?php endif; ?>
      <div class="cartouche-bullets">
        <?php
        $b = [];
        if ($surfaceHab) $b[] = '<b>' . htmlspecialchars(fmtNum($surfaceHab)) . '</b> m² habitables';
        if ($surfaceTerrain) $b[] = '<b>' . htmlspecialchars(fmtNum($surfaceTerrain)) . '</b> m² terrain';
        if ($chambres) $b[] = '<b>' . (int) $chambres . '</b> ' . ($chambres > 1 ? 'chambres' : 'chambre');
        if ($piscine && $piscine !== 'Aucune') $b[] = '<b>1</b> piscine';
        echo implode(' · ', $b);
        ?>
      </div>
    </div>

    <div class="cover-foot">
      <div class="price">
        <?php if ($prix && !$prixDemand): ?>
          <div class="amount"><b><?= htmlspecialchars(fmtNum($prix)) ?></b> <?= htmlspecialchars((string)$devise, ENT_QUOTES | ENT_HTML5, "UTF-8") ?></div>
          <?php if ($honoraires): ?><div class="hon">Honoraires inclus</div><?php endif; ?>
        <?php else: ?>
          <div class="amount" style="font-size:18px; color: var(--muted); font-style: italic;">Sur demande</div>
        <?php endif; ?>
      </div>
      <div class="center-mark">
        <span class="brand brand-md"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span>
        <div class="sub">Marrakech</div>
      </div>
      <div class="agent">
        <?php if ($hideIdentity): ?>
          <div style="color: var(--muted); font-style: italic; font-size: 11px;">Coordonnées sur demande</div>
        <?php else: ?>
          <div class="name"><?= h($agentName) ?></div>
          <?php if ($agentTel): ?><div><?= h($agentTel) ?></div><?php endif; ?>
          <?php if ($agentEmail): ?><div><?= h($agentEmail) ?></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ====================== PAGE 2 — DESCRIPTIF ====================== -->
  <section class="page">
    <div class="runhead">
      <span class="left brand brand-sm"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span>
      <span>Réf. <?= h($ref) ?> · Page 2/3</span>
    </div>

    <div class="eye" style="text-align:left;">Le bien</div>
    <h1 class="ed-title"><?= h($titreBien) ?></h1>

    <div class="ed-lead">
      <?php if ($descriptifLead): ?>
        <?= nl2br(h($descriptifLead)) ?>
      <?php else: ?>
        <?= h($titreBien) ?> niché à <?= h($ville ?: 'Marrakech') ?>. Volumes généreux, prestations de standing, jardin paysager. Une adresse rare pour qui cherche le confort d'une vie entre médina et palmeraie.
      <?php endif; ?>
    </div>

    <div class="ed-grid" style="<?= $_hasEditorialPhotos ? '' : 'grid-template-columns: 1fr;' ?>">
      <div class="ed-text">
        <?php if ($descriptifTexte): ?>
          <?= nl2br(h($descriptifTexte)) ?>
        <?php else: ?>
          <h4>Au rez-de-jardin</h4>
          <p>Un vaste séjour traversant ouvre sur le jardin par de larges baies coulissantes. La cuisine équipée prolonge l'espace de réception et donne accès à la terrasse couverte. Un bureau, une suite d'amis et les pièces de service complètent ce niveau.</p>
          <h4>À l'étage</h4>
          <p>La suite parentale offre dressing et salle de bain en marbre travertin. <?= $chambres ? ((int) $chambres - 1) : 'Trois' ?> autres chambres en suite, baignées de lumière, donnent toutes sur le jardin et la piscine.</p>
          <h4>Extérieurs &amp; prestations</h4>
          <p><?= $piscine && $piscine !== 'Aucune' ? 'Piscine ' . htmlspecialchars(strtolower($piscine)) . ' chauffée, ' : '' ?>jardin paysager d'inspiration méditerranéenne, terrasses ombragées et coin barbecue. Climatisation réversible, double vitrage, alarme et vidéosurveillance.</p>
        <?php endif; ?>
      </div>
      <?php if ($_hasEditorialPhotos): ?>
      <div class="ed-photos">
        <?php foreach ($photoEditorial as $p): ?>
        <div>
          <?php if ($p): ?>
            <img src="<?= h($p) ?>" alt="">
          <?php else: ?>
            <div class="ph-empty">Photo</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="runfoot">
      <span><span class="brand brand-sm"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span><span style="margin-left:6px">· Marrakech</span></span>
      <span>Document confidentiel — usage agent</span>
    </div>
  </section>

  <!-- ====================== PAGE 3 — TECHNIQUE ====================== -->
  <section class="page">
    <div class="runhead">
      <span class="left brand brand-sm"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span>
      <span>Réf. <?= h($ref) ?> · Page 3/3</span>
    </div>

    <div class="eye" style="text-align:left;">Fiche technique</div>
    <h1 class="tech-title">Caractéristiques <b>complètes</b></h1>

    <div class="tech-section">
      <h3>Identité</h3>
      <div class="tech-rows">
        <div class="row"><span class="k">Type</span><span><?= h($typeBien) ?></span></div>
        <div class="row"><span class="k">Statut foncier</span><span><?= h(_pick($bien, 'statut_foncier', 'titre_statut')) ?></span></div>
        <div class="row"><span class="k">État</span><span><?= h($etat) ?></span></div>
        <div class="row"><span class="k">Niveaux</span><span><?= h($etages) ?></span></div>
        <div class="row"><span class="k">Pièces</span><span><?= h($pieces) ?></span></div>
        <div class="row"><span class="k">Chambres</span><span><?= h($chambres) ?></span></div>
        <div class="row"><span class="k">Disponibilité</span><span><?= h(trim(($dispoStatut ?? '') . ($dispoDate ? ' · ' . $dispoDate : ''))) ?></span></div>
        <div class="row"><span class="k">Exposition</span><span><?= h($exposition) ?></span></div>
        <div class="row"><span class="k">Vue</span><span><?= bullets($vues, ', ') ?></span></div>
      </div>
    </div>

    <div class="tech-section">
      <h3>Surfaces</h3>
      <div class="tech-rows">
        <div class="row"><span class="k">Habitable</span><span><?= $surfaceHab ? fmtNum($surfaceHab) . ' m²' : '—' ?></span></div>
        <div class="row"><span class="k">Terrasses + garage</span><span><?= ($surfaceTerrasse || $surfaceGarage) ? fmtNum(((float) $surfaceTerrasse) + ((float) $surfaceGarage)) . ' m²' : '—' ?></span></div>
        <div class="row"><span class="k">Terrain</span><span><?= $surfaceTerrain ? fmtNum($surfaceTerrain) . ' m²' : '—' ?></span></div>
        <?php if ($piscine && $piscine !== 'Aucune'): ?>
        <div class="row"><span class="k">Piscine</span><span><?= h($piscine) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($bien['terrasse_couv_m2'])): ?>
        <div class="row"><span class="k">Terrasse couverte</span><span><?= h($bien['terrasse_couv_m2']) ?> m²</span></div>
        <?php endif; ?>
        <?php if (!empty($bien['nombre_places_parking'])): ?>
        <div class="row"><span class="k">Parking</span><span><?= (int) $bien['nombre_places_parking'] ?> place(s)</span></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="tech-section">
      <h3>Confort &amp; équipements</h3>
      <div class="tech-rows">
        <div class="inline">
          <?php foreach ($equipementsConfort as $e): ?>
            <span><?= h($e) ?></span>
          <?php endforeach; ?>
          <?php if (!$equipementsConfort): ?><span style="color:var(--muted)">—</span><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="tech-section">
      <h3>Sécurité &amp; extérieurs</h3>
      <div class="tech-rows">
        <div class="inline">
          <?php foreach (array_merge($securite, $amenagementsExt) as $e): ?>
            <span><?= h($e) ?></span>
          <?php endforeach; ?>
          <?php if (!$securite && !$amenagementsExt): ?><span style="color:var(--muted)">—</span><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="tech-section">
      <h3>Adresse &amp; proximités</h3>
      <div class="tech-rows">
        <?php if ($hideAddress): ?>
          <div class="row"><span class="k">Adresse</span><span style="color: var(--muted); font-style: italic;">Sur demande</span></div>
        <?php elseif ($adresse): ?>
          <div class="row"><span class="k">Adresse</span><span><?= h($adresse) ?></span></div>
        <?php endif; ?>
        <div class="inline">
          <?php foreach ($proximites as $e): ?>
            <span><?= h($e) ?></span>
          <?php endforeach; ?>
          <?php if (!$proximites): ?><span style="color:var(--muted)">—</span><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="contact-block">
      <div class="col">
        <h5>Agent référent</h5>
        <div class="body">
          <?php if ($hideIdentity): ?>
            <div style="color: var(--muted); font-style: italic; font-size: 12px;">Coordonnées sur demande auprès de l'agent</div>
          <?php else: ?>
            <div class="name"><?= h($agentName) ?></div>
            <?php if ($agentTel): ?><div><?= h($agentTel) ?></div><?php endif; ?>
            <?php if ($agentEmail): ?><div><?= h($agentEmail) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col">
        <h5>Dossier en ligne</h5>
        <div class="body">
          <div><?= h($shareBase . $ref) ?></div>
          <div style="color: var(--muted); font-size: 9px; margin-top: 2mm;">Lien valide 7 jours</div>
        </div>
      </div>
      <div class="col" style="text-align: right;">
        <h5>Prix</h5>
        <?php if ($prix && !$prixDemand): ?>
          <div class="price-final"><b><?= htmlspecialchars(fmtNum($prix)) ?></b> <?= htmlspecialchars((string)$devise, ENT_QUOTES | ENT_HTML5, "UTF-8") ?></div>
          <?php if ($honoraires): ?><div class="hon">Honoraires inclus</div><?php endif; ?>
        <?php else: ?>
          <div class="price-final" style="font-size: 18px; color: var(--muted); font-style: italic;">Sur demande</div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* M/2026/05/05/51 — Album photo deplace ici (fin PDF, juste avant footer). */ ?>
    <?php if (!empty($_albumExtra) && count($_albumExtra) > 0): ?>
    <h3 class="album-title">Album photo</h3>
    <div class="album">
      <?php foreach ($_albumExtra as $p): ?>
      <div class="album-cell"><img src="<?= h($p) ?>" alt=""></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="runfoot">
      <span><span class="brand brand-sm"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span><span style="margin-left:6px">· Marrakech</span></span>
      <span>Document confidentiel — usage agent</span>
    </div>
  </section>

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
