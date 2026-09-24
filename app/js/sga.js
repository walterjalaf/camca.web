// ============================================================
// Gestión ambiental (Obj. 5): el sistema de gestión ISO 14001 de CAMCA
// ligado a la operación.
//
//  - Disposición de efluentes (F4.2): cada servicio hecho, con la descarga en
//    planta habilitada que lo cierra o con la alerta que dice por qué no.
//  - No conformidades (F4.3): de la detección al cierre con la eficacia
//    comprobada, con la evidencia de cada acción.
//  - Documentos controlados (F4.1): qué versión rige, quién la aprobó, cuándo
//    hay que revisarla y quién tomó conocimiento.
//
// Vive en su propio módulo para no seguir inflando supervisor.js; recibe de
// él lo que comparte (sesión, formato, avisos de conexión) por iniciar().
// ============================================================

import * as api from './api.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const opcion = (v, t, sel) => '<option value="' + esc(v) + '"' + (String(sel) === String(v) ? ' selected' : '') + '>' + esc(t) + '</option>';
const fechaCorta = (iso) => iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '';
// Un instante UTC de la base («2026-09-24 01:10:00»), como fecha de Argentina:
// aprobado a las 22 h del 23 no puede decir «el 24».
const diaArt = (utc) => utc ? fechaCorta(new Date(Date.parse(utc.replace(' ', 'T') + 'Z') - 3 * 3600e3).toISOString().slice(0, 10)) : '';

let ctx = { soyAdmin: false, marcarConexion: () => {}, error: () => {} };
let docDatos = null;
let abierto = null;   // id del documento cuyo detalle está a la vista

export function iniciar(opciones) {
  ctx = { ...ctx, ...opciones };
  for (const id of ['ambiente', 'amb-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-sga]');
      if (b) accion(b);
    });
  }
}

export async function cargar() {
  await Promise.all([cargarDisposicion(), cargarNc(), cargarDocumentos()]);
}

async function cargarDocumentos() {
  try {
    docDatos = await api.pedir('/sga/documentos');
    ctx.marcarConexion(true, 'conectado');
    pintar();
    if (abierto !== null) await abrirDetalle(abierto, false);
  } catch (e) {
    ctx.error('amb-docs', e);
  }
}

// ------------------------------------------------------------------
// Disposición de efluentes (F4.2)
// ------------------------------------------------------------------
let dispDatos = null;
let dispDesde = null;
let dispHasta = null;
const sumarDias = (iso, n) => new Date(Date.parse(iso + 'T12:00:00Z') + n * 86400000).toISOString().slice(0, 10);
const hoyArt = () => new Date(Date.now() - 3 * 3600e3).toISOString().slice(0, 10);
const NIVEL = { alta: 'sello--mal', media: 'sello--aviso', baja: 'sello--gris' };
const ETIQUETA_ALERTA = {
  sin_disposicion: 'sin descarga', pendiente: 'en plazo', fuera_de_plazo: 'fuera de plazo', volumen_bajo: 'volumen bajo',
  planta_vencida: 'planta vencida', planta_por_vencer: 'planta por vencer',
};
const litros = (n) => Number(n).toLocaleString('es-AR') + ' L';

async function cargarDisposicion() {
  dispHasta ??= hoyArt();
  dispDesde ??= sumarDias(dispHasta, -13);
  try {
    dispDatos = await api.pedir('/ambiental?desde=' + dispDesde + '&hasta=' + dispHasta);
    ctx.marcarConexion(true, 'conectado');
    pintarDisposicion();
  } catch (e) {
    ctx.error('amb-disp', e);
  }
}

