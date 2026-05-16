// M/2026/05/16/04 — Tenant auth bootstrap guard.
// No cache. No SW reload. On tenant navigations, inject a tiny guard before the SPA:
// - localStorage token from URL if present
// - otherwise try cookie-backed /api/auth.php?action=me then /api/auth_v20.php?action=me
// - block auth redirects/reloads while this bootstrap is pending

const SW_VERSION = 'ocre-sw-safe-2026-05-16-04-tenant-auth-guard';

const TENANT_AUTH_GUARD = `
<script>
(function(){
  'use strict';
  if (window.__OCRE_TENANT_AUTH_GUARD__) return;
  window.__OCRE_TENANT_AUTH_GUARD__ = true;

  var host = location.hostname || '';
  var tenant = ((host.match(/^([a-z0-9][a-z0-9-]*)\\.ocre\\.immo$/) || [])[1] || '');
  var keys = ['ocre_token','session_token','sessionToken','token','mt_token'];

  function readToken(){
    try { for (var i=0;i<keys.length;i++){ var v=localStorage.getItem(keys[i]); if (v) return v; } } catch(e) {}
    return '';
  }
  function saveToken(v){
    if (!v || !/^[a-f0-9]{64}$/i.test(v)) return;
    try { localStorage.setItem('ocre_token', v); localStorage.setItem('session_token', v); localStorage.setItem('sessionToken', v); } catch(e) {}
  }
  function isAuthUrl(u){
    try { var x = new URL(String(u), location.href); return x.hostname === 'auth.ocre.immo' || x.pathname.indexOf('/login') === 0; } catch(e) { return false; }
  }
  function fetchJson(url){
    var h = {'X-Tenant-Slug': tenant};
    var t = readToken();
    if (t) h['X-Session-Token'] = t;
    return fetch(url, {credentials:'include', cache:'no-store', headers:h}).then(function(r){
      return r.json().catch(function(){ return {}; }).then(function(j){ j.__httpOk = r.ok; return j; });
    });
  }

  try {
    var qs = new URLSearchParams(location.search);
    var fromUrl = qs.get('mt_token') || qs.get('_s') || qs.get('session_token') || '';
    if (fromUrl) {
      saveToken(fromUrl);
      qs.delete('mt_token'); qs.delete('_s'); qs.delete('session_token');
      var clean = location.pathname + (qs.toString() ? '?' + qs.toString() : '') + location.hash;
      history.replaceState(null, '', clean);
    }
  } catch(e) {}

  window.__OCRE_AUTH_BOOTSTRAP_DONE__ = false;
  window.__OCRE_AUTH_BOOTSTRAPPING__ = true;
  window.__OCRE_COOKIE_SESSION_OK__ = false;

  var p = Promise.resolve(false);
  if (!tenant) {
    p = Promise.resolve(false);
  } else if (readToken()) {
    window.__OCRE_COOKIE_SESSION_OK__ = true;
    p = Promise.resolve(true);
  } else {
    p = fetchJson('/api/auth.php?action=me').then(function(j){
      var u = j && (j.user || j.currentUser || (j.data && j.data.user));
      var tok = j && (j.session_token || j.sessionToken || j.token || (j.data && (j.data.session_token || j.data.token)));
      if (j && j.__httpOk && j.ok !== false && (u || tok)) { saveToken(tok); window.__OCRE_COOKIE_SESSION_OK__ = true; return true; }
      return fetchJson('/api/auth_v20.php?action=me').then(function(k){
        var u2 = k && (k.user || k.currentUser || (k.data && k.data.user));
        var tok2 = k && (k.session_token || k.sessionToken || k.token || (k.data && (k.data.session_token || k.data.token)));
        if (k && k.__httpOk && k.ok !== false && (u2 || tok2)) { saveToken(tok2); window.__OCRE_COOKIE_SESSION_OK__ = true; return true; }
        return false;
      });
    }).catch(function(){ return false; });
  }

  window.__OCRE_AUTH_BOOTSTRAP_PROMISE__ = p.finally(function(){
    window.__OCRE_AUTH_BOOTSTRAPPING__ = false;
    window.__OCRE_AUTH_BOOTSTRAP_DONE__ = true;
  });

  var nativeFetch = window.fetch.bind(window);
  window.fetch = function(input, init){
    init = init || {};
    var url = (typeof input === 'string') ? input : ((input && input.url) || '');
    if (url.indexOf('/api/') === 0 || url.indexOf(location.origin + '/api/') === 0) {
      init.credentials = init.credentials || 'include';
      init.cache = init.cache || 'no-store';
      var headers = new Headers(init.headers || {});
      if (tenant && !headers.has('X-Tenant-Slug')) headers.set('X-Tenant-Slug', tenant);
      var t = readToken();
      if (t && !headers.has('X-Session-Token')) headers.set('X-Session-Token', t);
      init.headers = headers;
    }
    return nativeFetch(input, init);
  };

  try {
    var assign = Location.prototype.assign;
    Location.prototype.assign = function(url){
      if (tenant && isAuthUrl(url) && !window.__OCRE_AUTH_BOOTSTRAP_DONE__) {
        window.__OCRE_AUTH_BOOTSTRAP_PROMISE__.then(function(ok){ if (!ok && !readToken()) assign.call(location, url); });
        return;
      }
      return assign.call(this, url);
    };
    var replace = Location.prototype.replace;
    Location.prototype.replace = function(url){
      if (tenant && isAuthUrl(url) && !window.__OCRE_AUTH_BOOTSTRAP_DONE__) {
        window.__OCRE_AUTH_BOOTSTRAP_PROMISE__.then(function(ok){ if (!ok && !readToken()) replace.call(location, url); });
        return;
      }
      return replace.call(this, url);
    };
    var reload = Location.prototype.reload;
    Location.prototype.reload = function(){
      if (tenant && !window.__OCRE_AUTH_BOOTSTRAP_DONE__) return;
      return reload.call(this);
    };
  } catch(e) {}
})();
</script>`;

