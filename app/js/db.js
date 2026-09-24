// ============================================================
// IndexedDB de la app de campo. Envoltorio propio, sin librerias.
//
// Vive en /app/js/ y no en src/ a proposito: el service worker precachea por
// URL y el bundler de Astro le pondria un hash distinto en cada build, con lo
// que el precache apuntaria a archivos que ya no existen.
//
// Almacenes:
//   meta      clave -> valor  (sesion, verificador offline del PIN, config)
//   cola      la COLA DE SALIDA. Ver cola.js: es append-only y es sagrada.
//   adjuntos  blobs (fotos, firmas) pendientes de subir
//   jornada   la hoja de ruta descargada y su estado local
// ============================================================

const NOMBRE = 'camca-campo';
const VERSION = 1;

let _db = null;

/** Se llama cuando la base no se puede abrir. La app lo usa para ofrecer
 *  exportar la cola a un archivo ANTES de hacer cualquier otra cosa. */
let alFallar = null;
export function siFalla(fn) { alFallar = fn; }

export function abrir() {
  if (_db) return Promise.resolve(_db);

  return new Promise((resolve, reject) => {
    let req;
    try {
      req = indexedDB.open(NOMBRE, VERSION);
    } catch (e) {
      // Safari en modo privado tira aca mismo.
      if (alFallar) alFallar(e);
      reject(e);
      return;
    }

    req.onupgradeneeded = (ev) => {
      const db = req.result;

      if (!db.objectStoreNames.contains('meta')) {
        db.createObjectStore('meta');
      }

      if (!db.objectStoreNames.contains('cola')) {
        const cola = db.createObjectStore('cola', { keyPath: 'uuid' });
        cola.createIndex('estado', 'estado');
        cola.createIndex('jornada', 'jornada_id');
        cola.createIndex('orden', 'seq');
      }

      if (!db.objectStoreNames.contains('adjuntos')) {
        const adj = db.createObjectStore('adjuntos', { keyPath: 'uuid' });
        adj.createIndex('estado', 'estado');
        adj.createIndex('jornada', 'jornada_id');
      }

      if (!db.objectStoreNames.contains('jornada')) {
        db.createObjectStore('jornada', { keyPath: 'fecha' });
      }

      if (ev.oldVersion === 0) {
        // Primera vez: nada que migrar.
      }
    };

    // onblocked pasa cuando hay otra pestania abierta con una version vieja.
    // Sin manejarlo, la promesa nunca resuelve y la app queda en "Abriendo...".
    req.onblocked = () => {
      const e = new Error('Hay otra pestania de la app abierta. Cerrala y volve a intentar.');
      if (alFallar) alFallar(e);
      reject(e);
    };

    req.onsuccess = () => {
      _db = req.result;
      _db.onversionchange = () => { _db.close(); _db = null; };
      resolve(_db);
    };

    req.onerror = () => {
      if (alFallar) alFallar(req.error);
      reject(req.error);
    };
  });
}

function tx(almacenes, modo) {
  return abrir().then((db) => db.transaction(almacenes, modo));
}

function comoPromesa(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

export async function leer(almacen, clave) {
  const t = await tx([almacen], 'readonly');
  return comoPromesa(t.objectStore(almacen).get(clave));
}

export async function guardar(almacen, valor, clave) {
  const t = await tx([almacen], 'readwrite');
  const st = t.objectStore(almacen);
  const req = clave === undefined ? st.put(valor) : st.put(valor, clave);
  const r = await comoPromesa(req);
  await new Promise((res, rej) => { t.oncomplete = res; t.onerror = () => rej(t.error); });
  return r;
}

export async function borrar(almacen, clave) {
  const t = await tx([almacen], 'readwrite');
  await comoPromesa(t.objectStore(almacen).delete(clave));
  await new Promise((res, rej) => { t.oncomplete = res; t.onerror = () => rej(t.error); });
}

export async function todos(almacen, indice, valor) {
  const t = await tx([almacen], 'readonly');
  const st = t.objectStore(almacen);
  const origen = indice ? st.index(indice) : st;
  return comoPromesa(valor === undefined ? origen.getAll() : origen.getAll(valor));
}

export async function contar(almacen, indice, valor) {
  const t = await tx([almacen], 'readonly');
  const st = t.objectStore(almacen);
  const origen = indice ? st.index(indice) : st;
  return comoPromesa(valor === undefined ? origen.count() : origen.count(valor));
}

/**
 * Pide almacenamiento PERSISTENTE.
 *
 * En modo best-effort (el default) el navegador puede desalojar IndexedDB bajo
 * presion de espacio, y un telefono lleno de fotos ES ese caso. Se llevaria la
 * cola de un dia entero sin avisar.
 *
 * Devuelve false si no se concedio: la app lo muestra como advertencia
 * permanente y el supervisor lo ve, porque cambia el riesgo real del turno.
 */
export async function pedirPersistencia() {
  try {
    if (!navigator.storage || !navigator.storage.persist) return null;
    if (await navigator.storage.persisted()) return true;
    return await navigator.storage.persist();
  } catch {
    return null;
  }
}

/** Espacio disponible, para frenar la captura de fotos antes de quedarse sin lugar. */
export async function espacio() {
  try {
    if (!navigator.storage || !navigator.storage.estimate) return null;
    const e = await navigator.storage.estimate();
    return { usado: e.usage ?? 0, cuota: e.quota ?? 0, libre: (e.quota ?? 0) - (e.usage ?? 0) };
  } catch {
    return null;
  }
}
