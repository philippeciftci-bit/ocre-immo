// M98 — sw.js minimal pour app.ocre.immo hub.
// Network-first, pas de cache aggressive sur HTML/JS/CSS, killswitch via SW_VERSION.

const SW_VERSION = 'm98-hub-v1';
const PRECACHE = `ocre-hub-${SW_VERSION}`;

self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k !== PRECACHE).map(k => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
  // Pas de cache pour requêtes auth cross-origin (toujours fresh).
  if (url.origin === 'https://auth.ocre.immo') return;
  // Pas de cache pour HTML/JS/CSS de notre origine (network-first).
  if (url.origin === self.location.origin) {
    if (/\.(html|js|css|json)$/.test(url.pathname) || url.pathname === '/') {
      e.respondWith((async () => {
        try {
          const fresh = await fetch(e.request);
          return fresh;
        } catch (err) {
          const cached = await caches.match(e.request);
          if (cached) return cached;
          throw err;
        }
      })());
      return;
    }
    // Assets (icônes, fonts) : cache-first.
    e.respondWith((async () => {
      const cache = await caches.open(PRECACHE);
      const cached = await cache.match(e.request);
      if (cached) return cached;
      try {
        const fresh = await fetch(e.request);
        if (fresh.ok) cache.put(e.request, fresh.clone());
        return fresh;
      } catch (err) {
        if (cached) return cached;
        throw err;
      }
    })());
  }
});