function isTenantNavigation(request) {
  try {
    const url = new URL(request.url);
    return request.mode === 'navigate' && /^([a-z0-9][a-z0-9-]*)\.ocre\.immo$/.test(url.hostname);
  } catch (_) {
    return false;
  }
}

async function guardedNavigation(request) {
  const response = await fetch(request, { cache: 'no-store' });
  const type = response.headers.get('content-type') || '';
  if (!response.ok || !type.includes('text/html')) return response;
  const html = await response.text();
  const body = html.includes('__OCRE_TENANT_AUTH_GUARD__') ? html : html.replace(/<head([^>]*)>/i, '<head$1>' + TENANT_AUTH_GUARD);
  const headers = new Headers(response.headers);
  headers.set('content-type', 'text/html; charset=utf-8');
  headers.set('cache-control', 'no-store, max-age=0');
  return new Response(body, { status: response.status, statusText: response.statusText, headers });
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    try { const keys = await caches.keys(); await Promise.all(keys.map((k) => caches.delete(k))); } catch (_) {}
    try { self.skipWaiting(); } catch (_) {}
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try { const keys = await caches.keys(); await Promise.all(keys.map((k) => caches.delete(k))); } catch (_) {}
    try { if (self.registration && self.registration.navigationPreload) await self.registration.navigationPreload.disable(); } catch (_) {}
    try { await self.clients.claim(); } catch (_) {}
  })());
});

self.addEventListener('fetch', (event) => {
  if (isTenantNavigation(event.request)) {
    event.respondWith(guardedNavigation(event.request).catch(() => fetch(event.request)));
    return;
  }
  event.respondWith(fetch(event.request));
});

self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch (_) { try { data = { title: 'Oi Agent', body: event.data.text() }; } catch (__){ } }
  event.waitUntil(self.registration.showNotification(data.title || 'Oi Agent', {
    body: data.body || '', icon: '/icons/icon-192.png', badge: '/icons/badge-72.png', data: { url: data.url || '/' }
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil((async () => {
    try {
      const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const client of clients) {
        if (client && 'focus' in client) {
          try { await client.focus(); if ('navigate' in client) await client.navigate(url); return; } catch (_) {}
        }
      }
      if (self.clients.openWindow) await self.clients.openWindow(url);
    } catch (_) {}
  })());
});
