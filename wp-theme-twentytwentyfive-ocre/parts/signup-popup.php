<?php
// M/2026/05/11/34 — M_SIGNUP_UNIFIE : la modale signup interne ocre.immo est supprimee.
// Tous les CTA "Créer mon compte" (data-signup-trigger="agent" onclick=ocreSignupOpen())
// redirigent maintenant vers le form unifie auth.ocre.immo/signup.
// L'ancienne modale 412 lignes archivee en signup-popup.php.ARCHIVED-M_SIGNUP_UNIFIE-<TS>.
?>
<script>
(function() {
  function redirectToUnifiedSignup(el) {
    var app = (el && el.dataset && el.dataset.signupTrigger) || 'agent';
    var u = new URL('https://auth.ocre.immo/signup');
    u.searchParams.set('app', app);
    u.searchParams.set('source', 'vitrine');
    window.location.href = u.toString();
    return false;
  }
  // Compat avec les CTA existants qui appellent window.ocreSignupOpen() inline.
  window.ocreSignupOpen = function() { redirectToUnifiedSignup(null); };
  window.ocreSignupClose = function() {}; // no-op : plus de modale a fermer
  // Auto-bind sur tous les data-signup-trigger pour compat si le inline onclick est retire un jour.
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-signup-trigger]').forEach(function(el) {
      if (el._signupBound) return;
      el._signupBound = true;
      el.addEventListener('click', function(e) {
        if (e.defaultPrevented) return;
        e.preventDefault();
        redirectToUnifiedSignup(el);
      });
    });
  });
})();
</script>
