// ============================================================
// Remitos en papel (piloto de OCR, F4.4).
//
// La oficina sube la foto de un remito en papel; el servidor se la pasa al
// modelo y devuelve un BORRADOR. La pantalla muestra la foto al lado del
// formulario precargado, con lo que el modelo marcó como dudoso resaltado, y
// sólo «Confirmar y registrar» —una persona, mirando la foto— lo convierte en
// registro. Si el modelo no está configurado o falló, el mismo formulario
// sirve para transcribir a mano.
// ============================================================

import * as api from './api.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const opcion = (v, t, sel) => '<option value="' + esc(v) + '"' + (String(sel) === String(v) ? ' selected' : '') + '>' + esc(t) + '</option>';
const fechaCorta = (iso) => iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '';
const diaArt = (utc) => utc ? fechaCorta(new Date(Date.parse(utc.replace(' ', 'T') + 'Z') - 3 * 3600e3).toISOString().slice(0, 10)) : '';
const usd = (micros) => micros === null || micros === undefined ? '—' : (micros / 1e6).toLocaleString('es-AR', { minimumFractionDigits: 3, maximumFractionDigits: 4 }) + ' USD';
const ESTADO = {
  pendiente: ['sello--gris', 'sin leer'], procesando: ['sello--gris', 'leyendo'], borrador: ['sello--aviso', 'borrador: falta validar'], error: ['sello--mal', 'sin lectura'],
  validado: ['sello--ok', 'registrado'], descartado: ['sello--gris', 'descartado'],
};
const ETIQUETA = {
  numero: 'N.º de remito', fecha: 'Fecha', cliente: 'Cliente', sitio: 'Lugar / obra', servicio: 'Servicio', cantidad: 'Cantidad de baños',
  receptor: 'Aclaración de quien recibió', firmado: 'Firmado', observaciones: 'Observaciones',
};

let ctx = { marcarConexion: () => {}, error: () => {} };
let datos = null;
let fotoUrl = null;

export function iniciar(opciones) {
  ctx = { ...ctx, ...opciones };
  for (const id of ['papel', 'papel-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-ocr]');
      if (b) accion(b);
    });
  }
}

export async function cargar() {
  try {
    datos = await api.pedir('/ocr/remitos');
    ctx.marcarConexion(true, 'conectado');
    pintar();
  } catch (e) {
    ctx.error('papel', e);
  }
}

function pintar() {
  const d = datos;
  const m = d.medicion;
  const precision = m.precision_por_campo
    ? '<div class="tabla-envoltorio"><table class="tabla ocr-precision"><thead><tr><th>Campo</th><th>Bien sin corregir</th></tr></thead><tbody>' +
      Object.entries(m.precision_por_campo).map(([c, p]) => '<tr><td>' + esc(ETIQUETA[c] ?? c) + '</td><td class="trabajo-doc">' +
        (p * 100).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + ' %</td></tr>').join('') + '</tbody></table></div>'
    : '<p class="app-nota">Todavía no hay remitos validados con borrador: la precisión se mide con lo que corrigen las personas.</p>';
  $('papel').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Remitos en papel (piloto)</p>' +
    '<p class="app-nota">Sacale una foto al remito entero, derecho y con luz. El modelo lee la foto y deja un borrador; ' +
    'nada se registra hasta que alguien lo confirma mirando la foto.</p>' +
    (d.configurado ? '' : '<div class="aviso aviso--decision ocr-aviso"><div>La lectura automática no está configurada en el servidor: ' +
      'la foto se guarda y el remito se transcribe a mano en el mismo formulario.</div></div>') +
    '<div class="filtro-fecha sga-subir"><div class="app-campo"><label for="ocr-foto">Foto del remito (JPG o PNG)</label>' +
    '<input id="ocr-foto" type="file" accept="image/jpeg,image/png" capture="environment" /></div>' +
    '<button class="app-btn app-btn--principal" type="button" data-ocr="subir">Subir y leer</button></div>' +
    '<p class="trabajo-error" id="ocr-error-subir" role="alert" hidden></p><p class="app-nota" id="ocr-estado" role="status" aria-live="polite"></p></div>' +
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Lo que mide el piloto</p>' +
    '<ul class="conteos"><li><b>' + m.validados_con_borrador + '</b><span>validados con borrador</span></li>' +
    '<li><b>' + m.procesadas + '</b><span>fotos leídas por el modelo</span></li>' +
    '<li><b>' + usd(m.costo_promedio_usd_micros) + '</b><span>costo promedio por foto</span></li>' +
    '<li><b>' + usd(m.costo_total_usd_micros) + '</b><span>costo total</span></li></ul>' + precision + '</div>' +
    '<div class="app-tarjeta"><p class="app-titulo">Fotos</p>' + (d.fotos.length
      ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Subida</th><th>Estado</th><th>Remito</th><th>Costo</th><th></th></tr></thead><tbody>' +
        d.fotos.map((f) => '<tr data-foto="' + f.id + '"><td>' + diaArt(f.subido_utc) + '<div class="cli-falta">' + esc(f.nombre ?? '') + '</div></td>' +
          '<td><span class="sello ' + ESTADO[f.estado][0] + '">' + ESTADO[f.estado][1] + '</span>' +
            (f.corregidos ? '<div class="cli-falta">' + (f.corregidos.length ? 'corregido: ' + esc(f.corregidos.map((c) => ETIQUETA[c] ?? c).join(', ')) : 'sin correcciones') + '</div>' : '') + '</td>' +
          '<td>' + (f.registro ? esc(f.registro.numero ?? 's/n') + ' · ' + esc(f.registro.cliente) + '<div class="cli-falta">' + fechaCorta(f.registro.fecha) + '</div>' : '—') + '</td>' +
          '<td class="trabajo-doc">' + usd(f.costo_usd_micros) + '</td>' +
          '<td><button class="app-btn rec-mini" type="button" data-ocr="abrir" data-id="' + f.id + '">' + (['borrador', 'error', 'pendiente', 'procesando'].includes(f.estado) ? 'Validar' : 'Ver') + '</button></td></tr>').join('') +
        '</tbody></table></div>'
      : '<p class="app-nota">Todavía no se subió ninguna foto.</p>') + '</div>';
}

