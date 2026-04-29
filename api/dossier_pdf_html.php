<?php
// M/2026/04/29/16 — Endpoint authentifié qui retourne le HTML A4 d'un dossier.
// Réutilisé par le frontend DossierPdfView (fetch + dangerouslySetInnerHTML).
// Markup partagé conceptuellement avec /share/index.php (même style A4).
require_once __DIR__ . '/db.php';

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
$nom = trim(($dossier['prenom'] ?? '') . ' ' . strtoupper($dossier['nom'] ?? ''));
if (!$nom) $nom = $dossier['societe_nom'] ?? ('Dossier #' . $dossierId);
$projet = $dossier['projet'] ?? '';
$genDate = (new DateTime())->format('d/m/Y');

function _fld($v) {
    if ($v === null || $v === '' || $v === []) return '<span class="empty">—</span>';
    if (is_array($v)) return implode(', ', array_map('htmlspecialchars', $v));
    return htmlspecialchars((string) $v);
}
function _chips(array $arr): string {
    if (!$arr) return '<span class="empty">—</span>';
    $out = '';
    foreach ($arr as $v) $out .= '<span class="chip">' . htmlspecialchars((string) $v) . '</span>';
    return $out;
}

$photos = [];
$photosDir = '/opt/ocre-app/uploads/' . $dossierId;
if (is_dir($photosDir)) {
    foreach (glob($photosDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $p) {
        if (strpos($p, '_thumb.') !== false) continue;
        $photos[] = '/uploads/' . $dossierId . '/' . basename($p);
        if (count($photos) >= 6) break;
    }
}

$nomH = htmlspecialchars($nom);
$projetH = htmlspecialchars($projet);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; background: #E8E5E0; color: #1A1A1A; margin: 0; padding: 20px 16px; }
  .a4 { max-width: 720px; margin: 0 auto; background: #fff; padding: 36px 32px; box-shadow: 0 4px 20px rgba(0,0,0,.08); border-radius: 4px; aspect-ratio: 210/297; min-height: 70vh; }
  .header { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: 14px; border-bottom: 2px solid #8B5E3C; margin-bottom: 20px; }
  .logo { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 22px; color: #8B5E3C; letter-spacing: 1.5px; }
  .logo-immo { font-style: italic; color: #1A1A1A; font-size: 22px; margin-left: 4px; font-weight: 400; }
  .gen-date { font-size: 11px; color: #7A7167; }
  h1 { font-family: 'Cormorant Garamond', serif; font-size: 28px; color: #8B5E3C; margin: 0 0 4px; }
  .subtitle { color: #7A7167; font-size: 13px; margin-bottom: 20px; }
  h2 { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 18px; color: #8B5E3C; margin: 24px 0 8px; padding-bottom: 4px; border-bottom: 0.5px solid rgba(139,94,60,.3); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin: 8px 0; }
  .grid-1 { display: block; margin: 8px 0; }
  .field { padding: 4px 0; }
  .label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #7A7167; font-weight: 600; }
  .value { font-size: 13px; color: #1A1A1A; margin-top: 2px; }
  .value .empty, .empty { color: #999; font-style: italic; }
  .chip { display: inline-block; padding: 2px 8px; background: #FAF3E0; border-radius: 10px; margin: 2px 3px 2px 0; font-size: 11px; color: #8B5E3C; }
  .photos-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 8px; }
  .photos-grid img { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: 4px; border: 1px solid #E5DDC8; }
  .footer { display: flex; justify-content: space-between; font-size: 9px; color: #7A7167; margin-top: 32px; padding-top: 12px; border-top: 1px solid #E5DDC8; }
</style>
</head>
<body>
<div class="a4">
  <div class="header">
    <span class="logo">OCRE<span class="logo-immo">immo</span></span>
    <span class="gen-date"><?= $genDate ?></span>
  </div>

  <h1><?= $nomH ?></h1>
  <div class="subtitle"><?= $projetH ?> · Référence #<?= (int) $dossier['id'] ?></div>

  <h2>I. Identité</h2>
  <div class="grid">
    <div class="field"><div class="label">Profil</div><div class="value"><?= _fld($projet) ?></div></div>
    <div class="field"><div class="label">Téléphone</div><div class="value"><?= _fld($dossier['tel']) ?></div></div>
    <div class="field"><div class="label">Email</div><div class="value"><?= _fld($dossier['email']) ?></div></div>
    <div class="field"><div class="label">Société</div><div class="value"><?= _fld($dossier['societe_nom']) ?></div></div>
    <div class="field"><div class="label">Pays résidence</div><div class="value"><?= _fld($data['pays_residence'] ?? null) ?></div></div>
    <div class="field"><div class="label">Nationalité</div><div class="value"><?= _fld($data['nationalite'] ?? null) ?></div></div>
  </div>

  <h2>II. Le Bien</h2>
  <div class="grid">
    <div class="field"><div class="label">Type</div><div class="value"><?= _fld($bien['type'] ?? null) ?></div></div>
    <div class="field"><div class="label">Statut</div><div class="value"><?= _fld($bien['statut'] ?? 'existant') ?></div></div>
    <div class="field"><div class="label">Pays</div><div class="value"><?= _fld($bien['pays'] ?? null) ?></div></div>
    <div class="field"><div class="label">Ville</div><div class="value"><?= _fld($bien['ville'] ?? null) ?></div></div>
    <div class="field"><div class="label">Quartier</div><div class="value"><?= _fld($bien['quartier'] ?? null) ?></div></div>
    <div class="field"><div class="label">Surface habitable</div><div class="value"><?= _fld($bien['surface_hab'] ?? $bien['surface'] ?? null) ?> m²</div></div>
    <div class="field"><div class="label">Surface terrain</div><div class="value"><?= _fld($bien['surface_terrain_v2'] ?? $bien['terrain'] ?? null) ?> m²</div></div>
    <div class="field"><div class="label">Chambres</div><div class="value"><?= _fld($bien['chambres_v2'] ?? $bien['chambres'] ?? null) ?></div></div>
    <div class="field"><div class="label">SDB</div><div class="value"><?= _fld($bien['sdb_v2'] ?? $bien['sdb'] ?? null) ?></div></div>
    <div class="field"><div class="label">État</div><div class="value"><?= _fld($bien['etat_general'] ?? null) ?></div></div>
    <div class="field"><div class="label">DPE / GES</div><div class="value"><?= _fld(($bien['dpe'] ?? '?') . ' / ' . ($bien['ges'] ?? '?')) ?></div></div>
    <div class="field"><div class="label">Vue</div><div class="value"><?= _chips((array) ($bien['vue'] ?? [])) ?></div></div>
  </div>
  <div class="grid-1">
    <div class="field"><div class="label">Espaces extérieurs</div><div class="value"><?= _chips((array) ($bien['espaces_exterieurs'] ?? [])) ?></div></div>
    <div class="field"><div class="label">Annexes</div><div class="value"><?= _chips((array) ($bien['annexes'] ?? [])) ?></div></div>
    <?php if (!empty($bien['authenticite_riad'])): ?>
    <div class="field"><div class="label">Authenticité Riad</div><div class="value"><?= _chips((array) $bien['authenticite_riad']) ?></div></div>
    <?php endif; ?>
    <?php if (($bien['pays'] ?? '') === 'MA' && !empty($bien['titre_statut'])): ?>
    <div class="field"><div class="label">Statut foncier</div><div class="value"><?= _fld($bien['titre_statut']) ?></div></div>
    <?php endif; ?>
  </div>

  <h2>III. Volet financier</h2>
  <div class="grid">
  <?php if (in_array($projet, ['Acheteur', 'Locataire', 'Investisseur'])): ?>
    <div class="field"><div class="label">Budget min</div><div class="value"><?= _fld($data['budget_min'] ?? null) ?></div></div>
    <div class="field"><div class="label">Budget max</div><div class="value"><?= _fld($data['budget_max'] ?? null) ?></div></div>
    <div class="field"><div class="label">Apport personnel</div><div class="value"><?= _fld($data['apport_personnel'] ?? null) ?></div></div>
    <div class="field"><div class="label">Pré-accord bancaire</div><div class="value"><?= _fld(!empty($data['pre_accord_bancaire']) ? 'Oui' : 'Non') ?></div></div>
  <?php else: ?>
    <div class="field"><div class="label">Prix demandé</div><div class="value"><?= _fld($data['prix_affiche'] ?? null) ?> <?= _fld($data['devise'] ?? '') ?></div></div>
    <div class="field"><div class="label">Loyer mensuel</div><div class="value"><?= _fld($data['loyer_demande'] ?? null) ?></div></div>
    <div class="field"><div class="label">Commission</div><div class="value"><?= _fld($data['commission_pct'] ?? null) ?> %</div></div>
    <div class="field"><div class="label">Mode paiement</div><div class="value"><?= _fld($data['mode_paiement'] ?? null) ?></div></div>
  <?php endif; ?>
  </div>

  <h2>IV. Complémentaire</h2>
  <div class="grid-1">
    <div class="field"><div class="label">Notes</div><div class="value"><?= nl2br(_fld($data['notes'] ?? null)) ?></div></div>
    <div class="field"><div class="label">Origine du contact</div><div class="value"><?= _fld($data['origine_contact'] ?? null) ?></div></div>
  </div>

  <?php if ($photos): ?>
  <h2>Photos</h2>
  <div class="photos-grid">
    <?php foreach ($photos as $p): ?>
    <img src="<?= htmlspecialchars($p) ?>" alt=""/>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="footer">
    <span><?= $nomH ?> · <?= $projetH ?></span>
    <span>Ocre Immo · Page 1 / 1</span>
  </div>
</div>
</body>
</html>
