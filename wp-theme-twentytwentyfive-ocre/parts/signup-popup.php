<?php
// M/2026/05/14/80 — URGENT-POPUP-OPAQUE-RADICAL. Classes renommees ocre-popup-* avec !important
// pour battre les overrides WP. Card OPAQUE #FFFFFF + overlay sombre + body lock.
?>
<style>
/* Overlay sombre - !important pour battre styles WP */
.ocre-popup-overlay {
  position: fixed !important;
  inset: 0 !important;
  background-color: rgba(60, 40, 24, 0.6) !important;
  -webkit-backdrop-filter: blur(4px) !important;
  backdrop-filter: blur(4px) !important;
  z-index: 99998 !important;
  display: none !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 16px !important;
}
.ocre-popup-overlay.ocre-popup-show {
  display: flex !important;
  animation: ocrePopupFadeIn .25s ease;
}
@keyframes ocrePopupFadeIn { from { opacity: 0; } to { opacity: 1; } }
/* Bouton close - position absolue dans la card */
.ocre-popup-close {
  position: absolute !important;
  top: 16px !important;
  right: 16px !important;
  width: 32px !important;
  height: 32px !important;
  border-radius: 50% !important;
  background-color: #F4ECDF !important;
  color: #6B5642 !important;
  border: none !important;
  cursor: pointer !important;
  font-size: 18px !important;
  font-weight: 600 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  z-index: 100 !important;
  transition: background-color .15s;
  padding: 0 !important;
  line-height: 1 !important;
}
.ocre-popup-close:hover { background-color: #E5DAC6 !important; }
/* Body lock */
body.ocre-popup-open {
  overflow: hidden !important;
}
</style>
<div class="ocre-popup-overlay" id="ocre-popup-overlay" onclick="if(event.target===this){window.ocreSignupClose();}">
  <div style="position:relative;width:100%;max-width:460px;">
    <button type="button" class="ocre-popup-close" aria-label="Fermer" onclick="window.ocreSignupClose()">&times;</button>
    <div id="ocre-auth-mount"></div>
  </div>
</div>
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/js/auth-flow.js?v=' . date('Ymd-Hi')); ?>"></script>
<script>
(function() {
  var overlay = document.getElementById('ocre-popup-overlay');
  var mounted = false;
  window.ocreSignupOpen = function() {
    overlay.classList.add('ocre-popup-show');
    document.body.classList.add('ocre-popup-open');
    if (!mounted && window.OcreAuth) {
      window.OcreAuth.mount('#ocre-auth-mount', {
        apiBase: 'https://auth.ocre.immo',
        onSuccess: function(url) { window.location.href = url; }
      });
      mounted = true;
    }
  };
  window.ocreSignupClose = function() {
    overlay.classList.remove('ocre-popup-show');
    document.body.classList.remove('ocre-popup-open');
  };
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-signup-trigger]').forEach(function(el) {
      if (el._signupBound) return; el._signupBound = true;
      el.addEventListener('click', function(e) { e.preventDefault(); window.ocreSignupOpen(); });
    });
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && overlay.classList.contains('ocre-popup-show')) window.ocreSignupClose();
  });
})();
</script>
