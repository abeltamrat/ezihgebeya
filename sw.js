// EzihGebeya service worker - cache static assets, network-first for pages
const CACHE = 'ezihgebeya-v1';
const STATIC = [
  '/ezihgebeya/assets/css/app.css',
  '/ezihgebeya/assets/js/app.js',
  '/ezihgebeya/assets/icons/icon-192.png',
  '/ezihgebeya/assets/icons/icon-512.png',
  '/ezihgebeya/manifest.json'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys =>
    Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ).then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (STATIC.includes(url.pathname)) {
    e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
  }
});
