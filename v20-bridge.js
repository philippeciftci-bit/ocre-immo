// V20 bridge — branchement UI mobile sur endpoints multi-tenant.
// Charge depuis index.html : <script src="/v20-bridge.js" defer></script>
// Wordings issus de la mission 20260426_141708_096b.md, IMPÉRATIVEMENT verbatim.

(function () {
  'use strict';
  if (window.__OCRE_V20__) return;
  window.__OCRE_V20__ = true;

  // === Helpers réseau ===
  const tenantSlug = (location.hostname.match(/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/) || [])[1] || '';
  const SESSION_KEY = 'ocre_token';

  // M/2026/05/07/90 — extraction session token depuis URL ?_s=<token> (post-activation
  // M89 redirige vers https://<slug>.ocre.immo/?_s=<token>). Sans cette extraction,
  // le frontend ne récupère pas la session, l'auth échoue, et tout INSERT fiche client
  // remonte "Échec sauvegarde". Stocke + nettoie l'URL (history.replaceState).
  try {
    const urlParams = new URLSearchParams(location.search);
    const sessFromUrl = urlParams.get('_s') || '';
    if (sessFromUrl && /^[a-f0-9]{64}$/i.test(sessFromUrl)) {
      localStorage.setItem(SESSION_KEY, sessFromUrl);
      urlParams.delete('_s');
      const cleanQs = urlParams.toString();
      const cleanUrl = location.pathname + (cleanQs ? '?' + cleanQs : '') + location.hash;
      history.replaceState(null, '', cleanUrl);
    }
  } catch (_) { /* silencieux : si fallback échoue, l'user re-login via le form */ }

  const sess = () => localStorage.getItem(SESSION_KEY) || '';
  const apiFetch = async (url, opts = {}) => {
    opts.headers = Object.assign({}, opts.headers || {}, {
      'X-Session-Token': sess(),
      'X-Tenant-Slug': tenantSlug,
    });
    if (opts.body && typeof opts.body !== 'string') {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    const r = await fetch(url, opts);
    return r.json().catch(() => ({ ok: false, error: 'Réponse non-JSON' }));
  };

  // === Styles V20 (injection unique) ===
  const css = `
    .v20-banner{position:sticky;top:0;z-index:1000;padding:8px 14px;text-align:center;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif}
    .v20-banner-test{background:#FBE9C7;color:#8B5E3C}
    .v20-banner-superadmin{background:#FCD5D0;color:#8B0000}
    .v20-banner-rupture{background:#FCD5D0;color:#8B0000}
    .v20-banner-coedit{background:#FBE9C7;color:#8B5E3C}
    .v20-banner-pending{background:#F5EAD3;color:#6B4429}
    .v20-banner-archived{background:#E5DDC8;color:#6B5E4A}
    .v20-overlay{position:fixed;inset:0;background:rgba(20,12,4,.55);z-index:2000;display:flex;align-items:flex-start;justify-content:center;padding:60px 16px;animation:v20fade .15s}
    .v20-overlay-content{background:#FAF8F2;border-radius:14px;max-width:420px;width:100%;padding:22px 20px;box-shadow:0 8px 32px rgba(0,0,0,.18);font-family:'DM Sans',sans-serif}
    .v20-overlay h2{font-family:'Cormorant Garamond',serif;color:#8B5E3C;font-size:22px;margin:0 0 4px}
    .v20-overlay h3{font-size:11px;font-weight:700;color:#8B7F6E;text-transform:uppercase;letter-spacing:.6px;margin:18px 0 8px}
    .v20-overlay p.v20-sub{color:#6B5E4A;font-size:13px;margin:0 0 14px}
    .v20-row{display:flex;align-items:center;gap:10px;padding:14px 12px;border-radius:8px;cursor:pointer;min-height:48px}
    .v20-row:hover{background:#F0E8D8}
    /* M/2026/05/01/3 — typographie switcher : DM Sans 17px font-weight 700 (lisibilite). */
    .v20-ws-row strong{color:#2A2018;font-size:17px;font-weight:700;font-family:'DM Sans',system-ui,sans-serif;letter-spacing:0;line-height:1.4}
    .v20-row strong{color:#2A2018;font-size:14px}
    .v20-row small{color:#8B7F6E;font-size:12px;display:block}
    .v20-row.active{background:#F0E8D8}
    .v20-ws-test strong{color:#DC2626 !important}
    .v20-input{width:100%;padding:10px 12px;border:1px solid #B89968;border-radius:6px;font-family:inherit;font-size:14px;margin-bottom:6px;background:#fff;color:#2A2018}
    .v20-helper{font-size:12px;color:#8B7F6E;margin:0 0 12px}
    .v20-cta{padding:12px 20px;background:#8B5E3C;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600}
    .v20-cta:hover{background:#6B4429}
    .v20-cta:disabled{background:#C4B8A4;cursor:not-allowed}
    .v20-cta-secondary{padding:12px 20px;background:transparent;color:#8B5E3C;border:1px solid #B89968;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;margin-right:8px}
    .v20-cta-destructive{padding:12px 20px;background:#8B0000;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600}
    .v20-actions{display:flex;justify-content:flex-end;margin-top:16px;gap:8px}
    .v20-pact-frame{max-height:62vh;overflow-y:auto;border:1px solid #E5DDC8;border-radius:6px;padding:14px;background:#fff;font-size:13px;line-height:1.6}
    .v20-share-btn{display:inline-flex;align-items:center;gap:4px;padding:6px 10px;background:#F0E8D8;color:#8B5E3C;border:1px solid #B89968;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
    .v20-share-btn:hover{background:#E5DDC8}
    @keyframes v20fade{from{opacity:0}to{opacity:1}}
  `;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  // === État global ===
  const state = {
    ctx: null,
    workspaces: [],
  };

  // === Utils DOM ===
  const el = (tag, attrs = {}, ...children) => {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') e.className = v;
      else if (k === 'onclick') e.addEventListener('click', v);
      else if (k === 'oninput') e.addEventListener('input', v);
      else if (k === 'onchange') e.addEventListener('change', v);
      else if (k === 'html') e.innerHTML = v;
      else e.setAttribute(k, v);
    }
    for (const c of children) {
      if (c == null) continue;
      e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return e;
  };
  const closeOverlay = () => {
    const ov = document.querySelector('.v20-overlay');
    if (ov) ov.remove();
  };
  const overlay = (title, subtitle, body, actions) => {
    closeOverlay();
    const ov = el('div', { class: 'v20-overlay', onclick: (e) => { if (e.target === ov) closeOverlay(); } },
      el('div', { class: 'v20-overlay-content' },
        el('h2', {}, title),
        subtitle ? el('p', { class: 'v20-sub' }, subtitle) : null,
        body,
        actions || null
      )
    );
    document.body.appendChild(ov);
    return ov;
  };

  // === Bandeaux sticky ===
  function renderBanners() {
    document.querySelectorAll('.v20-banner').forEach(b => b.remove());
    const c = state.ctx;
    if (!c) return;
    const banners = [];
    if (c.is_super_admin && c.is_readonly) {
      banners.push({ cls: 'v20-banner-superadmin', txt: 'Vue super-administrateur · Lecture seule' });
    }
    // M84 — mode test/agent supprime, plus de window.__ocreMode.
    if (c.rupture_pending) {
      const d = new Date(c.rupture_pending.scheduled_for);
      const date = d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long' });
      const heure = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
      banners.push({ cls: 'v20-banner-rupture', txt: `Période de transition · Rupture effective le ${date} à ${heure}` });
    }
    if (c.workspace.type === 'wsc' && !c.pact_active) {
      const noms = (c.unsigned_members || []).join(', ');
      banners.push({ cls: 'v20-banner-pending', txt: `En attente de signature de ${noms}` });
    }
    if (c.workspace.archived_at) {
      const d = new Date(c.workspace.archived_at).toLocaleDateString('fr-FR');
      banners.push({ cls: 'v20-banner-archived', txt: `Workspace archivé · Lecture seule depuis le ${d}` });
    }
    banners.reverse().forEach(b => {
      document.body.insertBefore(el('div', { class: 'v20-banner ' + b.cls }, b.txt), document.body.firstChild);
    });
  }

  // M/2026/05/13/38 — Switcher overlay supprime (popup "Espaces de travail / Mon
  // espace / Se deconnecter"). Tap Oi gere par React onClick dans OcreLogo
  // (window.location.href='/'). Deconnexion deplacee dans le menu hamburger header.

  function logout() {
    apiFetch('/api/auth_v20.php?action=logout', { method: 'POST' }).then(() => {
      localStorage.removeItem(SESSION_KEY);
      location.href = '/';
    });
  }

  // === Modale créer partenariat ===
  function openCreatePartnership() {
    let nom = '', pays = '', emails = '';
    const update = () => { btn.disabled = !(nom && pays && emails); };
    const inp1 = el('input', { class: 'v20-input', placeholder: 'Ex : Cabinet Marrakech', oninput: e => { nom = e.target.value; update(); } });
    const inp2 = el('input', { class: 'v20-input', placeholder: 'France · Maroc', oninput: e => { pays = e.target.value; update(); } });
    const inp3 = el('input', { class: 'v20-input', placeholder: "Email d'un agent Ocre Immo", oninput: e => { emails = e.target.value; update(); } });
    const btn = el('button', { class: 'v20-cta', onclick: submit }, 'Créer le partenariat');
    btn.disabled = true;

    const body = el('div', {},
      el('label', { style: 'font-size:12px;color:#6B5E4A' }, 'Nom du workspace'), inp1,
      el('label', { style: 'font-size:12px;color:#6B5E4A;margin-top:8px;display:block' }, 'Pays de référence'), inp2,
      el('p', { class: 'v20-helper' }, 'Détermine le droit applicable du pacte'),
      el('label', { style: 'font-size:12px;color:#6B5E4A' }, 'Inviter des partenaires'), inp3,
      el('p', { class: 'v20-helper' }, 'Chaque agent invité devra signer le pacte de partenariat')
    );
    const actions = el('div', { class: 'v20-actions' },
      el('button', { class: 'v20-cta-secondary', onclick: closeOverlay }, 'Annuler'), btn);

    async function submit() {
      btn.disabled = true; btn.textContent = '…';
      const r = await apiFetch('/api/wsc_v20.php?action=create', { method: 'POST',
        body: { display_name: nom, country_code: pays.slice(0, 2).toUpperCase(), invite_emails: emails.split(/[,;\s]+/).filter(Boolean) } });
      if (r.ok) { closeOverlay(); refresh(); } else { alert(r.error || 'Erreur'); btn.disabled = false; btn.textContent = 'Créer le partenariat'; }
    }
    overlay('Nouveau partenariat', "Crée un workspace commun pour collaborer sur des dossiers partagés", body, actions);
  }

  // === Modale partager un dossier ===
  function openShareDossier(dossier) {
    const wscs = state.workspaces.filter(w => w.type === 'wsc' && w.pact_active);
    let target = '';
    const sel = el('select', { class: 'v20-input', onchange: e => { target = e.target.value; btn.disabled = !target; } },
      el('option', { value: '' }, '— Choisir —'),
      ...wscs.map(w => el('option', { value: w.slug }, w.display_name)));
    const btn = el('button', { class: 'v20-cta', onclick: submit }, 'Partager');
    btn.disabled = true;

    const recap = el('div', { style: 'background:#F0E8D8;padding:10px 12px;border-radius:6px;margin-bottom:12px;font-size:13px' },
      el('strong', {}, dossier.client_nom || ''),
      el('div', { style: 'color:#6B5E4A;font-size:12px;margin-top:3px' }, [(dossier.type_bien || ''), (dossier.prix ? Number(dossier.prix).toLocaleString('fr-FR') + ' EUR' : '')].filter(Boolean).join(' · ')));

    async function submit() {
      btn.disabled = true; btn.textContent = '…';
      const r = await apiFetch('/api/share_v20.php', { method: 'POST', body: { dossier_id: dossier.id, wsc_slug: target } });
      if (r.ok) { closeOverlay(); alert('Dossier partagé.'); } else { alert(r.error); btn.disabled = false; btn.textContent = 'Partager'; }
    }
    overlay('Partager ce dossier',
      "Le dossier sera transféré vers le workspace commun choisi. Tu en restes l'apporteur.",
      el('div', {}, recap, el('label', { style: 'font-size:12px;color:#6B5E4A' }, 'Workspace commun cible'), sel),
      el('div', { class: 'v20-actions' }, el('button', { class: 'v20-cta-secondary', onclick: closeOverlay }, 'Annuler'), btn));
  }

  // === Modale demander rupture ===
  function openRequestRupture(wscSlug) {
    let motif = '';
    const inp = el('textarea', { class: 'v20-input', placeholder: 'Optionnel', rows: '3', oninput: e => motif = e.target.value });
    const corps = el('div', { style: 'font-size:13px;color:#2A2018;margin-bottom:12px' },
      el('p', {}, 'La rupture sera effective dans 48h. Pendant cette période :'),
      el('ul', { style: 'padding-left:20px;margin-top:6px' },
        el('li', {}, 'Tes dossiers passent en lecture seule pour les autres membres'),
        el('li', {}, 'Tu vois les dossiers des autres en lecture seule également'),
        el('li', {}, "Toute tentative de modification est tracée dans l'audit log"),
        el('li', {}, "Tu peux annuler ta demande à tout moment avant l'échéance")));
    async function submit() {
      const r = await apiFetch('/api/wsc_v20.php?action=request_rupture', { method: 'POST', body: { wsc_slug: wscSlug, motif } });
      if (r.ok) { closeOverlay(); alert('Demande enregistrée.'); refresh(); } else { alert(r.error); }
    }
    overlay('Quitter ce partenariat', null,
      el('div', {}, corps,
        el('label', { style: 'font-size:12px;color:#6B5E4A' }, 'Motif (visible par les autres membres)'), inp),
      el('div', { class: 'v20-actions' },
        el('button', { class: 'v20-cta-secondary', onclick: closeOverlay }, 'Garder le partenariat'),
        el('button', { class: 'v20-cta-destructive', onclick: submit }, 'Confirmer la demande de rupture')));
  }

  // === Modale annuler rupture ===
  function openCancelRupture(wscSlug) {
    async function submit() {
      const r = await apiFetch('/api/wsc_v20.php?action=cancel_rupture', { method: 'POST', body: { wsc_slug: wscSlug } });
      if (r.ok) { closeOverlay(); refresh(); } else { alert(r.error); }
    }
    overlay('Annuler ta demande de rupture', null,
      el('p', {}, 'Tu vas annuler ta demande. Le partenariat reprendra normalement, et les dossiers redeviennent éditables pour tous.'),
      el('div', { class: 'v20-actions' },
        el('button', { class: 'v20-cta-secondary', onclick: closeOverlay }, 'Fermer'),
        el('button', { class: 'v20-cta', onclick: submit }, 'Annuler la rupture')));
  }

  // === Page signature pacte ===
  function openPactSign(wscSlug) {
    let scrolled = false, accepted = false;
    const update = () => { btn.disabled = !(scrolled && accepted); };
    const frame = el('div', { class: 'v20-pact-frame' }, el('p', {}, 'Chargement…'));
    frame.addEventListener('scroll', () => {
      if (frame.scrollTop + frame.clientHeight >= frame.scrollHeight - 10) { scrolled = true; update(); }
    });
    apiFetch(`/api/wsc_v20.php?action=get_pact&wsc_slug=${encodeURIComponent(wscSlug)}`).then(r => {
      if (r.ok && r.html) { frame.innerHTML = r.html; }
      else { frame.innerHTML = '<p>' + (r.error || 'Erreur chargement pacte') + '</p>'; }
    });
    const cb = el('input', { type: 'checkbox', onchange: e => { accepted = e.target.checked; update(); } });
    const btn = el('button', { class: 'v20-cta', onclick: submit }, 'Je signe');
    btn.disabled = true;

    async function submit() {
      btn.disabled = true; btn.textContent = '…';
      const r = await apiFetch('/api/wsc_v20.php?action=sign_pact', { method: 'POST', body: { wsc_slug: wscSlug } });
      if (r.ok) {
        const remaining = (r.unsigned || []).join(', ');
        const msg = remaining
          ? `Pacte signé. PDF envoyé sur ton email. En attente de signature de : ${remaining}.`
          : 'Pacte signé. PDF envoyé sur ton email. Le partenariat est actif.';
        closeOverlay(); alert(msg); refresh();
      } else { alert(r.error); btn.disabled = false; btn.textContent = 'Je signe'; }
    }
    overlay(`Pacte de partenariat — ${wscSlug}`,
      "Lis l'intégralité du pacte avant de signer. Chaque partenaire doit signer pour activer le workspace.",
      el('div', {}, frame,
        el('label', { style: 'display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px;color:#2A2018' },
          cb, el('span', {}, "J'ai lu et j'accepte le pacte de partenariat dans son intégralité"))),
      el('div', { class: 'v20-actions' },
        el('button', { class: 'v20-cta-secondary', onclick: closeOverlay }, 'Plus tard'), btn));
  }

  // === Page première connexion ===
  function showFirstLogin() {
    let cur = '', nv = '', conf = '';
    const update = () => { btn.disabled = !(cur && nv.length >= 12 && nv === conf); };
    const i1 = el('input', { type: 'password', class: 'v20-input', placeholder: 'Mot de passe actuel', oninput: e => { cur = e.target.value; update(); } });
    const i2 = el('input', { type: 'password', class: 'v20-input', placeholder: 'Nouveau mot de passe', oninput: e => { nv = e.target.value; update(); } });
    const i3 = el('input', { type: 'password', class: 'v20-input', placeholder: 'Confirme le nouveau mot de passe', oninput: e => { conf = e.target.value; update(); } });
    const btn = el('button', { class: 'v20-cta', onclick: submit }, 'Activer mon compte');
    btn.disabled = true;
    const u = state.ctx && state.ctx.user ? state.ctx.user : {};
    const prenom = (u.display_name || u.email || 'à toi').split(' ')[0];

    async function submit() {
      btn.disabled = true;
      const r = await apiFetch('/api/auth_v20.php?action=change_password', { method: 'POST',
        body: { current: cur, new: nv } });
      if (r.ok) { alert('Compte activé.'); closeOverlay(); refresh(); }
      else { alert(r.error); btn.disabled = false; }
    }
    overlay(`Bienvenue ${prenom}`,
      "Pour ta sécurité, choisis un mot de passe personnel. Le mot de passe initial transmis par Ocre Immo ne fonctionnera plus après cette étape.",
      el('div', {},
        el('label', { style: 'font-size:12px;color:#6B5E4A' }, 'Mot de passe actuel'), i1,
        el('label', { style: 'font-size:12px;color:#6B5E4A;margin-top:8px;display:block' }, 'Nouveau mot de passe'), i2,
        el('p', { class: 'v20-helper' }, '12 caractères minimum, mélange lettres et chiffres'),
        el('label', { style: 'font-size:12px;color:#6B5E4A' }, 'Confirme le nouveau mot de passe'), i3),
      el('div', { class: 'v20-actions' }, btn));
  }

  // === Page custom-fields settings (route /settings/custom-fields) ===
  async function maybeRenderCustomFieldsPage() {
    if (!location.pathname.startsWith('/settings/custom-fields')) return;
    const r = await apiFetch('/api/custom_fields_v20.php?action=list');
    if (!r.ok) return;
    document.body.innerHTML = '';
    const wrap = el('div', { style: 'max-width:680px;margin:30px auto;padding:0 20px;font-family:DM Sans,sans-serif' },
      el('h1', { style: 'font-family:Cormorant Garamond,serif;color:#8B5E3C' }, 'Champs personnalisés'),
      el('p', { style: 'color:#6B5E4A' }, 'Active les champs supplémentaires que tu veux afficher dans tes dossiers.'),
      ...r.fields.map(f => el('div', { style: 'display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid #E5DDC8' },
        el('div', {},
          el('strong', { style: 'color:#2A2018' }, f.label_override || f.label),
          el('div', { style: 'font-size:11px;color:#8B7F6E;margin-top:2px' }, `${f.type}${f.options ? ' · ' + f.options.length + ' options' : ''}`)),
        el('div', { class: 'switch' + (f.enabled ? ' on' : ''), onclick: async (ev) => {
          const newState = !f.enabled;
          const r2 = await apiFetch('/api/custom_fields_v20.php?action=toggle', { method: 'POST',
            body: { field_key: f.key, enabled: newState } });
          if (r2.ok) { f.enabled = newState; ev.target.classList.toggle('on', newState); }
        } })))
    );
    document.body.appendChild(wrap);
  }

  // === ListView — boutons Aperçu + Partager (M/2026/04/27/2) ===
  function injectShareButtons() {
    if (!state.ctx || state.ctx.is_readonly) return;
    document.querySelectorAll('[data-dossier-id]:not([data-v20-share])').forEach(card => {
      card.setAttribute('data-v20-share', '1');
      const id = card.getAttribute('data-dossier-id');
      const wrap = el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-top:6px' },
        el('button', { class: 'v20-share-btn', style: 'background:#8B5E3C;color:#fff;border-color:#8B5E3C', onclick: e => {
          e.stopPropagation();
          openDossierPdfView(parseInt(id, 10));
        } }, '👁 Aperçu'),
        el('button', { class: 'v20-share-btn', onclick: e => {
          e.stopPropagation();
          openSharePartnersSheet(parseInt(id, 10));
        } }, '🤝 Partager')
      );
      card.appendChild(wrap);
    });
  }

  // === DossierPdfView — overlay plein écran avec rendu A4 du dossier ===
  async function openDossierPdfView(dossierId) {
    closeOverlay();
    const ov = el('div', { class: 'v20-overlay', style: 'background:#E8E5E0;align-items:flex-start;padding:0' });
    const close = el('button', { style: 'position:fixed;top:14px;left:14px;background:#fff;border:1px solid #E5DDC8;border-radius:20px;padding:8px 14px;font-family:inherit;font-size:13px;font-weight:600;color:#8B5E3C;cursor:pointer;z-index:10;box-shadow:0 2px 6px rgba(0,0,0,.12)', onclick: () => ov.remove() }, '← Retour');
    const shareBtn = el('button', { class: 'v20-cta', style: 'position:fixed;bottom:20px;right:20px;z-index:10;box-shadow:0 4px 12px rgba(139,94,60,.3)', onclick: () => openSharePartnersSheet(dossierId) }, '🤝 Partager');
    const a4 = el('div', { style: 'max-width:720px;margin:60px auto 80px;background:#fff;padding:36px 32px;box-shadow:0 4px 20px rgba(0,0,0,.08);border-radius:4px;font-family:DM Sans,sans-serif' });
    a4.innerHTML = '<p style="text-align:center;color:#7A7167">Chargement…</p>';
    ov.appendChild(close);
    ov.appendChild(a4);
    ov.appendChild(shareBtn);
    document.body.appendChild(ov);
    // Crée un lien temporaire pour bénéficier du rendu serveur identique au partage public.
    const r = await apiFetch('/api/share_links_v20.php?action=create_link', { method: 'POST', body: { dossier_id: dossierId } });
    if (!r.ok) { a4.innerHTML = '<p style="color:#C04B20">Erreur : ' + (r.error || 'inconnue') + '</p>'; return; }
    try {
      const html = await fetch('/share/' + r.token).then(x => x.text());
      const m = html.match(/<body[^>]*>([\s\S]*)<\/body>/i);
      const inner = m ? m[1] : html;
      a4.innerHTML = inner;
    } catch (e) { a4.innerHTML = '<p style="color:#C04B20">Erreur réseau</p>'; }
  }

  // === Bottom sheet Partager : 2 options client / agent ===
  async function openSharePartnersSheet(dossierId) {
    const partnersResp = await apiFetch('/api/share_links_v20.php?action=list_partners');
    const partners = (partnersResp.ok && partnersResp.partners) || [];
    const sheet = el('div', { class: 'v20-overlay', style: 'align-items:flex-end;padding:0' });
    const card = el('div', { style: 'background:#fff;width:100%;max-width:520px;border-radius:16px 16px 0 0;padding:20px 18px 28px;font-family:DM Sans,sans-serif', onclick: e => e.stopPropagation() },
      el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px' },
        el('h2', { style: 'font-family:Cormorant Garamond,serif;font-size:18px;color:#8B5E3C;margin:0' }, 'Partager le dossier'),
        el('button', { style: 'background:none;border:none;font-size:22px;cursor:pointer;color:#8B7F6E', onclick: () => sheet.remove() }, '×')
      ),
      el('div', { class: 'v20-row', style: 'border:1px solid #E5DDC8;margin-bottom:10px;align-items:flex-start;padding:14px', onclick: async () => {
        sheet.remove();
        const r = await apiFetch('/api/share_links_v20.php?action=create_link', { method: 'POST', body: { dossier_id: dossierId } });
        if (!r.ok) { alert(r.error); return; }
        if (navigator.share) { try { await navigator.share({ url: r.url, title: 'Dossier Ocre Immo' }); return; } catch (e) {} }
        try { await navigator.clipboard.writeText(r.url); alert('Lien copié — expire dans 7 jours'); } catch (e) { prompt('Copie le lien :', r.url); }
      } },
        el('div', { style: 'flex:1' },
          el('strong', { style: 'display:block;font-size:14px;color:#2A2018' }, '👤 Partager avec un client'),
          el('small', { style: 'color:#8B7F6E;font-size:12px' }, 'Lien public lecture seule, expire dans 7 jours'))
      ),
      partners.length === 0
        ? el('div', { class: 'v20-row', style: 'border:1px solid #E5DDC8;opacity:.5;cursor:not-allowed;padding:14px;align-items:flex-start' },
            el('div', { style: 'flex:1' },
              el('strong', { style: 'display:block;font-size:14px;color:#2A2018' }, '👥 Partager avec un agent Ocre'),
              el('small', { style: 'color:#8B7F6E;font-size:12px' }, 'Crée d\'abord un WSc avec un autre agent')))
        : el('div', { style: 'border:1px solid #E5DDC8;border-radius:8px;padding:8px' },
            el('div', { style: 'font-size:13px;color:#6B5E4A;margin-bottom:6px;padding:0 4px' }, '👥 Partager avec un agent'),
            ...partners.map(p => el('div', { class: 'v20-row', style: 'padding:10px 12px', onclick: async () => {
              sheet.remove();
              const r = await apiFetch('/api/share_links_v20.php?action=create_internal', { method: 'POST', body: { dossier_id: dossierId, target_user_id: parseInt(p.id, 10) } });
              alert(r.ok ? 'Partagé avec ' + (p.display_name || p.email).split(' ')[0] : (r.error || 'Erreur'));
            } },
              el('strong', {}, p.display_name || p.email),
              el('small', { style: 'color:#8B7F6E' }, 'WSc ' + (p.wsc_name || p.wsc_slug))
            )))
    );
    sheet.appendChild(card);
    sheet.addEventListener('click', () => sheet.remove());
    document.body.appendChild(sheet);
  }

  // === Phase 2 — CGU gate plein écran ===
  async function maybeShowCguGate(user) {
    if (!user || user.cgu_accepted_at) return false;
    const cgu = await apiFetch('/api/cgu_v20.php?action=current');
    if (!cgu.ok) return false;
    return new Promise((resolve) => {
      const overlay = el('div', { class: 'v20-overlay', style: 'align-items:stretch;padding:0' },
        el('div', { class: 'v20-overlay-content', style: 'max-width:680px;width:100%;height:100%;max-height:none;border-radius:0;display:flex;flex-direction:column;padding:0' },
          el('div', { style: 'padding:18px 22px;border-bottom:1px solid #E5DDC8;background:#FAF8F2' },
            el('h2', { style: 'margin:0' }, 'Conditions générales d\'utilisation'),
            el('div', { style: 'font-size:12px;color:#8B7F6E;margin-top:2px' }, 'Version ' + cgu.version)),
          (function () {
            const frame = el('iframe', { src: cgu.url, style: 'flex:1;border:none;width:100%;background:#fff', id: 'v20-cgu-frame' });
            return frame;
          })(),
          el('div', { style: 'padding:14px 22px;border-top:1px solid #E5DDC8;background:#fff' },
            (function () {
              const cb = el('input', { type: 'checkbox', id: 'v20-cgu-cb', onchange: (e) => { btn.disabled = !e.target.checked; } });
              const lbl = el('label', { style: 'display:flex;gap:8px;align-items:center;font-size:14px;cursor:pointer;margin-bottom:10px', for: 'v20-cgu-cb' },
                cb, el('span', {}, 'J\'ai lu et j\'accepte les CGU v' + cgu.version));
              const btn = el('button', { class: 'v20-cta', style: 'width:100%', onclick: async () => {
                btn.disabled = true; btn.textContent = '…';
                const r = await apiFetch('/api/cgu_v20.php?action=accept', { method: 'POST', body: { version: cgu.version } });
                if (r.ok) { overlay.remove(); resolve(true); } else { alert(r.error || 'Erreur'); btn.disabled = false; btn.textContent = 'Continuer'; }
              } }, 'Continuer');
              btn.disabled = true;
              return el('div', {}, lbl, btn);
            })()
          )
        )
      );
      document.body.appendChild(overlay);
    });
  }

  // === Phase 5 — Tour produit ===
  // M/2026/05/07/91.2 — supprime sur decision Philippe : les modales onboarding
  // gênaient le decouverte autonome. La fonction reste exportee comme no-op pour
  // ne pas casser les call-sites (cf maybeShowProductTour appel ligne 611).
  async function maybeShowProductTour() { /* no-op M91.2 */ }

  // === Phase 3 — Bannière permission notifs ===
  function maybeShowNotifBanner(user) {
    if (!user || !user.cgu_accepted_at) return;
    if (typeof Notification === 'undefined') return;
    if (Notification.permission !== 'default') return;
    const dismissedAt = parseInt(localStorage.getItem('notif_dismissed_at') || '0', 10);
    if (dismissedAt && (Date.now() - dismissedAt) < 7 * 86400 * 1000) return;
    if (document.getElementById('v20-notif-cta')) return;

    const banner = el('div', { id: 'v20-notif-cta', style: 'position:sticky;top:0;z-index:900;background:#FBF7EE;border-bottom:1px solid #E5DDC8;padding:8px 14px;display:flex;align-items:center;gap:10px;font-size:13px;font-family:DM Sans,sans-serif' },
      el('span', { style: 'flex:1;color:#6B5E4A' }, 'Reçois des notifications pour tes dossiers et messages.'),
      el('button', { class: 'v20-cta', style: 'padding:6px 12px;font-size:12px', onclick: async () => {
        const p = await Notification.requestPermission();
        if (p !== 'default') banner.remove();
      } }, 'Activer'),
      el('button', { class: 'v20-cta-secondary', style: 'padding:6px 12px;font-size:12px', onclick: () => {
        localStorage.setItem('notif_dismissed_at', String(Date.now()));
        banner.remove();
      } }, 'Plus tard')
    );
    document.body.insertBefore(banner, document.body.firstChild);
  }

  // === Branding CSS variables ===
  function applyBranding(branding) {
    if (branding.primary_color) document.documentElement.style.setProperty('--ocre-primary', branding.primary_color);
    if (branding.display_name) document.title = branding.display_name + ' · Ocre Immo';
    if (branding.logo_path) {
      const logo = document.querySelector('[data-ocre-logo]');
      if (logo) logo.src = branding.logo_path;
    }
  }

  // === Fetch initial ===
  async function refresh() {
    if (!sess() || !tenantSlug) return;
    const ctx = await apiFetch('/api/workspace_v20.php?action=context');
    if (!ctx.ok) {
      if (ctx.error === 'Non authentifié' || ctx.error === 'Not a member') {
        // laisser l'app gérer le login
      }
      return;
    }
    state.ctx = ctx;
    if (ctx.branding) applyBranding(ctx.branding);
    // Charger user complet (master) avant tout gate.
    const wsList = await apiFetch('/api/auth_v20.php?action=me');
    if (wsList.ok && wsList.workspaces) state.workspaces = wsList.workspaces;
    const fullUser = (wsList.ok && wsList.user) ? wsList.user : (ctx.user || {});

    // M/2026/04/27/3 — CGU integrees au login (page /login/), plus d'overlay
    // bloquant post-login. must_change_password n'est plus declenche par le
    // login user (le code email = mdp d'usage). showFirstLogin et
    // maybeShowCguGate restent definis mais ne sont plus appeles par refresh().
    if (!fullUser.tour_completed_at) {
      maybeShowProductTour(fullUser);
    }
    // Phase 3 bannière notifs après CGU
    maybeShowNotifBanner(fullUser);

    renderBanners();
    maybeRenderCustomFieldsPage();
    injectShareButtons();

  }

  // M/2026/05/13/38 — Delegation click sur logo supprimee. Tap = onClick React direct
  // dans OcreLogo (window.location.href='/'). Plus de popup espaces.

  window.OcreV20 = {
    refresh,
    openCreatePartnership,
    openShareDossier,
    openRequestRupture,
    openCancelRupture,
    openPactSign,
    openDossierPdfView,
    openSharePartnersSheet,
    state,
  };

  // Heartbeat presence si on est sur un dossier
  setInterval(() => {
    const m = location.pathname.match(/\/dossier\/(\d+)/);
    if (m && sess() && tenantSlug) {
      apiFetch('/api/presence_v20.php?action=heartbeat', { method: 'POST', body: { dossier_id: parseInt(m[1], 10) } })
        .then(r => {
          if (r.ok && r.others && r.others.length) {
            const prenom = (r.others[0].user_display || '').split(' ')[0];
            let b = document.querySelector('.v20-banner-coedit');
            if (!b) {
              b = el('div', { class: 'v20-banner v20-banner-coedit' }, '');
              document.body.insertBefore(b, document.body.firstChild);
            }
            b.textContent = `${prenom} édite ce dossier en ce moment`;
          } else {
            document.querySelectorAll('.v20-banner-coedit').forEach(x => x.remove());
          }
        });
    }
  }, 15000);

  // Boot
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh);
  } else {
    refresh();
  }
  setInterval(refresh, 60000);
})();
