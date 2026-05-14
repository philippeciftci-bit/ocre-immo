/*!
 * M/2026/05/14/77 — Module auth-flow Ocre. Source UNIQUE partagee entre :
 *   - Popup vitrine ocre.immo (signup-popup.php)
 *   - SPA auth.ocre.immo (/opt/ocre-auth/index.html)
 * Cross-origin via apiBase (defaut https://auth.ocre.immo). Cookie .ocre.immo (M/14/66).
 * Usage : OcreAuth.mount('#container', { apiBase, initialEmail, initialStep, initialToken, onSuccess })
 * Spec maquette v4 M/14/75. Aucune dependance externe (jQuery free).
 */
(function() {
  'use strict';
  if (window.OcreAuth) return;

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var STEPS = ['email','login','signup','signup-sent','forgot','forgot-sent','reset','reset-done'];

  // CSS inline injecte une fois.
  var STYLE_ID = 'ocre-auth-flow-style';
  var CSS = [
    "[data-ocre-auth] *,[data-ocre-auth] *::before,[data-ocre-auth] *::after{box-sizing:border-box}",
    "[data-ocre-auth]{font-family:'DM Sans',-apple-system,BlinkMacSystemFont,sans-serif;color:#3D2818;line-height:1.5;-webkit-font-smoothing:antialiased}",
    // M/2026/05/14/80 — card OPAQUE FORCEE !important contre WP overrides. Class renomme ocre-popup-card.
    "[data-ocre-auth].ocre-popup-card,[data-ocre-auth] .ocre-popup-card{background-color:#FFFFFF !important;background:#FFFFFF !important;border-radius:16px !important;padding:32px 28px !important;border:1px solid #E5DAC6 !important;box-shadow:0 20px 60px rgba(60,40,24,0.3) !important;max-width:460px !important;width:100% !important;max-height:calc(100vh - 32px) !important;overflow-y:auto !important;margin:0 auto !important;position:relative !important;opacity:1 !important}",
    "[data-ocre-auth].ocre-popup-card *,[data-ocre-auth] .ocre-popup-card *{background-color:transparent}",
    "[data-ocre-auth] .of-brand{text-align:center;margin-bottom:4px;font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-weight:600;font-size:32px;color:#8B5A3C;letter-spacing:-0.02em}",
    "[data-ocre-auth] .of-brand span{color:#D4A256}",
    "[data-ocre-auth] .of-sub-app{text-align:center;font-size:11px;color:#998877;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:18px;font-weight:600}",
    "[data-ocre-auth] h1.of-h1{font-family:'Cormorant Garamond',Georgia,serif;font-weight:600;font-style:italic;font-size:24px;text-align:center;margin:0 0 6px;color:#3D2818}",
    "[data-ocre-auth] .of-sub{text-align:center;font-size:13.5px;color:#6B5642;margin-bottom:20px}",
    "[data-ocre-auth] .of-email-pill{background:#FAF6F1;border:1px solid #E5DAC6;border-radius:10px;padding:11px 14px;margin-bottom:18px;font-size:13.5px;display:flex;justify-content:space-between;align-items:center;gap:12px}",
    "[data-ocre-auth] .of-email-pill .e{color:#3D2818;word-break:break-all;flex:1;min-width:0}",
    "[data-ocre-auth] .of-email-pill a{color:#8B5A3C;text-decoration:none;font-weight:600;font-size:12px;white-space:nowrap}",
    "[data-ocre-auth] .of-field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}",
    "[data-ocre-auth] .of-field label{font-size:11.5px;color:#6B5642;font-weight:600;letter-spacing:0.04em;text-transform:uppercase}",
    "[data-ocre-auth] .of-field label .opt{color:#998877;font-weight:400;text-transform:none;letter-spacing:0}",
    "[data-ocre-auth] .of-field input,[data-ocre-auth] .of-field select{padding:13px 14px;border:1px solid #E5DAC6;border-radius:10px;font-size:16px;font-family:inherit;color:#3D2818;background-color:#FCFAF7 !important;width:100%;min-width:0}",
    "[data-ocre-auth] .of-field input:focus,[data-ocre-auth] .of-field select:focus{outline:none;border-color:#8B5A3C;background:#fff}",
    "[data-ocre-auth] .of-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}",
    "[data-ocre-auth] .of-row-2 .of-field{margin-bottom:0;min-width:0}",
    "[data-ocre-auth] .of-tel-row{display:grid;grid-template-columns:auto 1fr;gap:8px;align-items:end}",
    "[data-ocre-auth] .of-pwd-wrap{position:relative}",
    "[data-ocre-auth] .of-pwd-wrap input{padding-right:44px}",
    "[data-ocre-auth] .of-pwd-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:6px;color:#998877;font-size:16px}",
    // M/2026/05/14/81 — tooltip overlay autofill iOS Keychain.
    "[data-ocre-auth] .of-pwd-wrap{position:relative}",
    "[data-ocre-auth] .of-eye-tooltip{position:absolute;left:0;right:44px;bottom:calc(100% + 8px);background:#FFF8E7;border:1px solid #D4A256;padding:8px 12px;border-radius:8px;font-family:'SF Mono',Menlo,Consolas,monospace;color:#3D2818;font-size:15px;box-shadow:0 4px 12px rgba(60,40,24,0.2);z-index:50;word-break:break-all;pointer-events:none}",
    "[data-ocre-auth] .of-eye-tooltip::after{content:'';position:absolute;bottom:-6px;left:14px;width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:6px solid #D4A256}",
    "[data-ocre-auth] .of-eye-tooltip::before{content:'';position:absolute;bottom:-5px;left:15px;width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:5px solid #FFF8E7;z-index:1}",
    "[data-ocre-auth] .of-hint{font-size:11.5px;color:#998877;margin-top:4px}",
    "[data-ocre-auth] .of-hint.ok{color:#2E7D32}",
    "[data-ocre-auth] .of-hint.err{color:#C62828}",
    "[data-ocre-auth] .of-hint.weak{color:#E07B00}",
    "[data-ocre-auth] .of-hint.medium{color:#B58200}",
    "[data-ocre-auth] .of-cgu{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#6B5642;line-height:1.5;margin-bottom:10px}",
    "[data-ocre-auth] .of-cgu input{margin-top:2px;accent-color:#8B5A3C}",
    "[data-ocre-auth] .of-cgu a{color:#8B5A3C}",
    "[data-ocre-auth] .of-btn-primary{display:block;width:100%;padding:15px 20px;background-color:#8B5A3C !important;color:#fff !important;border:none;border-radius:12px;font-family:'DM Sans',-apple-system,sans-serif;font-size:16px;font-weight:700;line-height:1.2;cursor:pointer;transition:background .15s;margin-top:10px}",
    "[data-ocre-auth] .of-btn-primary:hover:not(:disabled){background:#6B3F26}",
    "[data-ocre-auth] .of-btn-primary:disabled{opacity:0.4;cursor:not-allowed}",
    "[data-ocre-auth] .of-link-row{text-align:center;margin-top:14px;font-size:13px;color:#998877}",
    "[data-ocre-auth] .of-link-row a{color:#8B5A3C;text-decoration:none;font-weight:600;cursor:pointer}",
    "[data-ocre-auth] .of-msg{margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;display:none}",
    "[data-ocre-auth] .of-msg.show{display:block}",
    "[data-ocre-auth] .of-msg.err{background:#FDECEA;color:#C62828}",
    "[data-ocre-auth] .of-msg.ok{background:#E8F5E9;color:#2E7D32}",
    "[data-ocre-auth] .of-icon-mail{width:56px;height:56px;border-radius:50%;background:#8B5A3C;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px}",
    "[data-ocre-auth] .of-back-btn{background:none;border:none;color:#998877;font-size:13px;cursor:pointer;margin-bottom:14px;padding:0;font-family:inherit}",
    "[data-ocre-auth] .of-strength-bar{height:4px;background:#E5DAC6;border-radius:2px;margin-top:6px;overflow:hidden}",
    "[data-ocre-auth] .of-strength-bar>span{display:block;height:100%;width:0;transition:width .2s,background .2s}",
    "[data-ocre-auth] .of-match{font-size:11.5px;margin-top:4px}",
    "[data-ocre-auth] .of-match.ok{color:#2E7D32}",
    "[data-ocre-auth] .of-match.err{color:#C62828}",
    "[data-ocre-auth] .of-hidden{display:none}"
  ].join('');

  function injectCss() {
    if (document.getElementById(STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  // Templates HTML par etape (data-step). Pas de Cormorant fontface ajoute ici - charge en parent.
  function htmlAll() {
    return ''
      + '<div class="of-card ocre-popup-card" data-ocre-auth>'
      + '<div class="of-brand">Oc<span>re</span></div>'
      + '<div class="of-sub-app">Oi Agent</div>'

      // STEP email
      + '<div data-step="email">'
      + '<h1 class="of-h1">Connexion ou inscription</h1>'
      + '<div class="of-sub">Entre ton email pour continuer.</div>'
      + '<form data-form="email" autocomplete="on" novalidate>'
      + '<div class="of-field"><label for="of-email">Email</label><input type="email" id="of-email" name="email" required autocomplete="username" placeholder="ton@email.com" autofocus></div>'
      + '<button type="submit" class="of-btn-primary" data-submit="email">Continuer</button>'
      + '<div class="of-msg" data-msg="email"></div>'
      + '</form>'
      + '</div>'

      // STEP login
      + '<div data-step="login" class="of-hidden">'
      + '<h1 class="of-h1">Bon retour</h1>'
      + '<div class="of-email-pill"><span class="e" data-pill="login"></span><a data-go="email">Changer</a></div>'
      + '<form data-form="login" autocomplete="on" novalidate>'
      + '<input type="email" data-hidden="login" name="username" autocomplete="username" class="of-hidden">'
      + '<div class="of-field"><label for="of-login-pwd">Mot de passe</label>'
      + '<div class="of-pwd-wrap"><input id="of-login-pwd" name="password" type="password" required autocomplete="current-password" placeholder="••••••••"><button type="button" class="of-pwd-toggle" data-toggle="of-login-pwd">👁</button></div>'
      + '</div>'
      + '<button type="submit" class="of-btn-primary" data-submit="login">Se connecter</button>'
      + '<div class="of-msg" data-msg="login"></div>'
      + '<div class="of-link-row"><a data-go="forgot">Mot de passe oublié ?</a></div>'
      + '</form>'
      + '</div>'

      // STEP signup
      + '<div data-step="signup" class="of-hidden">'
      + '<h1 class="of-h1">Crée ton compte</h1>'
      + '<div class="of-email-pill"><span class="e" data-pill="signup"></span><a data-go="email">Changer</a></div>'
      + '<form data-form="signup" autocomplete="on" novalidate>'
      + '<input type="email" data-hidden="signup" name="username" autocomplete="username" class="of-hidden">'
      + '<div class="of-row-2">'
      + '<div class="of-field"><label for="of-prenom">Prénom *</label><input id="of-prenom" type="text" required autocomplete="given-name" autocapitalize="words"></div>'
      + '<div class="of-field"><label for="of-nom">Nom *</label><input id="of-nom" type="text" required autocomplete="family-name" autocapitalize="words"></div>'
      + '</div>'
      + '<div class="of-field"><label for="of-societe">Société <span class="opt">(optionnel)</span></label><input id="of-societe" type="text" autocomplete="organization"></div>'
      + '<div class="of-field"><label>Téléphone *</label>'
      + '<div class="of-tel-row">'
      + '<select id="of-tel-country">'
      + '<option value="+33" data-cc="FR" data-min="9" data-max="10" data-ph="6 12 34 56 78" selected>🇫🇷 +33</option>'
      + '<option value="+212" data-cc="MA" data-min="9" data-max="10" data-ph="6 12 34 56 78">🇲🇦 +212</option>'
      + '<option value="+34" data-cc="ES" data-min="9" data-max="9" data-ph="6 12 34 56 78">🇪🇸 +34</option>'
      + '<option value="+39" data-cc="IT" data-min="9" data-max="11" data-ph="3 12 345 678">🇮🇹 +39</option>'
      + '<option value="+32" data-cc="BE" data-min="8" data-max="9" data-ph="4 12 34 56 78">🇧🇪 +32</option>'
      + '<option value="+1" data-cc="US" data-min="10" data-max="10" data-ph="201 555 0123">🇺🇸 +1</option>'
      + '<option value="+44" data-cc="GB" data-min="10" data-max="10" data-ph="7 700 900123">🇬🇧 +44</option>'
      + '</select>'
      + '<input id="of-tel" type="tel" required autocomplete="tel" inputmode="tel" placeholder="6 12 34 56 78">'
      + '</div><div class="of-hint" data-hint="tel"></div></div>'
      + '<div class="of-field"><label for="of-signup-pwd">Crée ton mot de passe *</label>'
      + '<div class="of-pwd-wrap"><input id="of-signup-pwd" type="password" required autocomplete="new-password" minlength="8" placeholder="8 caractères minimum"><button type="button" class="of-pwd-toggle" data-toggle="of-signup-pwd">👁</button></div>'
      + '<div class="of-strength-bar"><span data-strength></span></div>'
      + '<div class="of-hint" data-hint="pwd"></div></div>'
      + '<div class="of-field"><label for="of-signup-confirm">Confirme ton mot de passe *</label>'
      + '<div class="of-pwd-wrap"><input id="of-signup-confirm" type="password" required autocomplete="new-password" minlength="8"><button type="button" class="of-pwd-toggle" data-toggle="of-signup-confirm">👁</button></div>'
      + '<div class="of-match" data-match></div></div>'
      + '<label class="of-cgu"><input type="checkbox" id="of-cgu"> J\'accepte les <a href="https://ocre.immo/mentions-legales/" target="_blank">conditions générales d\'utilisation</a> *</label>'
      + '<label class="of-cgu"><input type="checkbox" id="of-rgpd"> J\'accepte le traitement de mes données (<a href="https://ocre.immo/confidentialite/" target="_blank">RGPD</a>) *</label>'
      + '<button type="submit" class="of-btn-primary" data-submit="signup" disabled>Activer mon compte</button>'
      + '<div class="of-msg" data-msg="signup"></div>'
      + '</form>'
      + '</div>'

      // STEP signup-sent
      + '<div data-step="signup-sent" class="of-hidden" style="text-align:center">'
      + '<div class="of-icon-mail">✉</div>'
      + '<h1 class="of-h1">Vérifie ta boîte mail</h1>'
      + '<div class="of-sub">Un email avec un lien d\'activation a été envoyé à <b data-pill="sent"></b>. Lien valide 24 heures.</div>'
      + '<div class="of-link-row"><a data-go="email">Retour</a></div>'
      + '</div>'

      // STEP forgot
      + '<div data-step="forgot" class="of-hidden">'
      + '<button type="button" class="of-back-btn" data-go="login">← Retour</button>'
      + '<h1 class="of-h1">Mot de passe oublié</h1>'
      + '<div class="of-sub">Saisis ton email, nous t\'enverrons un lien.</div>'
      + '<form data-form="forgot" autocomplete="on" novalidate>'
      + '<div class="of-field"><label for="of-forgot-email">Email</label><input id="of-forgot-email" type="email" required autocomplete="username"></div>'
      + '<button type="submit" class="of-btn-primary" data-submit="forgot">Envoyer le lien</button>'
      + '<div class="of-msg" data-msg="forgot"></div>'
      + '</form>'
      + '</div>'

      // STEP forgot-sent
      + '<div data-step="forgot-sent" class="of-hidden" style="text-align:center">'
      + '<div class="of-icon-mail">✉</div>'
      + '<h1 class="of-h1">Vérifie ta boîte mail</h1>'
      + '<div class="of-sub">Un lien de réinitialisation a été envoyé à <b data-pill="forgot-sent"></b>. Valide 1 heure.</div>'
      + '<div class="of-link-row"><a data-go="email">Retour à la connexion</a></div>'
      + '</div>'

      // STEP reset (token dans URL)
      + '<div data-step="reset" class="of-hidden">'
      + '<h1 class="of-h1">Nouveau mot de passe</h1>'
      + '<div class="of-sub">Choisis ton nouveau mot de passe.</div>'
      + '<form data-form="reset" autocomplete="on" novalidate>'
      + '<input type="email" name="username" autocomplete="username" class="of-hidden">'
      + '<div class="of-field"><label for="of-reset-pwd">Nouveau mot de passe *</label>'
      + '<div class="of-pwd-wrap"><input id="of-reset-pwd" type="password" required autocomplete="new-password" minlength="8"><button type="button" class="of-pwd-toggle" data-toggle="of-reset-pwd">👁</button></div>'
      + '<div class="of-strength-bar"><span data-strength="reset"></span></div>'
      + '<div class="of-hint" data-hint="reset-pwd"></div></div>'
      + '<div class="of-field"><label for="of-reset-confirm">Confirme *</label>'
      + '<div class="of-pwd-wrap"><input id="of-reset-confirm" type="password" required autocomplete="new-password" minlength="8"><button type="button" class="of-pwd-toggle" data-toggle="of-reset-confirm">👁</button></div>'
      + '<div class="of-match" data-match="reset"></div></div>'
      + '<button type="submit" class="of-btn-primary" data-submit="reset">Enregistrer</button>'
      + '<div class="of-msg" data-msg="reset"></div>'
      + '</form>'
      + '</div>'

      // STEP reset-done
      + '<div data-step="reset-done" class="of-hidden" style="text-align:center">'
      + '<div class="of-icon-mail">✓</div>'
      + '<h1 class="of-h1">Mot de passe modifié</h1>'
      + '<div class="of-sub">Connexion en cours…</div>'
      + '</div>'

      + '</div>';
  }

  function api(opts, path, body) {
    // M/2026/05/14/78 — error verbeux Safari iOS (Load failed = TypeError generique).
    return fetch(opts.apiBase + path, {
      method: 'POST',
      credentials: 'include',
      mode: 'cors',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function(r) {
      if (!r.ok && r.status !== 400 && r.status !== 401 && r.status !== 409) {
        throw new Error('HTTP ' + r.status + ' ' + r.statusText);
      }
      return r.json().catch(function(e) { throw new Error('JSON parse fail (status ' + r.status + ')'); });
    }).catch(function(err) {
      var msg = err && err.message ? err.message : String(err);
      var name = err && err.name ? err.name : 'Error';
      throw new Error(name + ' : ' + msg + ' (' + path + ')');
    });
  }

  function pwdStrength(p) {
    var len = p.length;
    if (len < 6) return { label: 'Trop court', color: '#C62828', pct: 10, key: 'err' };
    if (len < 8) return { label: 'Faible', color: '#E07B00', pct: 30, key: 'weak' };
    if (len < 12) return { label: 'Correct', color: '#B58200', pct: 55, key: 'medium' };
    if (len < 16) return { label: 'Bon', color: '#5BB85B', pct: 80, key: 'ok' };
    return { label: 'Fort', color: '#2E7D32', pct: 100, key: 'ok' };
  }

  window.OcreAuth = {
    mount: function(selector, opts) {
      opts = opts || {};
      opts.apiBase = opts.apiBase || 'https://auth.ocre.immo';
      opts.onSuccess = opts.onSuccess || function(url) { window.location.href = url; };
      injectCss();

      var root = typeof selector === 'string' ? document.querySelector(selector) : selector;
      if (!root) return;
      root.innerHTML = htmlAll();

      var state = { email: opts.initialEmail || '', resetToken: opts.initialToken || '' };

      function $(sel, ctx) { return (ctx || root).querySelector(sel); }
      function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

      function show(step) {
        STEPS.forEach(function(s) {
          var el = root.querySelector('[data-step="' + s + '"]');
          if (el) el.classList.toggle('of-hidden', s !== step);
        });
        if (step === 'login') { var p = $('[data-pill="login"]'); if (p) p.textContent = state.email; var h = $('[data-hidden="login"]'); if (h) h.value = state.email; setTimeout(function() { var i = $('#of-login-pwd'); if (i) i.focus(); }, 100); }
        if (step === 'signup') { var p2 = $('[data-pill="signup"]'); if (p2) p2.textContent = state.email; var h2 = $('[data-hidden="signup"]'); if (h2) h2.value = state.email; setTimeout(function() { var i2 = $('#of-prenom'); if (i2) i2.focus(); }, 100); }
        if (step === 'signup-sent') { var p3 = $('[data-pill="sent"]'); if (p3) p3.textContent = state.email; }
        if (step === 'forgot') { var e = $('#of-forgot-email'); if (e) e.value = state.email; }
        if (step === 'forgot-sent') { var p4 = $('[data-pill="forgot-sent"]'); if (p4) p4.textContent = state.email; }
      }

      function setMsg(name, kind, txt) {
        var m = $('[data-msg="' + name + '"]');
        if (!m) return;
        m.textContent = txt;
        m.className = 'of-msg ' + (kind === 'err' ? 'show err' : (kind === 'ok' ? 'show ok' : ''));
      }

      $$('[data-go]').forEach(function(a) {
        a.addEventListener('click', function(e) { e.preventDefault(); show(a.dataset.go); });
      });

      // M/2026/05/14/81 — Eye toggle + tooltip overlay pour autofill iOS Keychain.
      // Safari/iOS bloque le toggle type password->text pour les champs autofilled. Solution :
      // tooltip DOM separe qui lit input.value via JS (toujours accessible) et l affiche au-dessus.
      // Triangle indicateur, textContent (anti-XSS), focus-out hide.
      $$('.of-pwd-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var input = root.querySelector('#' + btn.dataset.toggle);
          if (!input) return;
          var wrap = btn.parentNode; // .of-pwd-wrap
          var tooltip = wrap.querySelector('.of-eye-tooltip');
          if (tooltip) {
            // Tooltip ouvert -> fermer
            tooltip.remove();
            try { input.type = 'password'; } catch (_) {}
            btn.textContent = '👁';
          } else {
            // Cree tooltip overlay (au-dessus du champ)
            try { input.type = 'text'; } catch (_) {} // marche en saisie manuelle, no-op en autofill
            tooltip = document.createElement('div');
            tooltip.className = 'of-eye-tooltip';
            tooltip.textContent = input.value || '(vide)';
            wrap.appendChild(tooltip);
            btn.textContent = '🔒';
          }
        });
      });
      // Fermer tooltips au focusout (securite)
      root.addEventListener('focusout', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('of-pwd-wrap')) return;
        setTimeout(function() {
          $$('.of-eye-tooltip').forEach(function(tt) {
            // Si focus est encore dans la card, garder tooltip ouvert pour cette session.
            // Sinon (focus out card) fermer pour securite.
            if (!root.contains(document.activeElement)) {
              tt.remove();
              var w = tt.closest('.of-pwd-wrap');
              if (w) {
                var i = w.querySelector('input');
                var b = w.querySelector('.of-pwd-toggle');
                if (i) { try { i.type = 'password'; } catch (_) {} }
                if (b) b.textContent = '👁';
              }
            }
          });
        }, 100);
      });

      // Form email
      $('[data-form="email"]').addEventListener('submit', function(e) {
        e.preventDefault();
        var email = $('#of-email').value.trim().toLowerCase();
        if (!EMAIL_RE.test(email)) { setMsg('email', 'err', 'Email invalide'); return; }
        state.email = email;
        var btn = $('[data-submit="email"]');
        btn.disabled = true; btn.textContent = 'Vérification…';
        api(opts, '/api/auth/email-check.php', { email: email }).then(function(d) {
          if (!d.ok) throw new Error(d.error || 'Erreur');
          show(d.exists && d.has_password ? 'login' : 'signup');
        }).catch(function(ex) {
          setMsg('email', 'err', ex.message || 'Erreur réseau');
        }).then(function() {
          btn.disabled = false; btn.textContent = 'Continuer';
        });
      });

      // Form login
      $('[data-form="login"]').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = $('[data-submit="login"]');
        btn.disabled = true; btn.textContent = 'Connexion…';
        api(opts, '/api/auth/login.php', { email: state.email, password: $('#of-login-pwd').value }).then(function(d) {
          if (!d.ok) throw new Error(d.error || 'Erreur');
          try { if (d.session_token) localStorage.setItem('ocre_token', d.session_token); } catch (_) {}
          var qs = new URLSearchParams(location.search);
          var next = qs.get('next');
          opts.onSuccess(next && /^https?:\/\/[a-z0-9.-]+\.ocre\.immo\//.test(next) ? next : (d.redirect || '/'));
        }).catch(function(ex) {
          setMsg('login', 'err', ex.message || 'Erreur réseau');
          btn.disabled = false; btn.textContent = 'Se connecter';
        });
      });

      // Signup live validations
      var telCountry = $('#of-tel-country');
      var phoneRules = {};
      Array.prototype.forEach.call(telCountry.options, function(o) {
        phoneRules[o.value] = { min: +o.dataset.min, max: +o.dataset.max, cc: o.dataset.cc, ph: o.dataset.ph };
      });
      function updateTelPlaceholder() { $('#of-tel').placeholder = phoneRules[telCountry.value].ph; validateSignup(); }
      telCountry.addEventListener('change', updateTelPlaceholder);
      updateTelPlaceholder();

      function validateTel() {
        var rule = phoneRules[telCountry.value];
        var digits = $('#of-tel').value.replace(/\D/g, '').replace(/^0+/, '');
        var hint = $('[data-hint="tel"]');
        if (digits.length === 0) { hint.textContent = ''; hint.className = 'of-hint'; return false; }
        if (digits.length < rule.min || digits.length > rule.max) {
          hint.textContent = 'Trop court pour ' + rule.cc + ' (' + rule.min + '-' + rule.max + ' chiffres)';
          hint.className = 'of-hint err'; return false;
        }
        hint.textContent = '✓ Format valide pour ' + rule.cc;
        hint.className = 'of-hint ok'; return true;
      }
      function validatePwd() {
        var p = $('#of-signup-pwd').value, c = $('#of-signup-confirm').value;
        var s = pwdStrength(p);
        $('[data-strength]').style.width = s.pct + '%';
        $('[data-strength]').style.background = s.color;
        var ph = $('[data-hint="pwd"]');
        ph.textContent = p ? s.label : '';
        ph.className = 'of-hint ' + s.key;
        var m = $('[data-match]');
        if (c) {
          if (p === c) { m.textContent = '✓ Les mots de passe correspondent'; m.className = 'of-match ok'; }
          else { m.textContent = '✗ Les mots de passe ne correspondent pas'; m.className = 'of-match err'; }
        } else m.textContent = '';
      }
      function validateSignup() {
        var prenom = $('#of-prenom').value.trim();
        var nom = $('#of-nom').value.trim();
        var tel = validateTel();
        var p = $('#of-signup-pwd').value;
        var c = $('#of-signup-confirm').value;
        var cgu = $('#of-cgu').checked;
        var rgpd = $('#of-rgpd').checked;
        var ok = prenom.length >= 2 && nom.length >= 2 && tel && p.length >= 8 && p === c && cgu && rgpd;
        $('[data-submit="signup"]').disabled = !ok;
      }
      ['of-prenom','of-nom','of-tel','of-signup-pwd','of-signup-confirm','of-cgu','of-rgpd'].forEach(function(id) {
        var el = $('#' + id);
        if (el) {
          el.addEventListener('input', function() { validatePwd(); validateSignup(); });
          el.addEventListener('change', function() { validatePwd(); validateSignup(); });
        }
      });

      $('[data-form="signup"]').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = $('[data-submit="signup"]');
        btn.disabled = true; btn.textContent = 'Création…';
        var digits = $('#of-tel').value.replace(/\D/g, '').replace(/^0+/, '');
        api(opts, '/api/auth/signup.php', {
          email: state.email,
          prenom: $('#of-prenom').value.trim(),
          nom: $('#of-nom').value.trim(),
          societe: $('#of-societe').value.trim(),
          telephone: telCountry.value + digits,
          password: $('#of-signup-pwd').value,
          cgu: $('#of-cgu').checked,
          rgpd: $('#of-rgpd').checked
        }).then(function(d) {
          if (!d.ok) throw new Error(d.error || 'Erreur');
          show('signup-sent');
        }).catch(function(ex) {
          setMsg('signup', 'err', ex.message || 'Erreur réseau');
          btn.disabled = false; btn.textContent = 'Activer mon compte';
        });
      });

      // Form forgot
      $('[data-form="forgot"]').addEventListener('submit', function(e) {
        e.preventDefault();
        var email = $('#of-forgot-email').value.trim().toLowerCase();
        state.email = email;
        var btn = $('[data-submit="forgot"]');
        btn.disabled = true; btn.textContent = 'Envoi…';
        api(opts, '/api/auth/reset_request.php', { email: email }).then(function() {
          show('forgot-sent');
        }).catch(function() {
          setMsg('forgot', 'err', 'Erreur réseau');
        }).then(function() {
          btn.disabled = false; btn.textContent = 'Envoyer le lien';
        });
      });

      // Form reset (avec token)
      function validateResetPwd() {
        var p = $('#of-reset-pwd').value, c = $('#of-reset-confirm').value;
        var s = pwdStrength(p);
        var span = $('[data-strength="reset"]');
        if (span) { span.style.width = s.pct + '%'; span.style.background = s.color; }
        var h = $('[data-hint="reset-pwd"]');
        if (h) { h.textContent = p ? s.label : ''; h.className = 'of-hint ' + s.key; }
        var m = $('[data-match="reset"]');
        if (m) {
          if (c) {
            if (p === c) { m.textContent = '✓ Les mots de passe correspondent'; m.className = 'of-match ok'; }
            else { m.textContent = '✗ Les mots de passe ne correspondent pas'; m.className = 'of-match err'; }
          } else m.textContent = '';
        }
      }
      ['of-reset-pwd','of-reset-confirm'].forEach(function(id) {
        var el = $('#' + id);
        if (el) el.addEventListener('input', validateResetPwd);
      });
      $('[data-form="reset"]').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = $('[data-submit="reset"]');
        btn.disabled = true; btn.textContent = 'Enregistrement…';
        api(opts, '/api/auth/reset_complete.php', {
          token: state.resetToken,
          password: $('#of-reset-pwd').value,
          confirmation: $('#of-reset-confirm').value
        }).then(function(d) {
          if (!d.ok) throw new Error(d.error || 'Erreur');
          try { if (d.session_token) localStorage.setItem('ocre_token', d.session_token); } catch (_) {}
          show('reset-done');
          setTimeout(function() { opts.onSuccess(d.redirect || '/'); }, 1000);
        }).catch(function(ex) {
          setMsg('reset', 'err', ex.message || 'Erreur réseau');
          btn.disabled = false; btn.textContent = 'Enregistrer';
        });
      });

      // Pre-fill email + initial step
      if (state.email && EMAIL_RE.test(state.email)) {
        $('#of-email').value = state.email;
      }
      var initStep = opts.initialStep && STEPS.indexOf(opts.initialStep) >= 0 ? opts.initialStep : 'email';
      if (state.resetToken) initStep = 'reset';
      show(initStep);
    }
  };
})();
