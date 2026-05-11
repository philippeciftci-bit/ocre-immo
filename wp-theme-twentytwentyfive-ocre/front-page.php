<?php
/**
 * M_OCRE_HOME_VISUELLE — Refonte radicale home en HUB outils gratuits visuel.
 * Style Airbnb/Notion/Apple inspired. Zero paragraphe >3 lignes. Photos Unsplash CDN.
 * Rejette M102 textuel (9 sections paragraphes B2B SaaS).
 * M_OCRE_PWA_UNIFIE — meta PWA + apple-touch-icon + register sw.js dans cette page.
 */
get_header();
// M_OAUTH_BOUCLE_FIX — détection cookie ocre_jwt + bandeau connecté + CTA bandeau gold adapté
require_once get_stylesheet_directory() . '/parts/auth-helper.php';
$ocre_logged_in = ocre_is_logged_in();
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3D2818">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Ocre">
<link rel="apple-touch-icon" href="/icons/icon-180.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function(){});
  });
}
</script>

<style>
:root {
  --cream: #FAF6F1;
  --cream-2: #F4ECDF;
  --brown: #3D2818;
  --brown-soft: #6B5642;
  --brown-mute: #998877;
  --ocre: #C9A961;
  --ocre-dark: #8B5E3C;
  --gold: #D4A256;
  --gold-bright: #E8B95E;
  --line: #E5DAC6;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--brown); background: var(--cream); overflow-x: hidden; }
.ocre-nav, .ocre-hero, .ocre-section, .ocre-footer { display: none !important; }

.hv-hero {
  height: 100svh;
  min-height: 480px;
  max-height: 820px;
  padding-block: clamp(40px, 6vh, 80px);
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background-image: linear-gradient(180deg, rgba(0,0,0,0.15) 0%, rgba(0,0,0,0.05) 30%, rgba(0,0,0,0.55) 100%),
                    url('https://images.unsplash.com/photo-1564013799919-ab600027ffc6?auto=format&fit=crop&w=2400&q=80');
  background-size: cover;
  background-position: center;
  text-align: center;
  color: #fff;
  overflow: hidden;
}
.hv-hero-inner { z-index: 2; padding: 0 20px; animation: hv-fade-up 1.4s cubic-bezier(.2,.7,.2,1) both; }
.hv-hero h1 {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-style: italic;
  font-weight: 600;
  font-size: clamp(72px, 13vw, 160px);
  line-height: 0.95;
  letter-spacing: -0.02em;
  color: #E8B95E;
  text-shadow: 0 4px 30px rgba(0,0,0,0.4);
  margin-bottom: 18px;
}
.hv-hero .hv-tag {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-style: italic;
  font-weight: 500;
  font-size: clamp(22px, 3.5vw, 36px);
  color: #FFF;
  margin-bottom: 8px;
  letter-spacing: 0.01em;
  text-shadow: 0 2px 14px rgba(0,0,0,0.5);
}
.hv-hero .hv-sub {
  font-size: clamp(13px, 1.8vw, 16px);
  color: rgba(255,255,255,0.85);
  margin-bottom: 36px;
  letter-spacing: 0.04em;
}
.hv-cta {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 17px 36px;
  border-radius: 999px;
  background: var(--gold-bright);
  color: var(--brown);
  font-weight: 600;
  font-size: 15px;
  letter-spacing: 0.02em;
  border: none;
  cursor: pointer;
  text-decoration: none;
  transition: all .25s ease;
  box-shadow: 0 8px 24px rgba(212,162,86,0.4);
}
.hv-cta:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(212,162,86,0.55); background: #fff; color: var(--brown); }
.hv-scroll-indicator {
  position: absolute;
  bottom: 36px;
  left: 50%;
  transform: translateX(-50%);
  color: rgba(255,255,255,0.85);
  animation: hv-bounce 2.2s ease-in-out infinite;
  z-index: 2;
}
.hv-scroll-indicator svg { width: 26px; height: 26px; }
@keyframes hv-fade-up { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
@keyframes hv-bounce { 0%, 100% { transform: translateX(-50%) translateY(0); } 50% { transform: translateX(-50%) translateY(10px); } }

.hv-tools { padding: 110px 24px 80px; background: var(--cream); }
.hv-tools-head { text-align: center; max-width: 760px; margin: 0 auto 60px; }
.hv-tools-head h2 {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-style: italic;
  font-weight: 600;
  font-size: clamp(40px, 6vw, 72px);
  letter-spacing: -0.01em;
  color: var(--brown);
  margin-bottom: 14px;
}
.hv-tools-head p { font-size: 16px; color: var(--brown-soft); letter-spacing: 0.02em; }
.hv-tools-grid { max-width: 1320px; margin: 0 auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
@media (max-width: 980px) { .hv-tools-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 620px) { .hv-tools-grid { grid-template-columns: 1fr; } }

.hv-tile {
  position: relative; aspect-ratio: 1 / 1; border-radius: 18px; overflow: hidden;
  cursor: pointer; background: var(--cream-2); text-decoration: none; color: #fff;
  display: block; transition: transform .35s cubic-bezier(.2,.7,.2,1);
  opacity: 0; transform: translateY(30px);
}
.hv-tile.hv-revealed { opacity: 1; transform: translateY(0); }
.hv-tile-img { position: absolute; inset: 0; background-size: cover; background-position: center; transition: transform .8s cubic-bezier(.2,.7,.2,1); }
.hv-tile:hover .hv-tile-img { transform: scale(1.06); }
.hv-tile-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 30%, rgba(0,0,0,0.65) 100%); transition: background .3s; }
.hv-tile:hover .hv-tile-overlay { background: linear-gradient(180deg, rgba(212,162,86,0.15) 0%, rgba(139,94,60,0.78) 100%); }
.hv-tile-badge {
  position: absolute; top: 16px; right: 16px; background: var(--gold-bright); color: var(--brown);
  font-size: 10.5px; font-weight: 800; letter-spacing: 0.12em; padding: 6px 12px; border-radius: 999px;
  text-transform: uppercase; box-shadow: 0 4px 14px rgba(0,0,0,0.2);
}
.hv-tile-label { position: absolute; left: 22px; bottom: 22px; right: 22px; z-index: 2; }
.hv-tile-label .hv-name {
  font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600;
  font-size: 32px; line-height: 1.1; letter-spacing: -0.01em; margin-bottom: 6px; color: #fff;
  text-shadow: 0 2px 12px rgba(0,0,0,0.4);
}
.hv-tile-label .hv-desc { font-size: 13.5px; font-style: italic; color: rgba(255,255,255,0.92); letter-spacing: 0.02em; }

.hv-demo { padding: 110px 0 90px; background: linear-gradient(180deg, var(--cream) 0%, var(--cream-2) 100%); overflow: hidden; }
.hv-demo-head { text-align: center; margin-bottom: 50px; padding: 0 24px; }
.hv-demo-head h2 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: clamp(36px, 5vw, 60px); color: var(--brown); }
.hv-demo-strip { display: flex; gap: 24px; padding: 10px 24px 30px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; }
.hv-demo-strip::-webkit-scrollbar { display: none; }
.hv-demo-card {
  flex: 0 0 280px; height: 540px; border-radius: 32px; background: #1A1A1A;
  position: relative; overflow: hidden; scroll-snap-align: center;
  box-shadow: 0 30px 60px -20px rgba(60,40,20,0.35); border: 8px solid #2C2820;
  transition: transform .3s;
}
.hv-demo-card:hover { transform: translateY(-6px); }
.hv-demo-card-img { position: absolute; inset: 0; background-size: cover; background-position: center; }
.hv-demo-card-cap {
  position: absolute; bottom: 18px; left: 16px; right: 16px; background: rgba(255,255,255,0.92);
  backdrop-filter: blur(10px); padding: 10px 14px; border-radius: 14px; text-align: center;
  font-size: 12.5px; color: var(--brown); font-weight: 600;
}

