// ============================================================
// Cliente HTTP de la app de campo.
//
// Todo lo que sale del telefono pasa por aca, y aca viven las reglas que
// evitan que el pico de las 20:00 tumbe el hosting compartido:
//
//  - UN solo pedido en vuelo por vez. Seis choferes bajando del cerro con 24
//    evidencias cada uno, todos disparando en paralelo, es la receta del
//    error 508 en un plan compartido.
//  - Backoff exponencial CON JITTER. Sin el jitter, los seis reintentan en
//    lockstep y reproducen exactamente el pico que causo el error.
//  - Un 503 o un 429 NO son un rechazo: son "reintentar". La cola no se toca.
//  - Una respuesta que no es JSON del sobre esperado tampoco es exito: el
//    bug historico del formulario de contacto fue tomar un 200 con HTML por
//    un envio exitoso.
// ============================================================

import * as sesion from './sesion.js';

const BASE = '/api/v1';

let enVuelo = null;

export class ErrorApi extends Error {
  constructor(estado, codigo, mensaje, campos) {
    super(mensaje || 'Error');
    this.estado = estado;
    this.codigo = codigo || 'DESCONOCIDO';
    this.campos = campos || null;
  }
  /** Reintentable: el servidor no dijo que no, dijo "ahora no". */
  get esTemporal() {
    return this.estado === 0 || this.estado === 408 || this.estado === 429 ||
           this.estado === 502 || this.estado === 503 || this.estado === 504;
  }
  get esAuth() {
    return this.estado === 401;
  }
}

async function crudo(ruta, opciones = {}) {
  const tok = opciones.sinSesion ? null : await sesion.token();

  const cabeceras = { 'Accept': 'application/json', ...(opciones.headers || {}) };
  if (opciones.cuerpo !== undefined && !(opciones.cuerpo instanceof Blob)) {
    cabeceras['Content-Type'] = 'application/json';
  }
  if (tok) cabeceras['Authorization'] = 'Bearer ' + tok;

  const ctrl = new AbortController();
  // Timeout por INACTIVIDAD, no absoluto: una subida lenta pero viva no se
  // corta, y una conexion muerta no deja el pedido colgado para siempre.
  const temporizador = setTimeout(() => ctrl.abort(), opciones.timeout ?? 30000);

  let res;
  try {
    res = await fetch(BASE + ruta, {
      method: opciones.metodo || 'GET',
      headers: cabeceras,
      body: opciones.cuerpo instanceof Blob
        ? opciones.cuerpo
        : (opciones.cuerpo !== undefined ? JSON.stringify(opciones.cuerpo) : undefined),
      signal: ctrl.signal,
      cache: 'no-store',
      credentials: 'same-origin',
    });
  } catch (e) {
    throw new ErrorApi(0, 'SIN_RED', 'Sin conexión.');
  } finally {
    clearTimeout(temporizador);
  }

  let cuerpo = null;
  try {
    cuerpo = await res.json();
  } catch {
    // No es JSON. Puede ser el HTML de un portal cautivo de la obra, o una
    // pagina de error del hosting. No es una respuesta de la API.
    if (res.ok) {
      throw new ErrorApi(0, 'RESPUESTA_INVALIDA', 'Respuesta inesperada del servidor.');
    }
  }

  if (!res.ok || !cuerpo || cuerpo.ok !== true) {
    const e = cuerpo?.error || {};
    throw new ErrorApi(res.status, e.codigo, e.mensaje, e.campos);
  }

  await sesion.marcarOnline();
  return cuerpo.datos;
}

/**
 * Un documento (HTML imprimible o PDF) como Blob, con la sesion.
 *
 * Los documentos no se pueden abrir con un enlace comun: la sesion viaja en
 * la cabecera Authorization y un enlace no la manda, asi que el servidor
 * contestaria 401. Se piden con fetch y se abren como blob.
 */
export async function documento(ruta) {
  const tok = await sesion.token();
  let res;
  try {
    res = await fetch(BASE + ruta, {
      headers: tok ? { Authorization: 'Bearer ' + tok } : {},
      cache: 'no-store',
      credentials: 'same-origin',
    });
  } catch {
    throw new ErrorApi(0, 'SIN_RED', 'Sin conexión.');
  }
  if (!res.ok) {
    let e = {};
    try { e = (await res.json())?.error || {}; } catch { /* no era JSON */ }
    throw new ErrorApi(res.status, e.codigo, e.mensaje || 'No se pudo abrir el documento.');
  }
  return res.blob();
}

/** Serializa los pedidos: nunca hay mas de uno en vuelo. */
export function pedir(ruta, opciones) {
  const siguiente = () => crudo(ruta, opciones);
  enVuelo = enVuelo ? enVuelo.then(siguiente, siguiente) : siguiente();
  return enVuelo;
}

/** Espera con backoff exponencial y jitter. El jitter no es un detalle: sin
 *  el, seis telefonos reintentan al mismo milisegundo. */
export function espera(intento) {
  const base = Math.min(1000 * 2 ** intento, 60000);
  return new Promise((r) => setTimeout(r, base * (0.5 + Math.random())));
}

export const entrarConPin = (dispositivo, pin) =>
  pedir('/auth/pin', { metodo: 'POST', cuerpo: { dispositivo, pin }, sinSesion: true });

export const enrolar = (datos) =>
  pedir('/dispositivo/reclamar', { metodo: 'POST', cuerpo: datos, sinSesion: true });

export const dia = () => pedir('/dia');

export const enviarLote = (eventos) =>
  pedir('/sync/lote', { metodo: 'POST', cuerpo: { eventos }, timeout: 60000 });

export const estadoSync = () => pedir('/sync/estado');
