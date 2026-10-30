const CACHE_NAME = 'goodlucks-public-shell-v2';
const PUBLIC_SHELL = [
  '/', '/index.html', '/favicon.svg', '/favicon.png', '/apple-touch-icon.png',
  '/icon-192.png', '/icon-512.png', '/site.webmanifest',
  '/assets/index-third-upgrade.js', '/assets/index-mRWbV6Ft.css', '/assets/goodlucks-pwa.js', '/assets/goodlucks-page-enhancements.js'
];
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(PUBLIC_SHELL.map(path => new Request(path, {cache:'reload'})))).catch(() => {}));
  self.skipWaiting();
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('goodlucks-public-shell-') && key !== CACHE_NAME).map(key => caches.delete(key)))));
  self.clients.claim();
});
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const path = url.pathname;
  if (path.startsWith('/api/') || path.startsWith('/admin') || path.startsWith('/receipt') || path.startsWith('/checkout') || path.startsWith('/review-request') || path.endsWith('.php')) return;
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(async () => (await caches.match('/index.html')) || Response.error()));
    return;
  }
  if (PUBLIC_SHELL.includes(path)) {
    event.respondWith(caches.match(req).then(cached => cached || fetch(req).then(response => {
      if (response && response.ok) { const copy = response.clone(); caches.open(CACHE_NAME).then(cache => cache.put(req, copy)); }
      return response;
    })));
  }
});