function pintarDisposicion() {
  const d = dispDatos;
  const r = d.resumen;
  const adm = ctx.soyAdmin;
  const selloAlerta = (a) => '<span class="sello ' + NIVEL[a.nivel] + '">' + esc(ETIQUETA_ALERTA[a.codigo] ?? a.codigo) + '</span>';

  const alertas = d.alertas.length
    ? '<ul class="rec-alertas">' + d.alertas.map((a) => '<li>' + selloAlerta(a) + ' ' +
        (a.fecha ? '<b>' + fechaCorta(a.fecha) + ' · ' + esc(a.ruta) + '</b> ' : '') + esc(a.texto) +
        (a.jornada_id && a.codigo === 'sin_disposicion'
          ? ' <button class="app-btn rec-mini" type="button" data-sga="amb-nueva" data-jornada="' + a.jornada_id + '">Registrar descarga</button>' : '') +
        ' <button class="app-btn rec-mini" type="button" data-sga="nc-desde-alerta" data-alerta="' + d.alertas.indexOf(a) + '">Abrir no conformidad</button>' +
        '</li>').join('') + '</ul>'
    : '<p class="app-nota">Sin alertas: todo lo hecho en el período tiene su descarga, o todavía está en plazo.</p>';

  const conteos = '<ul class="conteos">' +
    '<li><b>' + r.servicios + '</b><span>servicios hechos</span></li>' +
    '<li><b>' + r.con_disposicion + '</b><span>con descarga trazada</span></li>' +
    '<li><b>' + r.pendientes + '</b><span>en plazo</span></li>' +
    '<li><b>' + r.sin_disposicion + '</b><span>sin descarga</span></li>' +
    '<li><b>' + litros(r.litros_dispuestos) + '</b><span>recibidos en planta, de ' + litros(r.litros_estimados) + ' estimados</span></li></ul>';

  const jornadas = d.jornadas.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Jornada</th><th>Servicios</th><th>Estimado</th>' +
      '<th>Descarga en planta</th><th>Estado</th><th></th></tr></thead><tbody>' +
      d.jornadas.map((j) => '<tr data-jornada="' + j.jornada_id + '"><td><b>' + fechaCorta(j.fecha) + '</b><div class="cli-falta">' + esc(j.ruta) + '</div></td>' +
        '<td>' + j.servicios + '<div class="cli-falta">' + j.banos + ' baños</div></td><td class="trabajo-doc">' + litros(j.litros_estimados) + '</td>' +
        '<td>' + (j.disposiciones.length
          ? j.disposiciones.map((x) => fechaCorta(x.fecha) + ' · ' + esc(x.planta) + '<div class="cli-falta">manifiesto ' + esc(x.manifiesto) + ' · ' + litros(x.litros_asignados) + '</div>').join('')
          : '—') + '</td>' +
        '<td>' + (j.alertas.length ? j.alertas.map(selloAlerta).join(' ') : '<span class="sello sello--ok">trazada</span>') + '</td>' +
        '<td>' + (j.disposiciones.length ? '' : '<button class="app-btn rec-mini" type="button" data-sga="amb-nueva" data-jornada="' + j.jornada_id + '">Registrar descarga</button>') + '</td></tr>').join('') +
      '</tbody></table></div>'
    : '<p class="app-nota">No hubo servicios hechos en el período.</p>';

  const descargas = d.descargas.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Fecha</th><th>Planta</th><th>Manifiesto</th><th>Litros</th>' +
      '<th>Jornadas</th><th></th></tr></thead><tbody>' +
      d.descargas.map((x) => '<tr data-descarga="' + x.id + '"><td>' + fechaCorta(x.fecha) + (x.patente ? '<div class="cli-falta">' + esc(x.patente) + '</div>' : '') + '</td>' +
        '<td>' + esc(x.planta) + '</td><td class="trabajo-doc">' + esc(x.manifiesto) + '</td><td class="trabajo-doc">' + litros(x.litros) + '</td>' +
        '<td>' + esc(x.jornadas) + '</td><td>' + (x.estado === 'anulada'
          ? '<span class="sello sello--gris">anulada</span><div class="cli-falta">' + esc(x.anulada_motivo) + '</div>'
          : (adm ? '<button class="app-btn rec-mini" type="button" data-sga="amb-pedir-anular" data-id="' + x.id + '">Anular</button>' : '')) + '</td></tr>').join('') +
      '</tbody></table></div>'
    : '<p class="app-nota">No hay descargas cargadas en el período.</p>';

  const plantas = (d.plantas.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Planta</th><th>Habilitación</th><th>Vence</th><th></th></tr></thead><tbody>' +
      d.plantas.map((p) => '<tr><td><b>' + esc(p.nombre) + '</b>' + (p.operador ? '<div class="cli-falta">' + esc(p.operador) + '</div>' : '') + '</td>' +
        '<td class="trabajo-doc">' + esc(p.habilitacion) + '</td>' +
        '<td>' + (p.habilitacion_vence ? fechaCorta(p.habilitacion_vence) : 'sin vencimiento declarado') + ' ' +
          (!p.activa ? '<span class="sello sello--gris">de baja</span>' : p.estado === 'vencida' ? '<span class="sello sello--mal">vencida</span>'
            : p.estado === 'por_vencer' ? '<span class="sello sello--aviso">por vencer</span>' : '<span class="sello sello--ok">vigente</span>') + '</td>' +
        '<td>' + (adm ? '<button class="app-btn rec-mini" type="button" data-sga="amb-planta" data-id="' + p.id + '">Editar</button>' : '') + '</td></tr>').join('') +
      '</tbody></table></div>'
    : '<p class="app-nota">No hay plantas cargadas. Sin una planta habilitada no se puede registrar ninguna descarga.</p>') +
    (adm ? '<div class="cli-acciones"><button class="app-btn rec-mini" type="button" data-sga="amb-planta">Agregar una planta</button></div>' : '');

  $('amb-disp').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Disposición de efluentes</p>' +
    '<div class="cli-acciones"><button class="app-btn" type="button" data-sga="amb-nueva">Registrar descarga</button>' +
    '<button class="app-btn" type="button" data-sga="amb-registro">Imprimir el registro</button></div></div>' +
    '<div class="filtro-fecha"><div class="app-campo"><label for="amb-desde">Desde</label><input id="amb-desde" type="date" value="' + d.desde + '" /></div>' +
    '<div class="app-campo"><label for="amb-hasta">Hasta</label><input id="amb-hasta" type="date" value="' + d.hasta + '" /></div>' +
    '<button class="app-btn" type="button" data-sga="amb-ver">Ver</button></div>' + conteos +
    '<p class="app-titulo sga-sub">Alertas</p>' + alertas +
    '<p class="app-titulo sga-sub">Jornadas</p>' + jornadas +
    '<p class="app-nota">Estimado: baños atendidos × ' + d.config.litros_por_bano + ' L. Plazo para descargar: ' + d.config.plazo_dias + ' días desde la jornada.</p>' +
    '<p class="app-titulo sga-sub">Descargas</p>' + descargas +
    '<p class="app-titulo sga-sub">Plantas de tratamiento</p>' + plantas +
    '<p class="trabajo-error" id="amb-error-lista" role="alert" hidden></p></div>';
}

function formDescarga(jornadaId) {
  const d = dispDatos;
  const plantas = d.plantas.filter((p) => p.activa && p.estado !== 'vencida');
  const candidatas = d.jornadas;
  panel('<p class="app-titulo">Registrar una descarga en planta</p>' +
    '<p class="app-nota">Con el manifiesto que dio la planta. Marcá de qué jornadas es lo que se descargó.</p>' +
    '<form class="cli-form"><div class="app-campo"><label for="amb-fecha">Fecha de la descarga</label><input id="amb-fecha" type="date" value="' + hoyArt() + '" /></div>' +
    '<div class="app-campo"><label for="amb-planta">Planta</label><select id="amb-planta">' + plantas.map((p) => opcion(p.id, p.nombre, '')).join('') + '</select></div>' +
    '<div class="app-campo"><label for="amb-vehiculo">Vehículo <span class="conf-opcional">(opcional)</span></label><select id="amb-vehiculo"><option value="">—</option>' +
      d.vehiculos.map((v) => opcion(v.id, v.patente, '')).join('') + '</select></div>' +
    '<div class="app-campo"><label for="amb-litros">Litros recibidos</label><input id="amb-litros" type="number" min="1" max="60000" inputmode="numeric" /></div>' +
    '<div class="app-campo"><label for="amb-manifiesto">N.º de manifiesto</label><input id="amb-manifiesto" maxlength="60" /></div>' +
    '<div class="app-campo"><label for="amb-obs">Observaciones <span class="conf-opcional">(opcional)</span></label><input id="amb-obs" maxlength="255" /></div></form>' +
    (candidatas.length
      ? '<fieldset class="plan-activos"><legend>Jornadas que vacía</legend>' + candidatas.map((j) =>
          '<label><input type="checkbox" name="amb-jornada" value="' + j.jornada_id + '"' + (String(j.jornada_id) === String(jornadaId) ? ' checked' : '') + ' /> ' +
          fechaCorta(j.fecha) + ' · ' + esc(j.ruta) + ' <span class="cli-falta">' + litros(j.litros_estimados) + (j.disposiciones.length ? ' · ya tiene descarga' : '') + '</span></label>').join('') +
        '</fieldset>'
      : '<p class="app-nota">No hay jornadas con servicios hechos en el período elegido.</p>') +
    (plantas.length ? '' : '<p class="app-nota">No hay ninguna planta con la habilitación vigente: pedile a administración que la cargue.</p>') +
    '<p class="trabajo-error" id="sga-error" role="alert" hidden></p>' +
    (plantas.length ? botones('data-sga="amb-guardar"', 'Registrar')
                    : '<div class="cli-acciones"><button class="app-btn" type="button" data-sga="cerrar">Cerrar</button></div>'));
  $('amb-litros').focus({ preventScroll: true });
}

