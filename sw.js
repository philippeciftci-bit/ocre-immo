// M/2026/05/09/42 — M88 — Service Worker actif (PWA push + network passthrough).
// Conversion depuis killswitch v474 : on garde le SW REGISTERED (plus d'unregister à activate)
// pour permettre la réception des push notifications PWA. Tous les fetchs restent en network-first
// (pas de cache offline business — fallback minimal pour mode=navigate uniquement).
const SW_VERSION = 'ocre-sw-v503.0-m12-52-fix-deeplink-help-reglages';

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
    // M88 : NE PLUS unregister — on garde le SW pour PWA push.
    try { await self.clients.claim(); } catch (e) {}
  })());
});

self.addEventListener('fetch', (event) => {
  // Network-first total, fallback offline.html sur navigation fail.
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

// M88 — Push notifications PWA (VAPID + endpoints serveur /api/push_*.php).
self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch (_) { try { data = {title:'Oi Agent', body: event.data.text()}; } catch (__) {} }
  const title = data.title || 'Oi Agent';
  const opts = {
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: data.badge || '/icons/badge-72.png',
    tag: data.tag || 'ocre-default',
    data: { url: data.url || '/', type: data.type || 'info' },
    requireInteraction: false,
  };
  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil((async () => {
    try {
      const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      const target = all.find(c => c.url && c.url.includes(self.location.origin));
      if (target) { try { await target.focus(); await target.navigate(url); return; } catch (_) {} }
      await self.clients.openWindow(url);
    } catch (e) {}
  })());
});
