// M/2026/05/11/35 — Service worker minimal Oi Agent landing.
// Objectif : rendre l'app installable (Chrome/Edge requirement) + cache offline assets statiques.
const CACHE = 'oi-agent-v1';
const ASSETS = ['/', '/favicon/oi-logo.svg', '/manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(() => {})));
  self.skipWaiting();
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))));
  self.clients.claim();
});
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  // Network-first pour HTML (no stale), cache-first pour assets statiques
  const url = new URL(e.request.url);
  if (url.origin !== location.origin) return;
  if (e.request.destination === 'document') {
    e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
  } else if (/\.(svg|png|webp|css|js|ico|woff2?)$/i.test(url.pathname)) {
    e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(resp => {
      const copy = resp.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy));
      return resp;
    })));
  }
});
