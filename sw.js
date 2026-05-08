// V52.5 KILL SWITCH — remplace l'ancien SW. Quand l'iPad récupère ce sw.js,
// il purge tous les caches, désinscrit le SW, et force navigate des fenêtres
// pour reload propre. Tous les fetchs passent par le réseau (pas de cache).
const SW_VERSION = 'ocre-sw-v435.0-killswitch';

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
  // Bypass cache total : toujours reseau pur.
  // M/2026/05/07/115 — fallback offline.html sur navigation fail (mode=navigate).
  // Utile uniquement si SW non-killswitch est active a l avenir, sinon no-op (SW unregister
  // a activate). Le navigateur essaie /offline.html en cache, sinon tombe sur le fallback HTML inline.
  const isNav = event.request.mode === 'navigate';
  event.respondWith(
    fetch(event.request).catch(async () => {
      if (isNav) {
        try {
          const cached = await caches.match('/offline.html');
          if (cached) return cached;
        } catch (_) {}
        return new Response(
          '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Hors ligne — Oi Agent</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font-family:system-ui,-apple-system,sans-serif;background:#FCFAF6;color:#2A1810;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center;margin:0}h1{color:#8B5E3C;font-size:24px;margin:0 0 8px}p{color:#5A4E3D;max-width:360px;line-height:1.5}button{padding:12px 24px;background:#8B5E3C;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;margin-top:18px}</style></head><body><h1>Connexion requise</h1><p>Oi Agent a besoin d\'une connexion pour synchroniser. Verifie ton reseau puis reessaye.</p><button onclick="location.reload()">Reessayer</button></body></html>',
          {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8'}}
        );
      }
      return new Response('', {status: 504});
    })
  );
});
