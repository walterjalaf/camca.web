// ============================================================
// Sesion y validacion del PIN, incluida la validacion SIN RED.
//
// R2 — El chofer TIENE que poder abrir la jornada sin cobertura. Un lunes a
// las 6 de la maniana en Tamberias no hay senial; si la app exige validar el
// PIN contra el servidor, no abre, el chofer sale igual y el dia se pierde
// entero. Por eso el PIN se verifica contra un verificador LOCAL derivado con
// PBKDF2, y la jornada arranca.
//
// Que se guarda y que no:
//   - El PIN NUNCA se guarda, ni en claro ni hasheado a secas.
//   - Se guarda un verificador PBKDF2(PIN, sal-del-dispositivo, 150k) que
//     sirve para comparar, no para autenticarse contra el servidor.
//   - El secreto del dispositivo se guarda CIFRADO con una clave derivada del
//     propio PIN: un telefono perdido sin el PIN no entrega el secreto.
//
// Este modulo importa cola.js para leer pendientes, pero NUNCA la modifica.
// La relacion inversa esta prohibida y la verifica scripts/guard.mjs.
// ============================================================

import { leer, guardar, borrar } from './db.js';

const ITERACIONES = 150000;

const enc = new TextEncoder();

function b64(buf) {
  return btoa(String.fromCharCode(...new Uint8Array(buf)));
}
function deB64(s) {
  return Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
}

async function derivar(pin, sal, uso) {
  const base = await crypto.subtle.importKey('raw', enc.encode(pin), 'PBKDF2', false, ['deriveBits', 'deriveKey']);
  if (uso === 'verificador') {
    const bits = await crypto.subtle.deriveBits(
      { name: 'PBKDF2', salt: enc.encode('verif|' + sal), iterations: ITERACIONES, hash: 'SHA-256' },
      base, 256
    );
    return b64(bits);
  }
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt: enc.encode('cifra|' + sal), iterations: ITERACIONES, hash: 'SHA-256' },
    base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']
  );
}

/** Guarda todo lo que hace falta para entrar despues, con o sin red. */
export async function establecer({ token, usuario, dispositivo, offline, servidor_utc }, pin) {
  const sal = offline?.sal || usuario.id + '|camca';

  const verificador = await derivar(pin, sal, 'verificador');
  const clave = await derivar(pin, sal, 'cifra');
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const secretoCifrado = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv }, clave, enc.encode(dispositivo)
  );

  // Desfase entre el reloj del telefono y el del servidor. Si el telefono
  // tiene la hora corrida, las horas de arribo mienten; guardarlo permite
  // corregirlo en vez de descubrirlo en una auditoria.
  const delta = servidor_utc ? Date.parse(servidor_utc) - Date.now() : 0;

  await guardar('meta', {
    token,
    usuario,
    sal,
    verificador,
    secreto_iv: b64(iv),
    secreto: b64(secretoCifrado),
    delta_reloj_ms: delta,
    establecida_ms: Date.now(),
    ultimo_online_ms: Date.now(),
  }, 'sesion');
}

export async function actual() {
  return leer('meta', 'sesion');
}

export async function hayDispositivo() {
  const s = await actual();
  return !!(s && s.secreto);
}

export async function token() {
  const s = await actual();
  return s?.token ?? null;
}

export async function usuario() {
  const s = await actual();
  return s?.usuario ?? null;
}

export async function deltaReloj() {
  const s = await actual();
  return s?.delta_reloj_ms ?? 0;
}

/**
 * Verifica el PIN contra el verificador local. Funciona sin red.
 * Devuelve el secreto del dispositivo descifrado, o null si el PIN no va.
 */
export async function verificarLocal(pin) {
  const s = await actual();
  if (!s || !s.verificador) return null;

  const v = await derivar(pin, s.sal, 'verificador');
  // Comparacion en tiempo constante: aca no hay atacante remoto, pero el
  // costo es cero y evita depender de como optimice el motor.
  if (v.length !== s.verificador.length) return null;
  let dif = 0;
  for (let i = 0; i < v.length; i++) dif |= v.charCodeAt(i) ^ s.verificador.charCodeAt(i);
  if (dif !== 0) return null;

  try {
    const clave = await derivar(pin, s.sal, 'cifra');
    const plano = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: deB64(s.secreto_iv) }, clave, deB64(s.secreto)
    );
    return new TextDecoder().decode(plano);
  } catch {
    return null;
  }
}

/** Backoff local contra la fuerza bruta cuando no hay servidor que la frene. */
export async function intentoFallidoLocal() {
  const s = (await leer('meta', 'intentos')) ?? { n: 0 };
  s.n = (s.n || 0) + 1;
  s.ultimo_ms = Date.now();
  await guardar('meta', s, 'intentos');
  return s.n;
}

export async function limpiarIntentos() {
  await borrar('meta', 'intentos');
}

/** Segundos de espera que quedan, o 0. Escala igual que el servidor. */
export async function bloqueoLocal() {
  const s = await leer('meta', 'intentos');
  if (!s || !s.n) return 0;
  const escala = [[20, 86400], [12, 1800], [8, 300], [5, 60]];
  for (const [umbral, segundos] of escala) {
    if (s.n >= umbral) {
      const faltan = Math.ceil((s.ultimo_ms + segundos * 1000 - Date.now()) / 1000);
      return faltan > 0 ? faltan : 0;
    }
  }
  return 0;
}

export async function marcarOnline() {
  const s = await actual();
  if (!s) return;
  s.ultimo_online_ms = Date.now();
  await guardar('meta', s, 'sesion');
}

export async function actualizarToken(nuevo) {
  const s = await actual();
  if (!s) return;
  s.token = nuevo;
  await guardar('meta', s, 'sesion');
}

/**
 * Cierra la sesion. Borra credenciales y NADA MAS.
 *
 * R1: la cola no se toca. Cerrar sesion, o que venza el PIN, no puede
 * llevarse evidencia sin sincronizar. El chofer vuelve a entrar y la cola
 * sigue exactamente donde estaba.
 */
export async function cerrar() {
  await borrar('meta', 'sesion');
  await borrar('meta', 'intentos');
}

/**
 * Hace cuanto que no se habla con el servidor.
 *
 * R6 — Este numero NUNCA bloquea la captura. Una campania de cuatro dias en
 * Los Azules deja al chofer sin senial desde el martes; si al tercer dia la
 * app dejara de abrir, se quedaria a 3.000 m con tres dias de evidencia
 * adentro y una aplicacion que no arranca. Como mucho, se muestra un aviso.
 */
export async function diasSinServidor() {
  const s = await actual();
  if (!s) return 0;
  return (Date.now() - (s.ultimo_online_ms ?? s.establecida_ms)) / 86400000;
}
