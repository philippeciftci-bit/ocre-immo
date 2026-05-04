/* Admin shell — auth gate (super_admin) + active nav state + mobile drawer + logout */
/* A1/2026-05-04 — refonte sidebar unifiée                                            */
/*                                                                                    */
/* Pattern : la structure header + sidebar est ECRITE STATIQUEMENT dans chaque page  */
/* admin (cf. /admin/_layout.html). Ce script ne fait que :                           */
/*   1. checker l'auth super_admin et afficher la gate si KO                          */
/*   2. marquer le lien actif dans la sidebar (data-page sur <body>)                  */
/*   3. binder le burger mobile + overlay + logout                                    */
/*   4. exposer window.AdminShell.req() (fetch + X-Session-Token auto)                */

(function () {
  'use strict';

  let token = '';
  try { token = localStorage.getItem('ocre_token') || ''; } catch (e) {}
  let currentUser = null;

  async function req(url, opts) {
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['X-Session-Token'] = token;
    const merged = Object.assign({}, opts || {}, { headers: Object.assign(headers, (opts && opts.headers) || {}) });
    const res = await fetch(url, merged);
    return res.json();
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  async function ensureAuth() {
    const shell = document.getElementById('admin-shell');
    if (!shell) return;
    if (!token) return showGate();
    let me;
    try { me = await req('/api/auth_v20.php?action=me'); }
    catch (e) { me = { ok: false }; }
    if (!me.ok) return showGate();
    if (!me.user || me.user.role !== 'super_admin') {
      document.body.innerHTML = '<div class="admin-loading">Acces refuse : super_admin requis. <a href="#" id="admin-relogin">Se reconnecter</a></div>';
      const link = document.getElementById('admin-relogin');
      if (link) link.onclick = (e) => { e.preventDefault(); try { localStorage.removeItem('ocre_token'); } catch (e) {} location.reload(); };
      return;
    }
    currentUser = me.user;
    activateShell();
  }

  function showGate() {
    document.body.innerHTML = `
      <div class="admin-gate-wrap">
        <div class="admin-gate">
          <h1>OCRE Admin</h1>
          <p>Authentification super-admin requise</p>
          <input id="admin-login-email" type="email" placeholder="email" autocomplete="email">
          <input id="admin-login-pwd" type="password" placeholder="code agent">
          <button id="admin-login-btn">Se connecter</button>
          <div id="admin-login-err" class="admin-gate-err"></div>
        </div>
      </div>`;
    const submit = async () => {
      const email = document.getElementById('admin-login-email').value.trim();
      const password = document.getElementById('admin-login-pwd').value;
      const errBox = document.getElementById('admin-login-err');
      errBox.textContent = '';
      let r;
      try {
        r = await req('/api/auth_v20.php?action=login', { method: 'POST', body: JSON.stringify({ email, password, accept_cgu: true }) });
      } catch (e) { errBox.textContent = 'Erreur reseau'; return; }
      if (r.ok && r.token) {
        token = r.token;
        try { localStorage.setItem('ocre_token', token); } catch (e) {}
        location.reload();
      } else {
        errBox.textContent = r.error || 'Echec login';
      }
    };
    document.getElementById('admin-login-btn').onclick = submit;
    document.getElementById('admin-login-pwd').addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
  }

  function activateShell() {
    const shell = document.getElementById('admin-shell');
    if (!shell) return;
    shell.removeAttribute('hidden');
    shell.style.display = '';

    // Active state on sidebar
    const activePage = shell.getAttribute('data-page') || 'dashboard';
    document.querySelectorAll('.admin-sidebar .admin-nav-item').forEach(a => {
      if (a.getAttribute('data-page') === activePage) a.classList.add('active');
      else a.classList.remove('active');
    });

    // User label in header
    const userBox = document.getElementById('admin-header-user');
    if (userBox) userBox.textContent = currentUser.email || currentUser.name || 'super-admin';

    // Mobile burger + overlay
    const burger = document.getElementById('admin-burger-btn');
    const overlay = document.getElementById('admin-sidebar-overlay');
    if (burger) burger.onclick = () => shell.classList.toggle('sidebar-open');
    if (overlay) overlay.onclick = () => shell.classList.remove('sidebar-open');
    document.querySelectorAll('.admin-sidebar .admin-nav-item').forEach(a => {
      a.addEventListener('click', () => shell.classList.remove('sidebar-open'));
    });

    // Logout
    const logout = document.getElementById('admin-logout-btn');
    if (logout) logout.onclick = () => {
      try { localStorage.removeItem('ocre_token'); } catch (e) {}
      location.href = '/admin/';
    };

    document.dispatchEvent(new CustomEvent('admin:shell-ready', { detail: { user: currentUser, page: activePage } }));
  }

  window.AdminShell = {
    req,
    esc,
    getToken: () => token,
    getUser: () => currentUser
  };

  document.addEventListener('DOMContentLoaded', ensureAuth);
})();
