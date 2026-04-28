// V52.5 KILL SWITCH — remplace l'ancien SW. Quand l'iPad récupère ce sw.js,
// il purge tous les caches, désinscrit le SW, et force navigate des fenêtres
// pour reload propre. Tous les fetchs passent par le réseau (pas de cache).
const SW_VERSION = 'ocre-sw-v52.7-killswitch';

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map(k => caches.delete(k)));
    } catch (e) {}
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map(k => caches.delete(k)));
    } catch (e) {}
    try { await self.registration.unregister(); } catch (e) {}
    try {
      const cls = await self.clients.matchAll({type: 'window'});
      cls.forEach(c => { try { c.navigate(c.url); } catch (e) {} });
    } catch (e) {}
  })());
});

self.addEventListener('fetch', (event) => {
  // Bypass cache total : toujours réseau pur.
  event.respondWith(fetch(event.request).catch(() => new Response('', {status: 504})));
});
