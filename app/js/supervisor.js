// ============================================================
// Panel de oficina.
//
// A diferencia de la app de campo, esta pantalla NO es offline-first: se usa
// desde una computadora con red. Si no hay conexión lo dice y no finge.
//
// El criterio que ordena todo: la pantalla nunca muestra un día como cerrado
// si le falta algo. Un tilde verde sobre una jornada con doce fotos todavía
// adentro de un teléfono es peor que no mostrar nada, porque alguien la
// archiva y el faltante aparece semanas después.
// ============================================================

import * as api from './api.js';
import * as sesion from './sesion.js';
import * as fmt from './formato.js';
import * as sga from './sga.js';
import * as ocr from './ocr.js';
import * as usuarios from './usuarios.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// El estado interno no se muestra crudo: "en_curso" no le dice nada a quien
// coordina, y "cerrada" contra "cerrada_confirmada" es justamente la distincion
// que importa (una tiene evidencias sin subir y la otra no).
const ESTADOS = {
  planificada: 'planificada',
  en_curso: 'en curso',
  cerrada: 'cerrada, con evidencias subiendo',
  cerrada_confirmada: 'cerrada y confirmada',
};

const fechaArt = (d = new Date()) =>
  new Date(d.getTime() - 3 * 3600 * 1000).toISOString().slice(0, 10);

function horaLocal(iso) {
  if (!iso) return '—';
  const d = new Date(iso.replace(' ', 'T') + (iso.endsWith('Z') ? '' : 'Z'));
  return d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
}

function marcarConexion(ok, texto) {
  window.dispatchEvent(new CustomEvent('camca:estado', {
    detail: { estado: ok ? 'en-linea' : 'error', texto },
  }));
}

function error(contenedor, e) {
  marcarConexion(false, e?.esAuth ? 'sesión vencida' : 'sin conexión');
  const msg = e?.esAuth
    ? 'Tu sesión venció. Volvé a entrar.'
    : e?.estado === 0
      ? 'Sin conexión con el servidor.'
      : (e?.message || 'No se pudo cargar.');
  $(contenedor).innerHTML = '<div class="app-tarjeta"><p class="app-titulo">No se pudo cargar</p>' +
    '<p class="app-nota">' + esc(msg) + '</p></div>';
}

// ------------------------------------------------------------------
// Cierre del día
// ------------------------------------------------------------------
async function cargarJornadas() {
  const fecha = $('fecha').value || fechaArt();
  $('jornadas').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const d = await api.pedir('/jornadas?fecha=' + encodeURIComponent(fecha));
    marcarConexion(true, 'conectado');
    pintarJornadas(d);
  } catch (e) {
    error('jornadas', e);
  }
}

function pintarJornadas(d) {
  if (!d.jornadas.length) {
    $('jornadas').innerHTML = '<div class="app-tarjeta"><p class="app-titulo">Sin jornadas</p>' +
      '<p class="app-nota">No hay ninguna ruta planificada para esa fecha.</p></div>';
    return;
  }

  const cabecera = d.resumen.con_alertas > 0
    ? '<div class="app-pendientes" style="margin-bottom:16px">' +
      d.resumen.con_alertas + ' de ' + d.resumen.jornadas +
      (d.resumen.jornadas === 1 ? ' jornada tiene' : ' jornadas tienen') +
      ' algo sin resolver. No las des por cerradas todavía.</div>'
    : '';

  $('jornadas').innerHTML = cabecera + d.jornadas.map((j) => {
    const sello = j.confiable
      ? '<span class="sello sello--ok">completa</span>'
      : '<span class="sello sello--aviso">falta algo</span>';

    // Los km se presentan por lo que son. Sin odómetro es una estimación por
    // posiciones, y el haversine infla por ruido de GPS: decirle "real" a eso
    // es mentir en un número que puede terminar en una discusión de factura.
    // El formato sale de formato.js y no de toFixed(): en es-AR el separador
    // decimal es la coma, y "12.4 km" se lee como doce mil cuatrocientos.
    const km = j.km_real === null
      ? fmt.km(j.km_estimado) + ' estimados'
      : (j.km_fuente === 'odometro'
          ? fmt.km(j.km_real) + ' (odómetro)'
          : fmt.km(j.km_real) + ' (aproximado por posiciones)');

    // La misma barra y la misma leyenda que ve el chofer. Que las dos
    // pantallas cuenten la jornada con la misma gramática es lo que permite
    // hablar por teléfono sin tener que traducir.
    const total = j.total || 0;
    const ancho = (n) => (total ? ((n / total) * 100).toFixed(2) : '0') + '%';
    const leyenda = [
      { marca: 'm-confirmada', n: j.hechas, texto: 'hechas' },
      { marca: 'm-motivo', n: j.no_hechas, texto: 'con motivo' },
      { marca: 'm-resta', n: j.pendientes, texto: 'sin resolver' },
    ].filter((x) => x.n > 0);

    const barra =
      '<div class="barra" role="img" aria-label="' +
        esc(leyenda.map((x) => x.n + ' ' + x.texto).join(', ') || 'Sin paradas') + '">' +
        '<div class="barra-seg barra-seg--confirmada" style="width:' + ancho(j.hechas) + '"></div>' +
        '<div class="barra-seg barra-seg--motivo" style="width:' + ancho(j.no_hechas) + '"></div>' +
      '</div>' +
      '<ul class="leyenda">' +
        leyenda.map((x) => '<li><i class="' + x.marca + '"></i><b>' + x.n + '</b> ' + x.texto + '</li>').join('') +
      '</ul>';

    return '<article class="app-tarjeta jornada">' +
      '<div class="jornada-cabeza">' +
        '<div><p class="app-titulo" style="margin:0">' + esc(j.ruta) + '</p>' +
        '<p class="app-nota">' + esc(j.chofer || 'sin chofer asignado') + ' · ' + esc(ESTADOS[j.estado] ?? j.estado) + '</p></div>' +
        sello +
      '</div>' +
      barra +
      '<div class="jornada-cifras">' +
        '<div class="cifra"><b>' + j.hechas + '</b><span>hechas</span></div>' +
        '<div class="cifra"><b>' + j.no_hechas + '</b><span>no hechas</span></div>' +
        '<div class="cifra"><b>' + j.pendientes + '</b><span>sin resolver</span></div>' +
        '<div class="cifra"><b>' + j.evidencias.ok + '</b><span>evidencias</span></div>' +
        // La evidencia incompleta sube al renglón de cifras. Estaba solo en la
        // lista de alertas, abajo: se leía después de haber decidido que la
        // jornada estaba bien.
        (j.evidencias.incompletas > 0
          ? '<div class="cifra cifra--aviso"><b>' + j.evidencias.incompletas +
            '</b><span>sin subir</span></div>'
          : '') +
      '</div>' +
      '<p class="app-nota">' + esc(km) +
        (j.cerrada ? ' · cerrada ' + horaLocal(j.cerrada) : '') + '</p>' +
      (j.alertas.length
        ? '<ul class="alertas">' + j.alertas.map((a) => '<li>' + esc(a) + '</li>').join('') + '</ul>' +
          // Una alerta que no lleva a ningún lado se lee y se deja: el botón
          // abre las paradas de ESTA jornada, en el mismo día.
          '<button class="app-btn jornada-ir" type="button" data-ir-jornada="' + j.id + '">Ver sus paradas en Documentos</button>'
        : '') +
    '</article>';
  }).join('');
}

// ------------------------------------------------------------------
// Flota
// ------------------------------------------------------------------
// ------------------------------------------------------------------
// Flota en vivo (F3.8)
//
// La pantalla pide /flota cada 30 segundos, sólo mientras la pestaña está a
// la vista y la ventana no está minimizada: un panel abierto toda la noche
// en otra pestaña no tiene por qué seguir preguntando. Wialon no aparece por
// ningún lado: las posiciones salen de nuestra base, que llena el cron.
// ------------------------------------------------------------------
const FLOTA_CADA_MS = 30000;
let flotaTimer = null;

async function cargarFlota(silencioso = false) {
  if (!silencioso) $('flota').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const d = await api.pedir('/flota');
    marcarConexion(true, 'conectado');
    pintarFlota(d);
  } catch (e) {
    if (!silencioso) error('flota', e);
    else marcarConexion(false, 'sin conexión');
  }
  programarFlota();
}

function programarFlota() {
  clearTimeout(flotaTimer);
  const aLaVista = () => !$('flota').closest('[data-panel]').hidden && document.visibilityState === 'visible';
  if (aLaVista()) flotaTimer = setTimeout(() => { if (aLaVista()) cargarFlota(true); }, FLOTA_CADA_MS);
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && !$('flota').closest('[data-panel]').hidden) cargarFlota(true);
});

/** Web Mercator normalizado: la misma proyección que el mapa del campo. */
function proyectarFlota(lat, lon) {
  const r = (lat * Math.PI) / 180;
  return { x: (lon + 180) / 360, y: (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 };
}

function mapaFlota(d) {
  const conPos = d.unidades.filter((u) => u.lat !== null);
  if (!conPos.length) return '';
  const pts = [...conPos.flatMap((u) => [[u.lat, u.lon], ...u.rastro]), ...d.bases.map((b) => [b.lat, b.lon])].map(([la, lo]) => proyectarFlota(la, lo));
  const minX = Math.min(...pts.map((p) => p.x)), maxX = Math.max(...pts.map((p) => p.x));
  const minY = Math.min(...pts.map((p) => p.y)), maxY = Math.max(...pts.map((p) => p.y));
  const W = 1000, H = 560, MARGEN = 60;
  // Una sola escala para los dos ejes (si no, las distancias mienten), la
  // que haga entrar lo más extendido; y un mínimo, para que una sola unidad
  // no quede con un zoom absurdo.
  const esc2 = Math.min((W - 2 * MARGEN) / Math.max(maxX - minX, 0.00012), (H - 2 * MARGEN) / Math.max(maxY - minY, 0.00012));
  const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
  const px = (p) => W / 2 + (p.x - cx) * esc2;
  const py = (p) => H / 2 + (p.y - cy) * esc2;
  const xy = (la, lo) => { const p = proyectarFlota(la, lo); return [px(p).toFixed(1), py(p).toFixed(1)]; };
  const bases = d.bases.map((b) => { const [x, y] = xy(b.lat, b.lon);
    return '<rect class="flota-base" x="' + (x - 9) + '" y="' + (y - 9) + '" width="18" height="18" rx="3"><title>' + esc(b.nombre) + '</title></rect>'; }).join('');
  const rastros = conPos.filter((u) => u.rastro.length > 1).map((u) =>
    '<polyline class="flota-rastro" points="' + u.rastro.map(([la, lo]) => xy(la, lo).join(',')).join(' ') + '" />').join('');
  const unidades = conPos.map((u) => { const [x, y] = xy(u.lat, u.lon);
    return '<g class="flota-unidad' + (u.senial_vieja ? ' flota-unidad--vieja' : '') + '"><circle cx="' + x + '" cy="' + y + '" r="11" />' +
      '<text x="' + (Number(x) + 16) + '" y="' + (Number(y) + 5) + '">' + esc(u.patente || u.nombre) + '</text>' +
      '<title>' + esc((u.patente || u.nombre) + (u.asignado ? ' · ' + u.asignado : '') + ' · hace ' + u.edad_min + ' min') + '</title></g>'; }).join('');
  return '<svg class="flota-mapa" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Mapa de la flota: ' + conPos.length + ' unidades con posición">' +
    bases + rastros + unidades + '</svg>' +
    '<ul class="leyenda flota-leyenda"><li><i class="flota-m flota-m--unidad"></i> al día</li><li><i class="flota-m flota-m--vieja"></i> señal de más de 15 min</li>' +
    '<li><i class="flota-m flota-m--base"></i> base</li><li><i class="flota-m flota-m--rastro"></i> última hora</li></ul>';
}

function pintarFlota(d) {
  // Si el enlace con Wialon está caído se dice, en vez de mostrar posiciones
  // viejas como si fueran actuales.
  const aviso = d.rastreo_vivo
    ? ''
    : '<div class="app-pendientes flota-aviso">El rastreo de flota no está respondiendo' +
      (d.ultimo_error ? ': ' + esc(d.ultimo_error) : '') +
      '. Las posiciones de abajo pueden estar viejas.</div>';
  const cabeza = '<div class="tar-cabeza flota-cabeza"><p class="app-nota" id="flota-hora" role="status">Actualizado a las ' +
    new Date(d.generado).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone: 'America/Argentina/Buenos_Aires' }) + ' · se actualiza sola cada 30 s</p>' +
    '<button class="app-btn rec-mini" type="button" id="flota-actualizar">Actualizar ahora</button></div>';

  if (!d.unidades.length) {
    $('flota').innerHTML = aviso + '<div class="app-tarjeta"><p class="app-titulo">Sin unidades</p>' +
      '<p class="app-nota">Todavía no se registró ninguna unidad. Revisá el token de Wialon en el servidor.</p></div>';
    return;
  }

  $('flota').innerHTML = aviso + '<div class="app-tarjeta tarjeta-filtro">' + cabeza + mapaFlota(d) + '</div>' +
    '<div class="app-tarjeta"><div class="tabla-envoltorio"><table class="tabla">' +
    '<thead><tr><th>Unidad</th><th>Hoy</th><th>Última posición</th><th class="num">Antigüedad</th>' +
    '<th class="num">Velocidad</th><th>Odómetro</th></tr></thead><tbody>' +
    d.unidades.map((u) => {
      const vieja = u.senial_vieja
        ? '<span class="sello sello--aviso">' + u.edad_min + ' min</span>'
        : (u.edad_min === null ? '—' : u.edad_min + ' min');
      const pos = u.lat === null ? '—'
        : '<a href="https://www.google.com/maps/search/?api=1&query=' + u.lat + ',' + u.lon +
          '" target="_blank" rel="noopener">' + u.lat.toFixed(5) + ', ' + u.lon.toFixed(5) + '</a>';
      const odo = u.reporta_odo === null ? 'sin datos aún' : (u.reporta_odo ? 'sí' : 'no reporta');
      return '<tr data-unidad="' + u.id + '"><td><b>' + esc(u.patente || u.nombre) + '</b>' + (u.patente ? '<div class="cli-falta">' + esc(u.nombre) + '</div>' : '') + '</td>' +
        '<td>' + esc(u.asignado ?? '—') + '</td>' +
        '<td>' + pos + '</td><td class="num">' + vieja + '</td>' +
        '<td class="num">' + (u.velocidad === null ? '—' : u.velocidad + ' km/h') + '</td>' +
        '<td>' + odo + '</td></tr>';
    }).join('') +
    '</tbody></table></div></div>';
  $('flota-actualizar').addEventListener('click', () => cargarFlota(true));
}

// ------------------------------------------------------------------
// Consultas del sitio
// ------------------------------------------------------------------
async function cargarConsultas() {
  $('consultas').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const d = await api.pedir('/consultas');
    marcarConexion(true, 'conectado');
    pintarConsultas(d);
  } catch (e) {
    error('consultas', e);
  }
}

