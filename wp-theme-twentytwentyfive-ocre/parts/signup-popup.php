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

.osp-btn-submit { width: 100%; padding: 14px; background: #8B5E3C; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .2s ease; }
.osp-btn-submit:hover:not(.osp-btn-disabled):not(:disabled) { background: #3D2818; transform: translateY(-1px); }
.osp-btn-submit:disabled { opacity: 0.5; cursor: wait; transform: none; }
/* M_OCRE_PARCOURS_V4_CORRECTIF — bouton désactivé tant que tous champs requis pas valides */
.osp-btn-submit.osp-btn-disabled { background: #C8C8C8 !important; color: #888 !important; opacity: 0.6; cursor: not-allowed; pointer-events: none; transform: none; }

/* M_OCRE_PARCOURS_V4_CORRECTIF — champ téléphone vert clair si E.164 valide pays-aware */
.osp-phone-input.is-phone-valid { background: #E8F5E9 !important; border-color: #4CAF50 !important; transition: all .2s ease; }
.osp-phone-input.is-phone-invalid { border-color: #E57373; background: #FFF5F5; }

/* M_OCRE_PARCOURS_V4_CORRECTIF — sélecteur pays drapeau + dropdown 21 pays */
.osp-country-wrap { position: relative; }
.osp-country-btn { width: 100%; padding: 13px 30px 13px 14px; border: 1px solid #E5DAC6; border-radius: 9px; font-size: 14px; font-family: inherit; background: #FCFAF7; color: #3D2818; cursor: pointer; text-align: left; appearance: none; display: flex; align-items: center; gap: 8px; }
.osp-country-btn::after { content: '▾'; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #998877; pointer-events: none; }
.osp-country-flag { font-size: 18px; line-height: 1; }
.osp-country-code { font-weight: 600; font-size: 13px; }
.osp-country-dropdown { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fff; border: 1px solid #E5DAC6; border-radius: 10px; max-height: 280px; overflow-y: auto; z-index: 10; box-shadow: 0 12px 28px rgba(0,0,0,0.12); display: none; }
.osp-country-dropdown.osp-cd-open { display: block; }
.osp-country-search { width: 100%; padding: 10px 12px; border: none; border-bottom: 1px solid #E5DAC6; font-size: 13px; font-family: inherit; background: #FCFAF7; outline: none; position: sticky; top: 0; }
.osp-country-item { padding: 9px 14px; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 13.5px; }
.osp-country-item:hover, .osp-country-item.osp-ci-hover { background: #FBF1E4; }
.osp-country-item .osp-ci-name { flex: 1; color: #3D2818; }
.osp-country-item .osp-ci-code { color: #998877; font-weight: 500; font-size: 12.5px; }
.osp-country-divider { padding: 6px 14px; font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: #998877; background: #F4ECDF; font-weight: 600; }

.osp-email-error { font-size: 11.5px; color: #C62828; margin-top: 4px; display: none; }
.osp-email-error.osp-show { display: block; }

.osp-feedback { margin-top: 10px; padding: 9px 13px; border-radius: 8px; font-size: 12.5px; display: none; }
.osp-feedback.osp-show-fb { display: block; }
.osp-feedback.osp-error { background: #FDECEA; color: #C62828; }
.osp-feedback.osp-info { background: #E8F4F8; color: #0277BD; }

/* Accordéon registration — M_OCRE_POPUP_TIMING_FIX : overflow:hidden retiré pendant osp-open pour éviter que form intercepts pointer events au check CGU pendant transition 400ms */
.osp-accordion { max-height: 0; overflow: hidden; transition: max-height .4s cubic-bezier(.2,.7,.2,1); }
.osp-accordion.osp-open { max-height: 800px; overflow: visible; }
.osp-accordion-inner { pointer-events: auto; }
.osp-accordion-inner { padding-top: 14px; border-top: 1px solid #E5DAC6; margin-top: 14px; }
.osp-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
.osp-tel-row { display: grid; grid-template-columns: 90px 1fr; gap: 8px; align-items: end; }
.osp-cgu { display: flex; align-items: flex-start; gap: 8px; margin: 8px 0 12px; font-size: 11.5px; color: #6B5642; line-height: 1.4; }
.osp-cgu input { margin-top: 2px; accent-color: #8B5E3C; cursor: pointer; flex-shrink: 0; }
.osp-cgu-label { cursor: pointer; user-select: none; }
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
        <div class="osp-email-error" id="osp-email-error">Format email invalide</div>
      </div>

      <!-- Accordéon registration : visible si email pas reconnu -->
      <div class="osp-accordion" id="osp-accordion" aria-hidden="true">
        <div class="osp-accordion-inner">
          <div class="osp-row-2">
            <div class="osp-field"><label for="osp-prenom">Prénom *</label><input type="text" id="osp-prenom" autocomplete="given-name"></div>
            <div class="osp-field"><label for="osp-nom">Nom *</label><input type="text" id="osp-nom" autocomplete="family-name"></div>
          </div>
          <div class="osp-field"><label for="osp-societe">Société (facultatif)</label><input type="text" id="osp-societe" autocomplete="organization" placeholder="Ton entreprise (facultatif)"></div>
          <div class="osp-tel-row">
            <div class="osp-field osp-country-wrap">
              <label>Pays</label>
              <button type="button" class="osp-country-btn" id="osp-country-btn" aria-haspopup="listbox" aria-expanded="false">
                <span class="osp-country-flag" id="osp-cf">🇫🇷</span>
                <span class="osp-country-code" id="osp-cc">+33</span>
              </button>
              <div class="osp-country-dropdown" id="osp-country-dropdown" role="listbox">
                <input type="text" class="osp-country-search" id="osp-country-search" placeholder="Recherche pays…">
                <div id="osp-country-list"></div>
              </div>
              <input type="hidden" id="osp-country-code" name="country_code" value="FR">
              <input type="hidden" id="osp-country-prefix" value="+33">
            </div>
            <div class="osp-field">
              <label for="osp-phone">Téléphone *</label>
              <input type="tel" id="osp-phone" name="phone" class="osp-phone-input" autocomplete="tel" placeholder="6 12 34 56 78">
            </div>
          </div>
          <!-- M_OCRE_SIGNUP_STATE_MACHINE_FIX — checkbox HORS label parent + label for=osp-cgu pour eviter double toggle quand click direct sur input + onclick stopPropagation defense supplementaire -->
          <div class="osp-cgu">
            <input type="checkbox" id="osp-cgu" onclick="event.stopPropagation();">
            <label for="osp-cgu" class="osp-cgu-label">J'accepte les <a href="https://ocre.immo/mentions-legales/" target="_blank">CGU</a> et la <a href="https://ocre.immo/confidentialite/" target="_blank">politique de confidentialité</a></label>
          </div>
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
// M_OCRE_PARCOURS_V4_CORRECTIF — PhoneInput country-aware E.164 + validation visuelle + bouton conditionnel + email onBlur
var OCRE_SIGNUP_STATE = 'initial';

// 21 pays supportés avec règles E.164 (cohérent Oi Agent app M86)
var OSP_COUNTRIES = [
  { code:'FR', name:'France',         flag:'🇫🇷', prefix:'+33',  length:9,  startsWith:['6','7','1','2','3','4','5','9'], priority:true },
  { code:'MA', name:'Maroc',          flag:'🇲🇦', prefix:'+212', length:9,  startsWith:['5','6','7'],                     priority:true },
  { code:'BE', name:'Belgique',       flag:'🇧🇪', prefix:'+32',  length:9,  startsWith:['4','2','3','9'],                 priority:true },
  { code:'CH', name:'Suisse',         flag:'🇨🇭', prefix:'+41',  length:9,  startsWith:['7','2','3','4','5','6','8'],     priority:true },
  { code:'ES', name:'Espagne',        flag:'🇪🇸', prefix:'+34',  length:9,  startsWith:['6','7','9'],                     priority:true },
  { code:'IT', name:'Italie',         flag:'🇮🇹', prefix:'+39',  length:10,                                                priority:true },
  { code:'DE', name:'Allemagne',      flag:'🇩🇪', prefix:'+49',  length:11,                                                priority:true },
  { code:'PT', name:'Portugal',       flag:'🇵🇹', prefix:'+351', length:9,                                                 priority:true },
  { code:'GB', name:'Royaume-Uni',    flag:'🇬🇧', prefix:'+44',  length:10,                                                priority:true },
  { code:'US', name:'États-Unis',     flag:'🇺🇸', prefix:'+1',   length:10,                                                priority:true },
  { code:'CA', name:'Canada',         flag:'🇨🇦', prefix:'+1',   length:10,                                                priority:true },
  { code:'NL', name:'Pays-Bas',       flag:'🇳🇱', prefix:'+31',  length:9 },
  { code:'TN', name:'Tunisie',        flag:'🇹🇳', prefix:'+216', length:8 },
  { code:'DZ', name:'Algérie',        flag:'🇩🇿', prefix:'+213', length:9 },
  { code:'LU', name:'Luxembourg',     flag:'🇱🇺', prefix:'+352', length:9 },
  { code:'AE', name:'Émirats arabes', flag:'🇦🇪', prefix:'+971', length:9 },
  { code:'SA', name:'Arabie saoudite',flag:'🇸🇦', prefix:'+966', length:9 },
  { code:'TR', name:'Turquie',        flag:'🇹🇷', prefix:'+90',  length:10 },
  { code:'IE', name:'Irlande',        flag:'🇮🇪', prefix:'+353', length:9 },
  { code:'AT', name:'Autriche',       flag:'🇦🇹', prefix:'+43',  length:11 },
  { code:'GR', name:'Grèce',          flag:'🇬🇷', prefix:'+30',  length:10 },
];

function ospValidatePhoneE164(rawPhone, countryCode) {
  if (!rawPhone || !countryCode) return false;
  var digits = rawPhone.replace(/\D/g, '');
  // Strip leading 0 (numéros nationaux FR)
  if (digits.length > 0 && digits[0] === '0') digits = digits.substring(1);
  var rule = OSP_COUNTRIES.find(function(c){ return c.code === countryCode; });
  if (!rule) return digits.length >= 8 && digits.length <= 15;
  if (digits.length !== rule.length) return false;
  if (rule.startsWith && !rule.startsWith.some(function(s){ return digits.startsWith(s); })) return false;
  return true;
}

function ospRenderCountryList(filter) {
  filter = (filter || '').toLowerCase().trim();
  var list = document.getElementById('osp-country-list'); list.innerHTML = '';
  var prio = OSP_COUNTRIES.filter(function(c){ return c.priority && (!filter || c.name.toLowerCase().indexOf(filter) !== -1 || c.code.toLowerCase().indexOf(filter) !== -1); });
  var rest = OSP_COUNTRIES.filter(function(c){ return !c.priority && (!filter || c.name.toLowerCase().indexOf(filter) !== -1 || c.code.toLowerCase().indexOf(filter) !== -1); }).sort(function(a,b){ return a.name.localeCompare(b.name); });
  function appendItem(c) {
    var item = document.createElement('div'); item.className = 'osp-country-item'; item.setAttribute('data-code', c.code); item.setAttribute('data-prefix', c.prefix);
    item.innerHTML = '<span class="osp-country-flag">' + c.flag + '</span><span class="osp-ci-name">' + c.name + '</span><span class="osp-ci-code">' + c.prefix + '</span>';
    item.addEventListener('click', function(){ ospSelectCountry(c); });
    list.appendChild(item);
  }
  if (!filter && prio.length) {
    var div = document.createElement('div'); div.className = 'osp-country-divider'; div.textContent = 'Pays prioritaires'; list.appendChild(div);
    prio.forEach(appendItem);
    var div2 = document.createElement('div'); div2.className = 'osp-country-divider'; div2.textContent = 'Tous les pays'; list.appendChild(div2);
    rest.forEach(appendItem);
  } else {
    prio.forEach(appendItem); rest.forEach(appendItem);
  }
}

function ospSelectCountry(c) {
  document.getElementById('osp-cf').textContent = c.flag;
  document.getElementById('osp-cc').textContent = c.prefix;
  document.getElementById('osp-country-code').value = c.code;
  document.getElementById('osp-country-prefix').value = c.prefix;
  document.getElementById('osp-country-dropdown').classList.remove('osp-cd-open');
  document.getElementById('osp-country-btn').setAttribute('aria-expanded', 'false');
  ospValidateForm(); // revalider numéro avec nouvelles règles pays
}

function ospValidateForm() {
  if (OCRE_SIGNUP_STATE !== 'form_open') return;
  var prenom = (document.getElementById('osp-prenom').value || '').trim();
  var nom = (document.getElementById('osp-nom').value || '').trim();
  var phone = (document.getElementById('osp-phone').value || '').trim();
  var country = document.getElementById('osp-country-code').value;
  var cgu = document.getElementById('osp-cgu').checked;
  var phoneValid = ospValidatePhoneE164(phone, country);
  // Visuel champ téléphone
  var phoneEl = document.getElementById('osp-phone');
  phoneEl.classList.remove('is-phone-valid', 'is-phone-invalid');
  if (phoneValid) phoneEl.classList.add('is-phone-valid');
  else if (phone.replace(/\D/g, '').length > 4) phoneEl.classList.add('is-phone-invalid');
  // Bouton submit
  var btn = document.getElementById('osp-submit');
  var allValid = prenom.length >= 2 && nom.length >= 2 && phoneValid && cgu;
  btn.classList.toggle('osp-btn-disabled', !allValid);
}

// Email onBlur : check format + appel email-check pour entrée directe ou flow accordéon
async function ospEmailBlur() {
  if (OCRE_SIGNUP_STATE !== 'initial') return;
  var emailEl = document.getElementById('osp-email');
  var errEl = document.getElementById('osp-email-error');
  var email = emailEl.value.trim().toLowerCase();
  errEl.classList.remove('osp-show');
  if (!email) return;
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
    errEl.textContent = 'Email invalide'; errEl.classList.add('osp-show');
  }
}

// Init listeners au load
function ospInitForm() {
  ospRenderCountryList('');
  // Toggle dropdown
  document.getElementById('osp-country-btn').addEventListener('click', function(e){
    e.stopPropagation();
    var dd = document.getElementById('osp-country-dropdown');
    var open = dd.classList.toggle('osp-cd-open');
    document.getElementById('osp-country-btn').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) setTimeout(function(){ document.getElementById('osp-country-search').focus(); }, 50);
  });
  document.addEventListener('click', function(e){
    if (!e.target.closest('.osp-country-wrap')) document.getElementById('osp-country-dropdown').classList.remove('osp-cd-open');
  });
  document.getElementById('osp-country-search').addEventListener('input', function(){ ospRenderCountryList(this.value); });
  // Validate continue sur tous les inputs accordéon
  ['osp-prenom','osp-nom','osp-phone'].forEach(function(id){ document.getElementById(id).addEventListener('input', ospValidateForm); });
  document.getElementById('osp-cgu').addEventListener('change', ospValidateForm);
  // Email onBlur
  document.getElementById('osp-email').addEventListener('blur', ospEmailBlur);
}
document.addEventListener('DOMContentLoaded', ospInitForm);
// Si DOM déjà chargé (popup inclus après load WP)
if (document.readyState !== 'loading') ospInitForm();

function ocreSignupOpen() {
  document.getElementById('osp-accordion').classList.remove('osp-open');
  document.getElementById('osp-accordion').setAttribute('aria-hidden', 'true');
  document.getElementById('osp-email').disabled = false;
  document.getElementById('osp-submit').textContent = 'Continuer';
  var formReset = document.getElementById('osp-form');
  formReset.reset();
  formReset.dataset.state = 'initial'; // M_OCRE_SIGNUP_STATE_MACHINE_FIX reset dataset
  ospSelectCountry(OSP_COUNTRIES.find(function(c){ return c.code === 'FR'; }));
  document.getElementById('osp-submit').classList.remove('osp-btn-disabled');
  document.getElementById('osp-phone').classList.remove('is-phone-valid', 'is-phone-invalid');
  document.getElementById('osp-email-error').classList.remove('osp-show');
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

  // M_OCRE_SIGNUP_STATE_MACHINE_FIX — fallback dataset state si variable JS perdue
  var stateEffective = OCRE_SIGNUP_STATE;
  var formEl = document.getElementById('osp-form');
  if (formEl && formEl.dataset.state) stateEffective = formEl.dataset.state;

  if (stateEffective === 'initial') {
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
      // M_OCRE_SIGNUP_STATE_MACHINE_FIX — state robust : variable JS + dataset DOM source de vérité (resistance closure perdue ou re-render)
      OCRE_SIGNUP_STATE = 'form_open';
      var formEl = document.getElementById('osp-form');
      formEl.dataset.state = 'form_open';
      emailEl.disabled = true;
      var acc = document.getElementById('osp-accordion');
      acc.classList.add('osp-open');
      acc.setAttribute('aria-hidden', 'false');
      btn.disabled = false; btn.textContent = 'Recevoir mon lien';
      // M_OCRE_PARCOURS_V4_CORRECTIF — bouton désactivé initialement (validation continue)
      btn.classList.add('osp-btn-disabled');
      ospValidateForm();
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
  var indicatif = document.getElementById('osp-country-prefix').value;
  var country = document.getElementById('osp-country-code').value;
  var cgu = document.getElementById('osp-cgu').checked;
  if (!prenom || prenom.length < 2 || !nom || nom.length < 2 || !ospValidatePhoneE164(phone, country) || !cgu) {
    // Defense supplementaire (le bouton est normalement disabled si pas valide, mais au cas où)
    fb.textContent = '⚠ Tous les champs requis doivent être remplis valides + CGU cochée.'; fb.classList.add('osp-show-fb', 'osp-error');
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
