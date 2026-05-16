// V20 bridge — branchement UI mobile sur endpoints multi-tenant.
// M/2026/05/16/03 — Hotfix auth bootstrap tenant.
// Ne force aucun reload/redirect. Si le cookie .ocre.immo existe mais que localStorage
// n'a pas encore de token, tente /api/auth.php?action=me avant toute conclusion anonyme.

(function () {
  'use strict';
  if (window.__OCRE_V20__) return;
  window.__OCRE_V20__ = true;

  const tenantSlug = (location.hostname.match(/^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/) || [])[1] || '';
  const SESSION_KEY = 'ocre_token';
  const TOKEN_KEYS = ['ocre_token', 'session_token', 'sessionToken', 'token', 'mt_token'];

  const state = {
    ctx: null,
    workspaces: [],
    bootstrapping: false,
    bootstrapDone: false,
    cookieSessionOk: false,
  };

  function readToken() {
    try {
      for (const key of TOKEN_KEYS) {
        const value = localStorage.getItem(key);
        if (value) return value;
      }
    } catch (_) {}
    return '';
  }

  function writeToken(token) {
    if (!token) return;
    try {
      localStorage.setItem('ocre_token', token);
      localStorage.setItem('session_token', token);
      localStorage.setItem('sessionToken', token);
    } catch (_) {}
  }

  // Extraction token depuis URL, sans reload.
  try {
    const urlParams = new URLSearchParams(location.search);
    const sessFromUrl = urlParams.get('_s') || urlParams.get('session_token') || urlParams.get('token') || '';
    if (sessFromUrl && /^[a-f0-9]{64}$/i.test(sessFromUrl)) {
      writeToken(sessFromUrl);
      urlParams.delete('_s');
      urlParams.delete('session_token');
      urlParams.delete('token');
      const cleanQs = urlParams.toString();
      history.replaceState(null, '', location.pathname + (cleanQs ? '?' + cleanQs : '') + location.hash);
    }
  } catch (_) {}

  const sess = () => readToken();

  async function jsonFetch(url, opts = {}) {
    const token = sess();
    const headers = new Headers(opts.headers || {});
    if (tenantSlug && !headers.has('X-Tenant-Slug')) headers.set('X-Tenant-Slug', tenantSlug);
    if (token && !headers.has('X-Session-Token')) headers.set('X-Session-Token', token);

    let body = opts.body;
    if (body && typeof body !== 'string') {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(body);
    }

    const response = await fetch(url, {
      ...opts,
      body,
      headers,
      credentials: opts.credentials || 'include',
      cache: opts.cache || 'no-store',
    });

    const data = await response.json().catch(() => ({ ok: false, error: 'Réponse non-JSON' }));
    if (!response.ok && data && data.ok !== false) data.ok = false;
    return data;
  }

  const apiFetch = jsonFetch;

  let bootstrapPromise = null;

  async function bootstrapSessionFromCookie() {
    if (!tenantSlug) return false;
    if (sess()) {
      state.bootstrapDone = true;
      state.cookieSessionOk = true;
      window.__OCRE_AUTH_BOOTSTRAP_DONE__ = true;
      window.__OCRE_COOKIE_SESSION_OK__ = true;
      return true;
    }
    if (bootstrapPromise) return bootstrapPromise;

    state.bootstrapping = true;
    window.__OCRE_AUTH_BOOTSTRAPPING__ = true;

    bootstrapPromise = (async () => {
      const endpoints = [
        '/api/auth.php?action=me',
        '/api/auth_v20.php?action=me',
      ];

      for (const endpoint of endpoints) {
        try {
          const r = await jsonFetch(endpoint, { method: 'GET' });
          const user = r && (r.user || r.currentUser || (r.data && r.data.user));
          const token = r && (r.session_token || r.sessionToken || r.token || (r.data && (r.data.session_token || r.data.token)));
          if (r && r.ok !== false && (user || token)) {
            writeToken(token);
            state.cookieSessionOk = true;
            window.__OCRE_COOKIE_SESSION_OK__ = true;
            return true;
          }
        } catch (_) {}
      }
      return false;
    })().finally(() => {
      state.bootstrapping = false;
      state.bootstrapDone = true;
      window.__OCRE_AUTH_BOOTSTRAPPING__ = false;
      window.__OCRE_AUTH_BOOTSTRAP_DONE__ = true;
    });

    return bootstrapPromise;
  }

  function el(tag, attrs = {}, ...children) {
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
  }

  function closeOverlay() {
    const ov = document.querySelector('.v20-overlay');
    if (ov) ov.remove();
  }

  function overlay(title, subtitle, body, actions) {
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
  }

  const css = `
    .v20-banner{position:sticky;top:0;z-index:1000;padding:8px 14px;text-align:center;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif}
    .v20-banner-superadmin{background:#FCD5D0;color:#8B0000}
    .v20-banner-rupture{background:#FCD5D0;color:#8B0000}
    .v20-banner-pending{background:#F5EAD3;color:#6B4429}
    .v20-banner-archived{background:#E5DDC8;color:#6B5E4A}
    .v20-banner-coedit{background:#FBE9C7;color:#8B5E3C}
    .v20-overlay{position:fixed;inset:0;background:rgba(20,12,4,.55);z-index:2000;display:flex;align-items:flex-start;justify-content:center;padding:60px 16px;animation:v20fade .15s}
    .v20-overlay-content{background:#FAF8F2;border-radius:14px;max-width:420px;width:100%;padding:22px 20px;box-shadow:0 8px 32px rgba(0,0,0,.18);font-family:'DM Sans',sans-serif}
    .v20-overlay h2{font-family:'Cormorant Garamond',serif;color:#8B5E3C;font-size:22px;margin:0 0 4px}
    .v20-overlay p.v20-sub{color:#6B5E4A;font-size:13px;margin:0 0 14px}
    .v20-row{display:flex;align-items:center;gap:10px;padding:14px 12px;border-radius:8px;cursor:pointer;min-height:48px}
    .v20-row:hover{background:#F0E8D8}
    .v20-row strong{color:#2A2018;font-size:14px}
    .v20-row small{color:#8B7F6E;font-size:12px;display:block}
    .v20-input{width:100%;padding:10px 12px;border:1px solid #B89968;border-radius:6px;font-family:inherit;font-size:14px;margin-bottom:6px;background:#fff;color:#2A2018}
    .v20-helper{font-size:12px;color:#8B7F6E;margin:0 0 12px}
    .v20-cta{padding:12px 20px;background:#8B5E3C;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600}
    .v20-cta:disabled{background:#C4B8A4;cursor:not-allowed}
    .v20-cta-secondary{padding:12px 20px;background:transparent;color:#8B5E3C;border:1px solid #B89968;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;margin-right:8px}
    .v20-cta-destructive{padding:12px 20px;background:#8B0000;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600}
    .v20-actions{display:flex;justify-content:flex-end;margin-top:16px;gap:8px}
    .v20-share-btn{display:inline-flex;align-items:center;gap:4px;padding:6px 10px;background:#F0E8D8;color:#8B5E3C;border:1px solid #B89968;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
    @keyframes v20fade{from{opacity:0}to{opacity:1}}
  `;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  function renderBanners() {
    document.querySelectorAll('.v20-banner').forEach(b => b.remove());
    const c = state.ctx;
    if (!c) return;
    const banners = [];
    if (c.is_super_admin && c.is_readonly) banners.push({ cls: 'v20-banner-superadmin', txt: 'Vue super-administrateur · Lecture seule' });
    if (c.rupture_pending) {
      const d = new Date(c.rupture_pending.scheduled_for);
      banners.push({ cls: 'v20-banner-rupture', txt: `Période de transition · Rupture effective le ${d.toLocaleDateString('fr-FR')} à ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}` });
    }
    if (c.workspace && c.workspace.type === 'wsc' && !c.pact_active) banners.push({ cls: 'v20-banner-pending', txt: `En attente de signature de ${(c.unsigned_members || []).join(', ')}` });
    if (c.workspace && c.workspace.archived_at) banners.push({ cls: 'v20-banner-archived', txt: `Workspace archivé · Lecture seule depuis le ${new Date(c.workspace.archived_at).toLocaleDateString('fr-FR')}` });
    banners.reverse().forEach(b => document.body.insertBefore(el('div', { class: 'v20-banner ' + b.cls }, b.txt), document.body.firstChild));
  }

  function applyBranding(branding) {
    if (!branding) return;
    if (branding.primary_color) document.documentElement.style.setProperty('--ocre-primary', branding.primary_color);
    if (branding.display_name) document.title = branding.display_name + ' · Ocre Immo';
    if (branding.logo_path) {
      const logo = document.querySelector('[data-ocre-logo]');
      if (logo) logo.src = branding.logo_path;
    }
  }

  function maybeShowNotifBanner(user) {
    if (!user || !user.cgu_accepted_at) return;
    if (typeof Notification === 'undefined') return;
    if (Notification.permission !== 'default') return;
    if (document.getElementById('v20-notif-cta')) return;
    const dismissedAt = parseInt(localStorage.getItem('notif_dismissed_at') || '0', 10);
    if (dismissedAt && (Date.now() - dismissedAt) < 7 * 86400 * 1000) return;
    const banner = el('div', { id: 'v20-notif-cta', style: 'position:sticky;top:0;z-index:900;background:#FBF7EE;border-bottom:1px solid #E5DDC8;padding:8px 14px;display:flex;align-items:center;gap:10px;font-size:13px;font-family:DM Sans,sans-serif' },
      el('span', { style: 'flex:1;color:#6B5E4A' }, 'Reçois des notifications pour tes dossiers et messages.'),
      el('button', { class: 'v20-cta', style: 'padding:6px 12px;font-size:12px', onclick: async () => { const p = await Notification.requestPermission(); if (p !== 'default') banner.remove(); } }, 'Activer'),
      el('button', { class: 'v20-cta-secondary', style: 'padding:6px 12px;font-size:12px', onclick: () => { localStorage.setItem('notif_dismissed_at', String(Date.now())); banner.remove(); } }, 'Plus tard')
    );
    document.body.insertBefore(banner, document.body.firstChild);
  }

  async function maybeRenderCustomFieldsPage() {
    if (!location.pathname.startsWith('/settings/custom-fields')) return;
    const r = await apiFetch('/api/custom_fields_v20.php?action=list');
    if (!r.ok) return;
    document.body.innerHTML = '';
    document.body.appendChild(el('div', { style: 'max-width:680px;margin:30px auto;padding:0 20px;font-family:DM Sans,sans-serif' },
      el('h1', { style: 'font-family:Cormorant Garamond,serif;color:#8B5E3C' }, 'Champs personnalisés'),
      el('p', { style: 'color:#6B5E4A' }, 'Active les champs supplémentaires que tu veux afficher dans tes dossiers.'),
      ...(r.fields || []).map(f => el('div', { style: 'display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid #E5DDC8' },
        el('div', {}, el('strong', { style: 'color:#2A2018' }, f.label_override || f.label), el('div', { style: 'font-size:11px;color:#8B7F6E;margin-top:2px' }, `${f.type}${f.options ? ' · ' + f.options.length + ' options' : ''}`)),
        el('div', { class: 'switch' + (f.enabled ? ' on' : ''), onclick: async (ev) => {
          const newState = !f.enabled;
          const r2 = await apiFetch('/api/custom_fields_v20.php?action=toggle', { method: 'POST', body: { field_key: f.key, enabled: newState } });
          if (r2.ok) { f.enabled = newState; ev.target.classList.toggle('on', newState); }
        } })
      ))
    ));
  }

  function injectShareButtons() {
    if (!state.ctx || state.ctx.is_readonly) return;
    document.querySelectorAll('[data-dossier-id]:not([data-v20-share])').forEach(card => {
      card.setAttribute('data-v20-share', '1');
      const id = card.getAttribute('data-dossier-id');
      const wrap = el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-top:6px' },
        el('button', { class: 'v20-share-btn', style: 'background:#8B5E3C;color:#fff;border-color:#8B5E3C', onclick: e => { e.stopPropagation(); openDossierPdfView(parseInt(id, 10)); } }, '👁 Aperçu'),
        el('button', { class: 'v20-share-btn', onclick: e => { e.stopPropagation(); openSharePartnersSheet(parseInt(id, 10)); } }, '🤝 Partager')
      );
      card.appendChild(wrap);
    });
  }

  async function openDossierPdfView(dossierId) {
    closeOverlay();
    const ov = el('div', { class: 'v20-overlay', style: 'background:#E8E5E0;align-items:flex-start;padding:0' });
    const close = el('button', { style: 'position:fixed;top:14px;left:14px;background:#fff;border:1px solid #E5DDC8;border-radius:20px;padding:8px 14px;font-family:inherit;font-size:13px;font-weight:600;color:#8B5E3C;cursor:pointer;z-index:10;box-shadow:0 2px 6px rgba(0,0,0,.12)', onclick: () => ov.remove() }, '← Retour');
    const a4 = el('div', { style: 'max-width:720px;margin:60px auto 80px;background:#fff;padding:36px 32px;box-shadow:0 4px 20px rgba(0,0,0,.08);border-radius:4px;font-family:DM Sans,sans-serif' });
    a4.innerHTML = '<p style="text-align:center;color:#7A7167">Chargement…</p>';
    ov.appendChild(close); ov.appendChild(a4); document.body.appendChild(ov);
    const r = await apiFetch('/api/share_links_v20.php?action=create_link', { method: 'POST', body: { dossier_id: dossierId } });
    if (!r.ok) { a4.innerHTML = '<p style="color:#C04B20">Erreur : ' + (r.error || 'inconnue') + '</p>'; return; }
    try {
      const html = await fetch('/share/' + r.token, { credentials: 'include', cache: 'no-store' }).then(x => x.text());
      const m = html.match(/<body[^>]*>([\s\S]*)<\/body>/i);
      a4.innerHTML = m ? m[1] : html;
    } catch (_) { a4.innerHTML = '<p style="color:#C04B20">Erreur réseau</p>'; }
  }

  async function openSharePartnersSheet(dossierId) {
    const r = await apiFetch('/api/share_links_v20.php?action=create_link', { method: 'POST', body: { dossier_id: dossierId } });
    if (!r.ok) { alert(r.error || 'Erreur'); return; }
    if (navigator.share) { try { await navigator.share({ url: r.url, title: 'Dossier Ocre Immo' }); return; } catch (_) {} }
    try { await navigator.clipboard.writeText(r.url); alert('Lien copié — expire dans 7 jours'); } catch (_) { prompt('Copie le lien :', r.url); }
  }

  function openCreatePartnership() { alert('Fonction indisponible pendant la stabilisation auth.'); }
  function openShareDossier(dossier) { if (dossier && dossier.id) openSharePartnersSheet(dossier.id); }
  function openRequestRupture() { alert('Fonction indisponible pendant la stabilisation auth.'); }
  function openCancelRupture() { alert('Fonction indisponible pendant la stabilisation auth.'); }
  function openPactSign() { alert('Fonction indisponible pendant la stabilisation auth.'); }

  async function refresh() {
    if (!tenantSlug) return;

    const hasSession = await bootstrapSessionFromCookie();
    if (!hasSession && !sess() && !state.cookieSessionOk) {
      return;
    }

    const ctx = await apiFetch('/api/workspace_v20.php?action=context');
    if (!ctx.ok) {
      // Ne jamais rediriger pendant/juste après le bootstrap : laisser le guard principal finir.
      return;
    }

    state.ctx = ctx;
    if (ctx.branding) applyBranding(ctx.branding);

    const wsList = await apiFetch('/api/auth_v20.php?action=me');
    if (wsList.ok && wsList.workspaces) state.workspaces = wsList.workspaces;
    const fullUser = (wsList.ok && wsList.user) ? wsList.user : (ctx.user || {});

    maybeShowNotifBanner(fullUser);
    renderBanners();
    maybeRenderCustomFieldsPage();
    injectShareButtons();
  }

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

  // Boot sans reload automatique.
  bootstrapSessionFromCookie().finally(() => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', refresh, { once: true });
    } else {
      refresh();
    }
  });

  setInterval(refresh, 60000);
})();