function formPlanta(id) {
  const p = dispDatos.plantas.find((x) => x.id === Number(id)) ?? {};
  panel('<p class="app-titulo">' + (p.id ? 'Planta ' + esc(p.nombre) : 'Agregar una planta de tratamiento') + '</p>' +
    '<p class="app-nota">Sólo se registran descargas en plantas con la habilitación vigente ese día.</p>' +
    '<form class="cli-form"><div class="app-campo"><label for="pl-nombre">Nombre</label><input id="pl-nombre" maxlength="120" value="' + esc(p.nombre ?? '') + '" /></div>' +
    '<div class="app-campo"><label for="pl-operador">Operador <span class="conf-opcional">(opcional)</span></label><input id="pl-operador" maxlength="120" value="' + esc(p.operador ?? '') + '" /></div>' +
    '<div class="app-campo"><label for="pl-hab">N.º de habilitación</label><input id="pl-hab" maxlength="60" value="' + esc(p.habilitacion ?? '') + '" /></div>' +
    '<div class="app-campo"><label for="pl-vence">Vence <span class="conf-opcional">(si tiene)</span></label><input id="pl-vence" type="date" value="' + esc(p.habilitacion_vence ?? '') + '" /></div>' +
    '<div class="app-campo"><label for="pl-dir">Dirección <span class="conf-opcional">(opcional)</span></label><input id="pl-dir" maxlength="200" value="' + esc(p.direccion ?? '') + '" /></div>' +
    (p.id ? '<div class="app-campo"><label for="pl-activa">Estado</label><select id="pl-activa">' + opcion('1', 'activa', p.activa ? '1' : '0') + opcion('0', 'de baja', p.activa ? '1' : '0') + '</select></div>' : '') +
    '</form><p class="trabajo-error" id="sga-error" role="alert" hidden></p>' +
    botones('data-sga="amb-guardar-planta"' + (p.id ? ' data-id="' + p.id + '"' : ''), 'Guardar'));
  $('pl-nombre').focus({ preventScroll: true });
}

async function abrirRegistro() {
  const ventana = window.open('', '_blank');
  try {
    const blob = await api.documento('/ambiental/registro?desde=' + dispDatos.desde + '&hasta=' + dispDatos.hasta);
    const url = URL.createObjectURL(new Blob([blob], { type: 'text/html' }));
    if (ventana) ventana.location.href = url; else location.href = url;
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) {
    ventana?.close();
    mostrarError(e, 'amb-error-lista');
  }
}

// ------------------------------------------------------------------
// Documentos controlados
// ------------------------------------------------------------------
// ------------------------------------------------------------------
// No conformidades (F4.3)
// ------------------------------------------------------------------
let ncDatos = null;
let ncAbierta = null;   // id de la NC cuya ficha está a la vista
const ESTADO_NC = {
  abierta: ['sello--mal', 'abierta'], en_tratamiento: ['sello--aviso', 'en tratamiento'],
  en_verificacion: ['sello--aviso', 'en verificación'], cerrada: ['sello--ok', 'cerrada'], anulada: ['sello--gris', 'anulada'],
};
const ESTADO_ACCION = { pendiente: ['sello--aviso', 'pendiente'], cumplida: ['sello--ok', 'cumplida'], descartada: ['sello--gris', 'descartada'] };

async function cargarNc() {
  try {
    ncDatos = await api.pedir('/sga/nc');
    pintarNc();
    if (ncAbierta !== null) await abrirNc(ncAbierta, false);
  } catch (e) {
    ctx.error('amb-nc', e);
  }
}

function pintarNc() {
  const lista = ncDatos.no_conformidades;
  const cuenta = (e) => lista.filter((x) => x.estado === e).length;
  const vencidas = lista.reduce((n, x) => n + x.acciones_vencidas, 0);
  $('amb-nc').innerHTML = '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">No conformidades</p>' +
    '<button class="app-btn" type="button" data-sga="nc-nueva">Nueva no conformidad</button></div>' +
    '<ul class="conteos"><li><b>' + cuenta('abierta') + '</b><span>abiertas</span></li><li><b>' + cuenta('en_tratamiento') + '</b><span>en tratamiento</span></li>' +
    '<li><b>' + cuenta('en_verificacion') + '</b><span>esperando verificación</span></li><li><b>' + vencidas + '</b><span>acciones vencidas</span></li></ul>' +
    (lista.length
      ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Número</th><th>Qué pasó</th><th>Origen</th><th>Estado</th><th>Acciones</th><th></th></tr></thead><tbody>' +
        lista.map((x) => '<tr data-nc="' + x.id + '"><td><b class="trabajo-doc">' + esc(x.numero) + '</b><div class="cli-falta">' + fechaCorta(x.detectada_fecha) + '</div></td>' +
          '<td>' + esc(x.titulo) + (x.gravedad === 'mayor' ? ' <span class="sello sello--mal">mayor</span>' : '') + '</td>' +
          '<td>' + esc(x.origen_texto) + '</td><td>' + sello(ESTADO_NC[x.estado]) + '</td>' +
          '<td>' + (x.acciones_pendientes ? x.acciones_pendientes + ' pendiente' + (x.acciones_pendientes > 1 ? 's' : '') : '—') +
            (x.acciones_vencidas ? ' <span class="sello sello--mal">' + x.acciones_vencidas + ' vencida' + (x.acciones_vencidas > 1 ? 's' : '') + '</span>' : '') + '</td>' +
          '<td><button class="app-btn rec-mini" type="button" data-sga="nc-ver" data-id="' + x.id + '">Ver</button></td></tr>').join('') +
        '</tbody></table></div>'
      : '<p class="app-nota">No hay no conformidades registradas.</p>') + '</div>';
}

