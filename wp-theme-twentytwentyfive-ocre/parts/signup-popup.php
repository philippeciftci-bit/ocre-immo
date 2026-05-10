<?php
// M_OCRE_PATCH_OUTILS_RICHES — Popup signup overlay shared
// Utilise SSO endpoints M_OCRE_AGENT_SIGNUP_V1 (M52) sur auth.ocre.immo + magic link request
// Comportement : tap CTA → popup overlay (pas navigation) → SSO ou form email → submit → toast vert + ferme + reste sur page
// Inclus depuis template-outil.php et front-page.php via include get_stylesheet_directory().'/parts/signup-popup.php'
?>
<style>
.osp-overlay { position: fixed; inset: 0; background: rgba(15,10,5,0.55); backdrop-filter: blur(4px); z-index: 9998; opacity: 0; pointer-events: none; transition: opacity .25s; display: flex; align-items: center; justify-content: center; padding: 20px; }
.osp-overlay.osp-show { opacity: 1; pointer-events: auto; }
.osp-modal { background: #FAF6F1; border-radius: 22px; width: 100%; max-width: 460px; padding: 36px 30px 30px; box-shadow: 0 30px 80px rgba(0,0,0,0.35); position: relative; transform: translateY(40px); opacity: 0; transition: all .35s cubic-bezier(.2,.7,.2,1); max-height: 92vh; overflow-y: auto; }
.osp-overlay.osp-show .osp-modal { transform: translateY(0); opacity: 1; }
@media (max-width: 540px) { .osp-overlay { align-items: flex-end; padding: 0; } .osp-modal { border-radius: 22px 22px 0 0; max-width: 100%; padding: 30px 22px 22px; max-height: 88vh; transform: translateY(100%); } .osp-overlay.osp-show .osp-modal { transform: translateY(0); } }

.osp-close { position: absolute; top: 14px; right: 14px; width: 36px; height: 36px; border-radius: 50%; background: rgba(0,0,0,0.06); border: none; cursor: pointer; font-size: 20px; color: #6B5642; display: flex; align-items: center; justify-content: center; line-height: 1; }
.osp-close:hover { background: rgba(0,0,0,0.12); }
.osp-brand { text-align: center; margin-bottom: 18px; }
.osp-brand-mark { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 36px; color: #8B5E3C; letter-spacing: -0.02em; }
.osp-brand-mark span { color: #D4A256; }
.osp-modal h2 { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 26px; text-align: center; color: #3D2818; margin: 0 0 4px; letter-spacing: -0.01em; }
.osp-sub { text-align: center; font-size: 13px; color: #6B5642; margin-bottom: 22px; }
.osp-sso { display: flex; flex-direction: column; gap: 10px; }
.osp-btn-sso { display: flex; align-items: center; justify-content: center; gap: 10px; height: 46px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid transparent; font-family: inherit; transition: all .15s; text-decoration: none; }
.osp-btn-sso:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
.osp-google { background: #fff; color: #1F1F1F; border-color: #DADCE0; }
.osp-apple { background: #000; color: #fff; }
.osp-facebook { background: #1877F2; color: #fff; }
.osp-sep { display: flex; align-items: center; gap: 12px; margin: 18px 0 14px; color: #998877; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; }
.osp-sep::before, .osp-sep::after { content: ''; flex: 1; height: 1px; background: #E5DAC6; }
.osp-btn-email { display: block; width: 100%; text-align: center; padding: 12px; background: none; border: none; color: #8B5E3C; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.osp-btn-email:hover { color: #3D2818; }
.osp-form-grid { display: grid; gap: 11px; margin-bottom: 12px; }
.osp-form-grid .osp-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
.osp-field { display: flex; flex-direction: column; gap: 3px; }
.osp-field label { font-size: 11px; color: #6B5642; font-weight: 500; letter-spacing: 0.04em; }
.osp-field input { padding: 11px 12px; border: 1px solid #E5DAC6; border-radius: 9px; font-size: 13.5px; font-family: inherit; color: #3D2818; background: #FCFAF7; }
.osp-field input:focus { outline: none; border-color: #8B5E3C; background: #fff; }
.osp-cgu { display: flex; align-items: flex-start; gap: 8px; margin: 8px 0 12px; font-size: 11.5px; color: #6B5642; line-height: 1.4; }
.osp-cgu input { margin-top: 2px; accent-color: #8B5E3C; }
.osp-cgu a { color: #8B5E3C; text-decoration: underline; }
.osp-btn-submit { width: 100%; padding: 14px; background: #8B5E3C; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .15s; }
.osp-btn-submit:hover { background: #3D2818; transform: translateY(-1px); }
.osp-btn-submit:disabled { opacity: 0.5; cursor: wait; transform: none; }
.osp-back { display: block; text-align: center; padding: 8px; background: none; border: none; color: #998877; font-size: 12px; cursor: pointer; font-family: inherit; }
.osp-feedback { margin-top: 10px; padding: 9px 13px; border-radius: 8px; font-size: 12.5px; display: none; }
.osp-feedback.osp-show-fb { display: block; }
.osp-feedback.osp-error { background: #FDECEA; color: #C62828; }

.osp-hidden { display: none !important; }

.osp-toast { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); background: #2D7A3E; color: #fff; padding: 16px 26px; border-radius: 14px; font-size: 14.5px; font-weight: 600; box-shadow: 0 12px 40px rgba(45,122,62,0.4); opacity: 0; pointer-events: none; transition: all .25s cubic-bezier(.2,.7,.2,1); z-index: 9999; display: flex; align-items: center; gap: 10px; }
.osp-toast.osp-toast-show { opacity: 1; transform: translate(-50%, -50%) scale(1); }
</style>

<div class="osp-overlay" id="osp-overlay" onclick="if(event.target===this) ocreSignupClose()">
  <div class="osp-modal" role="dialog" aria-modal="true" aria-labelledby="osp-title">
    <button type="button" class="osp-close" onclick="ocreSignupClose()" aria-label="Fermer">×</button>
    <div class="osp-brand"><div class="osp-brand-mark">Oc<span>re</span></div></div>

    <div id="osp-screen-1">
      <h2 id="osp-title">Crée ton compte</h2>
      <div class="osp-sub">Gratuit · 1 minute · zéro mot de passe</div>
      <div class="osp-sso">
        <a href="https://auth.ocre.immo/api/oauth/google/init.php" class="osp-btn-sso osp-google"><svg width="17" height="17" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/><path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/><path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/><path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/></svg> Continuer avec Google</a>
        <a href="https://auth.ocre.immo/api/oauth/apple/init.php" class="osp-btn-sso osp-apple"><svg width="17" height="17" viewBox="0 0 384 512" fill="#fff"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg> Continuer avec Apple</a>
        <a href="https://auth.ocre.immo/api/oauth/facebook/init.php" class="osp-btn-sso osp-facebook"><svg width="17" height="17" viewBox="0 0 24 24" fill="#fff"><path d="M22.675 0H1.325C.593 0 0 .593 0 1.325v21.351C0 23.407.593 24 1.325 24H12.82v-9.294H9.692V11.25h3.128V8.564c0-3.1 1.893-4.788 4.659-4.788 1.325 0 2.464.099 2.795.143v3.24h-1.918c-1.504 0-1.795.715-1.795 1.763v2.313h3.587l-.467 3.456h-3.12V24h6.116c.73 0 1.323-.593 1.323-1.325V1.325C24 .593 23.407 0 22.675 0z"/></svg> Continuer avec Facebook</a>
      </div>
      <div class="osp-sep">ou</div>
      <button type="button" class="osp-btn-email" onclick="ocreSignupShowForm()">✉ Continuer avec mon email</button>
    </div>

    <div id="osp-screen-2" class="osp-hidden">
      <h2>Crée ton compte par email</h2>
      <div class="osp-sub">Lien magique envoyé · pas de mot de passe</div>
      <form id="osp-form" onsubmit="return ocreSignupSubmit(event)">
        <div class="osp-form-grid">
          <div class="osp-row-2">
            <div class="osp-field"><label for="osp-prenom">Prénom</label><input id="osp-prenom" type="text" required autocomplete="given-name"></div>
            <div class="osp-field"><label for="osp-nom">Nom</label><input id="osp-nom" type="text" required autocomplete="family-name"></div>
          </div>
          <div class="osp-field"><label for="osp-societe">Société (optionnel)</label><input id="osp-societe" type="text" autocomplete="organization"></div>
          <div class="osp-row-2">
            <div class="osp-field"><label for="osp-phone">Téléphone</label><input id="osp-phone" type="tel" required autocomplete="tel" placeholder="+33 6 12 34 56 78"></div>
            <div class="osp-field"><label for="osp-email">Email</label><input id="osp-email" type="email" required autocomplete="email"></div>
          </div>
          <label class="osp-cgu"><input type="checkbox" id="osp-cgu" required> J'accepte les <a href="https://ocre.immo/mentions-legales/" target="_blank">CGU</a> et la <a href="https://ocre.immo/confidentialite/" target="_blank">politique de confidentialité</a></label>
        </div>
        <button type="submit" class="osp-btn-submit" id="osp-submit">Recevoir mon lien →</button>
        <button type="button" class="osp-back" onclick="ocreSignupShowSso()">← Retour aux options</button>
        <div id="osp-feedback" class="osp-feedback osp-error"></div>
      </form>
    </div>
  </div>
</div>

<div class="osp-toast" id="osp-toast" role="status" aria-live="polite">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="osp-toast-msg">Lien envoyé, vérifie ton email</span>
</div>

<script>
function ocreSignupOpen() {
  ocreSignupShowSso();
  var ov = document.getElementById('osp-overlay');
  ov.classList.add('osp-show');
  document.body.style.overflow = 'hidden';
}
function ocreSignupClose() {
  var ov = document.getElementById('osp-overlay');
  ov.classList.remove('osp-show');
  document.body.style.overflow = '';
}
function ocreSignupShowForm() {
  document.getElementById('osp-screen-1').classList.add('osp-hidden');
  document.getElementById('osp-screen-2').classList.remove('osp-hidden');
}
function ocreSignupShowSso() {
  document.getElementById('osp-screen-2').classList.add('osp-hidden');
  document.getElementById('osp-screen-1').classList.remove('osp-hidden');
}
function ocreToast(msg) {
  var t = document.getElementById('osp-toast');
  document.getElementById('osp-toast-msg').textContent = msg;
  t.classList.add('osp-toast-show');
  setTimeout(function(){ t.classList.remove('osp-toast-show'); }, 2200);
}
async function ocreSignupSubmit(e) {
  e.preventDefault();
  var btn = document.getElementById('osp-submit'); btn.disabled = true; btn.textContent = '⏳ Envoi…';
  var fb = document.getElementById('osp-feedback'); fb.classList.remove('osp-show-fb');
  var payload = {
    email: document.getElementById('osp-email').value.trim(),
    first_name: document.getElementById('osp-prenom').value.trim(),
    last_name: document.getElementById('osp-nom').value.trim(),
    societe: document.getElementById('osp-societe').value.trim(),
    phone: document.getElementById('osp-phone').value.trim(),
    cgu_accepted: document.getElementById('osp-cgu').checked,
    target_app: window.OCRE_SIGNUP_APP || 'agent',
  };
  try {
    var r = await fetch('https://auth.ocre.immo/api/magic-link/request.php', {
      method: 'POST', credentials: 'include', mode: 'cors',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload),
    });
    var d = await r.json();
    if (d.ok) {
      ocreSignupClose();
      setTimeout(function(){ ocreToast('✓ Lien envoyé, vérifie ton email'); }, 200);
    } else {
      fb.textContent = 'Erreur : ' + (d.error || 'inconnue'); fb.classList.add('osp-show-fb');
    }
  } catch (err) {
    fb.textContent = 'Erreur réseau : ' + err.message; fb.classList.add('osp-show-fb');
  } finally {
    btn.disabled = false; btn.textContent = 'Recevoir mon lien →';
  }
  return false;
}
// Esc closes popup
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' && document.getElementById('osp-overlay').classList.contains('osp-show')) ocreSignupClose();
});
// M_OI_AGENT_CTA_FIX — Event delegation pour [data-signup-trigger] et [data-signup-close]
// Plus robuste que onclick inline (compatible CSP strict + WP security plugins qui strippent onclick)
document.addEventListener('click', function(e) {
  var trigger = e.target.closest('[data-signup-trigger]');
  if (trigger) {
    e.preventDefault();
    var app = trigger.getAttribute('data-signup-trigger');
    if (app && app !== '1' && app !== 'true') window.OCRE_SIGNUP_APP = app;
    ocreSignupOpen();
    return;
  }
  var closer = e.target.closest('[data-signup-close]');
  if (closer) { e.preventDefault(); ocreSignupClose(); }
});
</script>
