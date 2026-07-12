// EzihGebeya service worker - cache static assets only.
// Authenticated/account/API responses are always network-only and never cached.
// Bump this version when deployment behavior changes. Static assets are fetched
// network-first so a changed CSS/JS deploy does not strand users on stale files.
const SW_VERSION = '2026-07-12-asset-refresh';
const CACHE = 'ezihgebeya-' + SW_VERSION;
const BASE = location.pathname.startsWith('/ezihgebeya/') ? '/ezihgebeya' : '';
const stripBase = path => BASE && path.startsWith(BASE + '/') ? path.slice(BASE.length) : path;
const STATIC = [
  BASE + '/assets/css/app.css',
  BASE + '/assets/js/app.js',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png',
  BASE + '/manifest.webmanifest',
  BASE + '/offline'
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

  const path = stripBase(url.pathname);
  const networkOnlyPrefixes = [
    '/api',
    '/admin',
    '/vendor',
    '/account',
    '/cart',
    '/checkout',
    '/notifications',
    '/inquiries',
    '/login',
    '/logout',
    '/register',
    '/verify',
    '/forgot-password',
    '/support',
    '/web-vitals'
  ];
  const hasAuthSignal = e.request.headers.has('Authorization') || e.request.headers.has('Cookie');
  if (hasAuthSignal || networkOnlyPrefixes.some(prefix => path === prefix || path.startsWith(prefix + '/'))) {
    e.respondWith(fetch(e.request));
    return;
  }

  if (STATIC.includes(url.pathname)) {
    e.respondWith(
      fetch(e.request)
        .then(response => {
          const copy = response.clone();
          caches.open(CACHE).then(cache => cache.put(e.request, copy));
          return response;
        })
        .catch(() => caches.match(e.request))
    );
    return;
  }

  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => caches.match(BASE + '/offline')));
  }
});
