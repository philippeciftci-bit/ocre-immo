// M98 — app.ocre.immo hub logic. Check JWT cross-subdomain, render hub, gestion modules.

const AUTH_BASE = 'https://auth.ocre.immo';
const MODULES = {
  agent:   { url: 'https://agent.ocre.immo/',   active: true,  label: 'Oi Agent' },
  scan:    { url: 'https://scan.ocre.immo/',    active: false, label: 'Oi Scan',    soon: 'Le module diagnostic bâtiment arrive très bientôt — finalisation côté équipe technique.' },
  book:    { url: 'https://book.ocre.immo/',    active: false, label: 'Oi Book',    soon: 'Gestion locative — disponible Q3 2026. Bail, quittance, état des lieux PWA.' },
  demande: { url: 'https://demande.ocre.immo/', active: false, label: 'Oi Demande', soon: 'Module demande mandant — disponible Q4 2026. Captation FAI digitalisée.' },
};

let currentUser = null;

async function fetchMe() {
  try {
    const r = await fetch(`${AUTH_BASE}/api/me.php`, { credentials: 'include' });
    if (!r.ok) return null;
    const data = await r.json();
    return data.ok ? data.user : null;
  } catch (e) { return null; }
}

async function tryRefresh() {
  try {
    const r = await fetch(`${AUTH_BASE}/api/refresh.php`, { method: 'POST', credentials: 'include' });
    if (!r.ok) return null;
    return fetchMe();
  } catch (e) { return null; }
}

async function logout() {
  try { await fetch(`${AUTH_BASE}/api/logout.php`, { method: 'POST', credentials: 'include' }); } catch (e) {}
  window.location.href = `${AUTH_BASE}/`;
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function userDisplayName(user) {
  if (user.first_name) return user.first_name;
  if (user.email) return user.email.split('@')[0];
  return 'agent';
}

function userInitial(user) {
  const n = user.first_name || user.email || '';
  return (n.trim()[0] || 'O').toUpperCase();
}

function renderHub(user) {
  currentUser = user;
  document.getElementById('greeting').textContent = `Bienvenue, ${userDisplayName(user)}`;
  document.getElementById('avatarInitial').textContent = userInitial(user);

  document.querySelectorAll('.card').forEach(card => {
    const moduleKey = card.dataset.module;
    card.addEventListener('click', () => handleModuleClick(moduleKey));
  });

  document.getElementById('logoutBtn').addEventListener('click', logout);
  document.getElementById('avatarBtn').addEventListener('click', openSettings);
  document.getElementById('settingsLink').addEventListener('click', openSettings);
  document.getElementById('settingsCancel').addEventListener('click', () => closeModal('settingsModal'));
  document.getElementById('settingsSave').addEventListener('click', saveSettings);
  document.getElementById('soonClose').addEventListener('click', () => closeModal('soonModal'));

  document.getElementById('loader').hidden = true;
  document.getElementById('hub').hidden = false;
}

function handleModuleClick(key) {
  const m = MODULES[key];
  if (!m) return;
  if (m.active) {
    window.location.href = m.url;
  } else {
    showSoon(m.label, m.soon);
  }
}

function showSoon(title, message) {
  const dlg = document.getElementById('soonModal');
  document.getElementById('soonTitle').textContent = title;
  document.getElementById('soonMessage').textContent = message;
  if (typeof dlg.showModal === 'function') dlg.showModal();
}

function openSettings() {
  document.getElementById('emailRO').value = currentUser.email || '';
  document.getElementById('firstName').value = currentUser.first_name || '';
  document.getElementById('lastName').value = currentUser.last_name || '';
  const fb = document.getElementById('settingsFeedback');
  fb.className = 'feedback';
  fb.textContent = '';
  const dlg = document.getElementById('settingsModal');
  if (typeof dlg.showModal === 'function') dlg.showModal();
}

function closeModal(id) {
  const dlg = document.getElementById(id);
  if (dlg.open) dlg.close();
}

async function saveSettings() {
  const btn = document.getElementById('settingsSave');
  const fb = document.getElementById('settingsFeedback');
  const first = document.getElementById('firstName').value.trim();
  const last = document.getElementById('lastName').value.trim();
  btn.disabled = true;
  btn.textContent = 'Enregistrement…';
  fb.className = 'feedback';
  try {
    const r = await fetch(`${AUTH_BASE}/api/me.php`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ first_name: first, last_name: last }),
    });
    const data = await r.json();
    if (r.ok && data.ok) {
      currentUser = data.user;
      document.getElementById('greeting').textContent = `Bienvenue, ${userDisplayName(currentUser)}`;
      document.getElementById('avatarInitial').textContent = userInitial(currentUser);
      fb.className = 'feedback show success';
      fb.textContent = 'Profil mis à jour.';
      setTimeout(() => closeModal('settingsModal'), 800);
    } else {
      fb.className = 'feedback show error';
      fb.textContent = data.error || 'Erreur enregistrement.';
    }
  } catch (e) {
    fb.className = 'feedback show error';
    fb.textContent = 'Erreur réseau.';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Enregistrer';
  }
}

(async () => {
  let user = await fetchMe();
  if (!user) {
    user = await tryRefresh();
    if (!user) {
      window.location.href = `${AUTH_BASE}/`;
      return;
    }
  }
  renderHub(user);
})();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
}
