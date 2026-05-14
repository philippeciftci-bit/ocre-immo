<?php
// M/2026/05/14/77 — POPUP-INLINE-FULL. Popup self-contained 6 etapes via module unique auth-flow.js.
// Cross-origin vers https://auth.ocre.immo (CORS + cookie .ocre.immo M/14/66).
// Toute la logique est dans /assets/js/auth-flow.js (source unique partagee avec SPA auth.ocre.immo).
?>
<style>
.oal-overlay { position: fixed; inset: 0; background: rgba(15,10,5,0.55); backdrop-filter: blur(4px); z-index: 9998; opacity: 0; pointer-events: none; transition: opacity .25s; display: flex; align-items: center; justify-content: center; padding: 20px; }
.oal-overlay.oal-show { opacity: 1; pointer-events: auto; }
.oal-shell { width: 100%; max-width: 440px; max-height: 90dvh; overflow-y: auto; position: relative; transform: translateY(40px); opacity: 0; transition: all .35s cubic-bezier(.2,.7,.2,1); }
.oal-overlay.oal-show .oal-shell { transform: translateY(0); opacity: 1; }
.oal-close { position: absolute; top: 14px; right: 14px; width: 34px; height: 34px; border-radius: 50%; background: rgba(0,0,0,0.06); border: none; cursor: pointer; font-size: 18px; color: #6B5642; z-index: 2; }
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
    document.documentElement.style.overflow = 'hidden';
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
    document.documentElement.style.overflow = '';
  };
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-signup-trigger]').forEach(function(el) {
      if (el._signupBound) return; el._signupBound = true;
      el.addEventListener('click', function(e) { e.preventDefault(); window.ocreSignupOpen(); });
    });
  });
})();
</script>