function pintarConsultas(d) {
  const c = d.conteos;
  const total = Object.values(c).reduce((a, b) => a + b, 0);
  $('consultas-resumen').textContent = total === 0
    ? 'Todavía no entró ninguna consulta por el formulario del sitio.'
    : total + ' consultas · ' + (c.enviado || 0) + ' avisadas por mail · ' +
      (c.fallido || 0) + ' sin poder avisar · ' + (c.spam || 0) + ' marcadas como spam';

  if (!d.consultas.length) {
    $('consultas').innerHTML = '';
    return;
  }

  $('consultas').innerHTML = '<div class="app-tarjeta">' + d.consultas.map((q) => {
    const sello = {
      enviado: '<span class="sello sello--ok">avisada</span>',
      pendiente: '<span class="sello sello--aviso">sin avisar</span>',
      // Fallido NO significa perdida: la consulta está guardada, lo que falló
      // fue el mail. Se dice así para que nadie crea que hay que pedirla de nuevo.
      fallido: '<span class="sello sello--mal">guardada, mail no salió</span>',
      spam: '<span class="sello sello--aviso">posible spam</span>',
    }[q.estado] ?? '';

    const contacto = [
      q.email ? '<a href="mailto:' + esc(q.email) + '">' + esc(q.email) + '</a>' : null,
      q.telefono ? '<a href="tel:' + esc(q.telefono) + '">' + esc(q.telefono) + '</a>' : null,
    ].filter(Boolean).join(' · ');

    return '<div class="consulta">' +
      '<div class="consulta-cabeza"><b>' + esc(q.nombre) + '</b>' + sello +
      '<span class="consulta-contacto">' + horaLocal(q.creado) + '</span></div>' +
      '<p class="consulta-contacto">' + contacto +
        (q.empresa ? ' · ' + esc(q.empresa) : '') +
        (q.servicio ? ' · ' + esc(q.servicio) : '') + '</p>' +
      '<p class="consulta-mensaje">' + esc(q.mensaje) + '</p>' +
      (q.error ? '<p class="consulta-contacto" style="color:var(--app-error)">Error del envío: ' + esc(q.error) + '</p>' : '') +
    '</div>';
  }).join('') + '</div>';
}

// ------------------------------------------------------------------
// Documentos: el circuito de cada trabajo
// ------------------------------------------------------------------
// Los nombres del circuito, por lo que significan para quien coordina.
const FLUJO = {
  planificado: 'planificado', asignado: 'asignado', en_curso: 'en curso',
  ejecutado: 'ejecutado', verificado: 'verificado', certificado: 'certificado',
  facturable: 'facturable', facturado: 'facturado', reprogramado: 'reprogramado', anulado: 'anulado',
};
const CAMPO = { ejecutada: 'hecha', no_ejecutada: 'no hecha', planificada: 'sin resolver' };
const CONFORMIDAD = {
  conforme: '<span class="sello sello--ok">conforme</span>',
  rechazado: '<span class="sello sello--mal">rechazado</span>',
  pendiente: '<span class="sello sello--gris">sin conformidad</span>',
};

let trabajos = [];
let firmaAlg = 'ninguna';

async function cargarTrabajos() {
  $('trabajos').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const d = await api.pedir('/trabajos?fecha=' + encodeURIComponent($('fecha').value || fechaArt()));
    marcarConexion(true, 'conectado');
    trabajos = d.trabajos;
    firmaAlg = d.firma;
    pintarTrabajos();
    destacarJornada();
  } catch (e) {
    error('trabajos', e);
  }
}

/** Se puede verificar si el campo la dio por hecha y el circuito no pasó de ejecutado. */
const verificable = (t) => t.estado === 'ejecutada' &&
  ['planificado', 'asignado', 'en_curso', 'ejecutado'].includes(t.flujo);

function pintarResumen() {
  const n = (f) => trabajos.filter(f).length;
  const porVerificar = n(verificable);
  $('trb-verificar-todas').disabled = porVerificar === 0;
  $('trb-resumen').textContent = trabajos.length === 0 ? '' :
    fmt.plural(trabajos.length, 'trabajo', 'trabajos') + ' · ' +
    porVerificar + ' por verificar · ' +
    n((t) => t.flujo === 'verificado' && !t.remito) + ' verificados sin remito · ' +
    n((t) => t.remito) + ' con remito';
}

function pintarTrabajos() {
  pintarResumen();

  if (!trabajos.length) {
    $('trabajos').innerHTML = '<div class="app-tarjeta"><p class="app-titulo">Sin trabajos</p>' +
      '<p class="app-nota">No hay ninguna ruta planificada para esa fecha.</p></div>';
    return;
  }

  // Agrupados por jornada, en el orden de la hoja de ruta.
  const grupos = new Map();
  for (const t of trabajos) {
    if (!grupos.has(t.jornada_id)) grupos.set(t.jornada_id, []);
    grupos.get(t.jornada_id).push(t);
  }

  $('trabajos').innerHTML = [...grupos.values()].map((lista) =>
    '<article class="app-tarjeta jornada" id="trb-j-' + lista[0].jornada_id + '">' +
      '<p class="app-titulo jornada-titulo" tabindex="-1">' + esc(lista[0].ruta) + '</p>' +
      '<div class="tabla-envoltorio"><table class="tabla">' +
        '<thead><tr><th class="num">#</th><th>Sitio</th><th>Campo</th><th>Circuito</th>' +
        '<th>R28</th><th>Remito</th><th>Acciones</th></tr></thead><tbody>' +
        lista.map(filaTrabajo).join('') +
      '</tbody></table></div>' +
    '</article>').join('');
}

function filaTrabajo(t) {
  const acciones = [];
  if (verificable(t)) {
    acciones.push('<button class="app-btn" type="button" data-accion="verificar" data-parada="' + t.id + '">Verificar</button>');
  }
  if (t.flujo === 'verificado' && !t.remito) {
    acciones.push('<button class="app-btn app-btn--principal" type="button" data-accion="remito" data-parada="' + t.id + '">Emitir remito</button>');
  }
  // Certificar sólo se ofrece cuando puede salir bien: hay clave de firma,
  // el remito está firmado y el cliente estuvo conforme. El servidor lo
  // vuelve a comprobar todo; esto es para no ofrecer un botón que siempre falla.
  if (t.flujo === 'verificado' && t.remito?.conformidad === 'conforme' &&
      t.remito.firma !== 'ninguna' && firmaAlg !== 'ninguna') {
    acciones.push('<button class="app-btn app-btn--principal" type="button" data-accion="certificar" data-parada="' + t.id + '">Certificar</button>');
  }
  if (t.r28) {
    acciones.push('<button class="app-btn" type="button" data-accion="abrir" data-ruta="/registro/' + t.r28.id + '?formato=html">R28</button>');
  }
  if (t.remito) {
    acciones.push('<button class="app-btn" type="button" data-accion="abrir" data-ruta="/remito/' + t.remito.id + '?formato=html">Remito</button>');
    acciones.push('<button class="app-btn" type="button" data-accion="abrir" data-ruta="/remito/' + t.remito.id + '?formato=pdf">PDF</button>');
  }

  const campo = t.estado === 'no_ejecutada'
    ? '<span class="sello sello--aviso">no hecha</span>' + (t.motivo ? '<div class="trabajo-sitio">' + esc(t.motivo) + '</div>' : '')
    : esc(CAMPO[t.estado] ?? t.estado);

  return '<tr data-fila="' + t.id + '">' +
    '<td class="num">' + t.orden + '</td>' +
    '<td><b>' + esc(t.sitio) + '</b>' + (t.cliente ? '<div class="trabajo-sitio">' + esc(t.cliente) + '</div>' : '') + '</td>' +
    '<td>' + campo + '</td>' +
    '<td>' + esc(FLUJO[t.flujo] ?? t.flujo) + '</td>' +
    '<td class="trabajo-doc">' + (t.r28 ? esc(t.r28.numero) : '—') + '</td>' +
    '<td class="trabajo-doc">' + (t.remito
      ? esc(t.remito.numero) + ' ' + (CONFORMIDAD[t.remito.conformidad] ?? '') +
        (t.remito.firma === 'ninguna' ? ' <span class="sello sello--gris">sin firma digital</span>' : '')
      : '—') + '</td>' +
    '<td><div class="trabajo-acciones">' + acciones.join('') + '</div><p class="trabajo-error" role="alert" hidden></p></td>' +
  '</tr>';
}

function errorEnFila(paradaId, e) {
  const p = document.querySelector('[data-fila="' + paradaId + '"] .trabajo-error');
  if (!p) return;
  p.textContent = e?.message || 'No se pudo.';
  p.hidden = false;
}

async function accionTrabajo(boton) {
  const accion = boton.dataset.accion;

  if (accion === 'abrir') {
    // La ventana se abre YA, dentro del click: si se abriera después del
    // fetch, el navegador la trataría como un popup no pedido y la bloquearía.
    const ventana = window.open('', '_blank');
    try {
      const blob = await api.documento(boton.dataset.ruta);
      const url = URL.createObjectURL(blob);
      if (ventana) ventana.location.href = url; else location.href = url;
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      ventana?.close();
      errorEnFila(boton.closest('[data-fila]')?.dataset.fila, e);
    }
    return;
  }

  const paradaId = Number(boton.dataset.parada);
  boton.disabled = true;
  try {
    if (accion === 'verificar') {
      await api.pedir('/trabajo/mover', { metodo: 'POST', cuerpo: { parada_id: paradaId, hacia: 'verificado' } });
    } else if (accion === 'remito') {
      await api.pedir('/remito', { metodo: 'POST', cuerpo: { parada_id: paradaId } });
    } else if (accion === 'certificar') {
      await api.pedir('/trabajo/mover', { metodo: 'POST', cuerpo: { parada_id: paradaId, hacia: 'certificado' } });
    }
    await refrescarFila(paradaId, accion);
  } catch (e) {
    boton.disabled = false;
    errorEnFila(paradaId, e);
  }
}

const HECHO = { verificar: 'verificado', remito: 'con remito emitido', certificar: 'certificado' };

// Después de una acción se cambia SOLO su fila. Antes se repintaba la tabla
// entera con «Cargando…» de por medio: la página saltaba, el foco se perdía
// en el body y quien cierra un día con cuarenta paradas tenía que volver a
// buscar dónde estaba después de cada click.
async function refrescarFila(paradaId, accion) {
  const d = await api.pedir('/trabajos?fecha=' + encodeURIComponent($('fecha').value || fechaArt()));
  trabajos = d.trabajos;
  firmaAlg = d.firma;
  const t = trabajos.find((x) => x.id === paradaId);
  const fila = document.querySelector('[data-fila="' + paradaId + '"]');
  if (!t || !fila) { pintarTrabajos(); return; }
  fila.outerHTML = filaTrabajo(t);
  pintarResumen();
  anunciar('Parada ' + t.orden + ' · ' + t.sitio + ': ' + (HECHO[accion] ?? 'actualizada') +
    (t.remito && accion === 'remito' ? ' (' + t.remito.numero + ')' : '') + '.');
  // El foco va a lo que sigue en esa misma parada (emitir después de
  // verificar, certificar después de emitir); si no queda nada, a la próxima
  // parada que tenga algo por hacer.
  const nueva = document.querySelector('[data-fila="' + paradaId + '"]');
  const siguiente = nueva?.querySelector('button[data-accion]:not([data-accion="abrir"])') ??
    [...document.querySelectorAll('#trabajos tr[data-fila]')]
      .slice([...document.querySelectorAll('#trabajos tr[data-fila]')].indexOf(nueva) + 1)
      .map((f) => f.querySelector('button[data-accion]:not([data-accion="abrir"])')).find(Boolean) ??
    nueva?.querySelector('button[data-accion]');
  siguiente?.focus();
}

function anunciar(texto) {
  const r = $('trb-estado');
  r.textContent = '';
  // Vaciar y volver a escribir: si el texto se repite, el lector de pantalla
  // no lo anuncia de nuevo.
  requestAnimationFrame(() => { r.textContent = texto; });
}

async function verificarTodas() {
  const boton = $('trb-verificar-todas');
  boton.disabled = true;
  // De a una y en orden: el servidor serializa igual, y si una falla las
  // demás siguen. Al final se dice cuántas no pudieron y por qué.
  const fallas = [];
  for (const t of trabajos.filter(verificable)) {
    try {
      await api.pedir('/trabajo/mover', { metodo: 'POST', cuerpo: { parada_id: t.id, hacia: 'verificado' } });
    } catch (e) {
      fallas.push([t.id, e]);
    }
  }
  const total = trabajos.filter(verificable).length;
  const d = await api.pedir('/trabajos?fecha=' + encodeURIComponent($('fecha').value || fechaArt()));
  trabajos = d.trabajos;
  firmaAlg = d.firma;
  pintarTrabajos();
  for (const [id, e] of fallas) errorEnFila(id, e);
  anunciar((total - fallas.length) + ' de ' + total + ' verificadas' +
    (fallas.length ? '; ' + fallas.length + ' no se pudieron, el motivo está en su fila.' : '.'));
  document.querySelector('#trabajos button[data-accion="remito"]')?.focus();
}

// ------------------------------------------------------------------
// Desvíos: lo planificado contra lo ejecutado
// ------------------------------------------------------------------
async function cargarDesvios() {
  $('desvios').innerHTML = '<p class="app-nota">Cargando…</p>';
  const q = new URLSearchParams({ desde: $('dsv-desde').value, hasta: $('dsv-hasta').value });
  if ($('dsv-tipo').value) q.set('tipo', $('dsv-tipo').value);
  try {
    const d = await api.pedir('/desvios?' + q.toString());
    marcarConexion(true, 'conectado');
    pintarDesvios(d);
  } catch (e) {
    error('desvios', e);
  }
}

const SEVERIDAD = { alta: 'sello--mal', media: 'sello--aviso', baja: 'sello--gris' };

function pintarDesvios(d) {
  // El selector de tipos se llena con lo que dice el servidor: si mañana
  // aparece un tipo nuevo, está acá sin tocar esta pantalla.
  const sel = $('dsv-tipo');
  if (sel.options.length === 1) {
    for (const [clave, nombre] of Object.entries(d.tipos ?? {})) {
      const o = document.createElement('option');
      o.value = clave; o.textContent = nombre;
      sel.appendChild(o);
    }
  }

  const r = d.resumen;
  const cabecera =
    '<div class="app-tarjeta" style="margin-bottom:16px">' +
      '<p class="app-titulo" style="margin:0">' +
        r.total + (r.total === 1 ? ' desvío' : ' desvíos') + ' en ' +
        fmt.plural(d.jornadas, 'jornada', 'jornadas') + '</p>' +
      '<p class="app-nota">Sobre ' + fmt.plural(d.paradas, 'parada revisada', 'paradas revisadas') +
        ', del ' + esc(d.desde) + ' al ' + esc(d.hasta) + '.</p>' +
      '<ul class="conteos">' +
        Object.entries(r.por_tipo).filter(([, n]) => n > 0).map(([t, n]) =>
          '<li><b>' + n + '</b><span>' + esc(d.tipos[t] ?? t) + '</span></li>').join('') +
      '</ul>' +
    '</div>';

  if (!d.desvios.length) {
    $('desvios').innerHTML = cabecera +
      '<div class="app-tarjeta"><p class="app-titulo">Sin desvíos</p>' +
      '<p class="app-nota">En ese rango, lo ejecutado coincide con lo planificado.</p></div>';
    return;
  }

  $('desvios').innerHTML = cabecera + '<div class="app-tarjeta">' +
    d.desvios.map((x) =>
      '<article class="desvio" data-sev="' + esc(x.severidad) + '">' +
        '<span class="sello ' + (SEVERIDAD[x.severidad] ?? 'sello--gris') + '">' +
          esc(d.tipos[x.tipo] ?? x.tipo) + '</span>' +
        '<div>' +
          '<div class="desvio-cabeza">' +
            '<b>' + esc(x.sitio ?? x.ruta) + '</b>' +
            '<span class="desvio-donde">' + esc(x.fecha) + ' · ' + esc(x.ruta) +
              (x.orden ? ' · parada ' + x.orden : '') +
              (x.cliente ? ' · ' + esc(x.cliente) : '') + '</span>' +
          '</div>' +
          '<p>' + esc(x.detalle) + '</p>' +
        '</div>' +
      '</article>').join('') +
    '</div>';
}

// ------------------------------------------------------------------
// Auditoría (F2.5)
// ------------------------------------------------------------------
let audUltimo = null;

