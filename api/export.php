<?php
// V17.3 Phase 2b — export PDF via print-to-PDF navigateur.
// Retourne une page HTML print-ready avec window.print() auto + iOS-compat.
// Auth : X-Session-Token OU ?token= (pour ouverture window.open directe).
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';
if ($action !== 'pdf') { http_response_code(404); echo 'Action inconnue'; exit; }

$user = requireAuth();
$dossier_id = (int)($_GET['dossier_id'] ?? 0);
if (!$dossier_id) { http_response_code(400); echo 'dossier_id requis'; exit; }

$stmt = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$dossier_id, $user['id']]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); echo 'Introuvable'; exit; }
$d = json_decode($r['data'] ?? '{}', true) ?: [];
$d['id'] = (int)$r['id'];
$d['projet'] = $r['projet'];
$d['is_investisseur'] = (bool)(int)$r['is_investisseur'];

// Photos
$photos = [];
$dir = dirname(__DIR__) . '/uploads/' . $dossier_id;
if (is_dir($dir)) {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://app.ocre.immo';
    foreach (glob($dir . '/*') ?: [] as $p) {
        if (!is_file($p)) continue;
        $n = basename($p);
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $n)) continue;
        $photos[] = $base . '/uploads/' . $dossier_id . '/' . $n;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function row($k, $v) {
    if ($v === '' || $v === null || (is_array($v) && !$v)) return '';
    if (is_array($v)) $v = implode(', ', $v);
    return '<tr><th>' . h($k) . '</th><td>' . h($v) . '</td></tr>';
}
function range_val($min, $max, $unit = '') {
    if (($min === '' || $min === null) && ($max === '' || $max === null)) return '';
    $u = $unit ? ' ' . $unit : '';
    if ($max === '' || $max === null) return '≥ ' . $min . $u;
    if ($min === '' || $min === null) return '≤ ' . $max . $u;
    return $min . ' → ' . $max . $u;
}
function display_date($iso) {
    if (!$iso) return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return $m[3] . '/' . $m[2] . '/' . $m[1];
    return $iso;
}