function formNc(pre = {}) {
  ncAbierta = null;
  panel('<p class="app-titulo">Nueva no conformidad</p>' +
    '<p class="app-nota">Lo que pasó, dónde se vio y quién se hace cargo. La causa y las acciones se cargan después, en su ficha.</p>' +
    '<form class="cli-form"><div class="app-campo"><label for="nc-titulo">Qué pasó (en una línea)</label><input id="nc-titulo" maxlength="160" value="' + esc(pre.titulo ?? '') + '" /></div>' +
    '<div class="app-campo"><label for="nc-origen">Cómo se detectó</label><select id="nc-origen">' +
      Object.entries(ncDatos.origenes).map(([k, t]) => opcion(k, t, pre.origen ?? 'otro')).join('') + '</select></div>' +
    '<div class="app-campo"><label for="nc-gravedad">Gravedad</label><select id="nc-gravedad">' + opcion('menor', 'menor', pre.gravedad ?? 'menor') +
      opcion('mayor', 'mayor', pre.gravedad ?? 'menor') + '</select></div>' +
    '<div class="app-campo"><label for="nc-resp">Responsable <span class="conf-opcional">(opcional)</span></label><select id="nc-resp"><option value="">—</option>' +
      ncDatos.usuarios.map((u) => opcion(u.id, u.nombre + ' (' + u.rol + ')', '')).join('') + '</select></div>' +
    '<div class="app-campo sga-ancho"><label for="nc-desc">Descripción</label><textarea id="nc-desc" maxlength="2000" rows="3">' + esc(pre.descripcion ?? '') + '</textarea></div>' +
    '</form><input type="hidden" id="nc-ref" value="' + esc(pre.ref ?? '') + '" />' +
    '<p class="trabajo-error" id="sga-error" role="alert" hidden></p>' + botones('data-sga="nc-crear"', 'Abrir'));
  $('nc-titulo').focus({ preventScroll: true });
}