.hv-testimonial {
  position: relative; height: 540px;
  background-image: linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.6) 100%),
                    url('https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=2400&q=80');
  background-size: cover; background-position: center;
  display: flex; align-items: center; justify-content: center; text-align: center; color: #fff;
}
.hv-testimonial blockquote {
  font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 500;
  font-size: clamp(28px, 4.5vw, 56px); line-height: 1.15; max-width: 900px; padding: 0 24px;
  text-shadow: 0 4px 30px rgba(0,0,0,0.5);
}
.hv-testimonial cite {
  display: block; font-size: 14px; font-style: normal; font-weight: 500;
  letter-spacing: 0.15em; text-transform: uppercase; margin-top: 26px; color: rgba(255,255,255,0.85);
}

.hv-gold { background: linear-gradient(135deg, var(--gold-bright) 0%, var(--ocre) 100%); padding: 110px 24px; text-align: center; color: var(--brown); }
.hv-gold h2 {
  font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600;
  font-size: clamp(48px, 7vw, 96px); line-height: 1; letter-spacing: -0.02em; margin-bottom: 20px;
}
.hv-gold p { font-size: clamp(16px, 2.5vw, 22px); font-style: italic; margin-bottom: 38px; color: rgba(61,40,24,0.78); }
.hv-gold .hv-cta { background: var(--brown); color: #fff; box-shadow: 0 12px 30px rgba(61,40,24,0.3); }
.hv-gold .hv-cta:hover { background: #1F140C; color: var(--gold-bright); }

.hv-footer { background: var(--brown); color: rgba(255,255,255,0.5); padding: 30px 24px; text-align: center; font-size: 12.5px; letter-spacing: 0.04em; }
.hv-footer a { color: rgba(255,255,255,0.7); text-decoration: none; margin: 0 8px; }
.hv-footer a:hover { color: var(--gold-bright); }

/* M_OAUTH_BOUCLE_FIX — bandeau connecté discret 32px */
.hv-connected-bar { background: linear-gradient(135deg, var(--ocre-dark) 0%, var(--brown) 100%); color: var(--cream); padding: 8px 24px; text-align: center; font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 14px; min-height: 36px; }
.hv-connected-bar strong { color: var(--gold-bright); font-weight: 600; }
.hv-connected-bar a { color: var(--cream); text-decoration: none; padding: 4px 12px; border: 1px solid rgba(232,185,94,0.4); border-radius: 999px; font-size: 12.5px; font-weight: 600; transition: all .15s; }
.hv-connected-bar a:hover { background: var(--gold-bright); color: var(--brown); border-color: var(--gold-bright); }
.hv-connected-bar .logout { border: none; color: rgba(255,255,255,0.5); font-size: 11.5px; }
.hv-connected-bar .logout:hover { background: transparent; color: var(--cream); border: none; }

.hv-reveal { opacity: 0; transform: translateY(30px); transition: opacity .9s cubic-bezier(.2,.7,.2,1), transform .9s cubic-bezier(.2,.7,.2,1); }
.hv-reveal.hv-on { opacity: 1; transform: translateY(0); }
</style>

<?php if ($ocre_logged_in): ?>
<div class="hv-connected-bar" id="hv-connected-bar">
  <span>Bonjour <strong id="hv-conn-name">·</strong></span>
  <a href="#tools">Mes outils →</a>
  <a href="https://auth.ocre.immo/api/logout.php" class="logout">Se déconnecter</a>
</div>
<script>
// Recupere first_name via /api/me asynchrone (JWT claims minimaux cote vitrine)
(function(){
  fetch('https://auth.ocre.immo/api/me.php', { credentials: 'include' })
    .then(function(r){ return r.json(); }).then(function(d){
      if (d && d.ok && (d.first_name || d.email)) {
        document.getElementById('hv-conn-name').textContent = d.first_name || d.email.split('@')[0];
      }
    }).catch(function(){});
})();
</script>
<?php endif; ?>

<section class="hv-hero" id="hero">
  <div class="hv-hero-inner">
    <h1>Ocre</h1>
    <div class="hv-tag">Vos outils immo, gratuits.</div>
    <div class="hv-sub">Pour agents, voyageurs, propriétaires.</div>
    <a href="#tools" class="hv-cta">Voir les outils
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 9l-7 7-7-7"/></svg>
    </a>
  </div>
  <div class="hv-scroll-indicator" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l5 5 5-5M7 6l5 5 5-5"/></svg>
  </div>
</section>

<section class="hv-tools" id="tools">
  <div class="hv-tools-head hv-reveal">
    <h2>Choisis ton outil</h2>
    <p>Six outils gratuits. Aucun paiement, aucune limite cachée.</p>
  </div>
  <div class="hv-tools-grid">
    <a href="/oi-agent" class="hv-tile">
      <div class="hv-tile-img" style="background-image:url('https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=900&q=80')"></div>
      <div class="hv-tile-overlay"></div>
      <div class="hv-tile-badge">Gratuit</div>
      <div class="hv-tile-label"><div class="hv-name">Oi Agent</div><div class="hv-desc">CRM matching immo</div></div>
    </a>
    <a href="/oi-scan" class="hv-tile">
      <div class="hv-tile-img" style="background-image:url('https://images.unsplash.com/photo-1542621334-a254cf47733d?auto=format&fit=crop&w=900&q=80')"></div>
      <div class="hv-tile-overlay"></div>
      <div class="hv-tile-badge">Gratuit</div>
      <div class="hv-tile-label"><div class="hv-name">Oi Scan</div><div class="hv-desc">Diagnostic bâtiment 2 min</div></div>
    </a>
    <a href="/oi-book" class="hv-tile">
      <div class="hv-tile-img" style="background-image:url('https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=900&q=80')"></div>
      <div class="hv-tile-overlay"></div>
      <div class="hv-tile-badge">Gratuit</div>
      <div class="hv-tile-label"><div class="hv-name">Oi Book</div><div class="hv-desc">Mon voyage, mon planning</div></div>
    </a>
    <a href="/oi-recherche" class="hv-tile">
      <div class="hv-tile-img" style="background-image:url('https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=900&q=80')"></div>
      <div class="hv-tile-overlay"></div>
      <div class="hv-tile-badge">Gratuit</div>
      <div class="hv-tile-label"><div class="hv-name">Oi Recherche</div><div class="hv-desc">Trouve ton bien rêvé</div></div>
    </a>
    <a href="/oi-capture" class="hv-tile">
      <div class="hv-tile-img" style="background-image:url('https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=900&q=80')"></div>
      <div class="hv-tile-overlay"></div>
      <div class="hv-tile-badge">Gratuit</div>
      <div class="hv-tile-label"><div class="hv-name">Oi Capture</div><div class="hv-desc">Scan tous mes documents</div></div>
    </a>
    <a href="/oi-estimer" class="hv-tile">
      <div class="hv-tile-img" style="background-image:url('https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=900&q=80')"></div>
      <div class="hv-tile-overlay"></div>
      <div class="hv-tile-badge">Gratuit</div>
      <div class="hv-tile-label"><div class="hv-name">Oi Estimer</div><div class="hv-desc">Estime ton bien gratuit</div></div>
    </a>
  </div>
</section>

<section class="hv-demo">
  <div class="hv-demo-head hv-reveal"><h2>Vois comme c'est simple</h2></div>
  <div class="hv-demo-strip">
    <div class="hv-demo-card"><div class="hv-demo-card-img" style="background-image:url('https://images.unsplash.com/photo-1551836022-deb4988cc6c0?auto=format&fit=crop&w=600&q=80')"></div><div class="hv-demo-card-cap">Oi Agent · création dossier</div></div>
    <div class="hv-demo-card"><div class="hv-demo-card-img" style="background-image:url('https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=600&q=80')"></div><div class="hv-demo-card-cap">Oi Scan · diagnostic photo</div></div>
    <div class="hv-demo-card"><div class="hv-demo-card-img" style="background-image:url('https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80')"></div><div class="hv-demo-card-cap">Oi Book · planning voyage</div></div>
    <div class="hv-demo-card"><div class="hv-demo-card-img" style="background-image:url('https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80')"></div><div class="hv-demo-card-cap">Oi Recherche · matching</div></div>
    <div class="hv-demo-card"><div class="hv-demo-card-img" style="background-image:url('https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=600&q=80')"></div><div class="hv-demo-card-cap">Oi Estimer · résultat</div></div>
  </div>
</section>

<section class="hv-testimonial">
  <blockquote>« Ocre m'a fait gagner 2h par jour. »<cite>— Karim, Marrakech</cite></blockquote>
</section>

<section class="hv-gold">
  <?php if ($ocre_logged_in): ?>
    <h2>Tu es déjà connecté</h2>
    <p>Choisis ton outil ci-dessus.</p>
    <a href="https://app.ocre.immo/oi-agent" class="hv-cta">Ouvrir Oi Agent →
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
  <?php else: ?>
    <h2>100% gratuit. Pour toujours ?</h2>
    <p>Profite tant qu'on le décide encore.</p>
    <button type="button" class="hv-cta" data-signup-trigger="agent" onclick="if(window.ocreSignupOpen){ocreSignupOpen();}return false;" style="border:none;cursor:pointer;font-family:inherit">Créer mon compte
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </button>
  <?php endif; ?>
</section>

<footer class="hv-footer">
  © 2026 Ocre · <a href="mailto:contact@ocre.immo">contact@ocre.immo</a> · <a href="/mentions-legales/">Mentions légales</a> · <a href="/confidentialite/">Confidentialité</a>
</footer>

<script>
(function(){
  if (!('IntersectionObserver' in window)) return;
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if (e.isIntersecting) { e.target.classList.add('hv-on', 'hv-revealed'); obs.unobserve(e.target); }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -10% 0px' });
  document.querySelectorAll('.hv-reveal').forEach(function(el){ obs.observe(el); });
  document.querySelectorAll('.hv-tile').forEach(function(el, i){ el.style.transitionDelay = (i * 80) + 'ms'; obs.observe(el); });
})();
window.OCRE_SIGNUP_APP = 'agent';
// M_OAUTH_DIAGNOSTIC_FIX — détection ?login=success&app=<slug> post-OAuth callback : toast vert + auto-redirect app
(function(){
  var qs = new URLSearchParams(location.search);
  if (qs.get('login') === 'success') {
    var app = (qs.get('app') || 'agent').replace(/[^a-z]/g, '');
    var via = (qs.get('via') || 'oauth').replace(/[^a-z_]/g, '');
    // Toast vert
    var toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) scale(0.9);background:#2D7A3E;color:#fff;padding:18px 30px;border-radius:14px;font-size:15px;font-weight:600;box-shadow:0 12px 40px rgba(45,122,62,0.45);z-index:99999;opacity:0;transition:all 0.3s cubic-bezier(.2,.7,.2,1);font-family:Inter,-apple-system,sans-serif;display:flex;align-items:center;gap:10px';
    toast.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>✓ Bienvenue ! Tu es connecté.</span>';
    document.body.appendChild(toast);
    requestAnimationFrame(function(){ toast.style.opacity = '1'; toast.style.transform = 'translate(-50%,-50%) scale(1)'; });
    // Cleanup URL (retire query string)
    if (history.replaceState) history.replaceState({}, '', location.pathname);
    // Auto-redirect vers app cible apres 1.5s
    // M_OAUTH_BOUCLE_FIX — destination = app cible OI sur app.ocre.immo (pas racine + sous-domaines pas tous deployes)
    var appUrls = { agent:'https://app.ocre.immo/oi-agent', scan:'https://app.ocre.immo/oi-scan', book:'https://app.ocre.immo/oi-book', demande:'https://app.ocre.immo/oi-recherche', capture:'https://app.ocre.immo/oi-capture', estimer:'https://app.ocre.immo/oi-estimer' };
    var dest = appUrls[app] || appUrls.agent;
    setTimeout(function(){ location.href = dest; }, 1500);
  } else if (qs.get('error')) {
    var err = (qs.get('error') || '').replace(/[^a-z_]/g, '');
    var msg = err === 'cancelled' ? 'Connexion annulée.' : 'Erreur OAuth : ' + err;
    var toastE = document.createElement('div');
    toastE.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#C62828;color:#fff;padding:16px 26px;border-radius:14px;font-size:14px;font-weight:600;box-shadow:0 12px 40px rgba(198,40,40,0.4);z-index:99999;font-family:Inter,sans-serif';
    toastE.textContent = '✗ ' + msg;
    document.body.appendChild(toastE);
    if (history.replaceState) history.replaceState({}, '', location.pathname);
    setTimeout(function(){ toastE.remove(); }, 3000);
  }
})();
</script>

<?php
// M_OCRE_PATCH_OUTILS_RICHES — popup signup overlay shared depuis home aussi
include get_stylesheet_directory() . '/parts/signup-popup.php';
wp_footer();
?>
</body>
</html>