$b = $d['bien'] ?? [];
$isAsking = in_array($d['projet'] ?? '', ['Acheteur','Locataire'], true);
$name = (($d['profil_type'] ?? '') === 'Société' && !empty($d['societe_nom']))
    ? $d['societe_nom']
    : (trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')) ?: 'Dossier');
$now = date('d/m/Y');
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Ocre Immo · <?= h($name) ?></title>
<style>
@page { size: A4; margin: 16mm 14mm; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: 'DM Sans', -apple-system, sans-serif; color: #2A2018; font-size: 11pt; margin: 0; padding: 16px; }
h1 { font-family: Georgia, serif; color: #8B5E3C; font-size: 26pt; margin: 0 0 4px; font-weight: 700; }
.sub { color: #8B7F6E; font-size: 9pt; margin-bottom: 14px; }
.section { margin-bottom: 14px; page-break-inside: avoid; border: 1px solid #E8DDD0; border-radius: 8px; padding: 10px 12px; }
.section h2 { font-family: Georgia, serif; color: #8B5E3C; font-size: 14pt; margin: 0 0 8px; font-weight: 700; border-bottom: 1px solid #F4EEE6; padding-bottom: 4px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; vertical-align: top; padding: 3px 6px; font-size: 10pt; }
th { color: #8B7F6E; font-weight: 600; width: 38%; text-transform: uppercase; font-size: 8pt; letter-spacing: .4px; }
td { color: #2A2018; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #FFF3EC; color: #8B5E3C; font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-right: 4px; }
.photos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
.photos img { width: 100%; height: 80px; object-fit: cover; border-radius: 4px; border: 1px solid #E8DDD0; }
.notes { white-space: pre-wrap; font-size: 10pt; padding: 4px 6px; }
.footer { text-align: center; font-size: 8pt; color: #8B7F6E; margin-top: 20px; border-top: 1px solid #F4EEE6; padding-top: 6px; }
.print-btn { position: fixed; top: 12px; right: 12px; padding: 10px 16px; background: #8B5E3C; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 13px; z-index:10; }
@media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
<h1><?= h($name) ?></h1>
<div class="sub">
  <span class="badge"><?= h($d['projet'] ?? 'Acheteur') ?></span>
  <?php if (!empty($d['is_investisseur'])): ?><span class="badge">💎 Investisseur</span><?php endif; ?>
  · Généré le <?= $now ?> · ID #<?= (int)$d['id'] ?>
</div>

<div class="section">
  <h2>Le contact</h2>
  <table>
    <?php if (($d['profil_type'] ?? '') === 'Société'): ?>
      <?= row('Raison sociale', $d['societe_nom'] ?? '') ?>
      <?= row('Forme juridique', $d['forme_juridique'] ?? '') ?>
      <?= row('Représentant', $d['representant'] ?? '') ?>
      <?= row('SIRET / ICE', $d['siret'] ?? '') ?>
    <?php else: ?>
      <?= row('Prénom', $d['prenom'] ?? '') ?>
      <?= row('Nom', $d['nom'] ?? '') ?>
      <?= row('Nationalité', $d['nationalite'] ?? '') ?>
      <?= row('Né le', display_date($d['date_naissance'] ?? '')) ?>
    <?php endif; ?>
    <?= row('Téléphone', $d['tel'] ?? '') ?>
    <?= row('Email', $d['email'] ?? '') ?>
    <?= row('Adresse', $d['adresse'] ?? '') ?>
    <?= row('Ville', $d['ville'] ?? '') ?>
    <?= row('Pays résidence', $d['pays_residence'] ?? '') ?>
  </table>
</div>

<?php if (!empty($b['type']) || !empty($b['pays'])): ?>
<div class="section">
  <h2>Le bien</h2>
  <table>
    <?= row('Pays', $b['pays'] ?? '') ?>
    <?= row('Type', $b['type'] ?? '') ?>
    <?= row('Adresse / quartier', $b['adresse'] ?? '') ?>
    <?= row('Ville', $b['ville'] ?? '') ?>
    <?php if (!empty($b['gps_lat']) && !empty($b['gps_lng'])): ?>
      <?= row('GPS', $b['gps_lat'] . ', ' . $b['gps_lng'] . (($b['gps_mode'] ?? '') === 'zone' ? ' (zone ' . (int)$b['gps_radius'] . ' m)' : '')) ?>
    <?php elseif (!empty($b['gps'])): ?>
      <?= row('GPS', $b['gps']) ?>
    <?php endif; ?>

    <?php if (in_array($b['type'] ?? '', ['Villa','Appartement','Riad','Maison'], true)): ?>
      <?php if ($isAsking): ?>
        <?= row('Chambres', range_val($b['chambres_min'] ?? '', $b['chambres_max'] ?? '')) ?>
        <?= row('SDB', range_val($b['sdb_min'] ?? '', $b['sdb_max'] ?? '')) ?>
        <?= row('Surface habitable', range_val($b['surface_min'] ?? '', $b['surface_max'] ?? '', 'm²')) ?>
      <?php else: ?>
        <?= row('Chambres', $b['chambres'] ?? '') ?>
        <?= row('SDB', $b['sdb'] ?? '') ?>
        <?= row('Surface', ($b['surface'] ?? '') ? ($b['surface'] . ' m²') : '') ?>
      <?php endif; ?>
      <?= row('Année', $b['annee'] ?? '') ?>
    <?php endif; ?>

    <?php // Villa
    if (($b['type'] ?? '') === 'Villa'):
      if ($isAsking) echo row('Surface terrain', range_val($b['terrain_min'] ?? '', $b['terrain_max'] ?? '', 'm²'));
      else if (!empty($b['terrain'])) echo row('Surface terrain', $b['terrain'] . ' m²');
    endif; ?>

    <?php // Appartement
    if (($b['type'] ?? '') === 'Appartement'):
      echo row('Étage', $b['etage'] ?? '');
      echo row('Étages immeuble', $b['total_etages'] ?? '');
      echo row('Charges / mois', $b['charges_mensuelles'] ?? '');
      echo row('Taxe foncière / an', $b['taxe_fonciere'] ?? '');
    endif; ?>

    <?php // Riad
    if (($b['type'] ?? '') === 'Riad'):
      echo row('Authenticité', $b['authenticite'] ?? '');
      $orn = []; foreach (['zellige','tadelakt','bejmat','fontaine','douiria'] as $k) if (!empty($b[$k])) $orn[] = ucfirst($k);
      if ($orn) echo row('Ornements', $orn);
    endif; ?>

    <?php // Maison
    if (($b['type'] ?? '') === 'Maison'):
      echo row('Configuration', $b['maison_config'] ?? '');
      if (($b['maison_config'] ?? '') === 'Étages') echo row('Niveaux', $isAsking ? range_val($b['niveaux_min'] ?? '', $b['niveaux_max'] ?? '') : ($b['niveaux'] ?? ''));
      if ($isAsking) {
        echo row('Habitable totale', range_val($b['habitable_totale_min'] ?? '', $b['habitable_totale_max'] ?? '', 'm²'));
        echo row('Terrain', range_val($b['terrain_min'] ?? '', $b['terrain_max'] ?? '', 'm²'));
      } else {
        if (!empty($b['habitable_totale'])) echo row('Habitable totale', $b['habitable_totale'] . ' m²');
        if (!empty($b['terrain'])) echo row('Terrain', $b['terrain'] . ' m²');
      }
      $maisonExt = []; foreach (['sous_sol' => 'Sous-sol', 'combles_amenages' => 'Combles aménagés', 'garage' => 'Garage'] as $k => $l) if (!empty($b[$k])) $maisonExt[] = $l;
      if ($maisonExt) echo row('Équipements', $maisonExt);
    endif; ?>

    <?php // Terrain
    if (($b['type'] ?? '') === 'Terrain'):
      echo row('Nature', $b['terrain_nature'] ?? '');
      if ($isAsking) echo row('Surface', range_val($b['terrain_surface_min'] ?? '', $b['terrain_surface_max'] ?? '', 'm²'));
      else if (!empty($b['terrain_surface'])) echo row('Surface', $b['terrain_surface'] . ' m²');
      echo row('COS / CES', $b['cos_ces'] ?? '');
      echo row('Hauteur max', !empty($b['hauteur_max']) ? $b['hauteur_max'] . ' m' : '');
      echo row('Forme', $b['forme_terrain'] ?? '');
      echo row('Pente', $b['pente'] ?? '');
      $viab = []; foreach (['eau_ville' => 'Eau', 'electricite' => 'Électricité', 'egout' => 'Égout', 'gaz_ville' => 'Gaz', 'acces_routier' => 'Accès routier'] as $k => $l) if (!empty($b[$k])) $viab[] = $l;
      if ($viab) echo row('Viabilisation', $viab);
    endif; ?>

    <?php // Commerce
    if (($b['type'] ?? '') === 'Commerce'):
      if ($isAsking) {
        echo row('Surface vente', range_val($b['surface_vente_min'] ?? '', $b['surface_vente_max'] ?? '', 'm²'));
        echo row('Surface stockage', range_val($b['surface_stockage_min'] ?? '', $b['surface_stockage_max'] ?? '', 'm²'));
        echo row('Vitrine', range_val($b['vitrine_min'] ?? '', $b['vitrine_max'] ?? '', 'm'));
      } else {
        if (!empty($b['surface_vente'])) echo row('Surface vente', $b['surface_vente'] . ' m²');
        if (!empty($b['surface_stockage'])) echo row('Surface stockage', $b['surface_stockage'] . ' m²');
        if (!empty($b['vitrine_lineaire'])) echo row('Vitrine', $b['vitrine_lineaire'] . ' m');
      }
      echo row('Emplacement', $b['emplacement'] ?? '');
      echo row('Bail / Murs', $b['bail_ou_murs'] ?? '');
      echo row('Activité autorisée', $b['activite_autorisee'] ?? '');
      echo row('Flux piétons / j', $b['flux_pietons'] ?? '');
    endif; ?>

    <?php // Industriel
    if (($b['type'] ?? '') === 'Industriel'):
      if ($isAsking) {
        echo row('Bâti', range_val($b['bati_min'] ?? '', $b['bati_max'] ?? '', 'm²'));
        echo row('Terrain', range_val($b['terrain_ind_min'] ?? '', $b['terrain_ind_max'] ?? '', 'm²'));
        echo row('Hauteur plafond', range_val($b['hauteur_plafond_min'] ?? '', $b['hauteur_plafond_max'] ?? '', 'm'));
      } else {
        if (!empty($b['bati'])) echo row('Bâti', $b['bati'] . ' m²');
        if (!empty($b['terrain_ind'])) echo row('Terrain', $b['terrain_ind'] . ' m²');
        if (!empty($b['hauteur_plafond'])) echo row('Hauteur plafond', $b['hauteur_plafond'] . ' m');
      }
      echo row('Quais de chargement', $b['quais_chargement'] ?? '');
      echo row('Puissance élec (kVA)', $b['puissance_electrique'] ?? '');
      if (!empty($b['monte_charge'])) echo row('Monte-charge', 'Oui');
      echo row('Classement ICPE', $b['icpe'] ?? '');
    endif; ?>

    <?php if (in_array($b['type'] ?? '', ['Villa','Appartement','Riad','Maison'], true)): ?>
      <?php if (!empty($b['energies']) && is_array($b['energies'])) echo row('Raccordements', $b['energies']); ?>
      <?= row('Chauffage', $b['chauffage_type'] ?? '') ?>
      <?php
      $conf = [];
      if (!empty($b['chauffe_eau_solaire'])) $conf[] = 'Chauffe-eau solaire';
      if (!empty($b['climatisation'])) $conf[] = 'Climatisation';
      if (!empty($b['ascenseur'])) $conf[] = 'Ascenseur';
      if ($conf) echo row('Confort', $conf);
      ?>
    <?php endif; ?>

    <?php // Documents
    if (($b['pays'] ?? '') === 'FR') {
      echo row('DPE', $b['dpe'] ?? '');
      echo row('GES', $b['ges'] ?? '');
      if (!empty($b['loi_carrez'])) echo row('Surface Loi Carrez', $b['loi_carrez'] . ' m²');
    }
    if (($b['pays'] ?? '') === 'ES' && !empty($b['nota_simple'])) echo row('Nota Simple', 'Disponible');
    if (($b['pays'] ?? '') === 'MA') {
      echo row('Statut foncier', $b['titre_statut'] ?? '');
      if (($b['titre_statut'] ?? '') === 'Titré' && !empty($b['titre_num'])) echo row('N° Titre foncier', $b['titre_num']);
    } ?>
  </table>
</div>
<?php endif; ?>

<div class="section">
  <h2>Budget & conditions</h2>
  <table>
    <?php
    $proj = $d['projet'] ?? '';
    if ($proj === 'Acheteur') {
      echo row('Budget', range_val($d['budget_min'] ?? '', $d['budget_max'] ?? ''));
      echo row('Apport', $d['apport'] ?? '');
      echo row('Financement', $d['financement'] ?? '');
    }
    if ($proj === 'Vendeur') {
      echo row('Prix affiché', $d['prix_affiche'] ?? '');
    }
    if ($proj === 'Locataire') {
      echo row('Loyer max', $d['loyer_max'] ?? '');
      echo row('Garants', $d['garants'] ?? '');
    }
    if ($proj === 'Bailleur') {
      echo row('Loyer demandé', $d['loyer_demande'] ?? '');
      echo row('Charges', $d['charges'] ?? '');
      echo row('Dépôt', $d['depot'] ?? '');
    }
    if (!empty($d['is_investisseur'])) {
      echo row('Rendement cible', !empty($d['rendement_cible']) ? $d['rendement_cible'] . ' %' : '');
      echo row('Horizon (ans)', $d['horizon'] ?? '');
    }
    ?>
  </table>
</div>

<?php if (!empty($d['notes']) || !empty($d['canal']) || !empty($d['origine']) || !empty($d['langue'])): ?>
<div class="section">
  <h2>Infos complémentaires</h2>
  <?php if (!empty($d['notes'])): ?><div class="notes"><?= h($d['notes']) ?></div><?php endif; ?>
  <table>
    <?= row('Langue', $d['langue'] ?? '') ?>
    <?= row('Canal', $d['canal'] ?? '') ?>
    <?= row('Origine', $d['origine'] ?? '') ?>
  </table>
</div>
<?php endif; ?>

<?php if ($photos): ?>
<div class="section">
  <h2>Photos · <?= count($photos) ?></h2>
  <div class="photos">
    <?php foreach ($photos as $url): ?>
      <img src="<?= h($url) ?>" alt=""/>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="footer">
  Ocre Immo · Dossier #<?= (int)$d['id'] ?> · <?= $now ?> · <?= h(APP_URL) ?>
</div>

<script>
window.addEventListener('load', function() {
  setTimeout(function() { window.print(); }, 600);
});
</script>
</body>
</html>
