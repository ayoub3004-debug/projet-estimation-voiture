/* Drivly — service worker
   Met l'application en cache pour qu'elle fonctionne sans réseau,
   par exemple dans un parking souterrain.

   Versionnement : changez CACHE (ex. "drivly-v2") quand vous modifiez
   manifest.webmanifest ou les icônes, pour que les visiteurs qui ont déjà
   installé l'app reçoivent la nouvelle version au lieu de garder l'ancienne
   indéfiniment. Inutile d'y penser pour index.html : il est toujours repris
   depuis le réseau en premier, la mise à jour est automatique. */
const CACHE = "drivly-v1";
const FICHIERS = [
  "./index.html",
  "./manifest.webmanifest",
  "./icon-192.png",
  "./icon-512.png",
  "./icon-maskable.png",
  "./apple-touch-icon.png"
];

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => Promise.allSettled(FICHIERS.map(f => c.add(f))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", e => {
  e.waitUntil(
    caches.keys()
      .then(cles => Promise.all(cles.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e => {
  const req = e.request;
  if(req.method !== "GET") return;
  const url = new URL(req.url);
  if(url.origin !== location.origin) return;          /* toute ressource externe : réseau direct */

  if(req.mode === "navigate"){
    /* réseau d'abord pour la page, cache en secours hors ligne */
    e.respondWith(
      fetch(req)
        .then(rep => {
          const copie = rep.clone();
          caches.open(CACHE).then(c => c.put("./index.html", copie));
          return rep;
        })
        .catch(() => caches.match("./index.html"))
    );
    return;
  }

  /* cache d'abord pour le reste */
  e.respondWith(
    caches.match(req).then(hit => hit || fetch(req).then(rep => {
      if(rep && rep.status === 200){
        const copie = rep.clone();
        caches.open(CACHE).then(c => c.put(req, copie));
      }
      return rep;
    }).catch(() => hit))
  );
});
