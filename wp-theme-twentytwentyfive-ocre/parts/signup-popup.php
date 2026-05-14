<?php
// M/2026/05/14/76 — FIX-POPUP-VITRINE. Refactor total : popup simple email + redirect auth.ocre.immo.
// AVANT (M/2026/05/11/37) : flow complet magic-link inline + accordeon signup. Endpoint /api/magic-link/request.php supprime en M/14/73 -> "Load failed".
// APRES : 1 champ email + bouton Continuer -> redirect https://auth.ocre.immo/?email=URL_ENC. Toute la logique d'auth gere par la SPA M/14/75.
?>
<style>
.oal-overlay { position: fixed; inset: 0; background: rgba(15,10,5,0.55); backdrop-filter: blur(4px); z-index: 9998; opacity: 0; pointer-events: none; transition: opacity .25s; display: flex; align-items: center; justify-content: center; padding: 20px; font-family: 'DM Sans', system-ui, sans-serif; }
.oal-overlay.oal-show { opacity: 1; pointer-events: auto; }
.oal-modal { background: #FFFFFF; border-radius: 16px; width: 100%; max-width: 420px; padding: 32px 28px; box-shadow: 0 30px 80px rgba(0,0,0,0.35); position: relative; transform: translateY(40px); opacity: 0; transition: all .35s cubic-bezier(.2,.7,.2,1); max-height: 90dvh; overflow-y: auto; -webkit-overflow-scrolling: touch; border: 1px solid #E5DAC6; }
.oal-overlay.oal-show .oal-modal { transform: translateY(0); opacity: 1; }
@media (max-width: 420px) {
  .oal-modal { padding: 24px 20px; padding-bottom: max(20px, env(safe-area-inset-bottom)); }
  .oal-brand { font-size: 24px !important; }
  .oal-h1 { font-size: 18px !important; line-height: 1.2; text-wrap: balance; }
  .oal-sub { font-size: 12.5px !important; line-height: 1.4; text-wrap: balance; }
}
.oal-close { position: absolute; top: 14px; right: 14px; width: 34px; height: 34px; border-radius: 50%; background: rgba(0,0,0,0.06); border: none; cursor: pointer; font-size: 18px; color: #6B5642; display: flex; align-items: center; justify-content: center; }
.oal-brand { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 32px; color: #8B5A3C; text-align: center; margin: 0 0 4px; }
.oal-brand .oal-re { color: #D4A256; }
.oal-h1 { font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-style: italic; font-size: 22px; color: #3D2818; text-align: center; margin: 4px 0 6px; text-wrap: balance; }
.oal-sub { font-size: 13.5px; color: #6B5642; text-align: center; margin-bottom: 22px; text-wrap: balance; }
.oal-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.oal-field label { font-size: 11.5px; font-weight: 600; color: #998877; text-transform: uppercase; letter-spacing: .04em; }
.oal-field input { padding: 13px 14px; border: 1px solid #E5DAC6; border-radius: 10px; font-size: 16px; font-family: inherit; color: #3D2818; background: #FCFAF7; width: 100%; min-width: 0; box-sizing: border-box; }
.oal-field input:focus { outline: none; border-color: #8B5A3C; background: #fff; }
.oal-btn { display: block; width: 100%; padding: 15px 20px; border-radius: 12px; font-family: 'DM Sans', system-ui, sans-serif; font-weight: 700; font-size: 16px; line-height: 1.2; border: none; cursor: pointer; background: #8B5A3C; color: #fff; transition: background .15s; margin-top: 10px; }
.oal-btn:hover:not(:disabled) { background: #6B3F26; }
.oal-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.oal-msg { margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; text-align: center; display: none; line-height: 1.5; }
.oal-msg.oal-show-msg { display: block; }
.oal-msg.oal-error { background: #FDECEA; color: #C62828; }
</style>
<div class="oal-overlay" id="oal-overlay" onclick="if(event.target===this){window.ocreSignupClose();}">
  <div class="oal-modal" role="dialog" aria-modal="true" aria-labelledby="oal-title">
    <button type="button" class="oal-close" aria-label="Fermer" onclick="window.ocreSignupClose()">&times;</button>
    <p class="oal-brand">Oc<span class="oal-re">re</span></p>
    <h2 class="oal-h1" id="oal-title">Connexion ou inscription</h2>
    <p class="oal-sub">Entre ton email pour continuer.</p>
    <form id="oal-form" autocomplete="on" onsubmit="return window.ocreSignupSubmit(event)">
      <div class="oal-field">
        <label for="oal-email">Email</label>
        <input type="email" id="oal-email" name="email" required autocomplete="email" inputmode="email" placeholder="ton@email.com">
      </div>
      <button type="submit" class="oal-btn" id="oal-submit">Continuer</button>
    </form>
    <div class="oal-msg" id="oal-msg"></div>
  </div>
</div>
<script>
(function() {
  var overlay = document.getElementById('oal-overlay');
  var emailIn = document.getElementById('oal-email');
  var submitBtn = document.getElementById('oal-submit');
  var msgEl = document.getElementById('oal-msg');
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function showError(html) { msgEl.className = 'oal-msg oal-show-msg oal-error'; msgEl.innerHTML = html; }
  function clearMsg() { msgEl.className = 'oal-msg'; msgEl.innerHTML = ''; }

  window.ocreSignupOpen = function(opts) {
    clearMsg();
    overlay.classList.add('oal-show');
    document.documentElement.style.overflow = 'hidden';
    setTimeout(function() { emailIn.focus(); }, 200);
  };
  window.ocreSignupClose = function() {
    overlay.classList.remove('oal-show');
    document.documentElement.style.overflow = '';
  };

  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-signup-trigger]').forEach(function(el) {
      if (el._signupBound) return; el._signupBound = true;
      el.addEventListener('click', function(e) {
        e.preventDefault();
        window.ocreSignupOpen();
      });
    });
  });

  window.ocreSignupSubmit = function(e) {
    e.preventDefault();
    var email = emailIn.value.trim().toLowerCase();
    if (!EMAIL_RE.test(email)) { showError('Email invalide.'); return false; }
    submitBtn.disabled = true; submitBtn.textContent = 'Redirection…';
    // M/14/76 — redirect SPA auth.ocre.immo qui gere tout le parcours (M/14/75).
    window.location.href = 'https://auth.ocre.immo/?email=' + encodeURIComponent(email);
    return false;
  };
})();
</script>