async function cargarAuditoria(mas = false) {
  const q = new URLSearchParams();
  if ($('aud-entidad').value) q.set('entidad', $('aud-entidad').value);
  if ($('aud-desde').value) q.set('desde', $('aud-desde').value);
  if ($('aud-hasta').value) q.set('hasta', $('aud-hasta').value);
  if (mas && audUltimo) q.set('antes_de', String(audUltimo));
  if (!mas) $('auditoria').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const d = await api.pedir('/auditoria?' + q.toString());
    marcarConexion(true, 'conectado');
    pintarAuditoria(d, mas);
  } catch (e) {
    error('auditoria', e);
  }
}

function pintarAuditoria(d, mas) {
  const sel = $('aud-entidad');
  if (sel.options.length === 1) {
    for (const ent of d.entidades) {
      const o = document.createElement('option');
      o.value = ent; o.textContent = ent;
      sel.appendChild(o);
    }
  }
  const filas = d.movimientos.map((m) =>
    '<tr><td class="trabajo-doc">' + horaLocal(m.cuando_utc) + '</td>' +
    '<td>' + esc(m.entidad) + (m.entidad_id ? ' #' + m.entidad_id : '') + '</td>' +
    '<td><b>' + esc(m.accion) + '</b></td>' +
    '<td>' + esc(m.usuario || '—') + '</td>' +
    '<td class="aud-datos">' + esc(m.datos ? JSON.stringify(m.datos) : '') + '</td></tr>').join('');
  if (d.movimientos.length) audUltimo = d.movimientos[d.movimientos.length - 1].id;

  if (mas) {
    document.querySelector('#auditoria tbody')?.insertAdjacentHTML('beforeend', filas);
    return;
  }
  const anclas = d.anclas.length
    ? '<ul class="alertas">' + d.anclas.slice(0, 5).map((a) =>
        '<li>Ancla del ' + esc(a.fecha) + ': ' + (a.firma_alg === 'ninguna' ? 'sin firma' : 'firmada') +
        ' · ' + (a.mail_enviado_utc ? 'enviada por mail' : 'sin mail') +
        ' · ' + (a.en_backup_utc ? 'fuera del proveedor' : 'todavía no salió en un backup') + '</li>').join('') + '</ul>'
    : '<p class="app-nota">Todavía no hay ninguna ancla. La genera el cron de cada noche.</p>';
  $('auditoria').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Anclas recientes</p>' + anclas + '</div>' +
    '<div class="app-tarjeta"><div class="tabla-envoltorio"><table class="tabla">' +
    '<thead><tr><th>Cuándo</th><th>Sobre qué</th><th>Qué pasó</th><th>Quién</th><th>Detalle</th></tr></thead>' +
    '<tbody>' + filas + '</tbody></table></div>' +
    (d.movimientos.length ? '<button class="app-btn aud-mas" id="aud-mas" type="button">Ver anteriores</button>'
                          : '<p class="app-nota">Nada en ese rango.</p>') +
    '</div>';
  $('aud-mas')?.addEventListener('click', () => cargarAuditoria(true));
}

async function verificarCadenas() {
  const b = $('aud-verificar');
  b.disabled = true;
  $('aud-verificacion').innerHTML = '<p class="app-nota">Verificando las dos cadenas enteras…</p>';
  try {
    const d = await api.pedir('/auditoria?verificar=1&limite=1');
    const v = d.verificacion;
    const problemas = [...v.remitos.problemas, ...v.auditoria.problemas, ...(v.ancla?.problemas ?? [])];
    const unicos = [...new Map(problemas.map((p) => [p.codigo + p.detalle, p])).values()];
    $('aud-verificacion').innerHTML = '<div class="app-tarjeta tarjeta-filtro">' +
      '<p class="app-titulo">' + (v.ok ? '<span class="sello sello--ok">todo coincide</span>' : '<span class="sello sello--mal">hay diferencias</span>') + '</p>' +
      '<p class="app-nota">Remitos: ' + v.remitos.eslabones + ' eslabones, ' + v.remitos.firmados + ' firmados. ' +
      'Auditoría: ' + v.auditoria.eslabones + ' eslabones. ' +
      (v.ancla ? 'Contra el ancla del ' + esc(v.ancla.fecha) + ': ' + (v.ancla.valida ? 'intacta.' : 'NO coincide.') : 'Sin ancla todavía.') + '</p>' +
      (unicos.length ? '<ul class="aud-problemas">' + unicos.map((p) => '<li>' + esc(p.detalle) + '</li>').join('') + '</ul>' : '') +
      '</div>';
  } catch (e) {
    error('aud-verificacion', e);
  } finally {
    b.disabled = false;
  }
}

// ------------------------------------------------------------------
// Clientes (F3.1)
// ------------------------------------------------------------------
const IVA = {
  responsable_inscripto: 'Responsable inscripto', monotributo: 'Monotributo',
  exento: 'Exento', consumidor_final: 'Consumidor final',
};
let clientesDatos = null;
let soyAdmin = false;

async function cargarClientes() {
  $('clientes').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    clientesDatos = await api.pedir('/clientes');
    marcarConexion(true, 'conectado');
    pintarClientes();
  } catch (e) {
    error('clientes', e);
  }
}

function pintarClientes() {
  const d = clientesDatos;
  const activos = d.clientes.filter((c) => c.activo);
  const listos = activos.filter((c) => !c.falta.length).length;

  const sugerencias = d.sugerencias.length && soyAdmin
    ? '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Posibles duplicados</p>' +
      '<p class="app-nota">El sistema sólo sugiere. Antes de fusionar se muestra qué se mueve, y se puede deshacer.</p>' +
      '<ul class="alertas">' + d.sugerencias.map((s) =>
        '<li>«' + esc(s.origen.nombre) + '» y «' + esc(s.destino.nombre) + '» (' + esc(s.razon) + ') ' +
        '<button class="app-btn" type="button" data-cli="previa" data-origen="' + s.origen.id + '" data-destino="' + s.destino.id + '">Revisar</button></li>'
      ).join('') + '</ul></div>'
    : '';

  const fusiones = d.fusiones.filter((f) => !f.deshecha_utc).length && soyAdmin
    ? '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Fusiones hechas</p><ul class="alertas">' +
      d.fusiones.filter((f) => !f.deshecha_utc).map((f) =>
        '<li>«' + esc(f.origen) + '» en «' + esc(f.destino) + '» · ' + esc(f.motivo) + ' ' +
        '<button class="app-btn" type="button" data-cli="deshacer" data-fusion="' + f.id + '">Deshacer</button></li>').join('') +
      '</ul></div>'
    : '';

  $('clientes').innerHTML = sugerencias + fusiones +
    '<div class="app-tarjeta"><p class="app-nota">' + listos + ' de ' + activos.length +
    ' clientes tienen todo para facturarles.</p><div class="tabla-envoltorio"><table class="tabla">' +
    '<thead><tr><th>Cliente</th><th>Razón social</th><th>CUIT</th><th>IVA</th><th class="num">Sitios</th><th></th></tr></thead><tbody>' +
    activos.map((c) => '<tr><td><b>' + esc(c.nombre) + '</b>' +
      (c.falta.length ? '<div class="cli-falta">Falta: ' + esc(c.falta.join(', ')) + '</div>' : '') + '</td>' +
      '<td>' + esc(c.razon_social || '—') + '</td><td class="trabajo-doc">' + esc(c.cuit || '—') + '</td>' +
      '<td>' + esc(IVA[c.condicion_iva] || '—') + '</td><td class="num">' + c.sitios + '</td>' +
      '<td><button class="app-btn" type="button" data-cli="editar" data-id="' + c.id + '">Editar</button></td></tr>').join('') +
    '</tbody></table></div></div>';
}

