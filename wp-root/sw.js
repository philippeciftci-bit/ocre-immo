// M_OCRE_PWA_UNIFIE — Service Worker partage hub Ocre
// Strategy : network-first sur paths /oi-* (toujours fresh), cache-first assets statiques.
// Push handler reutilise pattern M88 Oi Agent.

const CACHE_NAME = 'ocre-pwa-v1';
const PRECACHE_URLS = [
  '/',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-180.png'
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(c => c.addAll(PRECACHE_URLS).catch(()=>{}))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = event.request.url;
  // Skip non-GET et cross-origin (Unsplash CDN)
  if (event.request.method !== 'GET') return;
  if (url.match(/\/oi-(agent|scan|book|demande|capture|estimer)/)) {
    // Network-first pour outils
    event.respondWith(
      fetch(event.request).catch(() => caches.match(event.request) || caches.match('/'))
    );
    return;
  }
  if (url.startsWith(self.location.origin)) {
    // Cache-first pour assets locaux (icons, fonts CSS WP)
    event.respondWith(
      caches.match(event.request).then(cached => cached || fetch(event.request).then(resp => {
        if (resp && resp.status === 200) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then(c => c.put(event.request, clone)).catch(()=>{});
        }
        return resp;
      }).catch(() => caches.match('/')))
    );
  }
});

// Push handler (M88 reutilise)
self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch (e) { data = { title: 'Ocre', body: event.data.text() }; }
  event.waitUntil(self.registration.showNotification(data.title || 'Ocre', {
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: '/icons/badge-72.png',
    tag: data.tag || 'ocre-default',
    data: { url: data.url || '/' }
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(self.clients.openWindow(url));
});
