<?php
// M/2026/05/11/37 + AMENDEMENT — M_AUTH_FLOW_REFONTE : popup login unifie + accordeon signup cas C.
// Tap "Commencer (gratuit)" / "Créer mon compte" → popup overlay sur la même page (ZERO redirect).
// POST /api/login.php (auth.ocre.immo) → dispatch :
//   A) action=direct → message "deja connecté" + redirect <slug>.ocre.immo apres 1.2s
//   B) action=link_sent → message vert "Lien envoyé" + bouton "Renvoyer" disabled 30s
//   C) action=signup_required → accordeon deploye (prenom/nom/phone/cgu/rgpd) → POST /api/magic-link/request.php
?>
<style>
.oal-overlay { position: fixed; inset: 0; background: rgba(15,10,5,0.55); backdrop-filter: blur(4px); z-index: 9998; opacity: 0; pointer-events: none; transition: opacity .25s; display: flex; align-items: center; justify-content: center; padding: 20px; font-family: 'DM Sans', system-ui, sans-serif; }
.oal-overlay.oal-show { opacity: 1; pointer-events: auto; }
.oal-modal { background: #FAF6F1; border-radius: 22px; width: 100%; max-width: 460px; padding: 32px 28px 26px; box-shadow: 0 30px 80px rgba(0,0,0,0.35); position: relative; transform: translateY(40px); opacity: 0; transition: all .35s cubic-bezier(.2,.7,.2,1); max-height: 92vh; overflow-y: auto; }
.oal-overlay.oal-show .oal-modal { transform: translateY(0); opacity: 1; }
@media (max-width: 540px) {
  .oal-overlay { align-items: flex-end; padding: 0; }
  .oal-modal { border-radius: 22px 22px 0 0; max-width: 100%; padding: 26px 22px 20px; max-height: 90vh; transform: translateY(100%); }
  .oal-overlay.oal-show .oal-modal { transform: translateY(0); }
}
.oal-close { position: absolute; top: 14px; right: 14px; width: 34px; height: 34px; border-radius: 50%; background: rgba(0,0,0,0.06); border: none; cursor: pointer; font-size: 18px; color: #6B5642; display: flex; align-items: center; justify-content: center; }
.oal-brand { font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-weight: 600; font-size: 28px; color: #8B5E3C; text-align: center; margin: 0 0 4px; }
.oal-brand .oal-re { color: #D4A256; }
.oal-h1 { font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-style: italic; font-size: 22px; color: #3D2818; text-align: center; margin: 4px 0 4px; }
.oal-sub { font-size: 13px; color: #6B5642; text-align: center; margin-bottom: 18px; }
.oal-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.oal-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
@media (max-width: 420px) { .oal-row { grid-template-columns: 1fr; } }
.oal-row .oal-field { margin-bottom: 0; }
.oal-field label { font-size: 11px; font-weight: 600; color: #998877; text-transform: uppercase; letter-spacing: .04em; }
.oal-field input { padding: 12px 13px; border: 1px solid #E5DAC6; border-radius: 9px; font-size: 14px; font-family: inherit; color: #3D2818; background: #FCFAF7; }
.oal-field input:focus { outline: none; border-color: #8B5E3C; background: #fff; }
.oal-tel-row { display: grid; grid-template-columns: 110px 1fr; gap: 8px; align-items: end; }
.oal-tel-row select, .oal-tel-row input { padding: 12px 10px; border: 1px solid #E5DAC6; border-radius: 9px; font-family: inherit; font-size: 14px; background: #FCFAF7; }
.oal-cgu { display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: #5A4E3D; line-height: 1.5; margin-bottom: 10px; }
.oal-cgu input { margin-top: 2px; accent-color: #8B5E3C; }
.oal-cgu a { color: #8B5E3C; text-decoration: underline; }
.oal-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 13px 18px; border-radius: 10px; font-family: inherit; font-weight: 700; font-size: 14px; border: none; cursor: pointer; background: #3D2818; color: #fff; transition: background .15s, opacity .15s; }
.oal-btn:hover:not(:disabled) { background: #2A1810; }
.oal-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.oal-msg { margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; text-align: center; display: none; line-height: 1.5; }
.oal-msg.oal-show-msg { display: block; }
.oal-msg.oal-success { background: #E8F5E9; color: #2E7D32; }
.oal-msg.oal-info { background: #FFF4E5; color: #B15A00; }
.oal-msg.oal-error { background: #FDECEA; color: #C62828; }
.oal-extra { max-height: 0; overflow: hidden; opacity: 0; transition: max-height .35s ease-out, opacity .25s ease; }
.oal-extra.oal-extra-open { max-height: 720px; opacity: 1; }
.oal-extra-note { background: #FFF7E5; border: 1px solid #F3D9A0; color: #6B4F00; font-size: 13px; padding: 10px 12px; border-radius: 8px; margin: 10px 0 14px; }
</style>
<div class="oal-overlay" id="oal-overlay" onclick="if(event.target===this){window.ocreSignupClose();}">
  <div class="oal-modal" role="dialog" aria-modal="true" aria-labelledby="oal-title">
    <button type="button" class="oal-close" aria-label="Fermer" onclick="window.ocreSignupClose()">&times;</button>
    <p class="oal-brand">Oc<span class="oal-re">re</span></p>
    <h2 class="oal-h1" id="oal-title">Connecte-toi ou crée ton compte</h2>
    <p class="oal-sub">Reçois ton lien d'accès par email · zéro mot de passe</p>
    <form id="oal-form" autocomplete="on" onsubmit="return window.ocreLoginSubmit(event)">
      <div class="oal-field">
        <label for="oal-email">Email</label>
        <input type="email" id="oal-email" name="email" required autocomplete="email" inputmode="email" placeholder="ton@email.com">
      </div>

      <!-- Accordeon cas C : champs supplementaires deployes si email inconnu -->
      <div class="oal-extra" id="oal-extra">
        <div class="oal-extra-note" id="oal-extra-note">Tu n'as pas encore de compte. Complète tes infos ci-dessous pour t'inscrire.</div>
        <div class="oal-row">
          <div class="oal-field"><label for="oal-prenom">Prénom *</label><input type="text" id="oal-prenom" autocomplete="given-name"></div>
          <div class="oal-field"><label for="oal-nom">Nom *</label><input type="text" id="oal-nom" autocomplete="family-name"></div>
        </div>
        <div class="oal-field" style="margin-bottom:12px"><label for="oal-societe">Société (facultatif)</label><input type="text" id="oal-societe" autocomplete="organization"></div>
        <div class="oal-field" style="margin-bottom:12px">
          <label>Téléphone *</label>
          <div class="oal-tel-row">
            <select id="oal-tel-country">
              <option value="+33" data-cc="FR" selected>🇫🇷 +33</option>
              <option value="+32" data-cc="BE">🇧🇪 +32</option>
              <option value="+41" data-cc="CH">🇨🇭 +41</option>
              <option value="+34" data-cc="ES">🇪🇸 +34</option>
              <option value="+39" data-cc="IT">🇮🇹 +39</option>
              <option value="+44" data-cc="GB">🇬🇧 +44</option>
              <option value="+1"  data-cc="US">🇺🇸 +1</option>
              <option value="+212" data-cc="MA">🇲🇦 +212</option>
              <option value="+213" data-cc="DZ">🇩🇿 +213</option>
              <option value="+216" data-cc="TN">🇹🇳 +216</option>
            </select>
            <input type="tel" id="oal-tel" autocomplete="tel" inputmode="tel" placeholder="6 12 34 56 78">
          </div>
        </div>
        <label class="oal-cgu"><input type="checkbox" id="oal-cgu"> J'accepte les <a href="https://ocre.immo/mentions-legales/" target="_blank">conditions générales d'utilisation</a> *</label>
        <label class="oal-cgu"><input type="checkbox" id="oal-rgpd"> J'accepte le traitement de mes données conformément à la <a href="https://ocre.immo/confidentialite/" target="_blank">politique de confidentialité</a> (RGPD) *</label>
      </div>

      <button type="submit" class="oal-btn" id="oal-submit">Recevoir mon lien d'accès</button>
    </form>
    <div class="oal-msg" id="oal-msg"></div>
  </div>
</div>
<script>
(function() {
  var APP = 'agent';
  var STATE = 'login'; // 'login' | 'signup_accordeon'
  var overlay = document.getElementById('oal-overlay');
  var emailIn = document.getElementById('oal-email');
  var submitBtn = document.getElementById('oal-submit');
  var msgEl = document.getElementById('oal-msg');
  var extra = document.getElementById('oal-extra');
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var resendTimer = null;

  function showMsg(kind, html) { msgEl.className = 'oal-msg oal-show-msg oal-' + kind; msgEl.innerHTML = html; }
  function clearMsg() { msgEl.className = 'oal-msg'; msgEl.innerHTML = ''; }
  function openAccordeon() {
    STATE = 'signup_accordeon';
    extra.classList.add('oal-extra-open');
    submitBtn.textContent = 'Créer mon compte et recevoir mon lien';
    validateAccordeon();
  }
  function resetAccordeon() {
    STATE = 'login';
    extra.classList.remove('oal-extra-open');
    submitBtn.textContent = "Recevoir mon lien d'accès";
    submitBtn.disabled = false;
  }
  function validateAccordeon() {
    var p = (document.getElementById('oal-prenom').value || '').trim();
    var n = (document.getElementById('oal-nom').value || '').trim();
    var t = (document.getElementById('oal-tel').value || '').replace(/\D/g, '');
    var cgu = document.getElementById('oal-cgu').checked;
    var rgpd = document.getElementById('oal-rgpd').checked;
    var ok = p.length >= 2 && n.length >= 2 && t.length >= 6 && cgu && rgpd;
    submitBtn.disabled = !ok;
  }
  ['oal-prenom','oal-nom','oal-tel','oal-cgu','oal-rgpd'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', validateAccordeon);
    if (el) el.addEventListener('change', validateAccordeon);
  });

  window.ocreSignupOpen = function(opts) {
    APP = (opts && opts.app) || 'agent';
    STATE = 'login'; clearMsg(); resetAccordeon();
    overlay.classList.add('oal-show');
    document.documentElement.style.overflow = 'hidden';
    setTimeout(function() { emailIn.focus(); }, 200);
  };
  window.ocreSignupClose = function() {
    overlay.classList.remove('oal-show');
    document.documentElement.style.overflow = '';
    if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
  };

  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-signup-trigger]').forEach(function(el) {
      if (el._signupBound) return; el._signupBound = true;
      el.addEventListener('click', function(e) {
        e.preventDefault();
        window.ocreSignupOpen({ app: el.dataset.signupTrigger || 'agent' });
      });
    });
  });

  function setBtn(label, disabled) { submitBtn.textContent = label; submitBtn.disabled = !!disabled; }
  function startResendCooldown() {
    var secs = 30; setBtn('Renvoyer (' + secs + 's)', true);
    resendTimer = setInterval(function() {
      secs--;
      if (secs <= 0) { clearInterval(resendTimer); resendTimer = null; setBtn('Renvoyer', false); }
      else setBtn('Renvoyer (' + secs + 's)', true);
    }, 1000);
  }

  window.ocreLoginSubmit = async function(e) {
    e.preventDefault();
    var email = emailIn.value.trim().toLowerCase();
    if (!EMAIL_RE.test(email)) { showMsg('error', 'Email invalide.'); return false; }

    // STATE signup_accordeon : POST direct vers magic-link/request.php avec full profile.
    if (STATE === 'signup_accordeon') {
      var prenom = document.getElementById('oal-prenom').value.trim();
      var nom = document.getElementById('oal-nom').value.trim();
      var societe = document.getElementById('oal-societe').value.trim();
      var country = document.getElementById('oal-tel-country');
      var indicatif = country.value;
      var tel = document.getElementById('oal-tel').value.trim();
      var cgu = document.getElementById('oal-cgu').checked;
      var rgpd = document.getElementById('oal-rgpd').checked;
      if (!prenom || !nom || !tel || !cgu || !rgpd) { showMsg('error', 'Complète tous les champs requis.'); return false; }
      setBtn('Création…', true);
      try {
        var r2 = await fetch('https://auth.ocre.immo/api/magic-link/request.php', {
          method: 'POST', credentials: 'include', mode: 'cors',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            email: email, first_name: prenom, last_name: nom, societe: societe,
            phone: indicatif + ' ' + tel,
            cgu_accepted: true, rgpd_accepted: true, target_app: APP,
          }),
        });
        var d2 = await r2.json();
        if (d2.ok) {
          showMsg('success', '✓ Compte créé, lien envoyé à <b>' + email.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</b>. Vérifie ton email.');
          // AMENDEMENT #2 : fade form en accordeon + remplace titre + auto-close 4s.
          var form = document.getElementById('oal-form');
          var subEl = document.querySelector('#oal-overlay .oal-sub');
          var titleEl = document.getElementById('oal-title');
          form.style.height = form.offsetHeight + 'px'; form.offsetHeight; // reflow
          form.style.transition = 'height 300ms ease-out, opacity 200ms ease-out, margin 200ms ease-out';
          form.style.overflow = 'hidden';
          requestAnimationFrame(function() {
            form.style.height = '0'; form.style.opacity = '0'; form.style.margin = '0';
            if (subEl) { subEl.style.transition = 'opacity 200ms ease-out'; subEl.style.opacity = '0'; }
          });
          if (titleEl) titleEl.textContent = 'Compte créé !';
          setTimeout(function() { window.ocreSignupClose(); }, 4000);
        } else {
          showMsg('error', 'Erreur : ' + (d2.error || 'inconnue'));
          setBtn('Réessayer', false);
        }
      } catch (err) {
        showMsg('error', 'Erreur réseau : ' + (err && err.message || err));
        setBtn('Réessayer', false);
      }
      return false;
    }

    // STATE login : POST /api/login.php pour dispatcher A / B / C.
    setBtn('Envoi…', true); clearMsg();
    try {
      var r = await fetch('https://auth.ocre.immo/api/login.php', {
        method: 'POST', credentials: 'include', mode: 'cors',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ email: email, app: APP }),
      });
      var d = await r.json();
      if (!d.ok) {
        showMsg('error', d.error === 'rate_limit' ? 'Trop de tentatives. Réessaie dans 1h.' : ('Erreur : ' + (d.error || 'inconnue')));
        setBtn("Recevoir mon lien d'accès", false); return false;
      }
      if (d.action === 'direct') {
        showMsg('success', '✓ Tu es déjà connecté — on t\'envoie dans ton workspace…');
        setBtn('Redirection…', true);
        setTimeout(function() { window.location.href = d.redirect_url; }, 1200);
      } else if (d.action === 'link_sent') {
        // M/2026/05/11/40 BUG#2 — Cas B succes : fade form + auto-close 4s (idem cas C succes).
        showMsg('success', '✓ Lien envoyé à <b>' + email.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</b>. Vérifie ton email.');
        var form = document.getElementById('oal-form');
        var subEl = document.querySelector('#oal-overlay .oal-sub');
        var titleEl = document.getElementById('oal-title');
        form.style.height = form.offsetHeight + 'px'; form.offsetHeight; // reflow
        form.style.transition = 'height 300ms ease-out, opacity 200ms ease-out, margin 200ms ease-out';
        form.style.overflow = 'hidden';
        requestAnimationFrame(function() {
          form.style.height = '0'; form.style.opacity = '0'; form.style.margin = '0';
          if (subEl) { subEl.style.transition = 'opacity 200ms ease-out'; subEl.style.opacity = '0'; }
        });
        if (titleEl) titleEl.textContent = 'Lien envoyé !';
        setTimeout(function() { window.ocreSignupClose(); }, 4000);
      } else if (d.action === 'signup_required') {
        openAccordeon();
        showMsg('info', 'Tu n\'as pas encore de compte — complète tes infos ci-dessous.');
      } else {
        showMsg('error', 'Réponse inattendue.');
        setBtn("Recevoir mon lien d'accès", false);
      }
    } catch (err) {
      showMsg('error', 'Erreur réseau : ' + (err && err.message || err));
      setBtn("Recevoir mon lien d'accès", false);
    }
    return false;
  };
})();
</script>
