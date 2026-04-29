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

// Lire le dossier dans la WSp source : essai mode agent puis mode test.
// M/2026/04/29/21 — capture du suffix gagnant pour afficher badge "test" si dossier source mode test.
$slug_clean = preg_replace('/[^a-z0-9_-]/', '', $link['wsp_slug']);
$dossier = null;
$_share_is_test = false;
foreach (['', '_test'] as $suffix) {
    try {
        $pdo = pdo_workspace('ocre_wsp_' . $slug_clean . $suffix);
        $d = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $d->execute([$link['dossier_id']]);
        $r = $d->fetch();
        if ($r) { $dossier = $r; $_share_is_test = ($suffix === '_test'); break; }
    } catch (Throwable $e) {}
}
if (!$dossier) { http_response_code(404); echo "Dossier introuvable"; exit; }

$nom = htmlspecialchars(trim(($dossier['prenom'] ?? '') . ' ' . strtoupper($dossier['nom'] ?? '')));
$projet = htmlspecialchars($dossier['projet'] ?? '');
$expires = htmlspecialchars($link['expires_at']);

// Décode payload data JSON pour les sections riches
$data = json_decode($dossier['data'] ?? '{}', true) ?: [];
function fld($v) { return $v ? htmlspecialchars($v) : '—'; }

