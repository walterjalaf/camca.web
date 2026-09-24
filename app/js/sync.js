// ============================================================
// Orquestador de sincronizacion. Dos carriles, en este orden y nunca al reves:
//
//   A. EVENTOS (JSON, unos pocos kB). Son los que le cuentan al supervisor
//      que paso en la jornada. Con 20 segundos de senial en un repecho de la
//      bajada alcanza para vaciar el carril entero, y a partir de ahi el dia
//      ya no vive solo adentro del telefono.
//
//   B. ADJUNTOS (fotos y firmas, cientos de kB). Van despues, de a uno y por
//      trozos reanudables. Si se cortan, lo subido no se pierde.
//
// H8 — Se sincroniza OPORTUNISTAMENTE: apenas hay red, al volver a primer
// plano, y al recuperar conexion. No se espera al wifi de la base: cada
// minuto que la evidencia vive solo en el telefono es riesgo puro, porque un
// telefono roto a las 16:30 se lleva el dia entero.
//
// REGLA QUE NO SE NEGOCIA: de la cola solo se saca lo que el SERVIDOR nombro
// como aceptado o duplicado. Un 503, un 429, un timeout o una respuesta que
// no es JSON NO son un rechazo: son "reintentar". La cola no se toca.
// ============================================================

import * as cola from './cola.js';
import * as api from './api.js';

const TROZO = 64 * 1024;

let corriendo = false;
let intentos = 0;
let temporizador = null;

function avisar(estado, texto) {
  window.dispatchEvent(new CustomEvent('camca:estado', { detail: { estado, texto } }));
}

export async function sincronizarAhora() {
  if (corriendo) return;
  if (!navigator.onLine) { avisar('sin-red', 'sin red'); return; }

  const pendientes = await cola.cuantosPendientes();
  if (pendientes.total === 0) { avisar('en-linea', 'al día'); return; }

  corriendo = true;
  try {
    await carrilEventos();
    await carrilAdjuntos();

    const quedan = await cola.cuantosPendientes();
    if (quedan.total === 0) {
      intentos = 0;
      avisar('en-linea', 'al día');
      // La cola quedo vacia: el servidor ya tiene todo y puede tener MAS
      // (rastro de flota, correcciones del supervisor). La pantalla se entera
      // por este evento; sin el, el chofer ve su propia version congelada.
      window.dispatchEvent(new CustomEvent('camca:sincronizado'));
    } else {
      avisar('enviando', quedan.total + ' sin enviar');
      programarReintento();
    }
  } catch (e) {
    // Nada de esto toca la cola. Solo se reintenta mas tarde.
    if (e.esAuth) {
      avisar('error', 'hay que entrar de nuevo');
    } else {
      avisar(e.esTemporal ? 'enviando' : 'error', e.esTemporal ? 'reintentando' : 'sin enviar');
      programarReintento();
    }
  } finally {
    corriendo = false;
  }
}

// --- Carril A: eventos ---
async function carrilEventos() {
  let lote = await cola.pendientes();
  while (lote.length) {
    const tanda = lote.slice(0, 200);
    const cuerpo = tanda.map((r) => ({
      uuid: r.uuid,
      tipo: r.tipo,
      datos: r.datos,
      jornada_id: r.jornada_id,
      sitio_id: r.sitio_id,
      parada_orden: r.parada_orden,
      ronda: r.ronda ?? 0,
      ocurrido_ms: r.ocurrido_ms,
    }));

    const res = await api.enviarLote(cuerpo);

    // Solo se confirma lo que el servidor nombro.
    await cola.confirmar(res.confirmados || []);

    // Lo rechazado NO se borra: se anota el motivo y se deja en la cola para
    // que alguien lo pueda ver. Descartarlo seria perder evidencia en silencio.
    for (const r of res.rechazados || []) {
      if (r.uuid) await cola.marcarIntento(r.uuid, r.motivo);
    }

    // Si una tanda no avanzo nada, se corta para no quedar en bucle.
    if (!(res.confirmados || []).length) break;
    lote = await cola.pendientes();
  }
}

// --- Carril B: adjuntos ---
async function carrilAdjuntos() {
  const adjuntos = await cola.adjuntosPendientes();

  for (const a of adjuntos) {
    if (!a.blob) {
      // Sin blob no hay nada que subir: el registro quedo huerfano.
      await cola.marcarIntento(a.uuid, 'sin contenido');
      continue;
    }

    let offset = a.offset || 0;
    let vueltas = 0;

    while (offset < a.bytes) {
      // Tope de vueltas por adjunto: si el servidor sigue pidiendo reubicar,
      // se deja para el proximo ciclo en vez de trabar todo el carril.
      if (++vueltas > 200) break;

      const trozo = a.blob.slice(offset, Math.min(offset + TROZO, a.bytes));
      const res = await api.pedir('/sync/adjunto', {
        metodo: 'POST',
        cuerpo: trozo,
        timeout: 60000,
        headers: {
          'X-Camca-Adjunto': JSON.stringify({
            uuid: a.uuid,
            tipo: a.tipo,
            bytes: a.bytes,
            offset,
            sha256: a.sha256,
            jornada_id: a.jornada_id,
            parada_orden: a.parada_orden,
          }),
          'Content-Type': 'application/octet-stream',
        },
      });

      // EL OFFSET AUTORITATIVO ES EL DEL SERVIDOR, siempre. Si el cliente
      // insistiera con el suyo, un reintento a mitad de trozo duplicaria
      // bytes y el sha256 final no cerraria.
      offset = res.offset;
      await cola.actualizarOffset(a.uuid, offset);

      if (res.completa) {
        await cola.confirmar([a.uuid]);
        break;
      }
    }
  }
}

function programarReintento() {
  clearTimeout(temporizador);
  const base = Math.min(5000 * 2 ** intentos++, 300000);
  // El jitter no es un detalle: sin el, seis telefonos que se quedaron sin
  // senial al mismo tiempo reintentan al mismo milisegundo cuando vuelve, y
  // reproducen exactamente el pico que tumbo al servidor.
  temporizador = setTimeout(() => sincronizarAhora(), base * (0.5 + Math.random()));
}

export function arrancarSync() {
  // Al recuperar conexion.
  window.addEventListener('online', () => { intentos = 0; sincronizarAhora(); });
  window.addEventListener('offline', () => avisar('sin-red', 'sin red'));

  // Al volver a primer plano. En iOS no hay Background Sync, asi que este es
  // EL momento en que la evidencia sale del telefono: el chofer abre la app y
  // se dispara. Hay que aprovecharlo.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') sincronizarAhora();
  });

  // Cada vez que se encola algo nuevo.
  window.addEventListener('camca:cola', (e) => {
    if (e.detail?.total > 0 && navigator.onLine && !corriendo) sincronizarAhora();
  });

  // Red de seguridad: cada 2 minutos, por si todos los disparos anteriores
  // fallaron o el navegador se comio algun evento.
  setInterval(() => { if (navigator.onLine) sincronizarAhora(); }, 120000);

  sincronizarAhora();
}