function panel(html) {
  if (fotoUrl) { URL.revokeObjectURL(fotoUrl); fotoUrl = null; }
  $('papel-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html + '</div>' : '';
  if (html) $('papel-panel').scrollIntoView({ block: 'start' });
}

async function abrir(id) {
  let f;
  try {
    f = await api.pedir('/ocr/remito/' + id);
  } catch (e) {
    panel('<p class="app-titulo">No se pudo abrir</p><p class="app-nota">' + esc(e.message) + '</p>');
    return;
  }
  const abierta = ['borrador', 'error', 'pendiente', 'procesando'].includes(f.estado);
  const v = f.registro ?? f.borrador ?? {};
  const dudoso = new Set(f.dudosos ?? []);
  const marca = (c) => dudoso.has(c) ? ' <span class="sello sello--aviso">revisar</span>' : '';
  const campo = (c, control) => '<div class="app-campo' + (dudoso.has(c) ? ' ocr-dudoso' : '') + '"><label for="ocr-' + c + '">' + ETIQUETA[c] + marca(c) + '</label>' + control + '</div>';
  const dis = abierta ? '' : ' disabled';
  const form = '<form class="cli-form ocr-form">' +
    campo('numero', '<input id="ocr-numero" maxlength="30" value="' + esc(v.numero ?? '') + '"' + dis + ' />') +
    campo('fecha', '<input id="ocr-fecha" type="date" value="' + esc(v.fecha ?? '') + '"' + dis + ' />') +
    campo('cliente', '<input id="ocr-cliente" maxlength="160" value="' + esc(v.cliente ?? '') + '"' + dis + ' />') +
    campo('sitio', '<input id="ocr-sitio" maxlength="200" value="' + esc(v.sitio ?? '') + '"' + dis + ' />') +
    campo('servicio', '<select id="ocr-servicio"' + dis + '><option value="">—</option>' +
      Object.entries(f.servicios).map(([k, t]) => opcion(k, t, v.servicio ?? '')).join('') + '</select>') +
    campo('cantidad', '<input id="ocr-cantidad" type="number" min="1" max="500" inputmode="numeric" value="' + esc(v.cantidad ?? '') + '"' + dis + ' />') +
    campo('receptor', '<input id="ocr-receptor" maxlength="120" value="' + esc(v.receptor ?? '') + '"' + dis + ' />') +
    campo('firmado', '<select id="ocr-firmado"' + dis + '><option value="">—</option>' + opcion('si', 'sí, tiene firma', v.firmado === true ? 'si' : v.firmado === false ? 'no' : '') +
      opcion('no', 'no, está sin firmar', v.firmado === true ? 'si' : v.firmado === false ? 'no' : '') + '</select>') +
    campo('observaciones', '<input id="ocr-observaciones" maxlength="500" value="' + esc(v.observaciones ?? '') + '"' + dis + ' />') + '</form>';
  const pie = abierta
    ? '<p class="trabajo-error" id="ocr-error" role="alert" hidden></p><div class="cli-acciones">' +
      '<button class="app-btn app-btn--principal" type="button" data-ocr="validar" data-id="' + f.id + '">Confirmar y registrar</button>' +
      (['error', 'pendiente', 'procesando'].includes(f.estado) && f.configurado ? '<button class="app-btn" type="button" data-ocr="reprocesar" data-id="' + f.id + '">Volver a leer</button>' : '') +
      '<button class="app-btn" type="button" data-ocr="cerrar">Cerrar</button></div>' +
      '<div class="filtro-fecha"><div class="app-campo"><label for="ocr-motivo">Descartar la foto (motivo)</label><input id="ocr-motivo" maxlength="255" /></div>' +
      '<button class="app-btn rec-mini app-btn--peligro" type="button" data-ocr="descartar" data-id="' + f.id + '">Descartar</button></div>'
    : '<p class="app-nota">' + (f.estado === 'validado' ? 'Registrado por ' + esc(f.validado_por ?? '') + ' el ' + diaArt(f.validado_utc) +
        (f.corregidos?.length ? '. Corrigió: ' + esc(f.corregidos.map((c) => ETIQUETA[c] ?? c).join(', ')) + '.' : ', sin corregir el borrador.')
        : 'Descartada: ' + esc(f.descartado_motivo ?? '')) + '</p><div class="cli-acciones"><button class="app-btn" type="button" data-ocr="cerrar">Cerrar</button></div>';
  panel('<div class="tar-cabeza"><p class="app-titulo">Remito en papel</p><span class="sello ' + ESTADO[f.estado][0] + '">' + ESTADO[f.estado][1] + '</span></div>' +
    (f.error ? '<div class="aviso aviso--decision ocr-aviso"><div>El modelo no pudo leer esta foto: ' + esc(f.error) + ' Transcribila a mano mirando la foto.</div></div>' : '') +
    (f.borrador && abierta ? '<p class="app-nota">Leído por ' + esc(f.modelo ?? '') + ' en ' + ((f.duracion_ms ?? 0) / 1000).toLocaleString('es-AR', { maximumFractionDigits: 1 }) +
      ' s, ' + usd(f.costo_usd_micros) + '. Compará cada campo con la foto; lo marcado «revisar» el modelo no lo leyó con seguridad.</p>' : '') +
    '<div class="ocr-ficha"><figure class="ocr-foto"><img id="ocr-img" alt="Foto del remito en papel" /></figure><div>' + form + pie + '</div></div>');
  try {
    const blob = await api.documento('/ocr/remito/' + f.id + '/imagen');
    fotoUrl = URL.createObjectURL(blob);
    $('ocr-img').src = fotoUrl;
  } catch { /* la ficha sirve igual sin la foto a la vista */ }
}

function mostrarError(e, id) {
  const p = $(id);
  if (!p) return;
  p.textContent = e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.');
  p.hidden = false;
}

async function accion(b) {
  const a = b.dataset.ocr;
  if (a === 'cerrar') { panel(''); return; }
  if (a === 'abrir') { await abrir(Number(b.dataset.id)); return; }
  b.disabled = true;
  try {
    if (a === 'subir') {
      const archivo = $('ocr-foto').files[0];
      if (!archivo) { b.disabled = false; mostrarError({ message: 'Elegí la foto primero.' }, 'ocr-error-subir'); return; }
      $('ocr-estado').textContent = 'Leyendo la foto… puede tardar unos segundos.';
      const f = await api.pedir('/ocr/remito', { metodo: 'POST', cuerpo: archivo, timeout: 180000,
        headers: { 'X-Camca-Nombre': encodeURIComponent(archivo.name) } });
      await cargar();
      await abrir(f.id);
      // La misma foto otra vez no se vuelve a leer ni a pagar: se abre la que había.
      if (f.repetida) $('ocr-estado').textContent = 'Esa foto ya estaba subida: se abrió la que había, sin volver a leerla.';
      return;
    }
    const id = b.dataset.id;
    if (a === 'validar') {
      const val = (x) => $('ocr-' + x).value.trim();
      const firmado = $('ocr-firmado').value;
      await api.pedir('/ocr/remito/' + id + '/validar', { metodo: 'POST', cuerpo: {
        numero: val('numero') || undefined, fecha: val('fecha'), cliente: val('cliente'), sitio: val('sitio') || undefined,
        servicio: val('servicio'), cantidad: Number(val('cantidad')) || 0, receptor: val('receptor') || undefined,
        firmado: firmado === '' ? undefined : firmado === 'si', observaciones: val('observaciones') || undefined } });
    } else if (a === 'reprocesar') {
      await api.pedir('/ocr/remito/' + id + '/reprocesar', { metodo: 'POST', cuerpo: {}, timeout: 180000 });
    } else if (a === 'descartar') {
      await api.pedir('/ocr/remito/' + id + '/descartar', { metodo: 'POST', cuerpo: { motivo: $('ocr-motivo').value.trim() } });
    }
    await cargar();
    await abrir(Number(id));
  } catch (e) {
    b.disabled = false;
    if ($('ocr-estado')) $('ocr-estado').textContent = '';
    mostrarError(e, a === 'subir' ? 'ocr-error-subir' : 'ocr-error');
  }
}
