// ============================================================
// LA COLA DE SALIDA. Es la pieza mas importante de la app de campo.
//
// INVARIANTE (R1) — verificada por scripts/guard.mjs y por la prueba de
// aceptacion de la Fase 0:
//
//     Un registro de la cola SOLO se borra cuando el SERVIDOR confirmo su
//     uuid. Nunca por antiguedad, nunca por falta de espacio, nunca por un
//     fallo de sesion, nunca al cerrar sesion, nunca al actualizar la app.
//
// Por que importa tanto: el prototipo (panel_chofer.html, lineas 235-237)
// borraba en cada arranque TODAS las claves fram_* que no fueran las de hoy,
// sin preguntar si se habian sincronizado. Un chofer que cargaba ocho paradas
// sin senial y volvia al dia siguiente perdia el dia entero y no se enteraba.
//
// Este modulo NO IMPORTA sesion.js, a proposito y verificado por el guard: si
// dependiera de la sesion, un 401 podria terminar tocando la cola. Un PIN
// vencido tiene que pedir el PIN otra vez, no borrar trabajo.
// ============================================================

import { abrir, guardar, todos, contar, leer, borrar as borrarClave } from './db.js';

const ALMACEN = 'cola';
const ADJUNTOS = 'adjuntos';

/** Tiempo que se conserva un registro YA confirmado, como red de seguridad
 *  por si hay que reconstruir algo. No es un criterio de borrado: lo que no
 *  esta confirmado no se toca aunque tenga un anio. */
const DIAS_RETENCION_CONFIRMADOS = 30;

function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  // Safari viejo no tiene randomUUID.
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

let _seq = 0;

/**
 * Encola un evento. Devuelve el uuid, que es la clave de idempotencia: el
 * servidor acepta el mismo uuid muchas veces y lo cuenta una sola.
 */
export async function encolar(tipo, datos, opciones = {}) {
  const registro = {
    uuid: uuid(),
    tipo,
    datos,
    jornada_id: opciones.jornada_id ?? null,
    sitio_id: opciones.sitio_id ?? null,
    parada_orden: opciones.parada_orden ?? null,
    // Cuantas veces se reabrio la parada antes de este evento. Es parte de la
    // clave de negocio del servidor: sin ella, cerrar una parada reabierta se
    // tomaba como duplicado del primer cierre y se perdia.
    ronda: opciones.ronda ?? 0,
    // Hora del telefono. El servidor guarda ademas su propio desfase para que
    // un reloj corrido se pueda detectar en vez de descubrirse en una auditoria.
    ocurrido_ms: Date.now(),
    // Reloj monotono: sobrevive a que alguien cambie la hora del telefono.
    monotono: Math.round(performance.now()),
    estado: 'pendiente',
    intentos: 0,
    ultimo_error: null,
    seq: ++_seq + Date.now() * 1000,
  };
  await guardar(ALMACEN, registro);
  avisar();
  return registro.uuid;
}

/** Adjunto pesado (foto o firma). Viaja por el carril lento, despues de los eventos. */
export async function encolarAdjunto(tipo, blob, meta = {}) {
  const registro = {
    uuid: uuid(),
    tipo,
    blob,
    bytes: blob.size,
    mime: blob.type || 'application/octet-stream',
    jornada_id: meta.jornada_id ?? null,
    parada_orden: meta.parada_orden ?? null,
    sha256: meta.sha256 ?? null,
    offset: 0,
    estado: 'pendiente',
    intentos: 0,
    creado_ms: Date.now(),
  };
  await guardar(ADJUNTOS, registro);
  avisar();
  return registro.uuid;
}

export async function pendientes() {
  return todos(ALMACEN, 'estado', 'pendiente');
}

export async function adjuntosPendientes() {
  return todos(ADJUNTOS, 'estado', 'pendiente');
}

/**
 * Que paradas de una jornada tienen trabajo TODAVIA adentro del telefono.
 *
 * Devuelve un Set de numeros de parada. Es lo que permite que la pantalla
 * distinga "hecho y a salvo en el servidor" de "hecho, pero si este telefono
 * se cae al canal esto no existio". Hasta ahora las dos cosas se pintaban del
 * mismo verde, que es exactamente la mentira que no nos podemos permitir.
 *
 * Mira eventos Y adjuntos: una parada con la foto sin subir no esta completa
 * aunque el evento liviano ya haya viajado.
 */
