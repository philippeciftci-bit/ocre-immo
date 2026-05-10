<?php
/**
 * Template Name: Launcher Ocre PWA (M_OCRE_PARCOURS_V4)
 *
 * Mini-launcher PWA Ocre : grid 2x3 outils (debloques colores tappables / non-debloques grises "en cours" non-tappables)
 * Page reservee aux users connectes (cookie ocre_jwt requis), sinon redirect home publique.
 * Utilisation : creer page WP slug 'launcher' assignee à ce template.
 *
 * Pour s'assurer que /launcher est accessible : besoin de creer une page WP avec ce template
 * (action manuelle Philippe wp-admin ou via WP-CLI : wp post create --post_type=page --post_status=publish --post_title='Launcher' --post_name='launcher' + meta _wp_page_template=launcher.php)
 */
require_once get_stylesheet_directory() . '/parts/auth-helper.php';

if (!ocre_is_logged_in()) {
    wp_redirect(home_url('/'));
    exit;
}

// Decode JWT pour récupérer user_id (sub) puis fetch modules debloques via /api/me-modules.php
$payload = ocre_decode_jwt_payload($_COOKIE['ocre_jwt']);
$user_id = (int)($payload['sub'] ?? 0);
$first_name = (string)($payload['first_name'] ?? '');

// Catalogue 6 outils (synchronisé front-page.php)
$tools = [
    'agent'   => ['name'=>'Agent',     'icon'=>'🏠', 'color'=>'#8B5E3C', 'url'=>'https://app.ocre.immo/oi-agent'],
    'demande' => ['name'=>'Recherche', 'icon'=>'🔍', 'color'=>'#6B5642', 'url'=>'https://app.ocre.immo/oi-recherche'],
    'scan'    => ['name'=>'Scan',      'icon'=>'📷', 'color'=>'#998877', 'url'=>'https://app.ocre.immo/oi-scan'],
    'book'    => ['name'=>'Voyage',    'icon'=>'✈️', 'color'=>'#C9A961', 'url'=>'https://app.ocre.immo/oi-book'],
    'capture' => ['name'=>'Capture',   'icon'=>'📄', 'color'=>'#D4A256', 'url'=>'https://app.ocre.immo/oi-capture'],
    'estimer' => ['name'=>'Estimer',   'icon'=>'💰', 'color'=>'#E8B95E', 'url'=>'https://app.ocre.immo/oi-estimer'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#3D2818">
<title>Mes outils · Ocre</title>
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/icons/icon-180.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@1,500;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--cream:#FAF6F1;--cream-2:#F4ECDF;--brown:#3D2818;--brown-soft:#6B5642;--brown-mute:#998877;--ocre-dark:#8B5E3C;--gold:#D4A256;--gold-bright:#E8B95E;--line:#E5DAC6;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;background:linear-gradient(180deg,var(--cream) 0%,var(--cream-2) 100%);color:var(--brown);min-height:100vh;display:flex;flex-direction:column;}
.lh-header{padding:30px 24px 14px;text-align:center;}
.lh-brand{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-weight:600;font-size:34px;color:var(--ocre-dark);letter-spacing:-0.02em;margin-bottom:4px;}
.lh-brand span{color:var(--gold);}
.lh-sub{font-size:13px;color:var(--brown-soft);font-style:italic;}
.lh-greet{margin-top:4px;font-size:12px;color:var(--brown-mute);}

.lh-grid{flex:1;display:grid;grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(3,1fr);gap:14px;padding:18px 22px 30px;max-width:480px;margin:0 auto;width:100%;}
.lh-tile{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px 14px 18px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;text-decoration:none;color:var(--brown);transition:transform .25s,box-shadow .25s;cursor:pointer;}
.lh-tile:hover{transform:translateY(-3px);box-shadow:0 12px 28px -8px rgba(60,40,20,0.18);}
.lh-tile.lh-locked{opacity:0.5;cursor:default;background:#F0E8DA;filter:grayscale(0.85) sepia(0.15);}
.lh-tile.lh-locked:hover{transform:none;box-shadow:none;}
.lh-icon{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;margin-bottom:10px;}
.lh-tile.lh-active .lh-icon{background:linear-gradient(135deg,var(--gold-bright) 0%,var(--ocre-dark) 100%);color:#fff;box-shadow:0 6px 16px rgba(139,94,60,0.3);}
.lh-tile.lh-locked .lh-icon{background:#D9C9A8;color:#8B7E68;}
.lh-name{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-weight:600;font-size:18px;line-height:1.1;}
.lh-tile.lh-locked .lh-name{font-style:italic;font-size:14px;color:var(--brown-mute);font-family:'Inter',sans-serif;font-weight:500;}

.lh-foot{padding:18px 24px 24px;text-align:center;font-size:12px;color:var(--brown-mute);display:flex;justify-content:center;gap:18px;border-top:1px solid var(--line);background:rgba(255,255,255,0.5);}
.lh-foot a{color:var(--ocre-dark);text-decoration:none;font-weight:600;}
.lh-foot a:hover{color:var(--brown);}
</style>
</head>
<body>

<header class="lh-header">
  <div class="lh-brand">Oc<span>re</span></div>
  <div class="lh-sub">Tes outils gratuits</div>
  <?php if ($first_name): ?><div class="lh-greet">Bonjour <?php echo esc_html($first_name); ?></div><?php endif; ?>
</header>

<main class="lh-grid" id="lh-grid">
  <!-- Tuiles rendues en JS après fetch /api/me-modules.php pour avoir les vrais modules débloqués -->
  <?php foreach ($tools as $slug => $t): ?>
  <a href="<?php echo esc_url($t['url']); ?>" class="lh-tile lh-locked" data-slug="<?php echo esc_attr($slug); ?>">
    <div class="lh-icon"><?php echo $t['icon']; ?></div>
    <div class="lh-name">en cours</div>
  </a>
  <?php endforeach; ?>
</main>

<footer class="lh-foot">
  <a href="https://app.ocre.immo/account">Mon compte</a>
  <a href="https://auth.ocre.immo/api/logout.php">Se déconnecter</a>
</footer>

<script>
// Catalogue tools coté JS (sync PHP)
var TOOLS = <?php echo json_encode($tools, JSON_UNESCAPED_UNICODE); ?>;

// Fetch modules debloques + render
fetch('https://auth.ocre.immo/api/me-modules.php', { credentials: 'include' })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (!d.ok) return;
    var activeSlugs = (d.modules || []).map(function(m){ return m.module_slug; });
    document.querySelectorAll('.lh-tile').forEach(function(tile){
      var slug = tile.dataset.slug;
      if (activeSlugs.indexOf(slug) !== -1) {
        tile.classList.remove('lh-locked');
        tile.classList.add('lh-active');
        tile.querySelector('.lh-name').textContent = (TOOLS[slug] && TOOLS[slug].name) || slug;
      } else {
        tile.removeAttribute('href'); // empêche tap effectif sur tuile non-débloquée
        tile.style.pointerEvents = 'none';
      }
    });
  })
  .catch(function(){ /* offline : garder tout en "en cours" */ });
</script>
</body>
</html>
