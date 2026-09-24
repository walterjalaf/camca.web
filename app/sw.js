// ============================================================
// Service worker de la app de campo. Propio, sin Workbox.
//
// Su unico trabajo es que la app ABRA sin señal, con sus estilos y sus
// fuentes. Si abre en Times New Roman, el precache fallo.
//
// Reglas que vienen de fallas concretas:
//
//  1. PRECACHE ITEM POR ITEM, no cache.addAll(). addAll es atomico: un solo
//     404 tira abajo la instalacion entera y la app queda SIN NADA cacheado.
//     Mejor que falte un archivo a que no funcione nada.
//
//  2. Solo se cachea una respuesta que sea REALMENTE nuestra. Un 508 de
//     LiteSpeed en el pico de las 20:00, un 401, o el portal cautivo de la
//     obra son respuestas "exitosas" para fetch: si se cachearan encima del
//     shell bueno, la app quedaria rota hasta reinstalarla.
//
//  3. NADA de skipWaiting automatico. Cambiar la app abajo de los pies del
//     chofer a mitad de jornada es la forma mas rapida de que pierda el hilo.
//     La version nueva espera; la pagina decide cuando activarla.
//
//  4. La lista de precache sale de build.json, que escribe scripts/postbuild.mjs
//     recorriendo el build REAL. Scrapear el HTML con una regex no ve los
//     chunks de code-splitting ni los workers, y deja agujeros que recien se
//     descubren sin señal.
// ============================================================

const VERSION_FALLBACK = 'dev';

let BUILD = VERSION_FALLBACK;
let CACHE_SHELL = 'camca-shell-' + BUILD;

const OFFLINE = '/app/offline.html';

// ------------------------------------------------------------------
// Instalacion
// ------------------------------------------------------------------
self.addEventListener('install', (ev) => {
  ev.waitUntil((async () => {
    let lista = [];
    try {
      const res = await fetch('/app/build.json', { cache: 'no-store' });
      const manifiesto = await res.json();
      BUILD = manifiesto.version || VERSION_FALLBACK;
      CACHE_SHELL = 'camca-shell-' + BUILD;
      lista = manifiesto.precache || [];
    } catch (e) {
      console.warn('[sw] no se pudo leer build.json', e);
    }

    // offline.html primero y aparte: es el ultimo recurso, tiene que estar
    // aunque todo lo demas falle.
    const cache = await caches.open(CACHE_SHELL);
    try { await cache.add(new Request(OFFLINE, { cache: 'reload' })); } catch {}

    // Item por item, tolerando fallas individuales.
    let ok = 0, fallos = 0;
    await Promise.all(lista.map(async (url) => {
      try {
        const res = await fetch(url, { cache: 'reload' });
        if (res.ok && res.status === 200) { await cache.put(url, res.clone()); ok++; }
        else fallos++;
      } catch { fallos++; }
    }));

    // Las paginas de la app, que no estan en build.json (son HTML generado).
    await Promise.all(['/app/', '/app/campo/'].map(async (url) => {
      try {
        const res = await fetch(url, { cache: 'reload' });
        if (res.ok) { await cache.put(url, res.clone()); ok++; }
      } catch { fallos++; }
    }));

    console.log('[sw] precache ' + BUILD + ': ' + ok + ' ok, ' + fallos + ' fallos');
    // NO hay skipWaiting: la version nueva espera a que la pagina la habilite.
  })());
});

// ------------------------------------------------------------------
// Activacion
// ------------------------------------------------------------------
self.addEventListener('activate', (ev) => {
  ev.waitUntil((async () => {
    // Se borran SOLO las caches de shell de builds anteriores. IndexedDB, que
    // es donde vive la cola de salida, no se toca nunca desde aca.
    const nombres = await caches.keys();
    await Promise.all(
      nombres
        .filter((n) => n.startsWith('camca-shell-') && n !== CACHE_SHELL)
        .map((n) => caches.delete(n))
    );
    await self.clients.claim();
  })());
});

// La pagina pide activar la version nueva cuando considera que es seguro
// (sin jornada abierta). Activar una version nueva NO borra la cola: vive en
// IndexedDB y sobrevive a activate().
self.addEventListener('message', (ev) => {
  if (ev.data === 'activar-ahora') self.skipWaiting();
});

// ------------------------------------------------------------------
// Fetch
// ------------------------------------------------------------------
self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // La API NUNCA se cachea ni se intercepta: sus respuestas son estado, no
  // contenido, y servir una respuesta vieja le mentiria al chofer sobre si su
  // trabajo llego.
  if (url.pathname.startsWith('/api/')) return;

  // Se atiende /app/ y TAMBIEN /_astro/ y /fonts/, que es donde Astro pone el
  // CSS con hash y donde viven las fuentes self-hosted.
  //
  // Esto costo un bug real: precacheabamos esos archivos pero el manejador
  // solo miraba /app/, asi que sin servidor el navegador los pedia a la red,
  // fallaba, y la app abria en Times New Roman y sin layout. Los datos estaban
  // todos; parecia rota igual. Precachear y NO servir es peor que no
  // precachear, porque da una falsa sensacion de que funciona offline.
  const MANEJADOS = ['/app/', '/_astro/', '/fonts/'];
  if (!MANEJADOS.some((p) => url.pathname.startsWith(p))) return;

  ev.respondWith(responder(req, url));
});

async function responder(req, url) {
  const cache = await caches.open(CACHE_SHELL);

  // Navegacion: primero la red con un timeout corto (para tomar una version
  // nueva si la hay), pero cayendo rapido al cache. Arriba del cerro la red
  // "existe" pero no responde, y esperar 30 s es inaceptable.
  if (req.mode === 'navigate') {
    const guardada = await cache.match(url.pathname) || await cache.match('/app/');
    try {
      const res = await conTimeout(fetch(req), 2500);
      if (esNuestra(res)) {
        cache.put(url.pathname, res.clone());
        return res;
      }
      // 508, 401, portal cautivo: se descarta y se sirve lo bueno que ya hay.
      if (guardada) return guardada;
      return res;
    } catch {
      return guardada || (await cache.match(OFFLINE)) ||
        new Response('Sin conexión', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }
  }

  // Recursos: cache primero. Son inmutables dentro de un build.
  const guardado = await cache.match(req);
  if (guardado) return guardado;

  try {
    const res = await fetch(req);
    if (esNuestra(res)) cache.put(req, res.clone());
    return res;
  } catch {
    return new Response('', { status: 504 });
  }
}

/**
 * Una respuesta es NUESTRA solo si es un 200 propio del mismo origen.
 * Sin este filtro, el 508 del hosting a las 20:00 o la pagina del portal
 * cautivo de la obra se escribirian encima del shell bueno.
 */
function esNuestra(res) {
  return res && res.ok && res.status === 200 && (res.type === 'basic' || res.type === 'default');
}

function conTimeout(promesa, ms) {
  return new Promise((resolver, rechazar) => {
    const t = setTimeout(() => rechazar(new Error('timeout')), ms);
    promesa.then((v) => { clearTimeout(t); resolver(v); }, (e) => { clearTimeout(t); rechazar(e); });
  });
}