export async function ordenesPendientes(jornadaId = null) {
  const [ev, ad] = await Promise.all([pendientes(), adjuntosPendientes()]);
  const s = new Set();
  for (const r of ev.concat(ad)) {
    if (r.parada_orden === null || r.parada_orden === undefined) continue;
    // Un registro sin jornada_id cuenta para la jornada que se este mirando:
    // es de una version vieja del registro y no se puede descartar.
    if (jornadaId !== null && r.jornada_id !== null && r.jornada_id !== undefined
        && r.jornada_id !== jornadaId) continue;
    s.add(Number(r.parada_orden));
  }
  return s;
}

export async function cuantosPendientes() {
  const [e, a] = await Promise.all([
    contar(ALMACEN, 'estado', 'pendiente'),
    contar(ADJUNTOS, 'estado', 'pendiente'),
  ]);
  return { eventos: e, adjuntos: a, total: e + a };
}

/**
 * Marca como confirmados los uuid que el SERVIDOR acepto.
 *
 * Es el UNICO camino por el que un registro deja de estar pendiente. No hay
 * otra funcion que lo haga, y eso es deliberado.
 */
export async function confirmar(uuids) {
  if (!uuids || !uuids.length) return;
  const db = await abrir();
  const t = db.transaction([ALMACEN, ADJUNTOS], 'readwrite');
  const ce = t.objectStore(ALMACEN);
  const ca = t.objectStore(ADJUNTOS);

  for (const u of uuids) {
    for (const st of [ce, ca]) {
      const req = st.get(u);
      req.onsuccess = () => {
        const r = req.result;
        if (!r || r.estado === 'confirmado') return;
        r.estado = 'confirmado';
        r.confirmado_ms = Date.now();
        // Al confirmar un adjunto se suelta el blob: es lo unico que ocupa
        // espacio de verdad, y ya esta a salvo en el servidor.
        if (r.blob) delete r.blob;
        st.put(r);
      };
    }
  }

  await new Promise((res, rej) => { t.oncomplete = res; t.onerror = () => rej(t.error); });
  avisar();
}

/**
 * Registra un intento fallido. NO borra ni descarta: sube el contador y anota
 * el motivo. Un registro puede fallar cien veces y sigue en la cola.
 */
export async function marcarIntento(uuid, error) {
  for (const almacen of [ALMACEN, ADJUNTOS]) {
    const r = await leer(almacen, uuid);
    if (!r) continue;
    r.intentos = (r.intentos || 0) + 1;
    r.ultimo_error = String(error || '').slice(0, 200);
    r.ultimo_intento_ms = Date.now();
    await guardar(almacen, r);
    return;
  }
}

/** Avance de la subida de un adjunto. El offset lo manda el SERVIDOR: si lo
 *  decidiera el cliente, un reintento a mitad de trozo duplica bytes y el
 *  sha256 final no cierra. */
export async function actualizarOffset(uuid, offset) {
  const r = await leer(ADJUNTOS, uuid);
  if (!r) return;
  r.offset = offset;
  await guardar(ADJUNTOS, r);
}

/**
 * Limpieza. Solo toca registros CONFIRMADOS y con mas de 30 dias.
 *
 * No hay ninguna condicion que borre algo pendiente. Si el telefono se queda
 * sin espacio, la app deja de aceptar fotos nuevas y lo avisa — pero no se
 * come la evidencia que ya saco.
 */
export async function limpiar() {
  const corte = Date.now() - DIAS_RETENCION_CONFIRMADOS * 86400000;
  let borrados = 0;

  for (const almacen of [ALMACEN, ADJUNTOS]) {
    const confirmados = await todos(almacen, 'estado', 'confirmado');
    for (const r of confirmados) {
      if ((r.confirmado_ms ?? 0) < corte) {
        await borrarClave(almacen, r.uuid);
        borrados++;
      }
    }
  }
  return borrados;
}

/**
 * Exporta la cola completa a un archivo.
 *
 * Es la salida de emergencia: si la base se corrompe, si el telefono se va a
 * reparar, o si la sincronizacion no anda y hay que entregar el dia igual.
 * Un dia de trabajo nunca queda atrapado sin forma de sacarlo.
 */
export async function exportar() {
  const [eventos, adjuntos] = await Promise.all([
    todos(ALMACEN),
    todos(ADJUNTOS),
  ]);
  return {
    exportado_ms: Date.now(),
    version: 1,
    eventos,
    // Los blobs no entran en el JSON; se listan para saber que falta.
    adjuntos: adjuntos.map(({ blob, ...resto }) => resto),
  };
}

// --- Aviso a la interfaz ---
// La app entera escucha esto para mostrar "N sin enviar". El aviso no se puede
// descartar mientras haya pendientes: es la unica senial de que el trabajo
// todavia vive solo adentro del telefono.
function avisar() {
  cuantosPendientes().then((n) => {
    window.dispatchEvent(new CustomEvent('camca:cola', { detail: n }));
  }).catch(() => {});
}

export { uuid };
