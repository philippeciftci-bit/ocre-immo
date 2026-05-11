// M/2026/05/11/21 — admin.ocre.immo super-dashboard SPA.
const API = ''; // same-origin admin.ocre.immo
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
const fmtDate = s => s ? new Date(s.replace(' ', 'T')).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—';

let me = null;
let currentSection = 'overview';

async function api(path, opts = {}) {
  const r = await fetch(API + path, { credentials: 'include', ...opts, headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) } });
  if (r.status === 401 || r.status === 403) throw new Error('forbidden');
  if (!r.ok) throw new Error('http_' + r.status);
  return r.json();
}

async function bootstrap() {
  try {
    const r = await api('/api/me.php');
    me = r.user;
    $('#me-email').textContent = me.email;
    $('#loader').hidden = true;
    $('#app').hidden = false;
    bindNav();
    $('#refreshBtn').addEventListener('click', () => render(currentSection));
    $('#logoutBtn').addEventListener('click', logout);
    render('overview');
  } catch (e) {
    $('#loader').hidden = true;
    $('#auth-error').hidden = false;
    if (e.message === 'forbidden') $('#auth-msg').textContent = "Ton compte n'a pas les droits super-admin. Contacte l'administrateur.";
  }
}

function bindNav() {
  $$('.nav-item').forEach(b => b.addEventListener('click', () => {
    $$('.nav-item').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    render(b.dataset.section);
  }));
}

async function logout() {
  try { await fetch('https://auth.ocre.immo/api/logout.php', { method: 'POST', credentials: 'include' }); } catch {}
  location.href = 'https://auth.ocre.immo/';
}

const SECTIONS = {
  overview: { title: "Vue d'ensemble", render: renderOverview },
  brand:    { title: "Identité Ocre", render: renderStub('Identité Ocre — réglages de marque (logo, palette, polices, tagline)') },
  modules:  { title: "Modules Oi", render: renderModules },
  auth:     { title: "Auth & sécurité", render: renderAuth },
  users:    { title: "Utilisateurs", render: renderUsers },
  logs:     { title: "Logs centralisés", render: renderStub('Logs — flux temps réel, filtres module/niveau/user, export CSV') },
  stats:    { title: "Stats consolidées", render: renderStats },
  tools:    { title: "Outils techniques", render: renderTools },
};

async function render(section) {
  currentSection = section;
  const cfg = SECTIONS[section] || SECTIONS.overview;
  $('#section-title').textContent = cfg.title;
  $('#content-body').innerHTML = '<div class="empty">Chargement…</div>';
  try { await cfg.render(); } catch (e) { $('#content-body').innerHTML = `<div class="error-box">Erreur : ${esc(e.message)}</div>`; }
}

// ============== SECTION 1 — OVERVIEW ==============
async function renderOverview() {
  const r = await api('/api/overview.php');
  const s = r.stats || {};
  const kpis = [
    ['Utilisateurs total', s.users_total ?? '—'],
    ['Sessions actives', s.sessions_active ?? '—'],
    ['Magic links pending', s.magic_pending ?? '—'],
    ['Inscriptions 24h', s.signups_24h ?? '—'],
    ['Logins 24h', s.logins_24h ?? '—'],
  ];
  const kpiHtml = `<div class="kpi-grid">${kpis.map(([l,v]) => `<div class="kpi"><div class="kpi-label">${esc(l)}</div><div class="kpi-value">${esc(v)}</div></div>`).join('')}</div>`;
  const domHtml = `<div class="card"><h2>Status temps réel des domaines</h2><div class="domain-list">${
    r.domains.map(d => `
      <div class="domain">
        <span class="dot-status ${d.ok ? 'ok' : 'ko'}"></span>
        <div class="domain-info">
          <div class="domain-name">${esc(d.name)}</div>
          <div class="domain-meta">HTTP ${d.http} · ${d.ms} ms ${d.error ? '· ' + esc(d.error) : ''}</div>
        </div>
      </div>`).join('')
  }</div></div>
  <p class="muted">Généré ${fmtDate(r.generated_at)} · refresh manuel via ↻</p>`;
  $('#content-body').innerHTML = kpiHtml + domHtml;
}

// ============== SECTION 3 — MODULES ==============
async function renderModules() {
  const r = await api('/api/modules.php');
  $('#content-body').innerHTML = `<div class="card"><h2>Modules Oi (toggle visibilité hub)</h2>${
    r.modules.map(m => `
      <div class="module-row">
        <div class="label">${esc(m.label)}</div>
        <span class="badge ${m.active ? 'badge-active' : 'badge-inactive'}">${esc(m.badge)}</span>
        <button class="toggle ${m.active ? 'on' : ''}" data-module="${esc(m.key)}" title="Toggle"></button>
      </div>`).join('')
  }</div>`;
  $$('.toggle[data-module]').forEach(b => b.addEventListener('click', async () => {
    b.disabled = true;
    try { await api('/api/modules.php', { method: 'POST', body: JSON.stringify({ action: 'toggle_active', module: b.dataset.module }) }); render('modules'); }
    catch (e) { alert('Erreur : ' + e.message); b.disabled = false; }
  }));
}