async function abrirNc(id, desplazar = true) {
  ncAbierta = id;
  abierto = null;
  let d;
  try {
    d = await api.pedir('/sga/nc/' + id);
  } catch (e) {
    panel('<p class="app-titulo">No se pudo abrir</p><p class="app-nota">' + esc(e.message) + '</p>' + botones('data-sga="cerrar"', 'Cerrar'));
    return;
  }
  const adm = ctx.soyAdmin;
  const vivo = d.estado === 'abierta' || d.estado === 'en_tratamiento';
  const acciones = d.acciones.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Acción</th><th>Vence</th><th>Estado y evidencia</th><th></th></tr></thead><tbody>' +
      d.acciones.map((a) => '<tr data-accion="' + a.id + '"><td><b>' + esc(a.tipo_texto) + '</b><div>' + esc(a.descripcion) + '</div>' +
          (a.responsable ? '<div class="cli-falta">' + esc(a.responsable) + '</div>' : '') + '</td>' +
        '<td>' + fechaCorta(a.vence) + (a.vencida ? ' <span class="sello sello--mal">vencida</span>' : '') + '</td>' +
        '<td>' + sello(ESTADO_ACCION[a.estado]) + (a.sin_efecto ? ' <span class="sello sello--gris">no fue eficaz</span>' : '') +
          (a.evidencia ? '<div>' + esc(a.evidencia) + '</div><div class="cli-falta">' + esc(a.cumplida_por ?? '') + ' · ' + diaArt(a.cumplida_utc) + '</div>' : '') +
          (a.descartada_motivo ? '<div class="cli-falta">Descartada: ' + esc(a.descartada_motivo) + '</div>' : '') +
          (a.archivo ? '<button class="app-btn rec-mini" type="button" data-sga="nc-archivo" data-id="' + a.id + '">Ver el archivo</button>' : '') + '</td>' +
        '<td>' + (a.estado === 'pendiente' && vivo
          ? '<div class="app-campo"><label for="nca-ev-' + a.id + '">Qué se hizo y dónde se comprueba (o por qué se descarta)</label><input id="nca-ev-' + a.id + '" maxlength="2000" /></div>' +
            '<div class="filtro-fecha sga-subir"><div class="app-campo"><label for="nca-arch-' + a.id + '">Foto o PDF <span class="conf-opcional">(opcional)</span></label>' +
            '<input id="nca-arch-' + a.id + '" type="file" accept="application/pdf,image/jpeg,image/png" /></div></div>' +
            '<div class="cli-acciones"><button class="app-btn rec-mini app-btn--principal" type="button" data-sga="nc-cumplir" data-id="' + a.id + '">Cumplida</button>' +
            '<button class="app-btn rec-mini" type="button" data-sga="nc-descartar" data-id="' + a.id + '">Descartar</button></div>'
          : '') + '</td></tr>').join('') + '</tbody></table></div>'
    : '<p class="app-nota">Todavía no tiene acciones.</p>';

  const partes = [];
  if (vivo) {
    partes.push('<p class="app-titulo sga-sub">Causa</p><div class="app-campo"><label for="nc-causa">Qué la produjo (no qué pasó)</label>' +
      '<textarea id="nc-causa" maxlength="2000" rows="3">' + esc(d.causa ?? '') + '</textarea></div>' +
      '<div class="cli-acciones"><button class="app-btn rec-mini" type="button" data-sga="nc-causa" data-id="' + d.id + '">Guardar la causa</button></div>');
  } else if (d.causa) {
    partes.push('<p class="app-titulo sga-sub">Causa</p><p>' + esc(d.causa) + '</p>');
  }
  partes.push('<p class="app-titulo sga-sub">Acciones</p>' + acciones);
  if (vivo) {
    partes.push('<form class="cli-form"><div class="app-campo"><label for="nca-tipo">Nueva acción</label><select id="nca-tipo">' +
        Object.entries(ncDatos.tipos_accion).map(([k, t]) => opcion(k, t, 'correctiva')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="nca-desc">Qué hay que hacer</label><input id="nca-desc" maxlength="1000" /></div>' +
      '<div class="app-campo"><label for="nca-resp">Responsable <span class="conf-opcional">(opcional)</span></label><select id="nca-resp"><option value="">—</option>' +
        ncDatos.usuarios.map((u) => opcion(u.id, u.nombre, '')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="nca-vence">Para cuándo</label><input id="nca-vence" type="date" value="' + sumarDias(hoyArt(), 7) + '" /></div></form>' +
      '<div class="cli-acciones"><button class="app-btn rec-mini" type="button" data-sga="nc-accion" data-id="' + d.id + '">Agregar la acción</button>' +
      (d.estado === 'en_tratamiento' ? '<button class="app-btn rec-mini app-btn--principal" type="button" data-sga="nc-a-verificar" data-id="' + d.id + '">Enviar a verificación de eficacia</button>' : '') +
      '</div>');
  }
  if (d.estado === 'en_verificacion') {
    partes.push('<p class="app-titulo sga-sub">Verificación de eficacia</p>' + (adm
      ? '<div class="app-campo"><label for="nc-verif">Cómo se comprobó que la causa no volvió</label><textarea id="nc-verif" maxlength="2000" rows="2"></textarea></div>' +
        '<div class="cli-acciones"><button class="app-btn rec-mini app-btn--principal" type="button" data-sga="nc-eficaz" data-id="' + d.id + '">Fue eficaz: cerrar</button>' +
        '<button class="app-btn rec-mini" type="button" data-sga="nc-no-eficaz" data-id="' + d.id + '">No fue eficaz: volver a tratamiento</button></div>'
      : '<p class="app-nota">La verifica la dirección.</p>'));
  }
  if (d.verificacion) partes.push('<p class="app-titulo sga-sub">Última verificación</p><p>' + esc(d.verificacion) +
    (d.estado === 'cerrada' ? ' <span class="cli-falta">Cerró ' + esc(d.cerro ?? '') + ' · ' + diaArt(d.cerrada_utc) + '</span>' : '') + '</p>');
  if (adm && ['abierta', 'en_tratamiento', 'en_verificacion'].includes(d.estado)) {
    partes.push('<div class="filtro-fecha"><div class="app-campo"><label for="nc-anular-motivo">Anular (motivo)</label><input id="nc-anular-motivo" maxlength="255" /></div>' +
      '<button class="app-btn rec-mini app-btn--peligro" type="button" data-sga="nc-anular" data-id="' + d.id + '">Anular</button></div>');
  }

  panel('<div class="tar-cabeza"><p class="app-titulo">' + esc(d.numero) + ' · ' + esc(d.titulo) + '</p>' +
    '<button class="app-btn rec-mini" type="button" data-sga="cerrar">Cerrar</button></div>' +
    '<p class="app-nota">' + sello(ESTADO_NC[d.estado]) + ' ' + esc(d.origen_texto) + (d.origen_ref ? ' (' + esc(d.origen_ref) + ')' : '') +
      ' · detectada el ' + fechaCorta(d.detectada_fecha) + (d.detecto ? ' por ' + esc(d.detecto) : '') +
      (d.responsable ? ' · responsable ' + esc(d.responsable) : '') + ' · gravedad ' + esc(d.gravedad) + '</p>' +
    '<p>' + esc(d.descripcion) + '</p>' + (d.anulada_motivo ? '<p class="cli-falta">Anulada: ' + esc(d.anulada_motivo) + '</p>' : '') +
    '<p class="trabajo-error" id="sga-error" role="alert" hidden></p>' + partes.join(''));
  if (desplazar) $('amb-panel').querySelector('.app-titulo')?.focus?.({ preventScroll: true });
}

async function accionNc(a, b) {
  const id = b.dataset.id;
  if (a === 'nc-crear') {
    const r = await api.pedir('/sga/nc', { metodo: 'POST', cuerpo: {
      origen: $('nc-origen').value, origen_ref: $('nc-ref').value || undefined, titulo: $('nc-titulo').value.trim(),
      descripcion: $('nc-desc').value.trim(), gravedad: $('nc-gravedad').value, responsable_id: Number($('nc-resp').value) || undefined } });
    await cargarNc();
    await abrirNc(r.id);
    return;
  }
  if (a === 'nc-causa') {
    await api.pedir('/sga/nc/' + id + '/causa', { metodo: 'POST', cuerpo: { causa: $('nc-causa').value.trim() } });
  } else if (a === 'nc-accion') {
    await api.pedir('/sga/nc/' + id + '/accion', { metodo: 'POST', cuerpo: { tipo: $('nca-tipo').value, descripcion: $('nca-desc').value.trim(),
      responsable_id: Number($('nca-resp').value) || undefined, vence: $('nca-vence').value } });
  } else if (a === 'nc-cumplir') {
    const archivo = $('nca-arch-' + id).files[0];
    if (archivo) {
      await api.pedir('/sga/nc/accion/' + id + '/archivo', { metodo: 'POST', cuerpo: archivo, timeout: 120000,
        headers: { 'X-Camca-Nombre': encodeURIComponent(archivo.name) } });
    }
    await api.pedir('/sga/nc/accion/' + id + '/cumplir', { metodo: 'POST', cuerpo: { evidencia: $('nca-ev-' + id).value.trim() } });
  } else if (a === 'nc-descartar') {
    await api.pedir('/sga/nc/accion/' + id + '/descartar', { metodo: 'POST', cuerpo: { motivo: $('nca-ev-' + id).value.trim() } });
  } else if (a === 'nc-a-verificar') {
    await api.pedir('/sga/nc/' + id + '/verificacion', { metodo: 'POST', cuerpo: {} });
  } else if (a === 'nc-eficaz' || a === 'nc-no-eficaz') {
    await api.pedir('/sga/nc/' + id + '/eficacia', { metodo: 'POST', cuerpo: { eficaz: a === 'nc-eficaz', verificacion: $('nc-verif').value.trim() } });
  } else if (a === 'nc-anular') {
    await api.pedir('/sga/nc/' + id + '/anular', { metodo: 'POST', cuerpo: { motivo: $('nc-anular-motivo').value.trim() } });
  }
  await cargarNc();
}

const REVISION = {
  vencido: ['sello--mal', 'revisión vencida'],
  por_vencer: ['sello--aviso', 'revisar pronto'],
};
const ESTADO_VERSION = {
  borrador: ['sello--gris', 'borrador'], en_revision: ['sello--aviso', 'en revisión'],
  vigente: ['sello--ok', 'vigente'], obsoleta: ['sello--gris', 'obsoleta'], descartada: ['sello--gris', 'descartada'],
};
const sello = ([clase, texto]) => '<span class="sello ' + clase + '">' + esc(texto) + '</span>';

function pintar() {
  const d = docDatos;
  const pendientes = d.mis_pendientes.length
    ? '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Tenés que leer</p><ul class="rec-alertas">' +
      d.mis_pendientes.map((p) => '<li><b class="trabajo-doc">' + esc(p.codigo) + ' v' + p.version + '</b> ' + esc(p.titulo) +
        ' <button class="app-btn rec-mini" type="button" data-sga="abrir-archivo" data-version="' + p.version_id + '">Abrir</button>' +
        ' <button class="app-btn rec-mini" type="button" data-sga="leido" data-version="' + p.version_id + '">Lo leí</button></li>').join('') +
      '</ul><p class="trabajo-error" id="sga-error-pend" role="alert" hidden></p></div>'
    : '';

  const activos = d.documentos.filter((x) => x.activo);
  const vencidos = activos.filter((x) => x.vigente?.revision === 'vencido').length;
  const sinVigente = activos.filter((x) => !x.vigente).length;
  const conteos = '<ul class="conteos"><li><b>' + activos.length + '</b><span>documentos activos</span></li>' +
    '<li><b>' + vencidos + '</b><span>con la revisión vencida</span></li>' +
    '<li><b>' + sinVigente + '</b><span>sin versión aprobada</span></li></ul>';

  const filas = d.documentos.map((x) => {
    const vig = x.vigente;
    const estado = !x.activo ? sello(['sello--gris', 'retirado'])
      : !vig ? sello(['sello--aviso', 'sin versión vigente'])
      : (REVISION[vig.revision] ? sello(REVISION[vig.revision]) : sello(['sello--ok', 'en fecha']));
    return '<tr data-doc="' + x.id + '"><td><b class="trabajo-doc">' + esc(x.codigo) + '</b><div class="cli-falta">' + esc(x.tipo_texto) + '</div></td>' +
      '<td>' + esc(x.titulo) + (x.proceso ? '<div class="cli-falta">' + esc(x.proceso) + '</div>' : '') + '</td>' +
      '<td>' + (vig ? 'v' + vig.version + '<div class="cli-falta">desde ' + fechaCorta(vig.desde) + '</div>' : '—') + '</td>' +
      '<td>' + estado + (vig?.revisar_antes ? '<div class="cli-falta">revisar antes del ' + fechaCorta(vig.revisar_antes) + '</div>' : '') + '</td>' +
      '<td>' + (vig && vig.distribuidos ? vig.leidos + ' de ' + vig.distribuidos : '—') + '</td>' +
      '<td>' + (x.en_curso ? 'v' + x.en_curso.version + ' ' + sello(ESTADO_VERSION[x.en_curso.estado]) : '') + '</td>' +
      '<td><button class="app-btn rec-mini" type="button" data-sga="ver" data-doc="' + x.id + '">Ver</button></td></tr>';
  }).join('');

  $('amb-leer').innerHTML = pendientes;
  $('amb-docs').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Documentos controlados</p>' +
    '<button class="app-btn" type="button" data-sga="nuevo">Nuevo documento</button></div>' + conteos +
    (d.documentos.length
      ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Código</th><th>Título</th><th>Vigente</th><th>Revisión</th>' +
        '<th>Leído</th><th>En curso</th><th></th></tr></thead><tbody>' + filas + '</tbody></table></div>'
      : '<p class="app-nota">Todavía no hay documentos. Empezá por la política ambiental y el procedimiento de disposición de efluentes.</p>') +
    '</div>';
}

function panel(html) {
  $('amb-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html + '</div>' : '';
  if (html) $('amb-panel').scrollIntoView({ block: 'start' });
}

function mostrarError(e, id = 'sga-error') {
  const p = $(id);
  if (!p) return;
  p.textContent = e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.');
  p.hidden = false;
}

const botones = (accionData, texto) => '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" ' + accionData + '>' +
  texto + '</button><button class="app-btn" type="button" data-sga="cerrar">Cerrar</button></div>';

function formNuevo() {
  panel('<p class="app-titulo">Nuevo documento</p>' +
    '<p class="app-nota">Nace con su versión 1 en borrador. Después se le sube el PDF y se envía a revisión.</p>' +
    '<form class="cli-form"><div class="app-campo"><label for="sga-codigo">Código</label><input id="sga-codigo" maxlength="30" placeholder="PR-AMB-001" /></div>' +
    '<div class="app-campo"><label for="sga-titulo">Título</label><input id="sga-titulo" maxlength="160" /></div>' +
    '<div class="app-campo"><label for="sga-tipo">Tipo</label><select id="sga-tipo">' +
      Object.entries(docDatos.tipos).map(([k, t]) => opcion(k, t, 'procedimiento')).join('') + '</select></div>' +
    '<div class="app-campo"><label for="sga-proceso">Proceso <span class="conf-opcional">(opcional)</span></label><input id="sga-proceso" maxlength="80" /></div>' +
    '<div class="app-campo"><label for="sga-meses">Revisar cada (meses)</label><input id="sga-meses" type="number" min="1" max="60" value="12" /></div>' +
    '</form><p class="trabajo-error" id="sga-error" role="alert" hidden></p>' + botones('data-sga="crear"', 'Crear'));
  $('sga-codigo').focus({ preventScroll: true });
}

async function abrirDetalle(id, desplazar = true) {
  abierto = id;
  ncAbierta = null;
  let d;
  try {
    d = await api.pedir('/sga/documento/' + id);
  } catch (e) {
    panel('<p class="app-titulo">No se pudo abrir</p><p class="app-nota">' + esc(e.message) + '</p>' + botones('data-sga="cerrar"', 'Cerrar'));
    return;
  }
  const adm = ctx.soyAdmin;
  const vig = d.versiones.find((v) => v.estado === 'vigente');
  const enCurso = d.versiones.find((v) => v.estado === 'borrador' || v.estado === 'en_revision');

  const versiones = d.versiones.map((v) => {
    let acciones = '';
    if (v.archivo) acciones += '<button class="app-btn rec-mini" type="button" data-sga="abrir-archivo" data-version="' + v.version_id + '">Abrir PDF</button> ';
    if (v.estado === 'borrador' && d.activo) {
      acciones += '<div class="filtro-fecha sga-subir"><div class="app-campo"><label for="sga-pdf-' + v.version_id + '">PDF de la v' + v.version + '</label>' +
        '<input id="sga-pdf-' + v.version_id + '" type="file" accept="application/pdf" /></div>' +
        '<button class="app-btn rec-mini" type="button" data-sga="subir" data-version="' + v.version_id + '">Subir</button></div>' +
        '<button class="app-btn rec-mini app-btn--principal" type="button" data-sga="enviar" data-version="' + v.version_id + '"' + (v.archivo ? '' : ' disabled') + '>Enviar a revisión</button> ' +
        '<button class="app-btn rec-mini" type="button" data-sga="pedir-motivo" data-que="descartar" data-version="' + v.version_id + '">Descartar</button>';
    }
    if (v.estado === 'en_revision' && adm) {
      acciones += '<button class="app-btn rec-mini app-btn--principal" type="button" data-sga="aprobar" data-version="' + v.version_id + '">Aprobar</button> ' +
        '<button class="app-btn rec-mini" type="button" data-sga="pedir-motivo" data-que="devolver" data-version="' + v.version_id + '">Devolver</button>';
    }
    const quien = [v.elaborado_por ? 'elaboró ' + esc(v.elaborado_por) : '',
                   v.aprobado_por ? 'aprobó ' + esc(v.aprobado_por) + ' el ' + diaArt(v.aprobado_utc) : ''].filter(Boolean).join(' · ');
    return '<tr data-version="' + v.version_id + '"><td><b>v' + v.version + '</b></td><td>' + sello(ESTADO_VERSION[v.estado]) +
      (v.revision && REVISION[v.revision] ? ' ' + sello(REVISION[v.revision]) : '') +
      (v.devuelta_motivo ? '<div class="cli-falta">' + (v.estado === 'descartada' ? 'Descartada: ' : 'Devuelta: ') + esc(v.devuelta_motivo) + '</div>' : '') + '</td>' +
      '<td>' + esc(v.cambios) + '<div class="cli-falta">' + quien + '</div>' +
      (v.archivo ? '<div class="aud-datos">' + esc(v.archivo.nombre) + ' · sha256 ' + esc(v.archivo.sha256.slice(0, 16)) + '…</div>' : '') + '</td>' +
      '<td>' + (v.vigente_desde ? 'desde ' + fechaCorta(v.vigente_desde) : '') + (v.revisar_antes ? '<div class="cli-falta">revisar antes del ' + fechaCorta(v.revisar_antes) + '</div>' : '') + '</td>' +
      '<td>' + acciones + '</td></tr>';
  }).join('');

  // Distribución de la vigente: quién leyó y quién falta.
  const dist = vig ? d.distribucion.filter((x) => x.version_id === vig.version_id) : [];
  const distHtml = vig
    ? (dist.length
        ? '<ul class="rec-alertas">' + dist.map((x) => '<li>' + esc(x.nombre) + ' ' +
            (x.leida_utc ? sello(['sello--ok', 'leído ' + diaArt(x.leida_utc)]) + (x.registrada_por ? ' <span class="cli-falta">registró ' + esc(x.registrada_por) + '</span>' : '')
                         : sello(['sello--aviso', 'falta leer']) + ' <button class="app-btn rec-mini" type="button" data-sga="entregado" data-version="' + vig.version_id + '" data-usuario="' + x.usuario_id + '">Registrar entrega en mano</button>') +
            '</li>').join('') + '</ul>'
        : '<p class="app-nota">La v' + vig.version + ' no se distribuyó a nadie. Elegí a quién abajo.</p>')
    : '';
  const marcados = new Set(d.destinatarios.map((x) => x.usuario_id));
  const lista = d.activo
    ? '<fieldset class="plan-activos"><legend>Lista de distribución</legend>' +
      docDatos.usuarios.map((u) => '<label><input type="checkbox" name="sga-dest" value="' + u.id + '"' + (marcados.has(u.id) ? ' checked' : '') + ' /> ' +
        esc(u.nombre) + ' <span class="cli-falta">' + esc(u.rol) + '</span></label>').join('') +
      '</fieldset><div class="cli-acciones"><button class="app-btn rec-mini" type="button" data-sga="destinatarios" data-doc="' + d.id + '">Guardar la lista</button></div>'
    : '';

  const cabeza = [];
  if (d.activo && !enCurso) {
    cabeza.push('<div class="filtro-fecha"><div class="app-campo"><label for="sga-cambios">Qué cambia en la versión nueva</label><input id="sga-cambios" maxlength="1000" /></div>' +
      '<button class="app-btn rec-mini" type="button" data-sga="nueva-version" data-doc="' + d.id + '">Abrir versión nueva</button></div>');
  }
  if (d.activo && vig && adm) {
    cabeza.push('<button class="app-btn rec-mini" type="button" data-sga="confirmar" data-doc="' + d.id + '">Revisada sin cambios: confirmar vigencia</button>');
  }
  if (d.activo && adm) {
    cabeza.push('<button class="app-btn rec-mini" type="button" data-sga="pedir-motivo" data-que="retirar" data-doc="' + d.id + '">Retirar el documento</button>');
  }

  panel('<div class="tar-cabeza"><p class="app-titulo">' + esc(d.codigo) + ' · ' + esc(d.titulo) + '</p>' +
    '<button class="app-btn rec-mini" type="button" data-sga="cerrar">Cerrar</button></div>' +
    '<p class="app-nota">' + esc(d.tipo_texto) + (d.responsable ? ' · responsable ' + esc(d.responsable) : '') + ' · se revisa cada ' + d.revision_meses + ' meses' +
      (d.activo ? '' : ' · <b>retirado</b>: ' + esc(d.retirado_motivo)) + '</p>' +
    '<p class="trabajo-error" id="sga-error" role="alert" hidden></p>' +
    '<div id="sga-motivo"></div>' +
    (cabeza.length ? '<div class="cli-acciones">' + cabeza.join('') + '</div>' : '') +
    '<p class="app-titulo sga-sub">Versiones</p><div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Versión</th><th>Estado</th>' +
    '<th>Qué cambió</th><th>Vigencia</th><th></th></tr></thead><tbody>' + versiones + '</tbody></table></div>' +
    (vig ? '<p class="app-titulo sga-sub">Toma de conocimiento de la v' + vig.version + '</p>' + distHtml : '') + lista);
  if (!desplazar) return;
  $('amb-panel').querySelector('.app-titulo')?.setAttribute('tabindex', '-1');
  $('amb-panel').querySelector('.app-titulo')?.focus({ preventScroll: true });
}

async function abrirArchivo(versionId) {
  const ventana = window.open('', '_blank');
  try {
    const blob = await api.documento('/sga/version/' + versionId + '/archivo');
    const url = URL.createObjectURL(blob);
    if (ventana) ventana.location.href = url; else location.href = url;
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) {
    ventana?.close();
    mostrarError(e);
  }
}

const MOTIVO = {
  descartar: ['Por qué se descarta este borrador', 'Descartar'],
  devolver: ['Qué hay que corregir', 'Devolver'],
  retirar: ['Por qué se retira el documento', 'Retirar'],
};

async function accion(b) {
  const a = b.dataset.sga;
  if (a === 'cerrar') { abierto = null; ncAbierta = null; panel(''); return; }
  if (a === 'nc-nueva') { formNc(); return; }
  if (a === 'nc-ver') { await abrirNc(Number(b.dataset.id)); return; }
  if (a === 'nc-desde-alerta') {
    const al = dispDatos.alertas[Number(b.dataset.alerta)];
    formNc({ origen: 'ambiental', gravedad: al.nivel === 'alta' ? 'mayor' : 'menor',
      ref: al.jornada_id ? 'jornada:' + al.jornada_id : (al.planta_id ? 'planta:' + al.planta_id : ''),
      titulo: (ETIQUETA_ALERTA[al.codigo] ?? al.codigo).replace(/^./, (c) => c.toUpperCase()) + (al.fecha ? ' · ' + fechaCorta(al.fecha) + ' · ' + al.ruta : ''),
      descripcion: al.texto });
    return;
  }
  if (a === 'nc-archivo') {
    const ventana = window.open('', '_blank');
    try {
      const blob = await api.documento('/sga/nc/accion/' + b.dataset.id + '/archivo');
      const url = URL.createObjectURL(blob);
      if (ventana) ventana.location.href = url; else location.href = url;
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      ventana?.close();
      mostrarError(e);
    }
    return;
  }
  if (a.startsWith('nc-')) {
    b.disabled = true;
    try {
      await accionNc(a, b);
    } catch (e) {
      b.disabled = false;
      mostrarError(e);
    }
    return;
  }
  if (a === 'amb-nueva') { formDescarga(b.dataset.jornada); return; }
  if (a === 'amb-planta') { formPlanta(b.dataset.id); return; }
  if (a === 'amb-registro') { await abrirRegistro(); return; }
  if (a === 'amb-ver') {
    dispDesde = $('amb-desde').value || dispDesde;
    dispHasta = $('amb-hasta').value || dispHasta;
    await cargarDisposicion();
    return;
  }
  if (a === 'amb-pedir-anular') {
    const x = dispDatos.descargas.find((y) => y.id === Number(b.dataset.id));
    panel('<p class="app-titulo">Anular la descarga ' + esc(x?.manifiesto ?? '') + '</p>' +
      '<p class="app-nota">No se borra: queda anulada con el motivo, y el manifiesto se libera para cargarlo bien.</p>' +
      '<form class="cli-form"><div class="app-campo"><label for="amb-motivo">Motivo</label><input id="amb-motivo" maxlength="255" /></div></form>' +
      '<p class="trabajo-error" id="sga-error" role="alert" hidden></p>' + botones('data-sga="amb-anular" data-id="' + b.dataset.id + '"', 'Anular'));
    $('amb-motivo').focus({ preventScroll: true });
    return;
  }
  if (a === 'nuevo') { formNuevo(); return; }
  if (a === 'ver') { await abrirDetalle(Number(b.dataset.doc)); return; }
  if (a === 'abrir-archivo') { await abrirArchivo(b.dataset.version); return; }
  if (a === 'pedir-motivo') {
    const [etiqueta, texto] = MOTIVO[b.dataset.que];
    const dato = b.dataset.version ? 'data-version="' + b.dataset.version + '"' : 'data-doc="' + b.dataset.doc + '"';
    $('sga-motivo').innerHTML = '<div class="filtro-fecha"><div class="app-campo"><label for="sga-motivo-txt">' + etiqueta + '</label>' +
      '<input id="sga-motivo-txt" maxlength="255" /></div><button class="app-btn rec-mini app-btn--peligro" type="button" data-sga="' + b.dataset.que + '" ' + dato + '>' +
      texto + '</button></div>';
    $('sga-motivo-txt').focus();
    return;
  }

  b.disabled = true;
  const v = b.dataset.version;
  try {
    if (a === 'amb-guardar') {
      await api.pedir('/ambiental/descarga', { metodo: 'POST', cuerpo: {
        fecha: $('amb-fecha').value, planta_id: Number($('amb-planta').value) || 0,
        vehiculo_id: Number($('amb-vehiculo').value) || undefined, litros: Number($('amb-litros').value) || 0,
        manifiesto: $('amb-manifiesto').value, observaciones: $('amb-obs').value.trim() || undefined,
        jornadas: [...document.querySelectorAll('input[name="amb-jornada"]:checked')].map((x) => Number(x.value)) } });
      panel('');
      await cargarDisposicion();
      return;
    }
    if (a === 'amb-guardar-planta') {
      const cuerpo = { nombre: $('pl-nombre').value.trim(), operador: $('pl-operador').value.trim() || undefined,
        habilitacion: $('pl-hab').value.trim(), habilitacion_vence: $('pl-vence').value || undefined,
        direccion: $('pl-dir').value.trim() || undefined };
      if (b.dataset.id) { cuerpo.id = Number(b.dataset.id); cuerpo.activa = $('pl-activa').value === '1'; }
      await api.pedir('/ambiental/planta', { metodo: 'POST', cuerpo });
      panel('');
      await cargarDisposicion();
      return;
    }
    if (a === 'amb-anular') {
      await api.pedir('/ambiental/descarga/anular', { metodo: 'POST', cuerpo: { id: Number(b.dataset.id), motivo: $('amb-motivo').value.trim() } });
      panel('');
      await cargarDisposicion();
      return;
    }
    if (a === 'crear') {
      const r = await api.pedir('/sga/documento', { metodo: 'POST', cuerpo: {
        codigo: $('sga-codigo').value, titulo: $('sga-titulo').value.trim(), tipo: $('sga-tipo').value,
        proceso: $('sga-proceso').value.trim() || undefined, revision_meses: Number($('sga-meses').value) || 12 } });
      await cargarDocumentos();
      await abrirDetalle(r.id);
      return;
    }
    if (a === 'leido') {
      await api.pedir('/sga/version/' + v + '/conocimiento', { metodo: 'POST', cuerpo: {} });
    } else if (a === 'entregado') {
      await api.pedir('/sga/version/' + v + '/conocimiento', { metodo: 'POST', cuerpo: { usuario_id: Number(b.dataset.usuario) } });
    } else if (a === 'subir') {
      const archivo = $('sga-pdf-' + v).files[0];
      if (!archivo) { b.disabled = false; mostrarError({ message: 'Elegí el PDF primero.' }); return; }
      await api.pedir('/sga/version/' + v + '/archivo', { metodo: 'POST', cuerpo: archivo, timeout: 120000,
        headers: { 'X-Camca-Nombre': encodeURIComponent(archivo.name) } });
    } else if (a === 'enviar' || a === 'aprobar') {
      await api.pedir('/sga/version/' + v + '/' + a, { metodo: 'POST', cuerpo: {} });
    } else if (a === 'descartar' || a === 'devolver') {
      await api.pedir('/sga/version/' + v + '/' + a, { metodo: 'POST', cuerpo: { motivo: $('sga-motivo-txt').value.trim() } });
    } else if (a === 'retirar') {
      await api.pedir('/sga/documento/' + b.dataset.doc + '/retirar', { metodo: 'POST', cuerpo: { motivo: $('sga-motivo-txt').value.trim() } });
    } else if (a === 'confirmar') {
      await api.pedir('/sga/documento/' + b.dataset.doc + '/confirmar', { metodo: 'POST', cuerpo: {} });
    } else if (a === 'nueva-version') {
      await api.pedir('/sga/documento/' + b.dataset.doc + '/version', { metodo: 'POST', cuerpo: { cambios: $('sga-cambios').value.trim() } });
    } else if (a === 'destinatarios') {
      const usuarios = [...document.querySelectorAll('input[name="sga-dest"]:checked')].map((x) => Number(x.value));
      await api.pedir('/sga/documento/' + b.dataset.doc + '/destinatarios', { metodo: 'POST', cuerpo: { usuarios } });
    }
    await cargarDocumentos();
  } catch (e) {
    b.disabled = false;
    mostrarError(e, a === 'leido' ? 'sga-error-pend' : 'sga-error');
  }
}
