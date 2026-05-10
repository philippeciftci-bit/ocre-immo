<?php
// M_OCRE_PARCOURS_V4 — popup signup magic-link only (suppression OAuth UI)
// Etat 1 : email seul + Continuer
// Si email existant → entree directe app (cookie pose backend, redirect URL)
// Si email absent → accordeon enregistrement slide-down dans la meme popup
// Submit form → magic link OVH SMTP + toast vert + ferme popup
// Backup ancienne version OAuth-style : signup-popup.php.bak.M_OAUTH_MOCK_ACCOUNT_PICKER (commentaire)
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

.osp-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.osp-field label { font-size: 11px; color: #6B5642; font-weight: 500; letter-spacing: 0.04em; }
.osp-field input { padding: 13px 14px; border: 1px solid #E5DAC6; border-radius: 9px; font-size: 14px; font-family: inherit; color: #3D2818; background: #FCFAF7; }
.osp-field input:focus { outline: none; border-color: #8B5E3C; background: #fff; }
.osp-field input:disabled { opacity: 0.6; background: #F4ECDF; }

.osp-btn-submit { width: 100%; padding: 14px; background: #8B5E3C; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .15s; }
.osp-btn-submit:hover { background: #3D2818; transform: translateY(-1px); }
.osp-btn-submit:disabled { opacity: 0.5; cursor: wait; transform: none; }

.osp-feedback { margin-top: 10px; padding: 9px 13px; border-radius: 8px; font-size: 12.5px; display: none; }
.osp-feedback.osp-show-fb { display: block; }
.osp-feedback.osp-error { background: #FDECEA; color: #C62828; }
.osp-feedback.osp-info { background: #E8F4F8; color: #0277BD; }

/* Accordéon registration */
.osp-accordion { max-height: 0; overflow: hidden; transition: max-height .4s cubic-bezier(.2,.7,.2,1); }
.osp-accordion.osp-open { max-height: 800px; }
.osp-accordion-inner { padding-top: 14px; border-top: 1px solid #E5DAC6; margin-top: 14px; }
.osp-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
.osp-tel-row { display: grid; grid-template-columns: 90px 1fr; gap: 8px; align-items: end; }
.osp-cgu { display: flex; align-items: flex-start; gap: 8px; margin: 8px 0 12px; font-size: 11.5px; color: #6B5642; line-height: 1.4; }
.osp-cgu input { margin-top: 2px; accent-color: #8B5E3C; }
.osp-cgu a { color: #8B5E3C; text-decoration: underline; }

.osp-toast { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); background: #2D7A3E; color: #fff; padding: 16px 26px; border-radius: 14px; font-size: 14.5px; font-weight: 600; box-shadow: 0 12px 40px rgba(45,122,62,0.4); opacity: 0; pointer-events: none; transition: all .25s cubic-bezier(.2,.7,.2,1); z-index: 9999; display: flex; align-items: center; gap: 10px; }
.osp-toast.osp-toast-show { opacity: 1; transform: translate(-50%, -50%) scale(1); }
</style>

<div class="osp-overlay" id="osp-overlay" onclick="if(event.target===this) ocreSignupClose()">
  <div class="osp-modal" role="dialog" aria-modal="true" aria-labelledby="osp-title">
    <button type="button" class="osp-close" onclick="ocreSignupClose()" aria-label="Fermer">×</button>
    <div class="osp-brand"><div class="osp-brand-mark">Oc<span>re</span></div></div>

    <h2 id="osp-title">Crée ton compte</h2>
    <div class="osp-sub">Gratuit · 1 minute · zéro mot de passe</div>

    <form id="osp-form" onsubmit="return ocreSignupSubmit(event)" autocomplete="on">
      <div class="osp-field">
        <label for="osp-email">Email</label>
        <input type="email" id="osp-email" name="email" required autocomplete="email" placeholder="ton@email.com" autofocus>
      </div>

      <!-- Accordéon registration : visible si email pas reconnu -->
      <div class="osp-accordion" id="osp-accordion" aria-hidden="true">
        <div class="osp-accordion-inner">
          <div class="osp-row-2">
            <div class="osp-field"><label for="osp-prenom">Prénom *</label><input type="text" id="osp-prenom" autocomplete="given-name"></div>
            <div class="osp-field"><label for="osp-nom">Nom *</label><input type="text" id="osp-nom" autocomplete="family-name"></div>
          </div>
          <div class="osp-field"><label for="osp-societe">Société (facultatif)</label><input type="text" id="osp-societe" autocomplete="organization" placeholder="Ton agence (facultatif)"></div>
          <div class="osp-tel-row">
            <div class="osp-field"><label for="osp-indicatif">Indicatif</label><input type="text" id="osp-indicatif" value="+33" autocomplete="tel-country-code"></div>
            <div class="osp-field"><label for="osp-phone">Téléphone *</label><input type="tel" id="osp-phone" autocomplete="tel" placeholder="6 12 34 56 78"></div>
          </div>
          <label class="osp-cgu"><input type="checkbox" id="osp-cgu"> J'accepte les <a href="https://ocre.immo/mentions-legales/" target="_blank">CGU</a> et la <a href="https://ocre.immo/confidentialite/" target="_blank">politique de confidentialité</a></label>
        </div>
      </div>

      <button type="submit" class="osp-btn-submit" id="osp-submit">Continuer</button>
      <div id="osp-feedback" class="osp-feedback"></div>
    </form>
  </div>
</div>

<div class="osp-toast" id="osp-toast" role="status" aria-live="polite">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="osp-toast-msg">Lien envoyé, vérifie ton email</span>
</div>

<script>
// M_OCRE_PARCOURS_V4 — flow magic link only
// État machine : INITIAL (email seul) → CHECKING (POST email-check) → DIRECT_LOGIN (existing) ou FORM_OPEN (new) → SUBMITTING_FORM → DONE (toast)
var OCRE_SIGNUP_STATE = 'initial';

function ocreSignupOpen() {
  document.getElementById('osp-accordion').classList.remove('osp-open');
  document.getElementById('osp-accordion').setAttribute('aria-hidden', 'true');
  document.getElementById('osp-email').disabled = false;
  document.getElementById('osp-submit').textContent = 'Continuer';
  document.getElementById('osp-form').reset();
  document.getElementById('osp-indicatif').value = '+33';
  OCRE_SIGNUP_STATE = 'initial';
  var ov = document.getElementById('osp-overlay');
  ov.classList.add('osp-show');
  document.body.style.overflow = 'hidden';
  setTimeout(function(){ document.getElementById('osp-email').focus(); }, 200);
}
function ocreSignupClose() {
  var ov = document.getElementById('osp-overlay');
  ov.classList.remove('osp-show');
  document.body.style.overflow = '';
}
function ocreToast(msg, kind) {
  var t = document.getElementById('osp-toast');
  document.getElementById('osp-toast-msg').textContent = msg;
  t.style.background = (kind === 'error') ? '#C62828' : '#2D7A3E';
  t.classList.add('osp-toast-show');
  setTimeout(function(){ t.classList.remove('osp-toast-show'); }, 2200);
}

async function ocreSignupSubmit(e) {
  e.preventDefault();
  var btn = document.getElementById('osp-submit'); btn.disabled = true;
  var fb = document.getElementById('osp-feedback'); fb.classList.remove('osp-show-fb', 'osp-error', 'osp-info');
  var emailEl = document.getElementById('osp-email');
  var email = emailEl.value.trim().toLowerCase();
  var app = window.OCRE_SIGNUP_APP || 'agent';

  if (OCRE_SIGNUP_STATE === 'initial') {
    btn.textContent = '⏳ Vérification…';
    try {
      var r = await fetch('https://auth.ocre.immo/api/email-check.php', {
        method: 'POST', credentials: 'include', mode: 'cors',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ email: email, app: app }),
      });
      var d = await r.json();
      if (!r.ok) throw new Error(d.error || 'check failed');
      if (d.existing) {
        // Phase V4 : entrée directe sans magic link
        ocreToast('✓ Bon retour ' + (d.first_name || '') + ' !', 'success');
        setTimeout(function(){ window.location.href = d.redirect_url; }, 600);
        return false;
      }
      // Email absent : ouvrir accordéon
      OCRE_SIGNUP_STATE = 'form_open';
      emailEl.disabled = true;
      var acc = document.getElementById('osp-accordion');
      acc.classList.add('osp-open');
      acc.setAttribute('aria-hidden', 'false');
      btn.disabled = false; btn.textContent = 'Recevoir mon lien';
      // Focus sur prénom après animation
      setTimeout(function(){ document.getElementById('osp-prenom').focus(); }, 350);
      return false;
    } catch (err) {
      fb.textContent = 'Erreur : ' + err.message; fb.classList.add('osp-show-fb', 'osp-error');
      btn.disabled = false; btn.textContent = 'Continuer';
      return false;
    }
  }

  // form_open → submit registration + magic link
  var prenom = document.getElementById('osp-prenom').value.trim();
  var nom = document.getElementById('osp-nom').value.trim();
  var phone = document.getElementById('osp-phone').value.trim();
  var indicatif = document.getElementById('osp-indicatif').value.trim();
  var cgu = document.getElementById('osp-cgu').checked;
  if (!prenom || !nom || !phone) {
    fb.textContent = '⚠ Prénom, nom, téléphone obligatoires.'; fb.classList.add('osp-show-fb', 'osp-error');
    btn.disabled = false; btn.textContent = 'Recevoir mon lien';
    return false;
  }
  if (!cgu) {
    fb.textContent = '⚠ Tu dois accepter les CGU.'; fb.classList.add('osp-show-fb', 'osp-error');
    btn.disabled = false; btn.textContent = 'Recevoir mon lien';
    return false;
  }
  btn.textContent = '⏳ Envoi…';
  try {
    var r2 = await fetch('https://auth.ocre.immo/api/magic-link/request.php', {
      method: 'POST', credentials: 'include', mode: 'cors',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        email: email,
        first_name: prenom,
        last_name: nom,
        societe: document.getElementById('osp-societe').value.trim(),
        phone: indicatif + ' ' + phone,
        cgu_accepted: true,
        target_app: app,
      }),
    });
    var d2 = await r2.json();
    if (d2.ok) {
      ocreSignupClose();
      setTimeout(function(){ ocreToast('✓ Lien envoyé, vérifie ton email'); }, 200);
    } else {
      fb.textContent = 'Erreur : ' + (d2.error || 'inconnue'); fb.classList.add('osp-show-fb', 'osp-error');
      btn.disabled = false; btn.textContent = 'Recevoir mon lien';
    }
  } catch (err) {
    fb.textContent = 'Erreur réseau : ' + err.message; fb.classList.add('osp-show-fb', 'osp-error');
    btn.disabled = false; btn.textContent = 'Recevoir mon lien';
  }
  return false;
}

// Esc closes popup
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' && document.getElementById('osp-overlay').classList.contains('osp-show')) ocreSignupClose();
});
// Event delegation [data-signup-trigger]
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