// ============== SECTION 4 — AUTH ==============
async function renderAuth() {
  const r = await api('/api/auth.php');
  const t = r.totals;
  const kpis = `<div class="kpi-grid">
    <div class="kpi"><div class="kpi-label">Sessions actives</div><div class="kpi-value">${t.sessions_active}</div></div>
    <div class="kpi"><div class="kpi-label">Magic links pending</div><div class="kpi-value">${t.magic_pending}</div></div>
    <div class="kpi"><div class="kpi-label">Sessions révoquées 24h</div><div class="kpi-value">${t.sessions_revoked_24h}</div></div>
  </div>`;
  const killBtn = `<div class="card"><h2>Kill switch global</h2><p class="muted">Révoque toutes les sessions actives de tous les utilisateurs (incident sécurité).</p><button class="btn btn-danger" id="killAllBtn">Révoquer toutes les sessions</button></div>`;
  const sess = `<div class="card"><h2>Sessions actives (${r.sessions.length})</h2>${
    r.sessions.length === 0 ? '<div class="empty">Aucune.</div>' :
    `<table class="tbl"><thead><tr><th>User</th><th>IP</th><th>UA</th><th>Créée</th><th>Expire</th><th></th></tr></thead><tbody>${
      r.sessions.map(s => `<tr>
        <td>${esc(s.email || s.user_id)}</td>
        <td>${esc(s.ip)}</td>
        <td class="muted">${esc((s.user_agent || '').substring(0, 40))}</td>
        <td>${fmtDate(s.created_at)}</td>
        <td>${fmtDate(s.expires_at)}</td>
        <td><button class="btn btn-sm btn-danger" data-jti="${esc(s.jti)}">Révoquer</button></td>
      </tr>`).join('')
    }</tbody></table>`
  }</div>`;
  const magic = `<div class="card"><h2>Magic links pending (${r.magic_pending.length})</h2>${
    r.magic_pending.length === 0 ? '<div class="empty">Aucun.</div>' :
    `<table class="tbl"><thead><tr><th>User</th><th>Token</th><th>Expire</th><th>IP</th></tr></thead><tbody>${
      r.magic_pending.map(m => `<tr><td>${esc(m.email || m.user_id)}</td><td class="muted">${esc(m.token_short)}…</td><td>${fmtDate(m.expires_at)}</td><td>${esc(m.ip)}</td></tr>`).join('')
    }</tbody></table>`
  }</div>`;
  const fails = `<div class="card"><h2>IPs suspectes (≥5 échecs 1h)</h2>${
    r.login_failures.length === 0 ? '<div class="empty">Aucune.</div>' :
    `<table class="tbl"><thead><tr><th>IP</th><th>Tentatives</th><th>Dernière</th></tr></thead><tbody>${
      r.login_failures.map(f => `<tr><td>${esc(f.ip)}</td><td>${f.attempts}</td><td>${fmtDate(f.last_seen)}</td></tr>`).join('')
    }</tbody></table>`
  }</div>`;
  $('#content-body').innerHTML = kpis + killBtn + sess + magic + fails;
  $('#killAllBtn').addEventListener('click', async () => {
    if (!confirm('Confirmer : révoquer TOUTES les sessions de TOUS les users ?')) return;
    const res = await api('/api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'kill_all_sessions' }) });
    alert('Sessions révoquées : ' + res.affected); render('auth');
  });
  $$('button[data-jti]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Révoquer cette session ?')) return;
    await api('/api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'revoke_jti', jti: b.dataset.jti }) });
    render('auth');
  }));
}

