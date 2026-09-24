// ============================================================
// Formato de numeros y cantidades, en castellano de Argentina.
//
// Existe porque la app mostraba "12.4 km". En es-AR el separador decimal es
// la COMA: "12.4" se lee como doce mil cuatrocientos. En una pantalla que
// termina justificando una factura, un kilometraje que se lee mal es un
// numero discutido.
//
// Vive en un modulo propio y no adentro de cada pantalla para que campo y
// supervisor no puedan volver a divergir.
// ============================================================

const _unDecimal = new Intl.NumberFormat('es-AR', {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
});
const _entero = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 });

/** 202.6 -> "202,6 km". Acepta null y devuelve cadena vacia. */
export function km(n) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '';
  return _unDecimal.format(Number(n)) + ' km';
}

/** 1234 -> "1.234" */
export function entero(n) {
  return _entero.format(Number(n) || 0);
}

/**
 * Plural sin abreviar. "1 parada" / "3 paradas".
 *
 * Nada de "parada(s)": el chofer lee esto de reojo y con el sol de frente.
 */
export function plural(n, uno, varios) {
  return n + ' ' + (n === 1 ? uno : varios);
}

/**
 * Las coordenadas NO pasan por aca a proposito: se copian, se pegan en un
 * mapa y viajan en una URL. Una latitud con coma se rompe en el camino.
 */

/**
 * Pesos argentinos para leer: 1250050 (centavos) -> "$ 12.500,50".
 * Los importes viajan SIEMPRE en centavos enteros; esto es solo para mostrar.
 */
export function pesos(cent) {
  const n = Number(cent) || 0;
  const signo = n < 0 ? '-' : '';
  const a = Math.abs(n);
  return signo + '$ ' + _entero.format(Math.floor(a / 100)) + ',' + String(a % 100).padStart(2, '0');
}

/**
 * Lo que alguien escribe como precio, a centavos enteros. Sin pasar por un
 * float: "0,1 + 0,2" no puede terminar en una factura como 0,30000000000000004.
 *
 * Castellano de Argentina: el punto separa miles y la coma los decimales.
 * "12.500,50", "$ 12500,5" y "12500" valen; "12,505" (tres decimales) no.
 * Devuelve null si no es un importe.
 */
export function centavos(texto) {
  const s = String(texto ?? '').replace(/\$/g, '').replace(/\s/g, '');
  const m = /^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  return Number(m[1].replace(/\./g, '')) * 100 + Number((m[2] ?? '0').padEnd(2, '0'));
}
