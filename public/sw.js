// Service worker du portail : rend l'application installable et affiche une page
// « hors ligne » sans réseau. Les pages du portail ne sont JAMAIS mises en cache
// (elles contiennent tes données) : seuls les fichiers de style, scripts et icônes le sont.
var CACHE = 'portail-v1';
var HORS_LIGNE = '/offline.html';
var FICHIERS = [HORS_LIGNE, '/assets/style.css', '/assets/icons/icon-192.png', '/assets/favicon.svg'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(FICHIERS); }));
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (cles) {
      return Promise.all(cles.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET') return;
  var url = new URL(r.url);
  if (url.origin !== self.location.origin) return;

  // Pages : toujours le réseau ; la page « hors ligne » seulement si le réseau ne répond pas.
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).catch(function () { return caches.match(HORS_LIGNE); }));
    return;
  }
  // Fichiers statiques : copie en cache tout de suite, mise à jour en arrière-plan.
  if (url.pathname.indexOf('/assets/') === 0) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(r).then(function (copie) {
        var reseau = fetch(r).then(function (rep) {
          if (rep.ok) c.put(r, rep.clone());
          return rep;
        }).catch(function () { return copie; });
        return copie || reseau;
      });
    }));
  }
});
