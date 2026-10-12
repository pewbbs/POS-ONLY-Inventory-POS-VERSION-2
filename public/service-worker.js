'use strict';
const CACHE = 'posonly-offline-shell-d99f6c4d3962';
const ASSETS = ['/offline.html', '/css/workspace.css', '/css/fonts.css', '/css/offline.css', '/js/offline-store.js', '/js/offline-shell.js', '/images/favicon.svg', '/fonts/anton-400.ttf', '/fonts/manrope-400.ttf', '/fonts/manrope-500.ttf', '/fonts/manrope-600.ttf', '/fonts/manrope-700.ttf', '/fonts/manrope-800.ttf'];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS.map(path => new Request(path, {cache: 'reload'})))).then(() => self.skipWaiting())); });
self.addEventListener('activate', event => { event.waitUntil(Promise.all([caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('posonly-offline-shell-') && key !== CACHE).map(key => caches.delete(key)))), self.clients.claim()])); });
async function forgetSignedOutCatalog() {
    await new Promise((resolve, reject) => {
        const request = indexedDB.open('posonly-offline-v1', 1);
        request.onupgradeneeded = () => ['device', 'snapshots', 'sales', 'locks'].forEach(name => request.result.createObjectStore(name, {keyPath: 'id'}));
        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            const db = request.result;
            const tx = db.transaction(['device', 'snapshots'], 'readwrite');
            tx.objectStore('device').delete('active'); tx.objectStore('snapshots').clear();
            tx.oncomplete = () => { db.close(); resolve(); };
            tx.onabort = tx.onerror = () => { db.close(); reject(tx.error); };
        };
    });
    const clients = await self.clients.matchAll();
    clients.forEach(client => client.postMessage({type: 'posonly-signed-out'}));
}
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (url.origin === self.location.origin && url.pathname === '/logout' && event.request.method === 'POST') {
        event.respondWith(forgetSignedOutCatalog().then(() => fetch(event.request)));
        return;
    }
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) { return; }
    if (event.request.mode === 'navigate' && (url.pathname === '/app' || url.pathname.startsWith('/app/'))) {
        event.respondWith(fetch(event.request).catch(() => caches.open(CACHE).then(cache => cache.match('/offline.html'))));
        return;
    }
    if (ASSETS.includes(url.pathname)) {
        event.respondWith(caches.open(CACHE).then(cache => cache.match(url.pathname)).then(saved => saved || fetch(event.request)));
    }
});
