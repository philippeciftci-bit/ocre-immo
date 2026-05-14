<?php
// M/2026/05/14/79 — Popup visuel propre : overlay sombre + card OPAQUE blanche + close button + body lock.
// Le contenu interne (form 6 etapes) est rendu par OcreAuth.mount() depuis assets/js/auth-flow.js.
?>
<style>
/* Overlay sombre semi-transparent qui couvre tout */
.oal-overlay {
  position: fixed; inset: 0;
  background: rgba(60, 40, 24, 0.55);
  -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
  z-index: 9998;
  opacity: 0; pointer-events: none;
  transition: opacity .25s;
  display: flex; align-items: center; justify-content: center;
  padding: 16px;
}
.oal-overlay.oal-show { opacity: 1; pointer-events: auto; }
/* Shell qui wrap la card + close. Centre + scroll si overflow. */
.oal-shell {
  position: relative;
  width: 100%; max-width: 460px;
  max-height: calc(100vh - 32px);
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  transform: translateY(40px); opacity: 0;
  transition: all .35s cubic-bezier(.2,.7,.2,1);
  z-index: 9999;
}
.oal-overlay.oal-show .oal-shell { transform: translateY(0); opacity: 1; }
/* Bouton close en absolute sur la card (top-right) */
.oal-close {
  position: absolute; top: 16px; right: 16px;
  width: 32px; height: 32px; border-radius: 50%;
  background: #F4ECDF; color: #6B5642;
  border: none; cursor: pointer;
  font-size: 18px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  z-index: 10;
  transition: background .15s;
}
.oal-close:hover { background: #E5DAC6; }
/* Body lock quand popup ouverte (classe appliquee par JS) */
body.oal-locked { overflow: hidden !important; }
</style>
<div class="oal-overlay" id="oal-overlay" onclick="if(event.target===this){window.ocreSignupClose();}">
  <div class="oal-shell">
    <button type="button" class="oal-close" aria-label="Fermer" onclick="window.ocreSignupClose()">&times;</button>
    <div id="ocre-auth-mount"></div>
  </div>
</div>
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/js/auth-flow.js?v=' . date('Ymd-Hi')); ?>"></script>
<script>
(function() {
  var overlay = document.getElementById('oal-overlay');
  var mounted = false;
  window.ocreSignupOpen = function() {
    overlay.classList.add('oal-show');
    document.body.classList.add('oal-locked');
    if (!mounted && window.OcreAuth) {
      window.OcreAuth.mount('#ocre-auth-mount', {
        apiBase: 'https://auth.ocre.immo',
        onSuccess: function(url) { window.location.href = url; }
      });
      mounted = true;
    }
  };
  window.ocreSignupClose = function() {
    overlay.classList.remove('oal-show');
    document.body.classList.remove('oal-locked');
  };
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-signup-trigger]').forEach(function(el) {
      if (el._signupBound) return; el._signupBound = true;
      el.addEventListener('click', function(e) { e.preventDefault(); window.ocreSignupOpen(); });
    });
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && overlay.classList.contains('oal-show')) window.ocreSignupClose();
  });
})();
</script>
