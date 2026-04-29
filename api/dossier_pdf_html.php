<?php
// M/2026/04/29/24 — Refonte complete maquette C 3 pages (cover mosaïque + descriptif
// editorial + fiche technique). Cormorant Garamond + DM Sans. Indicateur test si mode test.
// Cible : DossierPdfView (in-app viewer) + impression A4 (Philippe declenche manuellement
// via bouton Partager / Imprimer dans le viewer).
require_once __DIR__ . '/db.php';
$_isTestMode = (isset($_v20_mode) && $_v20_mode === 'test');

$user = requireAuth();
$uid = (int) $user['id'];
$dossierId = (int) ($_GET['dossier_id'] ?? 0);
if (!$dossierId) { http_response_code(400); echo 'dossier_id requis'; exit; }

$st = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$dossierId, $uid]);
$dossier = $st->fetch();
if (!$dossier) { http_response_code(404); echo 'Dossier introuvable'; exit; }

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
$devise = $data['devise'] ?? '€';
$honoraires = $data['honoraires_inclus'] ?? true;
// M/2026/04/29/38 — toggle Prix / Sur demande embedded URL ?mode=price|demand.
$shareMode = ($_GET['mode'] ?? 'price') === 'demand' ? 'demand' : 'price';
$prixDemand = ($shareMode === 'demand');

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

  .mosaic { display: grid; grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 4mm; height: 105mm; margin: 12mm 0 8mm; }
  .mosaic .m1 { grid-column: 1; grid-row: 1 / span 2; position: relative; }
  .mosaic .m2 { grid-column: 2; grid-row: 1; }
  .mosaic .m3 { grid-column: 3; grid-row: 1; }
  .mosaic .m4 { grid-column: 2; grid-row: 2; }
  .mosaic .m5 { grid-column: 3; grid-row: 2; }
  .mosaic > div { background: var(--ocre-soft); overflow: hidden; position: relative; }
  .mosaic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .mosaic .ph-empty { display: flex; align-items: center; justify-content: center; color: var(--ocre-light); font-size: 10px; letter-spacing: 2px; text-transform: uppercase; }
  .mosaic .caption { position: absolute; bottom: 6px; left: 8px; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.7); font-weight: 500; }

  .cartouche { background: var(--bg); border-top: 0.5px solid var(--ocre); border-bottom: 0.5px solid var(--ocre); padding: 12mm 14mm; text-align: center; margin: 0 0 10mm; }
  .cartouche-title { font-family: 'Cormorant Garamond', serif; font-size: 26px; line-height: 1.2; color: var(--ink); font-weight: 400; margin-bottom: 6mm; }
  .cartouche-title b { font-weight: 700; color: var(--ink); }
  .cartouche-loc { font-family: 'DM Sans', sans-serif; font-size: 10px; letter-spacing: 4px; text-transform: uppercase; color: var(--ocre); font-weight: 500; margin-bottom: 6mm; }
  .cartouche-bullets { font-family: 'DM Sans', sans-serif; font-size: 10px; color: var(--muted); letter-spacing: 0.5px; }
  .cartouche-bullets b { font-family: 'Cormorant Garamond', serif; font-size: 18px; color: var(--ocre); font-weight: 700; letter-spacing: 0; margin: 0 2px; }

  .cover-foot { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6mm; padding-top: 6mm; border-top: 0.5px solid var(--ocre); align-items: end; }
  .cover-foot .price { text-align: left; }
  .cover-foot .price .amount { font-family: 'Cormorant Garamond', serif; font-size: 28px; color: var(--ocre); font-weight: 400; line-height: 1; }
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
  .contact-block .col .price-final { font-family: 'Cormorant Garamond', serif; font-size: 28px; color: var(--ocre); font-weight: 400; line-height: 1; }
  .contact-block .col .price-final b { font-weight: 700; }
  .contact-block .col .price-final .hon { font-family: 'DM Sans', sans-serif; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-top: 4px; }
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

    <div class="mosaic">
      <div class="m1">
        <?php if ($photoMain): ?>
          <img src="<?= h($photoMain) ?>" alt="">
          <span class="caption">Photo principale</span>
        <?php else: ?>
          <div class="ph-empty">Photo principale</div>
        <?php endif; ?>
      </div>
      <?php
      $captions = ['Salon', 'Cuisine', 'Suite parentale', 'Terrasse'];
      foreach ($photoSmall as $i => $p):
        $cls = 'm' . ($i + 2);
      ?>
      <div class="<?= $cls ?>">
        <?php if ($p): ?>
          <img src="<?= h($p) ?>" alt="">
          <span class="caption"><?= h($captions[$i]) ?></span>
        <?php else: ?>
          <div class="ph-empty"><?= h($captions[$i]) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="cartouche">
      <div class="cartouche-title"><b><?= h($titreBien) ?></b></div>
      <div class="cartouche-loc"><?= h($ville) ?></div>
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
          <div class="amount"><b><?= htmlspecialchars(fmtNum($prix)) ?></b> <?= h($devise) ?></div>
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
        <div class="name"><?= h($agentName) ?></div>
        <?php if ($agentTel): ?><div><?= h($agentTel) ?></div><?php endif; ?>
        <?php if ($agentEmail): ?><div><?= h($agentEmail) ?></div><?php endif; ?>
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
        <?php if ($adresse): ?>
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
          <div class="name"><?= h($agentName) ?></div>
          <?php if ($agentTel): ?><div><?= h($agentTel) ?></div><?php endif; ?>
          <?php if ($agentEmail): ?><div><?= h($agentEmail) ?></div><?php endif; ?>
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
          <div class="price-final"><b><?= htmlspecialchars(fmtNum($prix)) ?></b> <?= h($devise) ?></div>
          <?php if ($honoraires): ?><div class="hon">Honoraires inclus</div><?php endif; ?>
        <?php else: ?>
          <div class="price-final" style="font-size: 18px; color: var(--muted); font-style: italic;">Sur demande</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="runfoot">
      <span><span class="brand brand-sm"><span class="ocre-mark">OCRE</span><span class="immo-mark">immo</span></span><span style="margin-left:6px">· Marrakech</span></span>
      <span>Document confidentiel — usage agent</span>
    </div>
  </section>

</div>
</body>
</html>