// ============== SECTION 5 — USERS ==============
async function renderUsers() {
  $('#content-body').innerHTML = `
    <div class="search-bar"><input id="userQ" placeholder="Recherche email / nom / prénom" autofocus></div>
    <div id="userList"><div class="empty">Chargement…</div></div>`;
  let timer;
  $('#userQ').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadUsers, 250); });
  loadUsers();
}
async function loadUsers() {
  const q = $('#userQ').value.trim();
  const r = await api('/api/users.php?limit=100' + (q ? '&q=' + encodeURIComponent(q) : ''));
  if (r.users.length === 0) { $('#userList').innerHTML = '<div class="empty">Aucun utilisateur.</div>'; return; }
  $('#userList').innerHTML = `<div class="card"><h2>${r.count} utilisateur(s)</h2>
    <table class="tbl"><thead><tr><th>Email</th><th>Nom</th><th>Modules</th><th>Sessions</th><th>Statut</th><th>Dernière connexion</th><th>Actions</th></tr></thead>
    <tbody>${r.users.map(u => `<tr>
      <td>${esc(u.email)} ${u.is_super_admin ? '<span class="badge badge-admin">admin</span>' : ''}</td>
      <td>${esc((u.first_name || '') + ' ' + (u.last_name || ''))}</td>
      <td class="muted">${esc(u.modules || '—')}</td>
      <td>${u.active_sessions}</td>
      <td><span class="badge ${u.status === 'active' ? 'badge-active' : 'badge-suspended'}">${esc(u.status)}</span></td>
      <td>${fmtDate(u.last_login_at)}</td>
      <td>
        <button class="btn btn-sm btn-ghost" data-action="toggle_active" data-id="${u.id}">${u.status === 'active' ? 'Suspendre' : 'Réactiver'}</button>
        <button class="btn btn-sm btn-ghost" data-action="revoke_sessions" data-id="${u.id}">Killer sessions</button>
        <button class="btn btn-sm btn-ghost" data-action="send_magic_link" data-id="${u.id}">Magic link</button>
      </td></tr>`).join('')}</tbody></table></div>`;
  $$('button[data-action]').forEach(b => b.addEventListener('click', async () => {
    const action = b.dataset.action; const userId = parseInt(b.dataset.id, 10);
    if (action === 'toggle_active' && !confirm('Confirmer le changement de statut ?')) return;
    if (action === 'revoke_sessions' && !confirm('Confirmer : tuer toutes les sessions de ce user ?')) return;
    try {
      const res = await api('/api/users.php', { method: 'POST', body: JSON.stringify({ action, user_id: userId }) });
      if (action === 'send_magic_link' && res.token_url) prompt('Magic link généré (copier) :', res.token_url);
      loadUsers();
    } catch (e) { alert('Erreur : ' + e.message); }
  }));
}

// ============== SECTION 7 — STATS ==============
async function renderStats() {
  const r = await api('/api/overview.php');
  const s = r.stats || {};
  $('#content-body').innerHTML = `<div class="card"><h2>KPI consolidés</h2>
    <div class="kpi-grid">
      <div class="kpi"><div class="kpi-label">Users total</div><div class="kpi-value">${s.users_total ?? '—'}</div></div>
      <div class="kpi"><div class="kpi-label">Users actifs</div><div class="kpi-value">${s.users_active ?? '—'}</div></div>
      <div class="kpi"><div class="kpi-label">Sessions actives</div><div class="kpi-value">${s.sessions_active ?? '—'}</div></div>
      <div class="kpi"><div class="kpi-label">Inscriptions 24h</div><div class="kpi-value">${s.signups_24h ?? '—'}</div></div>
      <div class="kpi"><div class="kpi-label">Logins 24h</div><div class="kpi-value">${s.logins_24h ?? '—'}</div></div>
      <div class="kpi"><div class="kpi-label">Magic links pending</div><div class="kpi-value">${s.magic_pending ?? '—'}</div></div>
    </div>
    <p class="muted">Stats détaillées par module + sparklines à venir (M/2026/05/11/21 v2).</p>
  </div>`;
}

// ============== SECTION 8 — TOOLS ==============
async function renderTools() {
  let audit = { entries: [] };
  try { audit = await api('/api/audit.php?limit=30'); } catch {}
  const auditHtml = `<div class="card"><h2>Audit superadmin (30 derniers)</h2>${
    audit.entries.length === 0 ? '<div class="empty">Aucun événement.</div>' :
    `<table class="tbl"><thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Cible</th></tr></thead><tbody>${
      audit.entries.map(e => `<tr><td>${fmtDate(e.created_at)}</td><td>${esc(e.actor_email || e.actor_user_id)}</td><td><span class="badge badge-admin">${esc(e.action)}</span></td><td>${esc(e.target || '—')}</td></tr>`).join('')
    }</tbody></table>`
  }</div>`;
  $('#content-body').innerHTML = auditHtml + `<div class="card"><h2>Liens rapides</h2>
    <p>• <a href="https://github.com/philippeciftci-bit/ocre-immo" target="_blank">Repo ocre-immo (GitHub)</a></p>
    <p>• <a href="https://app.ocre.immo/" target="_blank">app.ocre.immo (hub)</a></p>
    <p>• <a href="https://agent.ocre.immo/" target="_blank">agent.ocre.immo (landing Oi Agent)</a></p>
    <p>• <a href="https://auth.ocre.immo/" target="_blank">auth.ocre.immo</a></p>
    <p class="muted">Déploiements / backups / crons : à brancher (M/2026/05/11/21 v2).</p>
  </div>`;
}

function renderStub(msg) {
  return () => { $('#content-body').innerHTML = `<div class="card"><h2>Section en cours de branchement</h2><p class="muted">${esc(msg)}</p></div>`; };
}

bootstrap();
