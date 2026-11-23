/**
 * Campus Event Hub — service worker (makes the site installable on phones:
 * "Add to Home screen" / "Install app"; registered by assets/js/app.js).
 * Pages always come from the network — nothing personal is cached. Only
 * when the phone is offline does a page show offline.html instead.
 */
const CACHE = 'ceh-app-v3'; // change it when offline.html or the icon changes (v3: new logo)
const OFFLINE_PAGE = 'offline.html';

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_PAGE, 'assets/images/app/icon-192.png'])));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return; // styles, images, API calls: straight to the network
  event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_PAGE)));
});