// M/2026/04/29/38 — toggle Prix / Sur demande embedded URL.
$shareMode = ($_GET['mode'] ?? 'price') === 'demand' ? 'demand' : 'price';
$prixRaw = $data['prix_affiche'] ?? $data['prix'] ?? $data['budget_max'] ?? null;
$deviseRaw = $data['devise'] ?? '€';
function _fmtPrix($v, $d) {
    if (!$v) return '—';
    $n = (float) preg_replace('/[^\d.,-]/', '', str_replace(',', '.', (string) $v));
    if ($n == 0) return htmlspecialchars((string) $v);
    return number_format($n, 0, ',', ' ') . ' ' . htmlspecialchars($d);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Dossier · <?php echo $nom; ?> — Ocre immo</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Caveat:wght@400;500&display=swap" rel="stylesheet">
<style>
  body { font-family: 'DM Sans', system-ui, sans-serif; background: #E8E5E0; color: #1A1A1A; margin: 0; padding: 20px 16px; min-height: 100vh; }
  .a4 { max-width: 720px; margin: 0 auto; background: #fff; padding: 36px 32px; box-shadow: 0 4px 20px rgba(0,0,0,.08); border-radius: 4px; }
  .header { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: 14px; border-bottom: 2px solid #8B5E3C; margin-bottom: 24px; }
  .logo { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 22px; color: #8B5E3C; letter-spacing: 1.5px; }
  .logo-immo { font-family: 'Caveat', cursive; font-weight: 400; color: #2A1810; font-size: 26px; margin-left: 4px; }
  h1 { font-family: 'Cormorant Garamond', serif; font-size: 26px; color: #8B5E3C; margin: 0 0 4px; }
  .subtitle { color: #7A7167; font-size: 13px; }
  h2 { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 18px; color: #8B5E3C; margin: 28px 0 8px; padding-bottom: 4px; border-bottom: 0.5px solid rgba(139,94,60,.3); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin: 8px 0; }
  .field { padding: 4px 0; }
  .label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #7A7167; font-weight: 600; }
  .value { font-size: 13px; color: #1A1A1A; margin-top: 2px; }
  .value.empty { color: #999; font-style: italic; }
  .footer { text-align: center; font-size: 11px; color: #7A7167; margin-top: 40px; padding-top: 16px; border-top: 1px solid #E5DDC8; }
  .logo-wrap { display: inline-flex; flex-direction: column; align-items: center; }
  .test-badge { font-family: 'DM Sans', system-ui, sans-serif; font-size: 18px; font-weight: 500; letter-spacing: 1.5px; color: #DC2626; text-transform: lowercase; line-height: 1; margin-top: 2px; }
  /* M/2026/04/29/38 — bandeau 4 boutons toggle Prix/Sur demande charte v3. */
  body { padding-bottom: 80px; }
  .prix-pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 9px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; vertical-align: middle; margin-left: 8px; }
  .prix-pill.affiche { background: #F5EFE7; color: #8B5E3C; }
  .prix-pill.masque { background: rgba(196,69,59,0.12); color: #C4453B; }
  .prix-value.demand { color: #6b6b6b; font-style: italic; }
  .share-bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #E8E0D2; padding: 12px 14px calc(env(safe-area-inset-bottom, 0px) + 12px); z-index: 100; }
  .share-bar-grid { max-width: 720px; margin: 0 auto; display: grid; grid-template-columns: 1.5fr 1fr 1fr 1.6fr; gap: 10px; }
  .share-btn { height: 56px; border-radius: 12px; border: 1.5px solid #D6C7A8; background: #fff; color: #8B5E3C; font-family: 'DM Sans', sans-serif; font-weight: 600; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: transform 100ms ease, background 150ms ease, border-color 150ms ease; }
  .share-btn:hover { border-color: #8B5E3C; background: #F5EFE7; }
  .share-btn:active { transform: scale(0.98); }
  .share-btn.toggle { flex-direction: column; gap: 2px; }
  .share-btn.toggle .lbl-mini { font-size: 10px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; opacity: 0.85; }
  .share-btn.active { background: #8B5E3C; border-color: #8B5E3C; color: #fff; box-shadow: inset 0 1px 3px rgba(0,0,0,0.2), 0 2px 8px rgba(139,94,60,0.25); }
  .share-btn.active.demand { background: #6B4520; border-color: #6B4520; }
  .share-btn svg { flex-shrink: 0; }
  @media (max-width: 720px) { .share-bar-grid { gap: 6px; } .share-btn { height: 50px; font-size: 14px; } .share-btn.toggle .lbl-mini { font-size: 9px; } }
  @media (max-width: 480px) { .share-btn .lbl-full { display: none; } .share-bar-grid { grid-template-columns: 1fr 1fr 1fr 1fr; } }
</style>
</head>
<body>
<div class="a4">
  <div class="header">
    <span class="logo-wrap">
      <span class="logo">OCRE<span class="logo-immo">immo</span></span>
      <?php if ($_share_is_test): ?><span class="test-badge">test</span><?php endif; ?>
    </span>
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
    <div class="field">
      <div class="label">
        Prix / Budget
        <span class="prix-pill <?php echo $shareMode === 'demand' ? 'masque' : 'affiche'; ?>" id="prix-pill"><?php echo $shareMode === 'demand' ? 'Masqué' : 'Affiché'; ?></span>
      </div>
      <div class="value prix-value <?php echo $shareMode === 'demand' ? 'demand' : ''; ?>" id="prix-value"
           data-prix-real="<?php echo htmlspecialchars(_fmtPrix($prixRaw, $deviseRaw)); ?>"
           data-prix-demand="Sur demande">
        <?php echo $shareMode === 'demand' ? 'Sur demande' : _fmtPrix($prixRaw, $deviseRaw); ?>
      </div>
    </div>
    <div class="field"><div class="label">Financement</div><div class="value <?php echo empty($data['financement']) ? 'empty' : ''; ?>"><?php echo fld($data['financement']['mode'] ?? null); ?></div></div>
  </div>

  <h2>IV. Complémentaire</h2>
  <div class="field"><div class="label">Notes</div><div class="value <?php echo empty($dossier['data']) ? 'empty' : ''; ?>"><?php echo nl2br(fld($data['notes'] ?? null)); ?></div></div>

  <div class="footer">
    Document partagé via Ocre Immo · expire le <?php echo $expires; ?> · Vue <?php echo (int)$link['viewed_count']; ?>×
  </div>
</div>

<!-- M/2026/04/29/38 — bandeau footer 4 boutons toggle Prix / Sur demande -->
<div class="share-bar" data-dossier-id="<?php echo (int) $dossier['id']; ?>">
  <div class="share-bar-grid">
    <button type="button" class="share-btn" id="btn-cancel" aria-label="Annuler">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      <span class="lbl-full">Annuler</span>
    </button>
    <button type="button" class="share-btn toggle <?php echo $shareMode === 'price' ? 'active' : ''; ?>" id="btn-price" aria-label="Afficher le prix">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 7.5a4 4 0 0 0-7 0M5 12h9M5 16h9M14 16.5a4 4 0 0 1-7 0"/></svg>
      <span class="lbl-mini">Prix</span>
    </button>
    <button type="button" class="share-btn toggle demand <?php echo $shareMode === 'demand' ? 'active' : ''; ?>" id="btn-demand" aria-label="Sur demande">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 7.5a4 4 0 0 0-7 0M5 12h9M5 16h9M14 16.5a4 4 0 0 1-7 0"/><line x1="3" y1="3" x2="21" y2="21" stroke="#C4453B" stroke-width="2.5"/></svg>
      <span class="lbl-mini">Sur demande</span>
    </button>
    <button type="button" class="share-btn" id="btn-share" aria-label="Partager">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      <span class="lbl-full">Partager</span>
    </button>
  </div>
</div>

<script>
(function() {
  var dossierId = document.querySelector('.share-bar').getAttribute('data-dossier-id');
  var btnCancel = document.getElementById('btn-cancel');
  var btnPrice = document.getElementById('btn-price');
  var btnDemand = document.getElementById('btn-demand');
  var btnShare = document.getElementById('btn-share');
  var prixVal = document.getElementById('prix-value');
  var prixPill = document.getElementById('prix-pill');
  var currentMode = (new URLSearchParams(location.search).get('mode') === 'demand') ? 'demand' : 'price';
  // Persist last chosen mode per dossier in localStorage.
  var lsKey = 'ocre_share_mode_' + dossierId;
  if (!new URLSearchParams(location.search).has('mode')) {
    var stored = localStorage.getItem(lsKey);
    if (stored === 'demand' || stored === 'price') currentMode = stored;
  }
  function applyMode(mode) {
    currentMode = mode;
    btnPrice.classList.toggle('active', mode === 'price');
    btnDemand.classList.toggle('active', mode === 'demand');
    if (prixVal) {
      if (mode === 'demand') {
        prixVal.textContent = prixVal.getAttribute('data-prix-demand');
        prixVal.classList.add('demand');
      } else {
        prixVal.textContent = prixVal.getAttribute('data-prix-real');
        prixVal.classList.remove('demand');
      }
    }
    if (prixPill) {
      prixPill.textContent = mode === 'demand' ? 'Masqué' : 'Affiché';
      prixPill.classList.toggle('masque', mode === 'demand');
      prixPill.classList.toggle('affiche', mode !== 'demand');
    }
    localStorage.setItem(lsKey, mode);
    var u = new URL(location.href);
    u.searchParams.set('mode', mode);
    history.replaceState(null, '', u.toString());
  }
  applyMode(currentMode);
  btnPrice.addEventListener('click', function() { applyMode('price'); });
  btnDemand.addEventListener('click', function() { applyMode('demand'); });
  btnCancel.addEventListener('click', function() { history.length > 1 ? history.back() : window.close(); });
  btnShare.addEventListener('click', function() {
    var u = new URL(location.href);
    u.searchParams.set('mode', currentMode);
    var url = u.toString();
    if (navigator.share) {
      navigator.share({url: url, title: document.title}).catch(function() {});
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function() { alert('Lien copié dans le presse-papier'); });
    } else {
      prompt('Copier le lien :', url);
    }
  });
})();
</script>
</body>
</html>
