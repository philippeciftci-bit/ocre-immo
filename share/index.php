<?php
// V20 Mission B — page publique lecture seule du dossier partagé via token.
// URL : https://<wsp-slug>.ocre.immo/share/<token>
require_once __DIR__ . '/../api/lib/router.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
if (!$token || strlen($token) < 32) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;text-align:center'><h1>Lien invalide</h1></body></html>";
    exit;
}

$meta = pdo_meta();
$st = $meta->prepare(
    "SELECT * FROM shared_links WHERE token = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1"
);
$st->execute([$token]);
$link = $st->fetch();
if (!$link) {
    http_response_code(410);
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;text-align:center;background:#F0E8D8'><h1 style='color:#8B5E3C'>Lien expiré</h1><p>Ce lien n'est plus valide.</p></body></html>";
    exit;
}

// Incr viewed_count
$meta->prepare("UPDATE shared_links SET viewed_count = viewed_count + 1, last_viewed_at = NOW() WHERE id = ?")->execute([$link['id']]);

// Lire le dossier dans la WSp source : essai mode agent puis mode test
$slug_clean = preg_replace('/[^a-z0-9_-]/', '', $link['wsp_slug']);
$dossier = null;
foreach (['', '_test'] as $suffix) {
    try {
        $pdo = pdo_workspace('ocre_wsp_' . $slug_clean . $suffix);
        $d = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $d->execute([$link['dossier_id']]);
        $r = $d->fetch();
        if ($r) { $dossier = $r; break; }
    } catch (Throwable $e) {}
}
if (!$dossier) { http_response_code(404); echo "Dossier introuvable"; exit; }

$nom = htmlspecialchars(trim(($dossier['prenom'] ?? '') . ' ' . strtoupper($dossier['nom'] ?? '')));
$projet = htmlspecialchars($dossier['projet'] ?? '');
$expires = htmlspecialchars($link['expires_at']);

// Décode payload data JSON pour les sections riches
$data = json_decode($dossier['data'] ?? '{}', true) ?: [];
function fld($v) { return $v ? htmlspecialchars($v) : '—'; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Dossier · <?php echo $nom; ?> — Ocre Immo</title>
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; background: #E8E5E0; color: #1A1A1A; margin: 0; padding: 20px 16px; min-height: 100vh; }
  .a4 { max-width: 720px; margin: 0 auto; background: #fff; padding: 36px 32px; box-shadow: 0 4px 20px rgba(0,0,0,.08); border-radius: 4px; }
  .header { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: 14px; border-bottom: 2px solid #8B5E3C; margin-bottom: 24px; }
  .logo { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 22px; color: #8B5E3C; letter-spacing: 1.5px; }
  .logo-immo { font-family: 'Brush Script MT', cursive; color: #1A1A1A; font-size: 24px; margin-left: 4px; }
  h1 { font-family: 'Cormorant Garamond', serif; font-size: 26px; color: #8B5E3C; margin: 0 0 4px; }
  .subtitle { color: #7A7167; font-size: 13px; }
  h2 { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 18px; color: #8B5E3C; margin: 28px 0 8px; padding-bottom: 4px; border-bottom: 0.5px solid rgba(139,94,60,.3); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin: 8px 0; }
  .field { padding: 4px 0; }
  .label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #7A7167; font-weight: 600; }
  .value { font-size: 13px; color: #1A1A1A; margin-top: 2px; }
  .value.empty { color: #999; font-style: italic; }
  .footer { text-align: center; font-size: 11px; color: #7A7167; margin-top: 40px; padding-top: 16px; border-top: 1px solid #E5DDC8; }
</style>
</head>
<body>
<div class="a4">
  <div class="header">
    <span class="logo">OCRE<span class="logo-immo">immo</span></span>
    <span class="subtitle">Dossier <?php echo $projet; ?></span>
  </div>

  <h1><?php echo $nom; ?></h1>
  <div class="subtitle">Référence dossier : #<?php echo (int)$dossier['id']; ?></div>

  <h2>I. Identité</h2>
  <div class="grid">
    <div class="field"><div class="label">Profil</div><div class="value"><?php echo $projet; ?></div></div>
    <div class="field"><div class="label">Téléphone</div><div class="value <?php echo empty($dossier['tel']) ? 'empty' : ''; ?>"><?php echo fld($dossier['tel']); ?></div></div>
    <div class="field"><div class="label">Email</div><div class="value <?php echo empty($dossier['email']) ? 'empty' : ''; ?>"><?php echo fld($dossier['email']); ?></div></div>
    <div class="field"><div class="label">Société</div><div class="value <?php echo empty($dossier['societe_nom']) ? 'empty' : ''; ?>"><?php echo fld($dossier['societe_nom']); ?></div></div>
  </div>

  <h2>II. Le Bien</h2>
  <div class="grid">
    <div class="field"><div class="label">Type</div><div class="value <?php echo empty($data['bien']['type']) ? 'empty' : ''; ?>"><?php echo fld($data['bien']['type'] ?? null); ?></div></div>
    <div class="field"><div class="label">Ville</div><div class="value <?php echo empty($data['bien']['ville']) ? 'empty' : ''; ?>"><?php echo fld($data['bien']['ville'] ?? null); ?></div></div>
    <div class="field"><div class="label">Surface</div><div class="value <?php echo empty($data['bien']['surface']) ? 'empty' : ''; ?>"><?php echo fld($data['bien']['surface'] ?? null); ?> m²</div></div>
    <div class="field"><div class="label">Chambres</div><div class="value <?php echo empty($data['bien']['chambres']) ? 'empty' : ''; ?>"><?php echo fld($data['bien']['chambres'] ?? null); ?></div></div>
  </div>

  <h2>III. Volet financier</h2>
  <div class="grid">
    <div class="field"><div class="label">Prix / Budget</div><div class="value <?php echo empty($data['prix']) && empty($data['budget_max']) ? 'empty' : ''; ?>"><?php echo fld($data['prix'] ?? $data['budget_max'] ?? null); ?></div></div>
    <div class="field"><div class="label">Financement</div><div class="value <?php echo empty($data['financement']) ? 'empty' : ''; ?>"><?php echo fld($data['financement']['mode'] ?? null); ?></div></div>
  </div>

  <h2>IV. Complémentaire</h2>
  <div class="field"><div class="label">Notes</div><div class="value <?php echo empty($dossier['data']) ? 'empty' : ''; ?>"><?php echo nl2br(fld($data['notes'] ?? null)); ?></div></div>

  <div class="footer">
    Document partagé via Ocre Immo · expire le <?php echo $expires; ?> · Vue <?php echo (int)$link['viewed_count']; ?>×
  </div>
</div>
</body>
</html>
