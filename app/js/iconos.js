// ============================================================
// public/app/js/iconos.js — SET DE ICONOS DE LA APP.
//
// Se entrega como objeto JS de STRINGS, no como sprite <symbol> + <use>,
// por tres razones concretas de este proyecto:
//
//  1. campo.js y supervisor.js pintan el DOM con innerHTML. Un string se
//     concatena; un <use> obliga a que el simbolo exista ya en ESE
//     documento y falla en silencio si algun dia algo se mueve a un
//     shadow root o a un iframe.
//  2. Tres propuestas distintas montaban tres sprites con los mismos ids
//     (#i-alerta, #i-camion, #i-reloj) y geometrias distintas: <use>
//     resuelve el PRIMERO del documento y dibuja el icono equivocado, sin
//     error ni aviso. Con un objeto JS no hay ids que colisionen.
//  3. Va dentro de un modulo que el service worker ya precachea: cero
//     requests, funciona sin senial desde el primer render. Un
//     /app/iconos.svg externo depende de que postbuild.mjs lo tome en
//     build.json, y si se saltea la app abre sin iconos en Calingasta.
//
// REGLA DE DIBUJO: viewBox 24x24, SIN fill ni stroke-width horneados en el
// markup. El trazo se declara UNA vez en la clase .ico, asi cambiar el
// tamanio no cambia el peso optico. Los puntos solidos SI van como
// atributo (fill="currentColor" stroke="none"): una presentation attribute
// en el hijo le gana al fill:none heredado del padre, que es justo lo que
// se necesita ahi y lo unico que no se puede hacer desde afuera.
//
// Reemplazan los emojis estructurales de campo.js:209-225 (📍 ⚠️ 👤 🚽 🕒)
// y los glifos ✓/✕ de campo.js:217. Los emojis de informe.js NO se tocan:
// viajan adentro del texto de WhatsApp, donde un SVG no existe.
// ============================================================

