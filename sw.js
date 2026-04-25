// V18.44 — SW auto-update. Ne plus skipWaiting() automatiquement : le nouveau SW
// attend dans l'état "waiting" jusqu'à réception du message {type:'SKIP_WAITING'}
// envoyé par le client après tap "Actualiser" ou auto-reload idle.
const SW_VERSION = 'ocre-sw-v53-cachebust';

self.addEventListener('install', e => {
  // V52.3 — purge tous caches existants à l'install pour forcer fresh fetch.
  e.waitUntil(
    (async () => {
      try {
        const names = await caches.keys();
        await Promise.all(names.map(n => caches.delete(n)));
      } catch (e) {}
      try { self.skipWaiting(); } catch (e) {}
    })()
  );
});
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

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
