// M/2026/05/16/01 — Defensive SW hotfix.
// Goal: prevent infinite spinner/reload loops on tenant subdomains after signup/login.
// No auto refresh, no forced reload, no cache-driven navigation.

const SW_VERSION = 'ocre-sw-safe-2026-05-16';

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    } catch (_) {}

    try {
      self.skipWaiting();
    } catch (_) {}
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    } catch (_) {}

    try {
      if (self.registration && self.registration.navigationPreload) {
        await self.registration.navigationPreload.disable();
      }
    } catch (_) {}

    try {
      await self.clients.claim();
    } catch (_) {}
  })());
});

// Strict network passthrough.
// Never force reloads or update flows from the SW.
self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});

// Defensive push handlers.
self.addEventListener('push', (event) => {
  if (!event.data) return;

  let data = {};

  try {
    data = event.data.json();
  } catch (_) {
    try {
      data = { title: 'Oi Agent', body: event.data.text() };
    } catch (__){ }
  }

  event.waitUntil(
    self.registration.showNotification(data.title || 'Oi Agent', {
      body: data.body || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/badge-72.png',
      data: { url: data.url || '/' }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const url = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil((async () => {
    try {
      const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      for (const client of clients) {
        if (client && 'focus' in client) {
          try {
            await client.focus();
            if ('navigate' in client) {
              await client.navigate(url);
            }
            return;
          } catch (_) {}
        }
      }

      if (self.clients.openWindow) {
        await self.clients.openWindow(url);
      }
    } catch (_) {}
  })());
});