function panelCliente(html) {
  $('cli-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html + '</div>' : '';
  if (html) $('cli-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function formCliente(c) {
  const opt = (v, t, sel) => '<option value="' + v + '"' + (sel === v ? ' selected' : '') + '>' + t + '</option>';
  panelCliente('<p class="app-titulo">' + esc(c.nombre) + '</p>' +
    '<form class="cli-form" id="cli-form">' +
      '<div class="app-campo"><label for="cli-razon">Razón social</label><input id="cli-razon" value="' + esc(c.razon_social || '') + '" /></div>' +
      '<div class="app-campo"><label for="cli-cuit">CUIT</label><input id="cli-cuit" inputmode="numeric" placeholder="30-12345678-9" value="' + esc(c.cuit || '') + '" /></div>' +
      '<div class="app-campo"><label for="cli-tipo">Tipo</label><select id="cli-tipo">' + opt('', '—', c.tipo || '') +
        opt('empresa', 'Empresa', c.tipo) + opt('persona', 'Persona', c.tipo) + '</select></div>' +
      '<div class="app-campo"><label for="cli-iva">Condición frente al IVA</label><select id="cli-iva">' + opt('', '—', c.condicion_iva || '') +
        Object.entries(IVA).map(([k, t]) => opt(k, t, c.condicion_iva)).join('') + '</select></div>' +
      '<div class="app-campo"><label for="cli-dom">Domicilio fiscal</label><input id="cli-dom" value="' + esc(c.domicilio_fiscal || '') + '" /></div>' +
      '<div class="app-campo"><label for="cli-mail">Email de facturación</label><input id="cli-mail" type="email" value="' + esc(c.email_facturacion || '') + '" /></div>' +
    '</form><p class="trabajo-error" id="cli-error" hidden></p>' +
    '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-cli="guardar" data-id="' + c.id + '">Guardar</button>' +
    '<button class="app-btn" type="button" data-cli="cerrar">Cancelar</button></div>' +
    // F3.10: acceso al portal. La contraseña se muestra una sola vez.
    (soyAdmin
      ? '<div class="cli-acceso"><p class="app-titulo">Acceso al portal de clientes</p><div id="cli-accesos"></div><div class="filtro-fecha">' +
        '<div class="app-campo"><label for="cli-acc-email">Email de quien va a entrar</label><input id="cli-acc-email" type="email" maxlength="190" /></div>' +
        '<button class="app-btn" type="button" data-cli="acceso" data-id="' + c.id + '">Dar acceso</button></div>' +
        '<div id="cli-acc-resultado" role="status"></div></div>'
      : ''));
  if (soyAdmin) pintarAccesos(c);
}

function pintarAccesos(c) {
  $('cli-accesos').innerHTML = c.accesos?.length
    ? '<ul class="cli-lista">' + c.accesos.map((a) => '<li><span class="trabajo-doc">' + esc(a.email) + '</span> ' +
        '<button class="app-btn" type="button" data-cli="quitar-acceso" data-id="' + c.id + '" data-usuario="' + a.usuario_id + '">Quitar acceso</button></li>').join('') + '</ul>'
    : '<p class="app-nota">Nadie de este cliente entra al portal todavía.</p>';
}

function errorCliente(e) {
  const p = $('cli-error');
  if (!p) return;
  p.textContent = e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.');
  p.hidden = false;
}

async function accionCliente(b) {
  const a = b.dataset.cli;
  if (a === 'cerrar') { panelCliente(''); return; }
  if (a === 'editar') { formCliente(clientesDatos.clientes.find((c) => c.id === Number(b.dataset.id))); return; }
  b.disabled = true;
  try {
    if (a === 'guardar') {
      await api.pedir('/cliente', { metodo: 'POST', cuerpo: {
        id: Number(b.dataset.id), razon_social: $('cli-razon').value, cuit: $('cli-cuit').value,
        tipo: $('cli-tipo').value, condicion_iva: $('cli-iva').value,
        domicilio_fiscal: $('cli-dom').value, email_facturacion: $('cli-mail').value,
      } });
      panelCliente('');
      await cargarClientes();
    } else if (a === 'acceso') {
      const c = clientesDatos.clientes.find((x) => x.id === Number(b.dataset.id));
      const r = await api.pedir('/clientes/acceso', { metodo: 'POST', cuerpo: { cliente_id: c.id, email: $('cli-acc-email').value.trim() } });
      c.accesos = [...(c.accesos ?? []).filter((x) => x.usuario_id !== r.usuario_id), { usuario_id: r.usuario_id, email: r.email }];
      pintarAccesos(c);
      $('cli-acc-email').value = '';
      $('cli-acc-resultado').innerHTML = '<div class="aviso aviso--decision"><div><b>Acceso creado para ' + esc(r.email) + '</b>' +
        'Contraseña: <span class="trabajo-doc">' + esc(r.clave) + '</span>. Pasásela por un canal seguro: no se vuelve a mostrar. ' +
        'Entra en camcasoluciones.com.ar/app con «Oficina».</div></div>';
      b.disabled = false;
    } else if (a === 'quitar-acceso') {
      const c = clientesDatos.clientes.find((x) => x.id === Number(b.dataset.id));
      const r = await api.pedir('/clientes/acceso/baja', { metodo: 'POST', cuerpo: { usuario_id: Number(b.dataset.usuario) } });
      c.accesos = (c.accesos ?? []).filter((x) => x.usuario_id !== r.usuario_id);
      pintarAccesos(c);
      $('cli-acc-resultado').innerHTML = '<div class="aviso"><div><b>Acceso quitado a ' + esc(r.email) + '</b>' +
        'Sus sesiones abiertas se cortaron. Si hace falta, se le puede volver a dar con una contraseña nueva.</div></div>';
    } else if (a === 'previa') {
      const p = await api.pedir('/clientes/fusion', { metodo: 'POST', cuerpo: {
        origen_id: Number(b.dataset.origen), destino_id: Number(b.dataset.destino) } });
      panelCliente('<p class="app-titulo">Fusionar «' + esc(p.origen.nombre) + '» en «' + esc(p.destino.nombre) + '»</p>' +
        '<p class="app-nota">Pasan ' + fmt.plural(p.sitios, 'sitio', 'sitios') + ' de «' + esc(p.origen.nombre) + '» a «' +
          esc(p.destino.nombre) + '». Los ' + p.documentos_que_no_cambian + ' documentos ya emitidos NO cambian.</p>' +
        (p.nombres_sitios.length ? '<ul class="cli-lista">' + p.nombres_sitios.map((n) => '<li>' + esc(n) + '</li>').join('') + '</ul>' : '') +
        (p.aviso_cuit ? '<div class="app-pendientes">' + esc(p.aviso_cuit) + '</div>' : '') +
        '<div class="app-campo"><label for="cli-motivo">¿Por qué son el mismo cliente?</label><input id="cli-motivo" /></div>' +
        '<p class="trabajo-error" id="cli-error" hidden></p>' +
        '<div class="cli-acciones"><button class="app-btn app-btn--peligro" type="button" data-cli="fusionar" data-origen="' + p.origen.id +
          '" data-destino="' + p.destino.id + '" data-clave="' + esc(p.confirmacion) + '">Confirmar fusión</button>' +
        '<button class="app-btn" type="button" data-cli="cerrar">Cancelar</button></div>');
    } else if (a === 'fusionar') {
      await api.pedir('/clientes/fusion', { metodo: 'POST', cuerpo: {
        origen_id: Number(b.dataset.origen), destino_id: Number(b.dataset.destino),
        confirmacion: b.dataset.clave, motivo: $('cli-motivo').value } });
      panelCliente('');
      await cargarClientes();
    } else if (a === 'deshacer') {
      await api.pedir('/clientes/fusion/deshacer', { metodo: 'POST', cuerpo: { fusion_id: Number(b.dataset.fusion) } });
      await cargarClientes();
    }
  } catch (e) {
    b.disabled = false;
    if ($('cli-error')) errorCliente(e); else error('clientes', e);
  }
}


// ------------------------------------------------------------------
// Tarifas (F3.2)
//
// Una tarifa no se edita: se cierra con la fecha en que deja de valer y se
// carga otra. Así lo que ya se facturó con un precio sigue explicándose con
// ese precio. La pantalla no ofrece "editar" porque el servidor no lo acepta.
// ------------------------------------------------------------------
let tarifasDatos = null;
// El que se preselecciona: nueve de cada diez visitas son de limpieza.
const SERVICIO_HABITUAL = 'limpieza';

async function cargarTarifas() {
  $('tarifas').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    tarifasDatos = await api.pedir('/tarifas');
    marcarConexion(true, 'conectado');
    pintarTarifas();
  } catch (e) {
    error('tarifas', e);
  }
}

const opcion = (v, t, sel) => '<option value="' + esc(v) + '"' + (String(sel) === String(v) ? ' selected' : '') + '>' + esc(t) + '</option>';

function estadoTarifa(t, hoy) {
  if (t.vigente_desde > hoy) return '<span class="sello sello--gris">desde el ' + esc(fechaCorta(t.vigente_desde)) + '</span>';
  if (t.vigente_hasta && t.vigente_hasta < hoy) return '<span class="sello sello--gris">cerrada</span>';
  return '<span class="sello sello--ok">vale hoy</span>';
}

const fechaCorta = (iso) => iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '';

function pintarTarifas() {
  const d = tarifasDatos;
  const hoy = fechaArt();
  const filtro = $('tar-cliente')?.value ?? '';

  // El cotizador va arriba: la pregunta de todos los días es "¿cuánto le
  // cobramos a este cliente por esto?", no "¿qué tarifas hay?".
  const cotizador =
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Cotizar</p>' +
    '<form class="cli-form" id="tar-cot">' +
      '<div class="app-campo"><label for="cot-cliente">Cliente</label><select id="cot-cliente">' +
        opcion('', 'Sin cliente (lista general)', '') + d.clientes.map((c) => opcion(c.id, c.nombre, '')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="cot-servicio">Servicio</label><select id="cot-servicio">' +
        d.servicios.map((s) => opcion(s.codigo, s.nombre, SERVICIO_HABITUAL)).join('') + '</select></div>' +
      '<div class="app-campo"><label for="cot-fecha">Fecha del servicio</label><input id="cot-fecha" type="date" value="' + hoy + '" /></div>' +
      '<div class="app-campo"><label for="cot-cantidad">Cantidad</label><input id="cot-cantidad" type="number" min="0" step="1" value="1" /></div>' +
      '<div class="app-campo"><label for="cot-km">Km adicionales</label><input id="cot-km" inputmode="decimal" value="0" /></div>' +
      '<div class="app-campo"><label for="cot-horas">Horas de espera</label><input id="cot-horas" inputmode="decimal" value="0" /></div>' +
    '</form>' +
    '<div class="cli-acciones"><button class="app-btn" type="button" data-tar="cotizar">Calcular</button></div>' +
    '<div id="cot-resultado" role="status" aria-live="polite"></div></div>';

  const filas = d.tarifas.filter((t) => filtro === '' || (filtro === 'general' ? t.cliente_id === null : String(t.cliente_id) === filtro));
  const abierta = (t) => !t.vigente_hasta || t.vigente_hasta >= hoy;

  const lista =
    '<div class="app-tarjeta">' +
    '<div class="tar-cabeza"><div class="app-campo"><label for="tar-cliente">Mostrar</label><select id="tar-cliente">' +
      opcion('', 'Todas', filtro) + opcion('general', 'Solo la lista general', filtro) +
      d.clientes.filter((c) => d.tarifas.some((t) => t.cliente_id === c.id)).map((c) => opcion(c.id, c.nombre, filtro)).join('') +
    '</select></div>' +
    (soyAdmin ? '<button class="app-btn app-btn--principal" type="button" data-tar="nueva">Cargar una tarifa</button>' : '') +
    '</div>' +
    (filas.length
      ? '<div class="tabla-envoltorio"><table class="tabla">' +
        '<thead><tr><th>Para</th><th>Servicio</th><th class="num">Por unidad</th><th class="num">Mínimo</th>' +
        '<th class="num">Km</th><th class="num">Hora</th><th>Vigencia</th><th></th></tr></thead><tbody>' +
        filas.map((t) => '<tr data-tarifa="' + t.id + '">' +
          '<td><b>' + esc(t.cliente ?? 'Lista general') + '</b>' + (t.nota ? '<div class="cli-falta">' + esc(t.nota) + '</div>' : '') + '</td>' +
          '<td>' + esc(t.servicio) + '</td>' +
          '<td class="num">' + fmt.pesos(t.precio_unitario_cent) + '</td>' +
          '<td class="num">' + (t.minimo_cent ? fmt.pesos(t.minimo_cent) : '—') + '</td>' +
          '<td class="num">' + (t.adicional_km_cent ? fmt.pesos(t.adicional_km_cent) : '—') + '</td>' +
          '<td class="num">' + (t.adicional_hora_cent ? fmt.pesos(t.adicional_hora_cent) : '—') + '</td>' +
          '<td class="trabajo-doc">' + fechaCorta(t.vigente_desde) + ' → ' + (t.vigente_hasta ? fechaCorta(t.vigente_hasta) : 'sin fin') +
            '<div>' + estadoTarifa(t, hoy) + '</div></td>' +
          '<td>' + (soyAdmin && !t.vigente_hasta && abierta(t)
            ? '<button class="app-btn" type="button" data-tar="cerrar" data-id="' + t.id + '">Cerrar</button>' : '') + '</td>' +
        '</tr>').join('') + '</tbody></table></div>'
      : '<p class="app-nota">No hay tarifas cargadas' + (filtro ? ' para ese filtro' : '') + '. Sin tarifa vigente no se puede facturar.</p>') +
    '</div>';

  $('tarifas').innerHTML = cotizador + lista;
  $('tar-cliente').addEventListener('change', pintarTarifas);
}

function panelTarifa(html) {
  $('tar-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html + '</div>' : '';
  if (html) {
    $('tar-panel').scrollIntoView({ block: 'start' });
    $('tar-panel').querySelector('input, select')?.focus({ preventScroll: true });
  }
}

function formTarifa() {
  const d = tarifasDatos;
  panelTarifa('<p class="app-titulo">Cargar una tarifa</p>' +
    '<p class="app-nota">Los importes en pesos, con coma para los centavos: 12.500,50. Una tarifa cargada no se edita; si cambia el precio, se cierra y se carga otra.</p>' +
    '<form class="cli-form" id="tar-form">' +
      '<div class="app-campo"><label for="tar-f-cliente">Para</label><select id="tar-f-cliente">' +
        opcion('', 'Lista general (todos los clientes sin tarifa propia)', '') + d.clientes.map((c) => opcion(c.id, c.nombre, '')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="tar-f-servicio">Servicio</label><select id="tar-f-servicio">' +
        d.servicios.map((s) => opcion(s.codigo, s.nombre + ' (por ' + s.unidad + ')', SERVICIO_HABITUAL)).join('') + '</select></div>' +
      '<div class="app-campo"><label for="tar-f-unit">Precio por unidad</label><input id="tar-f-unit" inputmode="decimal" placeholder="15.000,00" /></div>' +
      '<div class="app-campo"><label for="tar-f-min">Mínimo por visita <span class="conf-opcional">(opcional)</span></label><input id="tar-f-min" inputmode="decimal" /></div>' +
      '<div class="app-campo"><label for="tar-f-km">Por km adicional <span class="conf-opcional">(opcional)</span></label><input id="tar-f-km" inputmode="decimal" /></div>' +
      '<div class="app-campo"><label for="tar-f-hora">Por hora de espera <span class="conf-opcional">(opcional)</span></label><input id="tar-f-hora" inputmode="decimal" /></div>' +
      '<div class="app-campo"><label for="tar-f-desde">Vale desde</label><input id="tar-f-desde" type="date" value="' + fechaArt() + '" /></div>' +
      '<div class="app-campo"><label for="tar-f-hasta">Hasta <span class="conf-opcional">(vacío = sin fin)</span></label><input id="tar-f-hasta" type="date" /></div>' +
      '<div class="app-campo"><label for="tar-f-nota">Nota <span class="conf-opcional">(opcional)</span></label><input id="tar-f-nota" maxlength="255" placeholder="Contrato 2026, orden de compra…" /></div>' +
    '</form><p class="trabajo-error" id="tar-error" role="alert" hidden></p>' +
    '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-tar="guardar">Guardar tarifa</button>' +
    '<button class="app-btn" type="button" data-tar="cancelar">Cancelar</button></div>');
}

function errorTarifa(texto) {
  const p = $('tar-error');
  if (!p) return;
  p.textContent = texto;
  p.hidden = false;
}

async function accionTarifa(b) {
  const a = b.dataset.tar;
  if (a === 'nueva') { formTarifa(); return; }
  if (a === 'cancelar') { panelTarifa(''); return; }
  if (a === 'cerrar') {
    const t = tarifasDatos.tarifas.find((x) => x.id === Number(b.dataset.id));
    panelTarifa('<p class="app-titulo">Cerrar la tarifa de ' + esc(t.cliente ?? 'la lista general') + ' · ' + esc(t.servicio) + '</p>' +
      '<p class="app-nota">Vale hasta el día que elijas, inclusive. Después no se reabre: si hace falta, se carga otra.</p>' +
      '<form class="cli-form"><div class="app-campo"><label for="tar-c-hasta">Último día en que vale</label>' +
      '<input id="tar-c-hasta" type="date" min="' + t.vigente_desde + '" value="' + fechaArt() + '" /></div></form>' +
      '<p class="trabajo-error" id="tar-error" role="alert" hidden></p>' +
      '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-tar="confirmar-cierre" data-id="' + t.id + '">Cerrar la tarifa</button>' +
      '<button class="app-btn" type="button" data-tar="cancelar">Cancelar</button></div>');
    return;
  }

  if (a === 'cotizar') {
    const cliente = $('cot-cliente').value;
    const q = new URLSearchParams({ servicio: $('cot-servicio').value, fecha: $('cot-fecha').value,
      cantidad: $('cot-cantidad').value || '0',
      km: String($('cot-km').value || '0').replace(',', '.'), horas: String($('cot-horas').value || '0').replace(',', '.') });
    if (cliente) q.set('cliente_id', cliente);
    const r = $('cot-resultado');
    try {
      const c = await api.pedir('/tarifa/cotizar?' + q.toString());
      r.innerHTML = '<table class="tabla tar-cot-tabla"><tbody>' +
        '<tr><td>' + (c.minimo_aplicado ? 'Mínimo por visita' : 'Servicio (' + esc($('cot-cantidad').value) + ' × ' + fmt.pesos(c.unitario_cent) + ')') +
          '</td><td class="num">' + fmt.pesos(c.base_cent) + '</td></tr>' +
        (c.km_cent ? '<tr><td>Km adicionales</td><td class="num">' + fmt.pesos(c.km_cent) + '</td></tr>' : '') +
        (c.horas_cent ? '<tr><td>Horas de espera</td><td class="num">' + fmt.pesos(c.horas_cent) + '</td></tr>' : '') +
        '<tr class="tar-total"><td>Subtotal sin IVA</td><td class="num">' + fmt.pesos(c.subtotal_cent) + '</td></tr>' +
        '</tbody></table><p class="app-nota">' +
        (c.origen === 'propia' ? 'Con la tarifa propia del cliente.' : 'Con la lista general: el cliente no tiene tarifa propia para ese día.') + '</p>';
    } catch (e) {
      r.innerHTML = '<p class="trabajo-error">' + esc(e?.message || 'No se pudo calcular.') + '</p>';
    }
    return;
  }

  b.disabled = true;
  try {
    if (a === 'guardar') {
      const importes = { precio_unitario_cent: 'tar-f-unit', minimo_cent: 'tar-f-min', adicional_km_cent: 'tar-f-km', adicional_hora_cent: 'tar-f-hora' };
      const cuerpo = {};
      for (const [k, id] of Object.entries(importes)) {
        const txt = $(id).value.trim();
        if (txt === '' && k !== 'precio_unitario_cent') continue;
        const c = fmt.centavos(txt);
        if (c === null) {
          b.disabled = false;
          errorTarifa('«' + (txt || 'vacío') + '» no es un importe. Escribilo como 12.500,50.');
          $(id).focus();
          return;
        }
        cuerpo[k] = c;
      }
      if ($('tar-f-cliente').value) cuerpo.cliente_id = Number($('tar-f-cliente').value);
      cuerpo.servicio_codigo = $('tar-f-servicio').value;
      cuerpo.vigente_desde = $('tar-f-desde').value;
      if ($('tar-f-hasta').value) cuerpo.vigente_hasta = $('tar-f-hasta').value;
      if ($('tar-f-nota').value.trim()) cuerpo.nota = $('tar-f-nota').value.trim();
      await api.pedir('/tarifa', { metodo: 'POST', cuerpo });
    } else if (a === 'confirmar-cierre') {
      await api.pedir('/tarifa/cerrar', { metodo: 'POST', cuerpo: { id: Number(b.dataset.id), hasta: $('tar-c-hasta').value } });
    }
    panelTarifa('');
    await cargarTarifas();
  } catch (e) {
    b.disabled = false;
    errorTarifa(e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.'));
  }
}

// ------------------------------------------------------------------
// Facturación (F3.3)
//
// Se abre por la pregunta de todos los meses: ¿a quién hay que facturarle?
// Después, la vista previa (qué entra, qué queda afuera y por qué) y recién
// ahí armar. Nada entra que no esté certificado; eso lo garantiza el
// servidor, la pantalla sólo lo muestra.
// ------------------------------------------------------------------
let facDatos = null;
const ESTADO_PROP = {
  borrador: '<span class="sello sello--aviso">borrador</span>',
  aprobada: '<span class="sello sello--ok">aprobada</span>',
  descartada: '<span class="sello sello--gris">descartada</span>',
};

async function cargarFacturacion() {
  $('facturacion').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const [f, t] = await Promise.all([api.pedir('/facturacion'), tarifasDatos ? null : api.pedir('/tarifas')]);
    if (t) tarifasDatos = t;
    facDatos = f;
    marcarConexion(true, 'conectado');
    pintarFacturacion();
  } catch (e) {
    error('facturacion', e);
  }
}

function primeroDelMes() { return fechaArt().slice(0, 8) + '01'; }

function pintarFacturacion() {
  const d = facDatos;
  const porFacturar = d.por_facturar.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Cliente</th><th class="num">Remitos certificados</th>' +
      '<th>Desde</th><th>Hasta</th><th></th></tr></thead><tbody>' +
      d.por_facturar.map((p) => '<tr><td><b>' + esc(p.cliente) + '</b></td><td class="num">' + p.remitos + '</td>' +
        '<td class="trabajo-doc">' + fechaCorta(p.desde) + '</td><td class="trabajo-doc">' + fechaCorta(p.hasta) + '</td>' +
        '<td><button class="app-btn" type="button" data-fac="elegir" data-cliente="' + p.cliente_id + '" data-desde="' + p.desde +
        '" data-hasta="' + p.hasta + '">Ver la propuesta</button></td></tr>').join('') +
      '</tbody></table></div>'
    : '<p class="app-nota">No hay trabajo certificado esperando factura.</p>';

  const propuestas = d.propuestas.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>N.º</th><th>Cliente</th><th>Período</th>' +
      '<th>Estado</th><th class="num">Neto</th><th class="num">IVA</th><th class="num">Total</th><th></th></tr></thead><tbody>' +
      d.propuestas.map((p) => '<tr data-propuesta="' + p.id + '"><td class="trabajo-doc">' + esc(p.numero ?? 'sin número (#' + p.id + ')') + '</td>' +
        '<td><b>' + esc(p.cliente) + '</b><div class="cli-falta">' + fmt.plural(p.lineas_n, 'línea', 'líneas') + ' · tipo ' + esc(p.tipo_comprobante) + '</div></td>' +
        '<td class="trabajo-doc">' + fechaCorta(p.desde) + ' → ' + fechaCorta(p.hasta) + '</td>' +
        '<td>' + (ESTADO_PROP[p.estado] ?? esc(p.estado)) + '</td>' +
        '<td class="num">' + fmt.pesos(p.neto_cent) + '</td><td class="num">' + fmt.pesos(p.iva_cent) + '</td>' +
        '<td class="num"><b>' + fmt.pesos(p.total_cent) + '</b></td>' +
        '<td><div class="trabajo-acciones"><button class="app-btn" type="button" data-fac="ver" data-id="' + p.id + '">Ver</button>' +
        (soyAdmin && p.estado === 'borrador' ? '<button class="app-btn" type="button" data-fac="aprobar" data-id="' + p.id + '">Aprobar</button>' +
          '<button class="app-btn" type="button" data-fac="descartar" data-id="' + p.id + '">Descartar</button>' : '') +
        '</div></td></tr>').join('') +
      '</tbody></table></div>'
    : '<p class="app-nota">Todavía no se armó ninguna propuesta.</p>';

  const clientes = tarifasDatos?.clientes ?? [];
  $('facturacion').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Por facturar</p>' + porFacturar + '</div>' +
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Vista previa</p>' +
    '<form class="cli-form" id="fac-form">' +
      '<div class="app-campo"><label for="fac-cliente">Cliente</label><select id="fac-cliente">' +
        clientes.map((c) => opcion(c.id, c.nombre, '')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="fac-desde">Desde</label><input id="fac-desde" type="date" value="' + primeroDelMes() + '" /></div>' +
      '<div class="app-campo"><label for="fac-hasta">Hasta</label><input id="fac-hasta" type="date" value="' + fechaArt() + '" /></div>' +
    '</form>' +
    '<div class="cli-acciones"><button class="app-btn" type="button" data-fac="previa">Ver qué entra</button></div>' +
    '<div id="fac-previa" role="status" aria-live="polite"></div></div>' +
    '<div class="app-tarjeta"><p class="app-titulo">Propuestas</p>' + propuestas + '</div>' +
    (soyAdmin
      ? '<div class="app-tarjeta fac-export"><p class="app-titulo">Exportar para contabilidad</p>' +
        '<p class="app-nota">Lo aprobado en el período (propuestas, notas de crédito y de débito), en planillas para el sistema contable. ' +
        'La última fila de cada una trae las sumas de control.</p>' +
        '<form class="cli-form"><div class="app-campo"><label for="exp-desde">Aprobado desde</label><input id="exp-desde" type="date" value="' + primeroDelMes() + '" /></div>' +
        '<div class="app-campo"><label for="exp-hasta">Hasta</label><input id="exp-hasta" type="date" value="' + fechaArt() + '" /></div></form>' +
        '<div class="cli-acciones"><button class="app-btn" type="button" data-fac="exportar" data-tipo="comprobantes">Bajar comprobantes</button>' +
        '<button class="app-btn" type="button" data-fac="exportar" data-tipo="lineas">Bajar el detalle por línea</button></div>' +
        '<p class="trabajo-error" id="exp-error" role="alert" hidden></p></div>'
      : '');
}

// quitarDe: el id de un borrador, si se ofrece sacar líneas (administración).
function tablaLineas(lineas, quitarDe = null) {
  return '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Fecha</th><th>Detalle</th><th class="num">Cantidad</th>' +
    '<th class="num">Unitario</th><th class="num">Importe</th>' + (quitarDe ? '<th></th>' : '') + '</tr></thead><tbody>' +
    lineas.map((l) => '<tr><td class="trabajo-doc">' + fechaCorta(l.fecha) + '</td><td>' + esc(l.descripcion) +
      (l.minimo_aplicado ? ' <span class="sello sello--gris">mínimo por visita</span>' : '') + '</td>' +
      '<td class="num">' + l.cantidad + '</td><td class="num">' + fmt.pesos(l.unitario_cent) + '</td>' +
      '<td class="num">' + fmt.pesos(l.neto_cent) + '</td>' +
      (quitarDe && l.orden ? '<td><button class="app-btn fac-quitar" type="button" data-fac="quitar" data-id="' + quitarDe +
        '" data-orden="' + l.orden + '">Quitar</button></td>' : '') + '</tr>').join('') +
    '</tbody></table></div>';
}

// F3.4: las notas de crédito y débito de una aprobada, y lo que queda por cobrar.
function ajustesHtml(p) {
  if (p.estado !== 'aprobada') return '';
  const lista = p.ajustes.length
    ? '<ul class="cli-lista">' + p.ajustes.map((a) => '<li><b>' + esc(a.numero) + '</b> · ' +
        (a.tipo === 'credito' ? 'crédito' : 'débito') + ' ' + fmt.pesos(a.total_cent) + ' · ' + esc(a.motivo) + '</li>').join('') + '</ul>'
    : '<p class="app-nota">Sin notas de crédito ni débito.</p>';
  const remitos = [...new Set(p.lineas.flatMap((l) => l.remitos))];
  const form = soyAdmin
    ? '<form class="cli-form fac-ajuste" id="aj-form">' +
        '<div class="app-campo"><label for="aj-tipo">Nota de</label><select id="aj-tipo">' +
          opcion('credito', 'Crédito (resta)', 'credito') + opcion('debito', 'Débito (suma)', '') + '</select></div>' +
        '<div class="app-campo"><label for="aj-desc">Detalle</label><input id="aj-desc" maxlength="255" /></div>' +
        '<div class="app-campo"><label for="aj-importe">Importe neto, sin IVA</label><input id="aj-importe" inputmode="decimal" placeholder="15.000,00" /></div>' +
        '<div class="app-campo"><label for="aj-remito">Remito <span class="conf-opcional">(opcional)</span></label><select id="aj-remito">' +
          opcion('', '—', '') + remitos.map((r) => opcion(r, r, '')).join('') + '</select></div>' +
        '<div class="app-campo"><label for="aj-motivo">Motivo</label><input id="aj-motivo" maxlength="255" /></div>' +
      '</form>' +
      '<div class="cli-acciones"><button class="app-btn" type="button" data-fac="ajustar" data-id="' + p.id + '">Emitir la nota</button></div>'
    : '';
  return '<div class="fac-ajustes"><p class="app-titulo">Notas de crédito y débito</p>' + lista +
    '<p class="fac-saldo">Queda por cobrar: <b>' + fmt.pesos(p.saldo_cent) + '</b></p>' + form +
    '<p class="trabajo-error" id="fac-error" role="alert" hidden></p></div>';
}

async function abrirDetalle(id) {
  const fila = document.querySelector('#facturacion tr[data-propuesta="' + id + '"]');
  if (!fila) return;
  document.querySelectorAll('#facturacion .fac-detalle').forEach((x) => x.remove());
  const p = await api.pedir('/facturacion/' + id);
  fila.insertAdjacentHTML('afterend', '<tr class="fac-detalle"><td colspan="8">' +
    (p.numero ? '<p class="app-nota">Propuesta <b>' + esc(p.numero) + '</b>, aprobada. No se modifica: se corrige con notas.</p>' : '') +
    tablaLineas(p.lineas, soyAdmin && p.estado === 'borrador' ? p.id : null) + totales(p) + ajustesHtml(p) +
    listaOmitidos(p.omitidos) + (p.descartada_motivo ? '<p class="app-nota">Descartada: ' + esc(p.descartada_motivo) + '</p>' : '') +
    (p.estado === 'borrador' ? '<p class="trabajo-error" id="fac-error" role="alert" hidden></p>' : '') + '</td></tr>');
}

function totales(p) {
  const pct = (p.iva_alicuota_pb / 100).toLocaleString('es-AR');
  return '<table class="tabla fac-totales"><tbody>' +
    '<tr><td>Neto</td><td class="num">' + fmt.pesos(p.neto_cent) + '</td></tr>' +
    '<tr><td>IVA ' + pct + ' %</td><td class="num">' + fmt.pesos(p.iva_cent) + '</td></tr>' +
    '<tr class="tar-total"><td>Total (' + (p.tipo_comprobante === 'A' ? 'factura A, IVA discriminado' : 'factura B') + ')</td>' +
    '<td class="num">' + fmt.pesos(p.total_cent) + '</td></tr></tbody></table>';
}

function listaOmitidos(om) {
  return om.length
    ? '<div class="aviso aviso--decision fac-omitidos"><div><b>' + fmt.plural(om.length, 'remito queda afuera', 'remitos quedan afuera') + '</b>' +
      '<ul class="cli-lista">' + om.map((o) => '<li>' + esc(o.remito) + ' (' + fechaCorta(o.fecha) + '): ' + esc(o.motivo) + '</li>').join('') + '</ul></div></div>'
    : '';
}

async function accionFacturacion(b) {
  const a = b.dataset.fac;
  const out = $('fac-previa');
  if (a === 'elegir') {
    $('fac-cliente').value = b.dataset.cliente;
    $('fac-desde').value = b.dataset.desde;
    $('fac-hasta').value = b.dataset.hasta;
    $('fac-form').scrollIntoView({ block: 'start' });
    return accionFacturacion({ dataset: { fac: 'previa' } });
  }
  if (a === 'previa') {
    out.innerHTML = '<p class="app-nota">Calculando…</p>';
    const q = new URLSearchParams({ cliente_id: $('fac-cliente').value, desde: $('fac-desde').value, hasta: $('fac-hasta').value });
    try {
      const p = await api.pedir('/facturacion/previa?' + q.toString());
      out.innerHTML =
        (p.falta.length ? '<div class="aviso aviso--bloqueo fac-omitidos"><div><b>Al cliente le falta ' + esc(p.falta.join(', ')) + '.</b> ' +
          'Sin eso no se le puede facturar: se completa en Clientes.</div></div>' : '') +
        (p.lineas.length ? tablaLineas(p.lineas) + totales(p) : '<p class="app-nota">No hay trabajo certificado sin facturar en ese período.</p>') +
        listaOmitidos(p.omitidos) +
        (soyAdmin && p.lineas.length && !p.falta.length
          ? '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-fac="armar">Armar la propuesta</button></div>' : '') +
        '<p class="trabajo-error" id="fac-error" role="alert" hidden></p>';
    } catch (e) {
      out.innerHTML = '<p class="trabajo-error" role="alert">' + esc(e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.')) + '</p>';
    }
    return;
  }
  if (a === 'ver') {
    const fila = b.closest('tr');
    const abierta = fila.nextElementSibling?.classList.contains('fac-detalle');
    document.querySelectorAll('#facturacion .fac-detalle').forEach((x) => x.remove());
    if (abierta) return;
    try { await abrirDetalle(b.dataset.id); } catch (e) { error('facturacion', e); }
    return;
  }
  if (a === 'exportar') {
    const q = new URLSearchParams({ tipo: b.dataset.tipo, desde: $('exp-desde').value, hasta: $('exp-hasta').value });
    const err = $('exp-error');
    err.hidden = true;
    b.disabled = true;
    try {
      const blob = await api.documento('/facturacion/exportar?' + q.toString());
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');
      enlace.href = url;
      enlace.download = 'camca-' + b.dataset.tipo + '-' + q.get('desde') + '-a-' + q.get('hasta') + '.csv';
      document.body.appendChild(enlace);
      enlace.click();
      enlace.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      err.textContent = e?.message || 'No se pudo bajar la planilla.';
      err.hidden = false;
    } finally {
      b.disabled = false;
    }
    return;
  }
  if (a === 'quitar') {
    const tr = b.closest('tr');
    tr.insertAdjacentHTML('afterend', '<tr class="fac-quitar-fila"><td colspan="6"><div class="filtro-fecha">' +
      '<div class="app-campo"><label for="fac-q-motivo">Por qué se quita esta línea</label><input id="fac-q-motivo" maxlength="255" /></div>' +
      '<button class="app-btn app-btn--peligro" type="button" data-fac="confirmar-quitar" data-id="' + b.dataset.id + '" data-orden="' +
      b.dataset.orden + '">Quitar la línea</button></div></td></tr>');
    b.disabled = true;
    $('fac-q-motivo').focus();
    return;
  }
  if (a === 'descartar') {
    const fila = b.closest('tr');
    document.querySelectorAll('#facturacion .fac-detalle').forEach((x) => x.remove());
    fila.insertAdjacentHTML('afterend', '<tr class="fac-detalle"><td colspan="8"><div class="app-campo"><label for="fac-motivo">Por qué se descarta</label>' +
      '<input id="fac-motivo" maxlength="255" /></div><p class="app-nota">Sus remitos vuelven a quedar por facturar.</p>' +
      '<p class="trabajo-error" id="fac-error" role="alert" hidden></p>' +
      '<div class="cli-acciones"><button class="app-btn app-btn--peligro" type="button" data-fac="confirmar-descarte" data-id="' + b.dataset.id + '">Descartar la propuesta</button></div></td></tr>');
    $('fac-motivo').focus();
    return;
  }

  b.disabled = true;
  try {
    if (a === 'aprobar') {
      await api.pedir('/facturacion/aprobar', { metodo: 'POST', cuerpo: { id: Number(b.dataset.id) } });
    } else if (a === 'confirmar-quitar') {
      const motivo = $('fac-q-motivo').value.trim();
      if (!motivo) { b.disabled = false; mostrarErrorFac('Hace falta decir por qué se quita.'); $('fac-q-motivo').focus(); return; }
      await api.pedir('/facturacion/quitar', { metodo: 'POST', cuerpo: { id: Number(b.dataset.id), orden: Number(b.dataset.orden), motivo } });
      await cargarFacturacion();
      await abrirDetalle(b.dataset.id);
      return;
    } else if (a === 'ajustar') {
      const cent = fmt.centavos($('aj-importe').value);
      if (cent === null || cent <= 0) { b.disabled = false; mostrarErrorFac('El importe se escribe como 15.000,00.'); $('aj-importe').focus(); return; }
      const linea = { descripcion: $('aj-desc').value.trim(), neto_cent: cent };
      if ($('aj-remito').value) linea.remito = $('aj-remito').value;
      await api.pedir('/facturacion/ajuste', { metodo: 'POST', cuerpo: {
        propuesta_id: Number(b.dataset.id), tipo: $('aj-tipo').value, motivo: $('aj-motivo').value.trim(), lineas: [linea] } });
      await cargarFacturacion();
      await abrirDetalle(b.dataset.id);
      return;
    } else if (a === 'armar') {
      await api.pedir('/facturacion', { metodo: 'POST', cuerpo: {
        cliente_id: Number($('fac-cliente').value), desde: $('fac-desde').value, hasta: $('fac-hasta').value } });
    } else if (a === 'confirmar-descarte') {
      const motivo = $('fac-motivo').value.trim();
      if (!motivo) { b.disabled = false; mostrarErrorFac('Hace falta decir por qué se descarta.'); $('fac-motivo').focus(); return; }
      await api.pedir('/facturacion/descartar', { metodo: 'POST', cuerpo: { id: Number(b.dataset.id), motivo } });
    }
    await cargarFacturacion();
  } catch (e) {
    b.disabled = false;
    mostrarErrorFac(e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.'));
  }
}

function mostrarErrorFac(texto) {
  const p = $('fac-error');
  if (!p) return;
  p.textContent = texto;
  p.hidden = false;
}

// ------------------------------------------------------------------
// Recursos (F3.6): personal, vehículos, equipos y cuadrillas.
//
// Se abre por los vencimientos: lo que está vencido o falta va arriba y en
// rojo, porque un chofer con la licencia vencida no tiene que salir a la
// ruta aunque nadie haya mirado la planilla.
// ------------------------------------------------------------------
let recDatos = null;
const NIVEL_VENC = {
  vencido: ['sello--mal', 'vencido'], falta: ['sello--mal', 'falta'],
  urgente: ['sello--aviso', 'vence pronto'], proximo: ['sello--gris', 'por vencer'],
};
const ESTADO_ACTIVO = {
  disponible: 'sello--ok', instalado: 'sello--gris', mantenimiento: 'sello--aviso', baja: 'sello--mal',
};

async function cargarRecursos() {
  $('recursos').innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    recDatos = await api.pedir('/recursos');
    marcarConexion(true, 'conectado');
    pintarRecursos();
  } catch (e) {
    error('recursos', e);
  }
}

function docsHtml(entidad, docs) {
  const tipos = recDatos.tipos[entidad];
  const hoy = fechaArt();
  return Object.keys(tipos).filter((t) => docs[t]).map((t) => {
    const d = docs[t];
    const clase = d.vence < hoy ? 'sello--mal' : '';
    return '<span class="rec-doc">' + esc(tipos[t]) + ' <span class="' + (clase ? 'sello ' + clase : 'trabajo-doc') + '">' + fechaCorta(d.vence) + '</span></span>';
  }).join('') || '<span class="cli-falta">Sin documentos cargados</span>';
}

function pintarRecursos() {
  const d = recDatos;
  const alertas = d.alertas.length
    ? '<ul class="rec-alertas">' + d.alertas.map((a) => {
        const [clase, texto] = NIVEL_VENC[a.nivel];
        return '<li><span class="sello ' + clase + '">' + texto + '</span> <b>' + esc(a.nombre) + '</b> · ' + esc(a.documento) +
          (a.vence ? ' · ' + fechaCorta(a.vence) + (a.dias < 0 ? ' (hace ' + fmt.plural(-a.dias, 'día', 'días') + ')' : ' (en ' + fmt.plural(a.dias, 'día', 'días') + ')') : '') +
          ' <button class="app-btn rec-mini" type="button" data-rec="venc" data-entidad="' + a.entidad + '" data-id="' + a.id + '" data-tipo="' + a.tipo + '">' +
          (a.nivel === 'falta' ? 'Cargar' : 'Renovar') + '</button></li>';
      }).join('') + '</ul>'
    : '<p class="app-nota">Todos los documentos exigidos están al día.</p>';

  const personas = '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Persona</th><th>Rol</th><th>Cuadrilla</th>' +
    '<th>Documentos</th><th>Hoy</th><th></th></tr></thead><tbody>' +
    d.personas.map((p) => '<tr><td><b>' + esc(p.nombre) + '</b><div class="cli-falta">DNI ' + esc(p.dni) + (p.legajo ? ' · ' + esc(p.legajo) : '') + '</div></td>' +
      '<td>' + esc(d.roles[p.rol_operativo] ?? p.rol_operativo) + '</td><td>' + esc(p.cuadrilla ?? '—') + '</td>' +
      '<td>' + docsHtml('persona', p.documentos) + '</td>' +
      '<td>' + (p.inhabilitada.length ? '<span class="sello sello--mal">no habilitada</span><div class="cli-falta">' + esc(p.inhabilitada.join(' ')) + '</div>'
                                      : '<span class="sello sello--ok">habilitada</span>') + '</td>' +
      '<td><button class="app-btn rec-mini" type="button" data-rec="venc" data-entidad="persona" data-id="' + p.id + '">Documento</button></td></tr>').join('') +
    '</tbody></table></div>';

  const vehiculos = '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Vehículo</th><th>Documentos</th><th>Hoy</th><th></th></tr></thead><tbody>' +
    d.vehiculos.map((v) => '<tr><td><b class="trabajo-doc">' + esc(v.patente) + '</b><div class="cli-falta">' + esc(v.descripcion ?? '') + '</div></td>' +
      '<td>' + docsHtml('vehiculo', v.documentos) + '</td>' +
      '<td>' + (v.inhabilitado.length ? '<span class="sello sello--mal">no habilitado</span><div class="cli-falta">' + esc(v.inhabilitado.join(' ')) + '</div>'
                                      : '<span class="sello sello--ok">habilitado</span>') + '</td>' +
      '<td><button class="app-btn rec-mini" type="button" data-rec="venc" data-entidad="vehiculo" data-id="' + v.id + '">Documento</button></td></tr>').join('') +
    '</tbody></table></div>';

  const cuenta = {};
  for (const a of d.activos) cuenta[a.estado] = (cuenta[a.estado] ?? 0) + 1;
  const activos = '<ul class="conteos">' + ['disponible', 'instalado', 'mantenimiento', 'baja'].map((e) =>
      '<li><b>' + (cuenta[e] ?? 0) + '</b><span>' + e + '</span></li>').join('') + '</ul>' +
    (d.activos.length
      ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Equipo</th><th>Estado</th><th>Dónde está</th><th>Desde</th><th></th></tr></thead><tbody>' +
        d.activos.map((a) => '<tr data-activo="' + a.id + '"><td><b class="trabajo-doc">' + esc(a.identificador) + '</b><div class="cli-falta">' + esc(d.tipos_activo[a.tipo] ?? a.tipo) + '</div></td>' +
          '<td><span class="sello ' + (ESTADO_ACTIVO[a.estado] ?? '') + '">' + esc(a.estado) + '</span>' + (a.nota ? '<div class="cli-falta">' + esc(a.nota) + '</div>' : '') + '</td>' +
          '<td>' + (a.sitio ? esc(a.sitio) + (a.cliente ? '<div class="cli-falta">' + esc(a.cliente) + '</div>' : '') : esc(a.base ?? '—')) + '</td>' +
          '<td class="trabajo-doc">' + (a.desde ? horaLocal(a.desde) : '') + '</td>' +
          '<td>' + (a.estado !== 'baja' ? '<button class="app-btn rec-mini" type="button" data-rec="mover" data-id="' + a.id + '">Mover</button>' : '') + '</td></tr>').join('') +
        '</tbody></table></div>'
      : '<p class="app-nota">Todavía no hay equipos cargados.</p>');

  const libres = d.personas.filter((p) => !p.cuadrilla);
  const cuadrillas = (d.cuadrillas.length
    ? '<div class="rec-cuadrillas">' + d.cuadrillas.map((c) => '<div class="rec-cuadrilla"><p class="app-titulo">' + esc(c.nombre) + '</p>' +
        (c.miembros.length ? '<ul class="cli-lista">' + c.miembros.map((m) => '<li>' + esc(m.nombre) + ' · ' + esc(d.roles[m.rol] ?? m.rol) +
          ' <button class="app-btn rec-mini" type="button" data-rec="quitar" data-persona="' + m.id + '">Sacar</button></li>').join('') + '</ul>'
                           : '<p class="app-nota">Sin integrantes.</p>') +
        (libres.length
          ? '<div class="filtro-fecha"><div class="app-campo"><label for="rec-add-' + c.id + '">Sumar a</label><select id="rec-add-' + c.id + '">' +
            libres.map((p) => opcion(p.id, p.nombre, '')).join('') + '</select></div>' +
            '<button class="app-btn rec-mini" type="button" data-rec="sumar" data-cuadrilla="' + c.id + '">Sumar</button></div>'
          : '<p class="app-nota">Todo el personal ya está en alguna cuadrilla.</p>') + '</div>').join('') + '</div>'
    : '<p class="app-nota">No hay cuadrillas armadas.</p>') +
    '<div class="filtro-fecha rec-nueva"><div class="app-campo"><label for="rec-cua-nombre">Nueva cuadrilla</label><input id="rec-cua-nombre" maxlength="80" /></div>' +
    '<button class="app-btn rec-mini" type="button" data-rec="cuadrilla">Crear</button></div>';

  $('recursos').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Vencimientos</p>' + alertas + '</div>' +
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Personal</p>' +
      (soyAdmin ? '<button class="app-btn" type="button" data-rec="nueva-persona">Agregar una persona</button>' : '') + '</div>' + personas + '</div>' +
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Vehículos</p>' +
      (soyAdmin ? '<button class="app-btn" type="button" data-rec="nuevo-vehiculo">Agregar un vehículo</button>' : '') + '</div>' + vehiculos + '</div>' +
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Equipos</p>' +
      (soyAdmin ? '<button class="app-btn" type="button" data-rec="nuevos-activos">Dar de alta equipos</button>' : '') + '</div>' + activos + '</div>' +
    '<div class="app-tarjeta"><p class="app-titulo">Cuadrillas</p>' + cuadrillas + '<p class="trabajo-error" id="rec-error-cua" role="alert" hidden></p></div>';
}

function panelRecursos(html) {
  $('rec-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html +
    '<p class="trabajo-error" id="rec-error" role="alert" hidden></p></div>' : '';
  if (html) {
    $('rec-panel').scrollIntoView({ block: 'start' });
    $('rec-panel').querySelector('input, select')?.focus({ preventScroll: true });
  }
}

const botonesPanel = (accion, texto) => '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-rec="' + accion + '">' + texto + '</button>' +
  '<button class="app-btn" type="button" data-rec="cancelar">Cancelar</button></div>';

function errorRecursos(e, id = 'rec-error') {
  const p = $(id);
  if (!p) return;
  p.textContent = e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.');
  p.hidden = false;
}

async function accionRecursos(b) {
  const a = b.dataset.rec;
  const d = recDatos;
  if (a === 'cancelar') { panelRecursos(''); return; }
  if (a === 'venc') {
    const ent = b.dataset.entidad;
    const quien = ent === 'persona' ? d.personas.find((p) => p.id === Number(b.dataset.id))?.nombre : d.vehiculos.find((v) => v.id === Number(b.dataset.id))?.patente;
    panelRecursos('<p class="app-titulo">Documento de ' + esc(quien ?? '') + '</p>' +
      '<p class="app-nota">Si ya había uno del mismo tipo, queda como historia y manda el nuevo.</p>' +
      '<form class="cli-form"><div class="app-campo"><label for="rv-tipo">Documento</label><select id="rv-tipo">' +
        Object.entries(d.tipos[ent]).map(([k, t]) => opcion(k, t, b.dataset.tipo ?? '')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="rv-vence">Vence</label><input id="rv-vence" type="date" /></div>' +
      '<div class="app-campo"><label for="rv-doc">Número o referencia <span class="conf-opcional">(opcional)</span></label><input id="rv-doc" maxlength="120" /></div></form>' +
      botonesPanel('guardar-venc', 'Guardar').replace('data-rec="guardar-venc"', 'data-rec="guardar-venc" data-entidad="' + ent + '" data-id="' + b.dataset.id + '"'));
    return;
  }
  if (a === 'mover') {
    const ac = d.activos.find((x) => x.id === Number(b.dataset.id));
    panelRecursos('<p class="app-titulo">Mover ' + esc(ac.identificador) + '</p>' +
      '<form class="cli-form"><div class="app-campo"><label for="rm-destino">A dónde</label><select id="rm-destino">' +
        '<optgroup label="Bases">' + d.bases.map((x) => opcion('b' + x.id, x.nombre, '')).join('') + '</optgroup>' +
        '<optgroup label="Sitios de clientes">' + d.sitios.map((x) => opcion('s' + x.id, (x.cliente ? x.cliente + ' · ' : '') + x.nombre, '')).join('') + '</optgroup>' +
      '</select></div><div class="app-campo"><label for="rm-motivo">Motivo <span class="conf-opcional">(opcional)</span></label><input id="rm-motivo" maxlength="255" /></div></form>' +
      botonesPanel('confirmar-mover', 'Mover').replace('data-rec="confirmar-mover"', 'data-rec="confirmar-mover" data-id="' + ac.id + '"'));
    return;
  }
  if (a === 'nueva-persona') {
    panelRecursos('<p class="app-titulo">Agregar una persona</p><form class="cli-form">' +
      '<div class="app-campo"><label for="rp-nombre">Nombre y apellido</label><input id="rp-nombre" maxlength="120" /></div>' +
      '<div class="app-campo"><label for="rp-dni">DNI</label><input id="rp-dni" inputmode="numeric" /></div>' +
      '<div class="app-campo"><label for="rp-legajo">Legajo <span class="conf-opcional">(opcional)</span></label><input id="rp-legajo" maxlength="30" /></div>' +
      '<div class="app-campo"><label for="rp-rol">Rol</label><select id="rp-rol">' + Object.entries(d.roles).map(([k, t]) => opcion(k, t, 'chofer')).join('') + '</select></div>' +
      '</form>' + botonesPanel('guardar-persona', 'Agregar'));
    return;
  }
  if (a === 'nuevo-vehiculo') {
    panelRecursos('<p class="app-titulo">Agregar un vehículo</p><form class="cli-form">' +
      '<div class="app-campo"><label for="rve-patente">Patente</label><input id="rve-patente" maxlength="12" placeholder="AB123CD" /></div>' +
      '<div class="app-campo"><label for="rve-desc">Descripción <span class="conf-opcional">(opcional)</span></label><input id="rve-desc" maxlength="120" /></div>' +
      '</form>' + botonesPanel('guardar-vehiculo', 'Agregar'));
    return;
  }
  if (a === 'nuevos-activos') {
    panelRecursos('<p class="app-titulo">Dar de alta equipos</p><p class="app-nota">Prefijo «B-» del 1 al 40 da B-001 a B-040. Todos entran disponibles en la base elegida.</p>' +
      '<form class="cli-form"><div class="app-campo"><label for="ra-tipo">Tipo</label><select id="ra-tipo">' + Object.entries(d.tipos_activo).map(([k, t]) => opcion(k, t, 'bano')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="ra-prefijo">Prefijo</label><input id="ra-prefijo" maxlength="20" value="B-" /></div>' +
      '<div class="app-campo"><label for="ra-desde">Desde el número</label><input id="ra-desde" type="number" min="1" value="1" /></div>' +
      '<div class="app-campo"><label for="ra-hasta">Hasta</label><input id="ra-hasta" type="number" min="1" value="10" /></div>' +
      '<div class="app-campo"><label for="ra-base">Base</label><select id="ra-base">' + d.bases.map((x) => opcion(x.id, x.nombre, '')).join('') + '</select></div>' +
      '</form>' + botonesPanel('guardar-activos', 'Dar de alta'));
    return;
  }

  b.disabled = true;
  const cua = ['quitar', 'sumar', 'cuadrilla'].includes(a);
  try {
    if (a === 'guardar-venc') {
      await api.pedir('/recursos/vencimiento', { metodo: 'POST', cuerpo: { entidad: b.dataset.entidad, entidad_id: Number(b.dataset.id),
        tipo: $('rv-tipo').value, vence: $('rv-vence').value, documento: $('rv-doc').value.trim() || undefined } });
    } else if (a === 'confirmar-mover') {
      const v = $('rm-destino').value;
      const cuerpo = { activo_id: Number(b.dataset.id), motivo: $('rm-motivo').value.trim() || undefined };
      cuerpo[v[0] === 's' ? 'sitio_id' : 'base_id'] = Number(v.slice(1));
      await api.pedir('/recursos/activo/mover', { metodo: 'POST', cuerpo });
    } else if (a === 'guardar-persona') {
      await api.pedir('/recursos/persona', { metodo: 'POST', cuerpo: { nombre: $('rp-nombre').value.trim(), dni: $('rp-dni').value.trim(),
        legajo: $('rp-legajo').value.trim() || undefined, rol_operativo: $('rp-rol').value } });
    } else if (a === 'guardar-vehiculo') {
      await api.pedir('/recursos/vehiculo', { metodo: 'POST', cuerpo: { patente: $('rve-patente').value, descripcion: $('rve-desc').value.trim() || undefined } });
    } else if (a === 'guardar-activos') {
      await api.pedir('/recursos/activos', { metodo: 'POST', cuerpo: { tipo: $('ra-tipo').value, prefijo: $('ra-prefijo').value,
        desde: Number($('ra-desde').value), hasta: Number($('ra-hasta').value), base_id: Number($('ra-base').value) } });
    } else if (a === 'quitar') {
      await api.pedir('/recursos/cuadrilla/quitar', { metodo: 'POST', cuerpo: { persona_id: Number(b.dataset.persona) } });
    } else if (a === 'sumar') {
      const sel = $('rec-add-' + b.dataset.cuadrilla);
      if (!sel.value) { b.disabled = false; return; }
      await api.pedir('/recursos/cuadrilla/miembro', { metodo: 'POST', cuerpo: { cuadrilla_id: Number(b.dataset.cuadrilla), persona_id: Number(sel.value) } });
    } else if (a === 'cuadrilla') {
      await api.pedir('/recursos/cuadrilla', { metodo: 'POST', cuerpo: { nombre: $('rec-cua-nombre').value.trim() } });
    }
    if (!cua) panelRecursos('');
    await cargarRecursos();
  } catch (e) {
    b.disabled = false;
    errorRecursos(e, cua ? 'rec-error-cua' : 'rec-error');
  }
}

// ------------------------------------------------------------------
// Planificación (F3.7): quién sale, con qué camioneta y a dónde, las
// próximas dos semanas; y las campañas de varios días.
// ------------------------------------------------------------------
let planDatos = null;
const sumarDias = (iso, n) => new Date(Date.parse(iso + 'T12:00:00Z') + n * 86400000).toISOString().slice(0, 10);
const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const diaLargo = (iso) => DIAS_SEMANA[new Date(iso + 'T12:00:00Z').getUTCDay()] + ' ' + fechaCorta(iso).slice(0, 5);

async function cargarPlanificacion() {
  $('planificacion').innerHTML = '<p class="app-nota">Cargando…</p>';
  const desde = fechaArt();
  try {
    planDatos = await api.pedir('/asignaciones?desde=' + desde + '&hasta=' + sumarDias(desde, 13));
    marcarConexion(true, 'conectado');
    pintarPlanificacion();
  } catch (e) {
    error('planificacion', e);
  }
}

function pintarPlanificacion() {
  const d = planDatos;
  // Una línea por problema, con los días juntos: la misma licencia vencida
  // repetida cinco veces, una por día, esconde el resto de la lista.
  const grupos = new Map();
  for (const a of d.advertencias) {
    const k = a.quien + '|' + a.detalle;
    if (!grupos.has(k)) grupos.set(k, { ...a, dias: [] });
    grupos.get(k).dias.push(a.fecha);
  }
  const adv = grupos.size
    ? '<div class="aviso aviso--decision plan-avisos"><div><b>' + fmt.plural(grupos.size, 'problema de papeles', 'problemas de papeles') +
      ' en lo planificado</b><ul class="cli-lista">' + [...grupos.values()].map((g) => '<li><span class="plan-quien">' + esc(g.quien) + '</span> (' +
        esc(g.cuadrilla) + '): ' + esc(g.detalle) + ' <span class="cli-falta">' + esc(g.dias.map(diaLargo).join(', ')) + '</span></li>').join('') +
      '</ul></div></div>'
    : '';

  const porDia = new Map();
  for (const a of d.asignaciones) {
    if (!porDia.has(a.fecha)) porDia.set(a.fecha, []);
    porDia.get(a.fecha).push(a);
  }
  const dias = [];
  for (let f = d.desde; f <= d.hasta; f = sumarDias(f, 1)) dias.push(f);
  const grilla = '<div class="plan-dias">' + dias.map((f) => '<div class="plan-dia"><p class="plan-fecha">' + esc(diaLargo(f)) + '</p>' +
    ((porDia.get(f) ?? []).map((a) => '<div class="plan-asig' + (a.campana ? ' plan-asig--campana' : '') + '">' +
        '<b>' + esc(a.cuadrilla) + '</b>' + (a.patente ? ' · <span class="trabajo-doc">' + esc(a.patente) + '</span>' : '') +
        '<div class="cli-falta">' + esc(a.campana ? 'Campaña «' + a.campana + '»' : (a.ruta ? 'Ruta ' + a.ruta : 'Sin ruta')) + '</div>' +
        '<div class="cli-falta">' + esc(a.personas ?? '') + '</div>' +
        (a.campana ? '' : '<button class="app-btn rec-mini" type="button" data-plan="cancelar" data-id="' + a.id + '">Cancelar</button>') +
      '</div>').join('') || '<p class="app-nota">Nada planificado.</p>') + '</div>').join('') + '</div>';

  const campanas = d.campanas.length
    ? '<ul class="cli-lista">' + d.campanas.map((c) => '<li><b>' + esc(c.nombre) + '</b> · ' + fechaCorta(c.desde) + ' → ' + fechaCorta(c.hasta) +
        ' · ' + esc(c.cuadrilla) + (c.patente ? ' · ' + esc(c.patente) : '') + (c.activos ? ' · ' + esc(c.activos) : '') +
        ' <button class="app-btn rec-mini" type="button" data-plan="cancelar-campana" data-id="' + c.id + '">Cancelar campaña</button></li>').join('') + '</ul>'
    : '<p class="app-nota">No hay campañas en estas dos semanas.</p>';

  $('planificacion').innerHTML = adv +
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Próximas dos semanas</p>' +
      '<div class="cli-acciones"><button class="app-btn" type="button" data-plan="nueva-asig">Asignar un día</button>' +
      '<button class="app-btn" type="button" data-plan="nueva-campana">Planificar una campaña</button></div></div>' + grilla + '</div>' +
    '<div class="app-tarjeta"><p class="app-titulo">Campañas</p>' + campanas + '<p class="trabajo-error" id="plan-error-lista" role="alert" hidden></p></div>';
}

function panelPlan(html) {
  $('plan-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html + '<p class="trabajo-error" id="plan-error" role="alert" hidden></p></div>' : '';
  if (html) { $('plan-panel').scrollIntoView({ block: 'start' }); $('plan-panel').querySelector('input, select')?.focus({ preventScroll: true }); }
}

function rutasDelDia() {
  const f = $('pa-fecha').value;
  $('pa-ruta').innerHTML = opcion('', 'Sin ruta (otra tarea)', '') +
    planDatos.jornadas.filter((j) => j.fecha === f).map((j) => opcion(j.id, j.ruta, '')).join('');
}

async function accionPlan(b) {
  const a = b.dataset.plan;
  const d = planDatos;
  const cuadrillas = d.cuadrillas.map((c) => opcion(c.id, c.nombre + ' (' + fmt.plural(Number(c.miembros), 'persona', 'personas') + ')', '')).join('');
  const vehiculos = opcion('', 'Sin camioneta', '') + d.vehiculos.map((v) => opcion(v.id, v.patente, '')).join('');
  if (a === 'cerrar') { panelPlan(''); return; }
  if (a === 'nueva-asig') {
    panelPlan('<p class="app-titulo">Asignar un día</p><form class="cli-form">' +
      '<div class="app-campo"><label for="pa-fecha">Día</label><input id="pa-fecha" type="date" value="' + fechaArt() + '" /></div>' +
      '<div class="app-campo"><label for="pa-cuadrilla">Cuadrilla</label><select id="pa-cuadrilla">' + cuadrillas + '</select></div>' +
      '<div class="app-campo"><label for="pa-vehiculo">Camioneta</label><select id="pa-vehiculo">' + vehiculos + '</select></div>' +
      '<div class="app-campo"><label for="pa-ruta">Ruta</label><select id="pa-ruta"></select></div></form>' +
      '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-plan="guardar-asig">Asignar</button>' +
      '<button class="app-btn" type="button" data-plan="cerrar">Cancelar</button></div>');
    rutasDelDia();
    $('pa-fecha').addEventListener('change', rutasDelDia);
    return;
  }
  if (a === 'nueva-campana') {
    panelPlan('<p class="app-titulo">Planificar una campaña</p><p class="app-nota">Se crea un día por cada fecha del período, todos o ninguno. Si algo choca, se dice qué y qué día.</p>' +
      '<form class="cli-form">' +
      '<div class="app-campo"><label for="pc-nombre">Nombre</label><input id="pc-nombre" maxlength="120" placeholder="Los Azules" /></div>' +
      '<div class="app-campo"><label for="pc-cliente">Cliente <span class="conf-opcional">(opcional)</span></label><select id="pc-cliente">' +
        opcion('', '—', '') + d.clientes.map((c) => opcion(c.id, c.nombre, '')).join('') + '</select></div>' +
      '<div class="app-campo"><label for="pc-desde">Desde</label><input id="pc-desde" type="date" value="' + sumarDias(fechaArt(), 1) + '" /></div>' +
      '<div class="app-campo"><label for="pc-hasta">Hasta</label><input id="pc-hasta" type="date" value="' + sumarDias(fechaArt(), 4) + '" /></div>' +
      '<div class="app-campo"><label for="pc-cuadrilla">Cuadrilla</label><select id="pc-cuadrilla">' + cuadrillas + '</select></div>' +
      '<div class="app-campo"><label for="pc-vehiculo">Camioneta</label><select id="pc-vehiculo">' + vehiculos + '</select></div></form>' +
      (d.activos.length ? '<fieldset class="plan-activos"><legend>Equipos que se llevan</legend>' + d.activos.map((x) =>
        '<label><input type="checkbox" value="' + x.id + '" /> ' + esc(x.identificador) + '</label>').join('') + '</fieldset>' : '') +
      '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-plan="guardar-campana">Planificar</button>' +
      '<button class="app-btn" type="button" data-plan="cerrar">Cancelar</button></div>');
    return;
  }
  if (a === 'cancelar' || a === 'cancelar-campana') {
    const lugar = b.parentElement;
    if (lugar.querySelector('.plan-motivo')) return;
    lugar.insertAdjacentHTML('beforeend', '<div class="plan-motivo filtro-fecha"><div class="app-campo"><label for="pm-' + b.dataset.id + '">Motivo</label>' +
      '<input id="pm-' + b.dataset.id + '" maxlength="255" /></div><button class="app-btn app-btn--peligro rec-mini" type="button" data-plan="confirmar-' + a +
      '" data-id="' + b.dataset.id + '">Confirmar</button></div>');
    $('pm-' + b.dataset.id).focus();
    return;
  }

  b.disabled = true;
  const enPanel = a.startsWith('guardar');
  try {
    if (a === 'guardar-asig') {
      const cuerpo = { fecha: $('pa-fecha').value, cuadrilla_id: Number($('pa-cuadrilla').value) };
      if ($('pa-vehiculo').value) cuerpo.vehiculo_id = Number($('pa-vehiculo').value);
      if ($('pa-ruta').value) cuerpo.jornada_id = Number($('pa-ruta').value);
      await api.pedir('/asignacion', { metodo: 'POST', cuerpo });
    } else if (a === 'guardar-campana') {
      const cuerpo = { nombre: $('pc-nombre').value.trim(), desde: $('pc-desde').value, hasta: $('pc-hasta').value,
        cuadrilla_id: Number($('pc-cuadrilla').value), activos: [...document.querySelectorAll('.plan-activos input:checked')].map((x) => Number(x.value)) };
      if ($('pc-cliente').value) cuerpo.cliente_id = Number($('pc-cliente').value);
      if ($('pc-vehiculo').value) cuerpo.vehiculo_id = Number($('pc-vehiculo').value);
      await api.pedir('/campana', { metodo: 'POST', cuerpo });
    } else if (a === 'confirmar-cancelar' || a === 'confirmar-cancelar-campana') {
      await api.pedir(a === 'confirmar-cancelar' ? '/asignacion/cancelar' : '/campana/cancelar',
        { metodo: 'POST', cuerpo: { id: Number(b.dataset.id), motivo: $('pm-' + b.dataset.id).value.trim() } });
    }
    if (enPanel) panelPlan('');
    await cargarPlanificacion();
  } catch (e) {
    b.disabled = false;
    const p = $(enPanel ? 'plan-error' : 'plan-error-lista');
    if (p) { p.textContent = e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.'); p.hidden = false; }
  }
}

// ------------------------------------------------------------------
// Indicadores (F3.9): cumplimiento, desvíos, km, servicios y facturación.
//
// Formas elegidas para que ningún dato dependa de distinguir colores: la
// paleta de la marca (verde, terracota, piedra) no separa tres categorías
// con daltonismo (validado). Cumplimiento es UNA serie (el % del día);
// etapas y desvíos son barras de una sola magnitud; sólo los km comparan dos
// series, verde y terracota (validadas), con leyenda, rótulo al final y
// marcador distinto. Cada gráfico tiene su tabla y su tooltip.
// ------------------------------------------------------------------
let indRango = 30;

async function cargarIndicadores() {
  const hasta = $('ind-hasta')?.value || fechaArt();
  const desde = $('ind-desde')?.value || sumarDias(hasta, -(indRango - 1));
  const cont = $('indicadores');
  // Recargar conserva lo que había, atenuado: sin saltos ni «Cargando…».
  if (cont.dataset.listo) cont.classList.add('ind-recargando'); else cont.innerHTML = '<p class="app-nota">Cargando…</p>';
  try {
    const d = await api.pedir('/indicadores?desde=' + desde + '&hasta=' + hasta);
    marcarConexion(true, 'conectado');
    pintarIndicadores(d);
  } catch (e) {
    error('indicadores', e);
  } finally {
    cont.classList.remove('ind-recargando');
  }
}

const pct = (n) => (n === null ? '—' : n.toLocaleString('es-AR', { maximumFractionDigits: 1 }) + ' %');
const tile = (etiqueta, valor, detalle = '') => '<div class="ind-tile"><span class="ind-tile-et">' + esc(etiqueta) + '</span>' +
  '<b class="ind-tile-val">' + esc(valor) + '</b>' + (detalle ? '<span class="ind-tile-det">' + esc(detalle) + '</span>' : '') + '</div>';

/** Un gráfico de líneas por día. series: [{nombre, clase, marcador, valores: [n|null]}] */
function lineas(fechas, series, { maximo, formato, alto = 240, titulo }) {
  const W = 560, H = alto, IZQ = 52, DER = 92, ARR = 12, ABA = 30;
  const n = fechas.length;
  const x = (i) => IZQ + (n === 1 ? (W - IZQ - DER) / 2 : (i * (W - IZQ - DER)) / (n - 1));
  const y = (v) => ARR + (1 - v / maximo) * (H - ARR - ABA);
  const ticks = [0, maximo / 2, maximo];
  let svg = '<svg class="ind-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(titulo) + '">';
  for (const t of ticks) svg += '<line class="ind-grid" x1="' + IZQ + '" x2="' + (W - DER) + '" y1="' + y(t) + '" y2="' + y(t) + '" />' +
    '<text class="ind-eje" x="' + (IZQ - 6) + '" y="' + (y(t) + 4) + '" text-anchor="end">' + esc(formato(t)) + '</text>';
  const cada = Math.max(1, Math.ceil(n / 8));
  fechas.forEach((f, i) => { if (i % cada === 0 || i === n - 1) svg += '<text class="ind-eje" x="' + x(i) + '" y="' + (H - 8) + '" text-anchor="middle">' + fechaCorta(f).slice(0, 5) + '</text>'; });
  for (const s of series) {
    // Un tramo por cada racha de días con dato: un día sin jornadas es un
    // hueco, no un cero.
    let tramo = [];
    const tramos = [];
    s.valores.forEach((v, i) => { if (v === null) { if (tramo.length) tramos.push(tramo); tramo = []; } else tramo.push([x(i), y(v)]); });
    if (tramo.length) tramos.push(tramo);
    for (const t of tramos) svg += '<polyline class="ind-linea ' + s.clase + '" points="' + t.map((p) => p.join(',')).join(' ') + '" />';
    s.valores.forEach((v, i) => {
      if (v === null) return;
      svg += s.marcador === 'cuadrado'
        ? '<rect class="ind-marca ' + s.clase + '" x="' + (x(i) - 5.5) + '" y="' + (y(v) - 5.5) + '" width="11" height="11" />'
        : '<circle class="ind-marca ' + s.clase + '" cx="' + x(i) + '" cy="' + y(v) + '" r="6" />';
    });
    const ult = s.valores.map((v, i) => [v, i]).filter(([v]) => v !== null).pop();
    if (ult) svg += '<text class="ind-rotulo" x="' + (x(ult[1]) + 10) + '" y="' + (y(ult[0]) + 4) + '">' + esc(s.nombre) + '</text>';
  }
  // El blanco de cada día es la columna entera, no el punto de 8 px.
  const ancho = n === 1 ? W - IZQ - DER : (W - IZQ - DER) / (n - 1);
  fechas.forEach((f, i) => {
    const tip = diaLargo(f) + ': ' + series.map((s) => s.nombre + ' ' + (s.valores[i] === null ? 'sin datos' : formato(s.valores[i]))).join(' · ');
    svg += '<rect class="ind-hit" tabindex="0" x="' + (x(i) - ancho / 2) + '" y="0" width="' + ancho + '" height="' + (H - ABA) + '" data-tip="' + esc(tip) + '" />';
  });
  return svg + '</svg>';
}

/** Barras horizontales de una sola magnitud, con el valor en la punta. */
function barras(filas, { clase, formato, titulo }) {
  const W = 560, FILA = 36, IZQ = 190, DER = 60;
  const max = Math.max(1, ...filas.map((f) => f.valor));
  const H = filas.length * FILA + 8;
  let svg = '<svg class="ind-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(titulo) + '">' +
    '<line class="ind-grid" x1="' + IZQ + '" x2="' + IZQ + '" y1="0" y2="' + H + '" />';
  filas.forEach((f, i) => {
    const y = i * FILA + 8;
    const w = (f.valor / max) * (W - IZQ - DER);
    svg += '<text class="ind-eje ind-eje--cat" x="' + (IZQ - 10) + '" y="' + (y + 17) + '" text-anchor="end">' + esc(f.etiqueta) + '</text>';
    // Punta redondeada de 4 px, base recta: la barra crece desde el eje.
    if (w > 0) svg += '<path class="ind-barra ' + clase + '" d="M' + IZQ + ' ' + (y + 2) + ' h' + Math.max(0, w - 4) + ' a4 4 0 0 1 4 4 v12 a4 4 0 0 1 -4 4 h-' + Math.max(0, w - 4) + ' z" />';
    svg += '<text class="ind-valor" x="' + (IZQ + w + 8) + '" y="' + (y + 17) + '">' + esc(formato(f.valor)) + '</text>' +
      '<rect class="ind-hit" tabindex="0" x="0" y="' + y + '" width="' + W + '" height="' + (FILA - 4) + '" data-tip="' + esc(f.etiqueta + ': ' + formato(f.valor)) + '" />';
  });
  return svg + '</svg>';
}

const tablaDe = (cab, filas) => '<details class="ind-tabla"><summary>Ver como tabla</summary><div class="tabla-envoltorio"><table class="tabla"><thead><tr>' +
  cab.map((c, i) => '<th' + (i ? ' class="num"' : '') + '>' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' +
  filas.map((f) => '<tr>' + f.map((c, i) => '<td' + (i ? ' class="num"' : '') + '>' + esc(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div></details>';

function pintarIndicadores(d) {
  const c = d.cumplimiento, t = c.total;
  const fechas = c.por_dia.map((x) => x.fecha);
  const pctDia = c.por_dia.map((x) => (x.planificadas ? Math.round(1000 * x.hechas / x.planificadas) / 10 : null));
  const kmM = d.km.por_dia.map((x) => (x.con_medicion ? Math.round(x.medido * 10) / 10 : null));
  const kmE = d.km.por_dia.map((x) => (x.jornadas ? Math.round(x.estimado * 10) / 10 : null));
  const maxKm = Math.max(10, ...kmM.filter((v) => v !== null), ...kmE.filter((v) => v !== null));
  const topeKm = Math.ceil(maxKm / 50) * 50;
  const f = d.facturacion;
  const etapas = [['sin_verificar', 'Hechos sin verificar'], ['verificado', 'Verificados'], ['certificado', 'Certificados'],
    ['en_propuesta', 'En una propuesta'], ['facturado', 'Facturados']].map(([k, e]) => ({ etiqueta: e, valor: f.etapas[k] }));
  const desvios = d.desvios.por_tipo.filter((x) => x.n > 0).sort((a, b) => b.n - a.n).map((x) => ({ etiqueta: x.nombre, valor: x.n }));
  const kmTxt = (v) => v.toLocaleString('es-AR', { maximumFractionDigits: 0 }) + ' km';

  $('indicadores').innerHTML =
    '<div class="ind-tiles">' +
      '<div class="ind-tile ind-tile--hero"><span class="ind-tile-et">Cumplimiento</span><b class="ind-hero">' + esc(pct(c.porcentaje)) + '</b>' +
        '<span class="ind-tile-det">' + t.hechas + ' hechas de ' + t.planificadas + ' planificadas · ' + t.no_hechas + ' no hechas · ' + t.sin_resolver + ' sin resolver</span></div>' +
      tile('Baños atendidos', fmt.entero(d.servicios.banos), fmt.plural(d.servicios.visitas, 'visita', 'visitas') + ' · ' + fmt.plural(d.servicios.remitos, 'remito', 'remitos')) +
      tile('Desvíos', fmt.entero(d.desvios.total), (d.desvios.por_severidad.alta ?? 0) + ' de severidad alta') +
      tile('Km medidos', kmTxt(d.km.total.medido), 'contra ' + kmTxt(d.km.total.estimado) + ' estimados · ' + d.km.total.con_medicion + ' de ' + d.km.total.jornadas + ' jornadas medidas') +
      tile('Facturado', fmt.pesos(f.facturado_cent), fmt.pesos(f.en_propuesta_cent) + ' en propuesta') +
      tile('Por facturar', fmt.pesos(f.por_facturar_cent), [
        f.por_facturar_sin_tarifa ? fmt.plural(f.por_facturar_sin_tarifa, 'remito', 'remitos') + ' sin tarifa' : '',
        f.por_facturar_sin_cliente ? fmt.plural(f.por_facturar_sin_cliente, 'remito', 'remitos') + ' de sitios sin cliente' : '',
        f.por_facturar_sin_cantidad ? fmt.plural(f.por_facturar_sin_cantidad, 'remito', 'remitos') + ' sin cantidad' : '',
      ].filter(Boolean).join(' · ') + (f.por_facturar_sin_tarifa || f.por_facturar_sin_cliente || f.por_facturar_sin_cantidad
        ? ', no incluidos' : 'certificado, con la tarifa vigente')) +
    '</div>' +
    '<div class="ind-graficos">' +
      '<figure class="app-tarjeta ind-fig"><figcaption class="app-titulo">Cumplimiento por día</figcaption><p class="app-nota">Paradas hechas sobre planificadas. Un día sin jornadas queda en blanco.</p>' +
        lineas(fechas, [{ nombre: '% hechas', clase: 'ind-s-verde', marcador: 'circulo', valores: pctDia }], { maximo: 100, formato: (v) => Math.round(v) + ' %', titulo: 'Cumplimiento diario' }) +
        tablaDe(['Día', 'Planificadas', 'Hechas', 'No hechas', 'Sin resolver', '%'], c.por_dia.map((x, i) => [diaLargo(x.fecha), x.planificadas, x.hechas, x.no_hechas, x.sin_resolver, pctDia[i] === null ? '—' : pct(pctDia[i])])) + '</figure>' +
      '<figure class="app-tarjeta ind-fig"><figcaption class="app-titulo">Kilómetros por día</figcaption>' +
        '<ul class="leyenda ind-leyenda"><li><i class="ind-k ind-k--verde"></i> medidos</li><li><i class="ind-k ind-k--terracota"></i> estimados por ruta</li></ul>' +
        lineas(fechas, [{ nombre: 'medidos', clase: 'ind-s-verde', marcador: 'circulo', valores: kmM }, { nombre: 'estimados', clase: 'ind-s-terracota', marcador: 'cuadrado', valores: kmE }],
          { maximo: topeKm, formato: kmTxt, titulo: 'Kilómetros medidos y estimados por día' }) +
        tablaDe(['Día', 'Estimados', 'Medidos', 'Jornadas medidas'], d.km.por_dia.map((x, i) => [diaLargo(x.fecha), kmE[i] ?? '—', kmM[i] ?? '—', x.con_medicion + ' de ' + x.jornadas])) + '</figure>' +
      '<figure class="app-tarjeta ind-fig"><figcaption class="app-titulo">Dónde está el trabajo hecho</figcaption><p class="app-nota">Las visitas hechas del período, según hasta dónde llegaron en el circuito.</p>' +
        barras(etapas, { clase: 'ind-s-verde', formato: fmt.entero, titulo: 'Visitas por etapa del circuito' }) +
        tablaDe(['Etapa', 'Visitas'], etapas.map((x) => [x.etiqueta, x.valor])) + '</figure>' +
      '<figure class="app-tarjeta ind-fig"><figcaption class="app-titulo">Desvíos por tipo</figcaption>' +
        (desvios.length ? barras(desvios, { clase: 'ind-s-terracota', formato: fmt.entero, titulo: 'Desvíos por tipo' }) +
          tablaDe(['Tipo', 'Desvíos'], desvios.map((x) => [x.etiqueta, x.valor])) : '<p class="app-nota">Sin desvíos en el período.</p>') + '</figure>' +
    '</div><div class="ind-tip" id="ind-tip" role="tooltip" hidden></div>';
  $('indicadores').dataset.listo = '1';
}

function tipIndicadores(ev) {
  const hit = ev.target.closest?.('.ind-hit');
  const tip = $('ind-tip');
  if (!tip) return;
  if (!hit) { tip.hidden = true; return; }
  tip.textContent = hit.dataset.tip;
  tip.hidden = false;
  const caja = $('indicadores').getBoundingClientRect();
  const r = hit.getBoundingClientRect();
  const x = ev.clientX ?? (r.left + r.width / 2);
  tip.style.left = Math.min(caja.width - 260, Math.max(0, x - caja.left + 12)) + 'px';
  tip.style.top = (r.top - caja.top + (ev.clientY ? ev.clientY - r.top : 0) - 40) + 'px';
}

const cargadores = {
  jornadas: cargarJornadas, flota: cargarFlota, trabajos: cargarTrabajos,
  desvios: cargarDesvios, consultas: cargarConsultas, auditoria: () => cargarAuditoria(false),
  clientes: cargarClientes, tarifas: cargarTarifas, facturacion: cargarFacturacion, recursos: cargarRecursos,
  planificacion: cargarPlanificacion, indicadores: cargarIndicadores, ambiente: () => sga.cargar(),
  papel: () => ocr.cargar(), usuarios: () => usuarios.cargar(),
};
const yaCargado = new Set();

function mostrar(vista) {
  for (const b of document.querySelectorAll('.pestana')) {
    b.setAttribute('aria-pressed', String(b.dataset.vista === vista));
  }
  for (const s of document.querySelectorAll('[data-panel]')) {
    s.hidden = s.dataset.panel !== vista;
  }
  // Se carga al entrar la primera vez, no todo junto al abrir: no tiene
  // sentido pedirle al servidor tres cosas de las que se va a mirar una.
  if (!yaCargado.has(vista)) {
    yaCargado.add(vista);
    cargadores[vista]?.();
  } else if (vista === 'flota') {
    // Volver a la flota trae lo último; el refresco se había frenado al salir.
    cargarFlota(true);
  }
  $('filtro-dia').hidden = !DEL_DIA.has(vista);
  history.replaceState(null, '', '#' + vista);
}

// Cierre y Documentos miran el mismo día. Al cambiarlo se olvida lo cargado
// de los dos y se recarga el que está a la vista; el otro se pide al entrar.
const DEL_DIA = new Set(['jornadas', 'trabajos']);
function cambiarDia(fecha) {
  if (fecha) $('fecha').value = fecha;
  const vista = document.querySelector('.pestana[aria-pressed="true"]')?.dataset.vista;
  for (const v of DEL_DIA) yaCargado.delete(v);
  if (DEL_DIA.has(vista)) { yaCargado.add(vista); cargadores[vista](); }
}

// Desde una alerta del cierre a las paradas de esa jornada.
let irAJornada = null;
function destacarJornada() {
  if (irAJornada === null) return;
  const art = $('trb-j-' + irAJornada);
  irAJornada = null;
  if (!art) return;
  art.dataset.destacada = '';
  art.scrollIntoView({ block: 'start' });
  art.querySelector('.jornada-titulo')?.focus({ preventScroll: true });
}

// ------------------------------------------------------------------
(async function arrancar() {
  const u = await sesion.usuario();
  if (!u) { location.replace('/app/'); return; }
  if (u.rol !== 'supervisor' && u.rol !== 'admin') {
    // Un chofer que llega acá por un enlace viejo va a su pantalla, sin un
    // error que no puede resolver.
    location.replace(u.rol === 'cliente' ? '/app/cliente/' : '/app/campo/');
    return;
  }

  $('fecha').value = fechaArt();
  $('fecha').addEventListener('change', () => cambiarDia());

  // La semana que termina hoy.
  const hoy = fechaArt();
  const hace6 = new Date(Date.parse(hoy + 'T12:00:00Z') - 6 * 86400000).toISOString().slice(0, 10);
  $('dsv-desde').value = hace6;
  $('dsv-hasta').value = hoy;
  $('dsv-buscar').addEventListener('click', cargarDesvios);
  $('dsv-tipo').addEventListener('change', cargarDesvios);
  $('btn-hoy').addEventListener('click', () => cambiarDia(fechaArt()));
  $('jornadas').addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-ir-jornada]');
    if (!b) return;
    irAJornada = Number(b.dataset.irJornada);
    yaCargado.delete('trabajos');
    mostrar('trabajos');
  });
  $('trb-verificar-todas').addEventListener('click', verificarTodas);
  $('aud-ver').addEventListener('click', () => cargarAuditoria(false));
  $('aud-entidad').addEventListener('change', () => cargarAuditoria(false));
  $('aud-verificar').addEventListener('click', verificarCadenas);
  soyAdmin = u.rol === 'admin';
  sga.iniciar({ soyAdmin, marcarConexion, error });
  ocr.iniciar({ marcarConexion, error });
  usuarios.iniciar({ soyAdmin, yo: u.id, marcarConexion, error });
  for (const ev of ['pointermove', 'focusin']) $('indicadores').addEventListener(ev, tipIndicadores);
  $('indicadores').addEventListener('pointerleave', () => { if ($('ind-tip')) $('ind-tip').hidden = true; });
  $('ind-filtro').addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-dias]');
    if (!b) return;
    indRango = Number(b.dataset.dias);
    for (const x of document.querySelectorAll('#ind-filtro button[data-dias]')) x.setAttribute('aria-pressed', String(x === b));
    $('ind-hasta').value = fechaArt();
    $('ind-desde').value = sumarDias(fechaArt(), -(indRango - 1));
    cargarIndicadores();
  });
  $('ind-hasta').value = fechaArt();
  $('ind-desde').value = sumarDias(fechaArt(), -(indRango - 1));
  for (const id of ['ind-desde', 'ind-hasta']) $(id).addEventListener('change', cargarIndicadores);
  for (const id of ['planificacion', 'plan-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-plan]');
      if (b) accionPlan(b);
    });
  }
  for (const id of ['recursos', 'rec-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-rec]');
      if (b) accionRecursos(b);
    });
  }
  $('facturacion').addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-fac]');
    if (b) accionFacturacion(b);
  });
  for (const id of ['tarifas', 'tar-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-tar]');
      if (b) accionTarifa(b);
    });
  }
  for (const id of ['clientes', 'cli-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-cli]');
      if (b) accionCliente(b);
    });
  }
  // Un solo escuchador para toda la tabla: se repinta entera después de cada
  // acción y los botones se crean de nuevo.
  $('trabajos').addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-accion]');
    if (b) accionTrabajo(b);
  });

  for (const b of document.querySelectorAll('.pestana')) {
    b.addEventListener('click', () => mostrar(b.dataset.vista));
  }

  const inicial = (location.hash || '#jornadas').slice(1);
  mostrar(cargadores[inicial] ? inicial : 'jornadas');
})();
