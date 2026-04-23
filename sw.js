// Ocre v18.3 — Service Worker : push + notificationclick + caching minimal.
const SW_VERSION = 'ocre-sw-v18.3.0';

self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
  let data = {};
  try {
    if (event.data) data = event.data.json();
  } catch (e) {
    data = {title: 'Ocre Immo', body: event.data ? event.data.text() : ''};
  }
  const title = data.title || 'Ocre Immo';
  const opts = {
    body: data.body || '',
    icon: data.icon || '/icon-192.png',
    badge: data.badge || '/icon-192.png',
    data: {url: data.url || '/'},
    requireInteraction: false,
    tag: data.tag || 'ocre-rdv',
  };
  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil((async () => {
    const allClients = await clients.matchAll({type: 'window', includeUncontrolled: true});
    for (const c of allClients) {
      if (c.url.includes(self.location.origin)) {
        await c.focus();
        try { c.postMessage({type: 'notif-click', url}); } catch (e) {}
        try { await c.navigate(url); } catch (e) {}
        return;
      }
    }
    await clients.openWindow(url);
  })());
});
