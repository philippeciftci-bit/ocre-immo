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
    .v20-row{display:flex;align-items:center;gap:10px;padding:12px 10px;border-radius:8px;cursor:pointer}
    .v20-row:hover{background:#F0E8D8}
    .v20-row strong{color:#2A2018;font-size:14px}
    .v20-row small{color:#8B7F6E;font-size:12px;display:block}
    .v20-row.active{background:#F0E8D8}
    .v20-input{width:100%;padding:10px 12px;border:1px solid #B89968;border-radius:6px;font-family:inherit;font-size:14px;margin-bottom:6px;background:#fff;color:#2A2018}
    .v20-helper{font-size:12px;color:#8B7F6E;margin:0 0 12px}
    .v20-cta{padding:12px 20px;background:#8B5E3C;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600}
    .v20-cta:hover{background:#6B4429}
    .v20-cta:disabled{background:#C4B8A4;cursor:not-allowed}
    .v20-cta-secondary{padding:12px 20px;background:transparent;color:#8B5E3C;border:1px solid #B89968;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;margin-right:8px}
    .v20-cta-destructive{padding:12px 20px;background:#8B0000;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600}
    .v20-actions{display:flex;justify-content:flex-end;margin-top:16px;gap:8px}
    .v20-notif-bell{position:relative;display:inline-block;cursor:pointer;font-size:18px;padding:6px}
    .v20-notif-badge{position:absolute;top:-2px;right:-2px;background:#8B0000;color:#fff;border-radius:9px;font-size:10px;padding:1px 5px;font-weight:700;font-family:'DM Sans',sans-serif}
    .v20-notif-list{position:absolute;top:36px;right:0;width:340px;max-height:480px;overflow-y:auto;background:#fff;border:1px solid #E5DDC8;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);z-index:1500}
    .v20-notif-item{padding:12px 14px;border-bottom:1px solid #F0E8D8;font-size:13px;cursor:pointer}
    .v20-notif-item:hover{background:#FAF8F2}
    .v20-notif-item strong{display:block;color:#2A2018;margin-bottom:2px;font-size:13px}
    .v20-notif-item small{color:#8B7F6E;font-size:11px}
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
    notifications: [],
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
    if (c.workspace.type === 'wsp' && c.mode === 'test') {
      banners.push({ cls: 'v20-banner-test', txt: 'MODE TEST · Données isolées de votre activité réelle' });
    }
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

  // === Switcher overlay (tap logo) ===
  function openSwitcher() {
    const wsp = state.workspaces.filter(w => w.type === 'wsp');
    const wsc = state.workspaces.filter(w => w.type === 'wsc' && w.pact_active);
    const cur = state.ctx ? state.ctx.workspace.slug : '';
    const curMode = state.ctx ? state.ctx.mode : 'agent';

    const goto = (slug, mode) => {
      if (mode) document.cookie = `OCRE_MODE_${slug.toUpperCase()}=${mode};path=/;max-age=31536000`;
      location.href = `https://${slug}.ocre.immo/`;
    };

    const body = el('div', {},
      el('h3', {}, 'Mon espace'),
      ...wsp.map(w => {
        const activeAgent = (w.slug === cur && curMode === 'agent');
        const activeTest = (w.slug === cur && curMode === 'test');
        return el('div', {},
          el('div', { class: 'v20-row' + (activeAgent ? ' active' : ''), onclick: () => goto(w.slug, 'agent') },
            el('strong', {}, w.display_name + ' · Mode agent')),
          el('div', { class: 'v20-row' + (activeTest ? ' active' : ''), onclick: () => goto(w.slug, 'test') },
            el('strong', {}, w.display_name + ' · Mode test'))
        );
      }),
      wsc.length ? el('h3', {}, 'Partenariats') : null,
      ...wsc.map(w => el('div', { class: 'v20-row' + (w.slug === cur ? ' active' : ''), onclick: () => goto(w.slug) },
        el('strong', {}, w.display_name),
        el('small', {}, 'Avec ' + (w.other_members || []).join(', '))
      )),
      el('div', { style: 'margin-top:18px;padding-top:14px;border-top:1px solid #E5DDC8' },
        el('button', { class: 'v20-cta-secondary', onclick: logout }, 'Se déconnecter'))
    );
    overlay('Espaces de travail', null, body);
  }

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
      el('div', { style: 'color:#6B5E4A;font-size:12px;margin-top:3px' }, [(dossier.type_bien || ''), (dossier.prix ? Number(dossier.prix).toLocaleString('fr-FR') + ' €' : '')].filter(Boolean).join(' · ')));

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

  // === Notifications header ===
  function renderNotifBell() {
    const old = document.getElementById('v20-notif-bell');
    if (old) old.remove();
    const unread = state.notifications.filter(n => !n.read_at).length;
    const bell = el('div', { id: 'v20-notif-bell', class: 'v20-notif-bell', onclick: e => { e.stopPropagation(); toggleNotifList(); } },
      '🔔', unread ? el('span', { class: 'v20-notif-badge' }, String(unread)) : null);
    // Insertion dans le bloc icônes droite du header s'il existe (data-header-actions),
    // sinon fallback position:fixed top-right.
    const actions = document.querySelector('[data-header-actions]');
    if (actions) {
      bell.style.position = 'relative';
      actions.insertBefore(bell, actions.firstChild);
    } else {
      bell.style.position = 'fixed'; bell.style.top = '12px'; bell.style.right = '12px'; bell.style.zIndex = '900';
      document.body.appendChild(bell);
    }
  }
  function toggleNotifList() {
    const ex = document.querySelector('.v20-notif-list');
    if (ex) { ex.remove(); return; }
    const list = el('div', { class: 'v20-notif-list', onclick: e => e.stopPropagation() },
      ...state.notifications.slice(0, 30).map(n => {
        return el('div', { class: 'v20-notif-item', onclick: () => handleNotifClick(n) },
          el('strong', {}, n.title || ''),
          el('div', { style: 'color:#6B5E4A;font-size:12px;margin-top:2px' }, n.body || ''),
          el('small', {}, new Date(n.created_at).toLocaleString('fr-FR')));
      })
    );
    if (!state.notifications.length) {
      list.appendChild(el('div', { class: 'v20-notif-item', style: 'text-align:center;color:#8B7F6E' }, 'Aucune notification'));
    }
    document.body.appendChild(list);
    setTimeout(() => document.addEventListener('click', () => list.remove(), { once: true }), 50);
  }
  function handleNotifClick(n) {
    const p = (n.payload_json && typeof n.payload_json === 'string') ? JSON.parse(n.payload_json) : (n.payload_json || {});
    if (p.wsc_slug) location.href = `https://${p.wsc_slug}.ocre.immo/`;
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

  // === Phase 5 — Tour produit 3 slides ===
  async function maybeShowProductTour(user) {
    if (!user || user.tour_completed_at) return;
    const slides = [
      { h: 'Tes dossiers, tes contacts', p: 'Crée et suis tes clients, tes biens, tes mandats dans un espace strictement privé.' },
      { h: 'Mode test pour t\'entraîner', p: 'Tape sur le logo en haut à gauche pour basculer entre Mode agent et Mode test (dossiers d\'exemple).' },
      { h: 'Workspace partagé', p: 'Crée un partenariat avec un autre agent : pacte digital, dossiers partagés, rupture 48h.' },
    ];
    let i = 0;
    const overlay = el('div', { class: 'v20-overlay' });
    function render() {
      overlay.innerHTML = '';
      const slide = slides[i];
      const skip = el('button', { style: 'position:absolute;top:14px;right:18px;background:none;border:none;color:#8B7F6E;cursor:pointer;font-size:13px;font-family:inherit', onclick: finish }, 'Passer');
      const dots = el('div', { style: 'display:flex;gap:6px;justify-content:center;margin:14px 0' },
        ...slides.map((_, j) => el('span', { style: 'width:8px;height:8px;border-radius:50%;background:' + (j === i ? '#8B5E3C' : '#E5DDC8') })));
      const card = el('div', { class: 'v20-overlay-content', style: 'position:relative;text-align:center;max-width:380px' },
        skip,
        el('h2', { style: 'margin:14px 0 10px' }, slide.h),
        el('p', { style: 'color:#6B5E4A;line-height:1.6;font-size:14.5px' }, slide.p),
        dots,
        el('div', { style: 'display:flex;gap:8px;justify-content:center;margin-top:14px' },
          i > 0 ? el('button', { class: 'v20-cta-secondary', onclick: () => { i--; render(); } }, '← Précédent') : null,
          i < slides.length - 1
            ? el('button', { class: 'v20-cta', onclick: () => { i++; render(); } }, 'Suivant →')
            : el('button', { class: 'v20-cta', onclick: finish }, 'Commencer')
        )
      );
      overlay.appendChild(card);
    }
    async function finish() {
      await apiFetch('/api/cgu_v20.php?action=tour_completed', { method: 'POST' });
      overlay.remove();
    }
    document.body.appendChild(overlay);
    render();
  }

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

  // === Phase 4 — Empty state mode agent enrichi ===
  function maybeRenderEmptyState() {
    if (!state.ctx || state.ctx.workspace.type !== 'wsp' || state.ctx.mode !== 'agent') return;
    if (document.getElementById('v20-empty-state')) return;
    setTimeout(() => {
      const list = document.querySelector('[data-clients-list], .clients-list, main, #root');
      if (!list) return;
      const cards = document.querySelectorAll('[data-dossier-id], .client-row, .client-card');
      if (cards.length > 0) return;
      const wrap = el('div', { id: 'v20-empty-state', style: 'max-width:480px;margin:60px auto 40px;padding:40px 24px;text-align:center;font-family:DM Sans,sans-serif' },
        el('div', { html: '<svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin:0 auto 18px;display:block"><rect x="14" y="22" width="52" height="40" rx="6" stroke="#8B5E3C" stroke-width="2.5"/><line x1="14" y1="32" x2="66" y2="32" stroke="#8B5E3C" stroke-width="2.5"/><circle cx="40" cy="48" r="6" fill="#F0E8D8" stroke="#8B5E3C" stroke-width="2"/></svg>' }),
        el('h2', { style: 'font-family:Cormorant Garamond,serif;color:#8B5E3C;font-size:26px;margin:0 0 10px' }, 'Bienvenue dans ton espace Ocre Immo'),
        el('p', { style: 'color:#6B5E4A;line-height:1.6;font-size:14px;margin:0 0 22px' }, 'Tu n\'as pas encore de dossier. Crée le premier ou découvre l\'app avec des dossiers d\'exemple.'),
        el('div', { style: 'display:flex;flex-direction:column;gap:12px;align-items:center' },
          el('button', { class: 'v20-cta', onclick: () => {
            const btn = document.querySelector('button[aria-label*="ouveau"], button[title*="ouveau"], [data-action="new-dossier"]');
            if (btn) btn.click();
          } }, 'Créer mon premier dossier'),
          el('a', { href: '#', style: 'color:#8B5E3C;text-decoration:underline;font-size:13.5px', onclick: (e) => {
            e.preventDefault();
            document.cookie = 'OCRE_MODE_' + tenantSlug.toUpperCase() + '=test;path=/;max-age=31536000';
            location.reload();
          } }, 'Essayer en mode test (dossiers d\'exemple)')
        )
      );
      list.appendChild(wrap);
    }, 1500);
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
    // Phase 4 empty state si mode agent vide
    maybeRenderEmptyState();

    const notif = await apiFetch('/api/auth_v20.php?action=notifications');
    if (notif.ok && notif.items) state.notifications = notif.items;
    else state.notifications = [];

    renderBanners();
    renderNotifBell();
    maybeRenderCustomFieldsPage();
    injectShareButtons();

  }

  // Délégation event document-level : robuste face au remount React.
  document.addEventListener('click', (e) => {
    const target = e.target.closest('[data-ocre-logo], .topbar-title, .site-logo');
    if (target && state.ctx) {
      e.preventDefault();
      e.stopPropagation();
      openSwitcher();
    }
  }, true);

  window.OcreV20 = {
    refresh,
    openSwitcher,
    openCreatePartnership,
    openShareDossier,
    openRequestRupture,
    openCancelRupture,
    openPactSign,
    openDossierPdfView,
    openSharePartnersSheet,
    state,
  };
  // Alias debug : Philippe peut appeler openV20Switcher() depuis console Safari.
  window.openV20Switcher = openSwitcher;

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