const D = {
  // --- Origen de la marca (niveles de confianza) ---
  camion:    '<path d="M2.8 6.2h10.4v10.6H2.8z"/><path d="M13.2 9.6h3.9l4.1 3.6v3.6h-8z"/><circle cx="7.2" cy="18.6" r="2.1"/><circle cx="17.2" cy="18.6" r="2.1"/><path d="M9.3 18.6h5.8"/>',
  telefono:  '<rect x="6.5" y="2.5" width="11" height="19" rx="2.6"/><path d="M10.4 18.6h3.2"/>',
  mano:      '<path d="M9 12V5.6a1.6 1.6 0 1 1 3.2 0V11m0-1.8a1.6 1.6 0 1 1 3.2 0v2.4m0-1.6a1.6 1.6 0 1 1 3.2 0v4.6a6 6 0 0 1-6 6h-1a6 6 0 0 1-4.9-2.6L4.9 16.4a1.7 1.7 0 0 1 2.6-2.1L9 16.2"/>',

  // --- Meta de una parada (reemplazan 👤 🚽 🕒 📍) ---
  persona:   '<circle cx="12" cy="8.1" r="3.7"/><path d="M4.6 20.4a7.4 7.4 0 0 1 14.8 0"/>',
  unidad:    '<path d="M5.5 20.5V5a1.5 1.5 0 0 1 1.5-1.5h10A1.5 1.5 0 0 1 18.5 5v15.5z"/><path d="M12 3.5v17"/><path d="M7.8 7.2h2.6M13.6 7.2h2.6"/><circle cx="10.4" cy="12.4" r="1" fill="currentColor" stroke="none"/>',
  reloj:     '<circle cx="12" cy="12" r="8.6"/><path d="M12 6.9V12l3.5 2.1"/>',
  mapa:      '<path d="M12 21.4s7-6.1 7-10.9a7 7 0 1 0-14 0c0 4.8 7 10.9 7 10.9z"/><circle cx="12" cy="10.4" r="2.6"/>',

  // --- Estado de una parada ---
  tilde:     '<path d="M4.6 12.4 9.4 17.2 19.4 7.2"/>',
  cruz:      '<path d="M6.4 6.4 17.6 17.6M17.6 6.4 6.4 17.6"/>',

  // --- Evidencia ---
  foto:      '<path d="M3 7.6h3.4L8.2 5h7.6l1.8 2.6H21v12H3z"/><circle cx="12" cy="13.5" r="3.4"/>',
  firma:     '<path d="M2.8 16.8c2.9 0 3.4-9.4 6-9.4 2 0 1.1 6.4 3.1 6.4 1.7 0 2.1-3.6 3.9-3.6 1.3 0 1.6 1.9 2.7 1.9"/><path d="M2.8 20.6h18.4"/>',

  // --- Avisos, por nivel ---
  alerta:      '<path d="M10.7 3.9 2.2 18.6a1.5 1.5 0 0 0 1.3 2.3h17a1.5 1.5 0 0 0 1.3-2.3L13.3 3.9a1.5 1.5 0 0 0-2.6 0Z"/><path d="M12 9.3v4.6"/><circle cx="12" cy="17.2" r="1.05" fill="currentColor" stroke="none"/>',
  bifurcacion: '<path d="M6 21v-5a4 4 0 0 1 4-4h8M6 3v9"/><path d="M15 9l3 3-3 3"/>',
  nubesube:    '<path d="M7 18.6a4.3 4.3 0 0 1 .4-8.6 5.6 5.6 0 0 1 10.7.4 3.9 3.9 0 0 1-.5 8.2"/><path d="M12 21.4V13m0 0-2.6 2.6M12 13l2.6 2.6"/>',

  // --- "No se sabe": contorno punteado, a proposito ---
  nosabe:    '<circle cx="12" cy="12" r="9" stroke-dasharray="3.2 2.6"/><path d="M9.4 9.4a2.7 2.7 0 1 1 3.4 3.1c-.6.3-.9.8-.9 1.5v.5"/><circle cx="11.9" cy="17.4" r="1.05" fill="currentColor" stroke="none"/>',

  // --- Frescura del dato de flota ---
  relojAlerta:  '<path d="M20.2 14.4A8.6 8.6 0 1 1 12 3.4"/><path d="M12 6.9V12l3.2 1.9"/><path d="M19.6 3.6v4.2"/><circle cx="19.6" cy="10.6" r="1.05" fill="currentColor" stroke="none"/>',
  relojTachado: '<circle cx="12" cy="12" r="8.6"/><path d="M12 6.9V12l3.4 2"/><path d="M5.4 5.4 18.6 18.6"/>',
  odometro:     '<circle cx="12" cy="12" r="8.6"/><path d="m12 12 4.2-3.2"/><path d="M12 3.4V5M20.6 12H19M12 20.6V19M3.4 12H5"/>',

  // --- Sistema ---
  sinred:     '<path d="M4.2 9.2A12.4 12.4 0 0 1 12 6.4c2.9 0 5.6 1 7.8 2.8M7.4 13a8 8 0 0 1 9.2 0M10.4 16.6a3.4 3.4 0 0 1 3.2 0"/><circle cx="12" cy="20" r="1.1" fill="currentColor" stroke="none"/><path d="m3 3 18 18"/>',
  reintentar: '<path d="M20.4 12a8.4 8.4 0 1 1-2.6-6.1"/><path d="M20.6 3.4v5h-5"/>',
  gps:        '<circle cx="12" cy="12" r="6.6"/><circle cx="12" cy="12" r="1.7" fill="currentColor" stroke="none"/><path d="M12 2.2v2.6M12 19.2v2.6M2.2 12h2.6M19.2 12h2.6"/>',
};

/**
 * Devuelve el <svg> como string, listo para concatenar en un innerHTML.
 *
 * El icono va SIEMPRE aria-hidden: nunca es el nombre accesible de nada.
 * Si el elemento no tiene texto visible al lado, el nombre va en un
 * aria-label del BOTON que lo contiene, no aca.
 *
 * @param {string} nombre  clave de D
 * @param {string} [clase] 'ico--sm' | 'ico--lg' | 'ico--xl' | 'ico--girando'
 */
export function ico(nombre, clase = '') {
  const d = D[nombre];
  if (!d) { console.warn('[camca] icono desconocido:', nombre); return ''; }
  return '<svg class="ico' + (clase ? ' ' + clase : '') +
    '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + d + '</svg>';
}

export const NOMBRES_ICONO = Object.keys(D);
export default ico;

/* ============================================================
   CSS que acompania. Va en app.css, una sola vez.

   .ico {
     width: 1.05em; height: 1.05em;
     flex: none;
     fill: none;
     stroke: currentColor;
     stroke-width: 1.8;
     stroke-linecap: round;
     stroke-linejoin: round;
     vertical-align: -.16em;   // se sienta sobre la linea base, cosa que
   }                           // un emoji nunca hace
   .ico--sm      { width: 14px; height: 14px; stroke-width: 2; }
   .ico--lg      { width: 22px; height: 22px; stroke-width: 1.6; }
   .ico--xl      { width: 36px; height: 36px; stroke-width: 1.4; }
   .ico--girando { animation: ico-giro 1.4s linear infinite; }
   @keyframes ico-giro { to { transform: rotate(360deg); } }
   ============================================================ */
