// ============================================================
// Logica de la hoja de ruta.
//
// Regla de oro de esta pantalla: TODO cambio se escribe primero en la cola
// local y despues se intenta enviar. Nunca al reves. Si el envio falla, el
// trabajo ya esta guardado; si el telefono se apaga, tambien.
// ============================================================

import * as sesion from './sesion.js';
import * as cola from './cola.js';
import * as api from './api.js';
import { leer, guardar, pedirPersistencia } from './db.js';
import { Gps, horaDe } from './geo.js';
import { Geocerca } from './geocerca.js';
import { arrancarSync, sincronizarAhora } from './sync.js';
import { cerrarParada, montarDialogo } from './evidencia.js';
import { pedirMotivo, montarMotivo } from './motivo.js';
import * as fmt from './formato.js';
import { ico } from './iconos.js';
import { Mapa } from './mapa.js';

const $ = (id) => document.getElementById(id);

let estado = {
  hoy: null,
  jornadas: [],
  seleccionada: null,
  base: null,
};

const gps = new Gps();
const geocerca = new Geocerca(alTildarAutomatico);

// El mapa se construye una sola vez y se le pasa la jornada al pintar. Ir a
// una parada desde el mapa lleva a su tarjeta: el mapa ubica, la tarjeta es
// donde se trabaja.
const mapa = new Mapa(document.getElementById('mapa'), (orden) => {
  verLista();
  const t = document.querySelector('.parada[data-orden="' + orden + '"]');
  t?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  t?.querySelector('[data-accion]')?.focus();
});

function verLista() { cambiarModo('lista'); }

// El punto azul se mueve con el GPS aunque la lista no se repinte: dibujar la
// jornada entera en cada fix seria tirar bateria y perder el foco del chofer.
gps.escuchar((p) => mapa.fijarPosicion(p.lat, p.lon, p.precision));

function cambiarModo(modo) {
  const esMapa = modo === 'mapa';
  $('mapa').hidden = !esMapa;
  $('paradas').hidden = esMapa;
  $('modo-lista').setAttribute('aria-pressed', String(!esMapa));
  $('modo-mapa').setAttribute('aria-pressed', String(esMapa));
  if (esMapa) {
    const j = jornadaActual();
    if (j) mapa.dibujar({ ...j, base: estado.base });
  }
}

// ------------------------------------------------------------------
// Carga: primero lo local, despues el servidor.
// ------------------------------------------------------------------
async function cargar() {
  if (!(await sesion.hayDispositivo())) {
    location.replace('/app/');
    return;
  }

  // 1. Lo que ya esta en el telefono. Abre al instante y funciona sin senial.
  const local = await leer('jornada', 'actual');
  if (local) {
    estado = { ...estado, ...local.datos };
    pintar();
  }

  // 2. Si hay red, se refresca. Si no, se sigue con lo local sin drama.
  try {
    const datos = await api.dia();
    estado.hoy = datos.hoy;
    estado.base = datos.base;
    // El servidor puede estar ATRASADO respecto del telefono: lo que el chofer
    // acaba de marcar todavia esta en la cola y no llego. Si se pisara sin
    // mirar, el chofer veria desaparecer de la pantalla paradas que ya cerro,
    // que es la forma mas rapida de que deje de confiar en la app.
    estado.jornadas = await fusionarConPendientes(datos.jornadas);
    await guardarEstado();
    pintar();
  } catch (e) {
    if (!local) {
      $('cargando').innerHTML =
        '<p class="app-titulo">Todavía no bajaste la hoja de ruta</p>' +
        '<p class="app-nota">Necesitás señal una vez para descargarla. Buscá cobertura antes de salir de la base.</p>';
      return;
    }
    console.warn('[camca] se trabaja con la hoja de ruta guardada:', e.codigo);
  }
}

/**
 * Funde la hoja de ruta del servidor con lo que todavia esta en la cola.
 *
 * Para cada parada que tenga un evento PENDIENTE, gana la version local: es
 * trabajo real que el servidor todavia no vio. Para el resto, gana el
 * servidor, que ademas trae lo que hizo el rastro de flota y lo que corrigio
 * el supervisor desde la oficina.
 */
async function fusionarConPendientes(jornadasServidor) {
  const pendientes = await cola.pendientes();
  if (!pendientes.length) return jornadasServidor;

  // Claves jornada|orden que todavia no llegaron al servidor.
  const enVuelo = new Set(
    pendientes
      .filter((r) => r.jornada_id != null && r.parada_orden != null)
      .map((r) => r.jornada_id + '|' + r.parada_orden)
  );
  if (!enVuelo.size) return jornadasServidor;

  const localPorId = new Map((estado.jornadas || []).map((j) => [j.id, j]));

  return jornadasServidor.map((js) => {
    const jl = localPorId.get(js.id);
    if (!jl) return js;
    return {
      ...js,
      paradas: js.paradas.map((ps) => {
        if (!enVuelo.has(js.id + '|' + ps.orden)) return ps;
        const pl = jl.paradas.find((x) => x.orden === ps.orden);
        return pl ?? ps;
      }),
    };
  });
}

function guardarEstado() {
  return guardar('jornada', {
    fecha: 'actual',
    datos: { hoy: estado.hoy, jornadas: estado.jornadas, base: estado.base },
    guardado_ms: Date.now(),
  });
}

// ------------------------------------------------------------------
// Pintado
// ------------------------------------------------------------------
function jornadaActual() {
  if (!estado.jornadas.length) return null;
  return estado.jornadas.find((j) => j.fecha === estado.seleccionada)
      ?? estado.jornadas.find((j) => j.es_hoy)
      ?? estado.jornadas[0];
}

/**
 * Que paradas de esta jornada siguen viviendo adentro del telefono.
 *
 * Si la cola no se puede leer se asume LO PEOR: que nada se envio. Una barra
 * que se queda corta molesta; una que miente para el lado bueno hace que
 * alguien archive como terminado un dia que todavia no salio del telefono.
 */
async function ordenesEnElTelefono(j) {
  try {
    return await cola.ordenesPendientes(j.id);
  } catch {
    return new Set(j.paradas.filter((p) => p.estado !== 'planificada').map((p) => p.orden));
  }
}

async function pintar() {
  const j = jornadaActual();
  $('cargando').hidden = true;
  if (!j) {
    $('paradas').innerHTML =
      '<div class="app-tarjeta"><p class="app-titulo">Sin jornada</p>' +
      '<p class="app-nota">No hay ruta planificada. Avisá a coordinación.</p></div>';
    return;
  }
  estado.seleccionada = j.fecha;

  // Se consulta UNA vez y se reparte: el resumen y el cierre tienen que
  // contar lo mismo. Consultando cada uno por su cuenta pueden mostrar dos
  // numeros distintos de la misma jornada.
  const pend = await ordenesEnElTelefono(j);

  pintarDias();
  pintarResumen(j, pend);
  pintarParadas(j);
  pintarCierre(j, pend);
  if (!$('mapa').hidden) mapa.dibujar({ ...j, base: estado.base });

  // La proxima parada pendiente define si conviene gastar bateria en precision.
  const prox = j.paradas.find((p) => p.estado === 'planificada');
  gps.fijarObjetivo(prox?.lat, prox?.lon);
  geocerca.fijarParadas(j.paradas);
}

function pintarDias() {
  const cont = $('dias');
  if (estado.jornadas.length < 2) { cont.hidden = true; return; }
  cont.hidden = false;
  cont.innerHTML = '';
  for (const j of estado.jornadas) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'dia-tab';
    b.setAttribute('aria-pressed', String(j.fecha === estado.seleccionada));
    b.textContent = j.ruta + (j.es_hoy ? ' (hoy)' : '');
    b.onclick = () => { estado.seleccionada = j.fecha; pintar(); };
    cont.appendChild(b);
  }
}

function pintarResumen(j, pend) {
  const total = j.paradas.length;
  const hechas = j.paradas.filter((p) => p.estado === 'ejecutada');
  const conMotivo = j.paradas.filter((p) => p.estado === 'no_ejecutada').length;
  const sinHacer = total - hechas.length - conMotivo;

  // El corte que importa. "Hecha" no es un estado: son dos.
  //   confirmada -> el servidor la tiene; si el telefono se rompe, existe.
  //   sin subir  -> esta hecha, pero vive solo aca adentro.
  // Antes se pintaban del mismo verde, y encima el relleno sumaba las no
  // ejecutadas: una jornada con 5 hechas y 6 con motivo mostraba la barra
  // llena. La pantalla decia "terminaste" de un dia a medio hacer.
  const sinSubir = hechas.filter((p) => pend.has(p.orden)).length;
  const confirmadas = hechas.length - sinSubir;

  const baniosHechos = hechas.reduce((a, p) => a + (p.cantidad_real ?? p.cantidad_plan), 0);

  $('resumen').hidden = false;
  $('resumen-ruta').textContent = j.ruta;
  $('resumen-fecha').textContent =
    new Date(j.fecha + 'T12:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: 'long' }) +
    (j.es_hoy ? ' · hoy' : '');
  $('resumen-hechas').textContent = String(hechas.length);
  $('resumen-total').textContent = '/' + total;

  // Ojo: estos numeros van a CSS, no a la pantalla. El separador decimal de
  // CSS es el punto SIEMPRE, sin importar el idioma; localizarlos aca romperia
  // el ancho de la barra.
  const ancho = (n) => (total ? ((n / total) * 100).toFixed(2) : '0') + '%';
  $('barra-confirmada').style.width = ancho(confirmadas);
  $('barra-sin-subir').style.width = ancho(sinSubir);
  $('barra-motivo').style.width = ancho(conMotivo);

  // La leyenda no es adorno: es lo que hace legible la barra sin depender del
  // color. Los segmentos en cero no se nombran, para no llenar de ceros una
  // pantalla que se mira de reojo.
  const partes = [
    { marca: 'm-confirmada', n: confirmadas, texto: 'hechas y enviadas' },
    { marca: 'm-sin-subir', n: sinSubir, texto: 'hechas sin subir' },
    { marca: 'm-motivo', n: conMotivo, texto: 'con motivo' },
    { marca: 'm-resta', n: sinHacer, texto: 'sin hacer' },
  ].filter((x) => x.n > 0);

  $('leyenda').innerHTML = partes
    .map((x) => '<li><i class="' + x.marca + '"></i><b>' + x.n + '</b> ' + x.texto + '</li>')
    .join('');
  $('barra').setAttribute('aria-label', partes.length
    ? 'Avance: ' + partes.map((x) => x.n + ' ' + x.texto).join(', ')
    : 'Sin paradas en esta jornada');

  // Los km se dicen con su procedencia. Sin odometro son una estimacion por
  // posiciones, que infla alrededor de un 9% por ruido de GPS, y este numero
  // puede terminar adentro de una factura.
  const km = (j.km_real === null || j.km_real === undefined)
    ? fmt.km(j.km_estimado) + ' estimados'
    : fmt.km(j.km_real) + (j.km_fuente === 'odometro' ? ' (odómetro)' : ' (aproximado por posiciones)');

  $('resumen-banios').textContent =
    baniosHechos + ' de ' + j.banios_plan + ' baños atendidos · ' + km;
}

function pintarParadas(j) {
  const cont = $('paradas');
  cont.innerHTML = '';

  for (const p of j.paradas) {
    const el = document.createElement('article');
    el.className = 'parada';
    el.dataset.estado = p.estado;
    el.dataset.orden = String(p.orden);

    const maps = 'https://www.google.com/maps/search/?api=1&query=' + p.lat + ',' + p.lon;
    const hecha = p.estado === 'ejecutada';
    const noHecha = p.estado === 'no_ejecutada';

    // Tres niveles de confianza, y se ven distintos porque LO SON. El rastro
    // de flota es autoritativo: sale del servidor y no depende de que el
    // telefono tenga pantalla encendida. El del celular corrobora. Lo marcado
    // a mano no tiene evidencia de posicion detras. Antes los tres primeros
    // usaban la misma clase verde: el dato de mayor peso de la jornada se
    // presentaba igual que el de menor peso.
    const etiquetas = [];
    if (hecha && p.origen === 'flota') etiquetas.push('<span class="etiqueta etiqueta--fuerte">GPS del camión</span>');
    if (hecha && p.origen === 'telefono') etiquetas.push('<span class="etiqueta">GPS del celular</span>');
    if (hecha && p.origen === 'manual') etiquetas.push('<span class="etiqueta etiqueta--gris">marcada a mano</span>');
    if (p.fotos) etiquetas.push('<span class="etiqueta etiqueta--gris">' + p.fotos + (p.fotos === 1 ? ' foto' : ' fotos') + '</span>');
    if (p.firmada) etiquetas.push('<span class="etiqueta">firmada</span>');
    // Un rechazo del cliente se ve en la tarjeta: el chofer tiene que poder
    // decírselo a la oficina sin abrir nada.
    if (p.conformidad === 'rechazado') etiquetas.push('<span class="etiqueta etiqueta--aviso">no conforme</span>');
    if (p.ambiguo) etiquetas.push('<span class="etiqueta etiqueta--decision">confirmar</span>');

    // La coordenada sospechosa se avisa: sin esto el chofer cree que el GPS
    // esta roto cuando en realidad el dato de origen esta mal cargado.
    const alertas = [];
    if (p.geo_calidad === 'sospechosa') {
      alertas.push('<div class="parada-alerta aviso aviso--decision">' + ico('mapa') +
        '<span><b>La coordenada de esta parada no es confiable.</b>' +
        'Marcala a mano cuando estés en el lugar: no se va a tildar sola.' +
        (p.geo_nota ? '<br><small>' + escapar(p.geo_nota) + '</small>' : '') + '</span></div>');
    }
    if (p.cluster_id && j.clusters_ambiguos.includes(p.cluster_id) && !hecha && !noHecha) {
      alertas.push('<div class="parada-alerta aviso aviso--decision">' + ico('bifurcacion') +
        '<span><b>Hay otra parada en esta misma dirección.</b>' +
        'El GPS no las puede distinguir: elegí vos cuál hiciste.</span></div>');
    }

    el.innerHTML =
      '<div class="parada-num">' +
        (hecha ? ico('tilde') : noHecha ? ico('cruz') : p.orden) + '</div>' +
      '<div>' +
        '<p class="parada-nombre">' + escapar(p.nombre) + ' ' + etiquetas.join(' ') + '</p>' +
        (p.direccion ? '<p class="parada-dir">' + escapar(p.direccion) + '</p>' : '') +
        '<div class="parada-meta">' +
          (p.responsable ? '<span>' + ico('persona') + ' ' + escapar(p.responsable) + '</span>' : '') +
          '<span>' + ico('unidad') + ' ' + fmt.plural(p.cantidad_plan, 'baño', 'baños') + '</span>' +
          (p.arribo_utc ? '<span>' + ico('reloj') + ' ' + horaDe(Date.parse(p.arribo_utc + 'Z')) + '</span>' : '') +
          '<a href="' + maps + '" target="_blank" rel="noopener">' + ico('mapa') + ' Cómo llegar</a>' +
        '</div>' +
        alertas.join('') +
        // La ranura existe solo en las paradas que todavia se pueden cumplir,
        // que son las unicas donde el contador puede arrancar. Reservar 20 px
        // en las 65 paradas de la semana seria puro aire.
        (p.estado === 'planificada'
          ? '<div class="dwell" data-dwell="' + p.orden + '">' +
              '<span class="dwell-riel"><span class="dwell-fill"></span></span>' +
              '<b class="dwell-seg"></b></div>'
          : '') +
        (noHecha && p.motivo ? '<p class="parada-dir"><b>Motivo:</b> ' + escapar(p.motivo) + '</p>' : '') +
        '<div class="parada-acciones">' + botones(p) + '</div>' +
      '</div>';

    cont.appendChild(el);
  }

  cont.querySelectorAll('[data-accion]').forEach((b) => {
    b.onclick = () => manejar(b.dataset.accion, Number(b.dataset.orden));
  });
}

function botones(p) {
  if (p.estado === 'planificada') {
    return '<button class="app-btn app-btn--principal" data-accion="hecha" data-orden="' + p.orden + '">Hecha</button>' +
           '<button class="app-btn" data-accion="no-hecha" data-orden="' + p.orden + '">No se hizo</button>';
  }
  return '<button class="app-btn app-btn--fila" data-accion="reabrir" data-orden="' + p.orden + '">Deshacer</button>';
}

function pintarCierre(j, pend) {
  const sinResolver = j.paradas.filter((p) => p.estado === 'planificada').length;
  const enElTelefono = j.paradas.filter((p) => p.estado !== 'planificada' && pend.has(p.orden)).length;

  $('cierre').hidden = !j.es_hoy;

  const partes = [sinResolver === 0
    ? 'Todas las paradas están resueltas.'
    : 'Quedan ' + fmt.plural(sinResolver, 'parada sin resolver', 'paradas sin resolver') + '.'];

  // Se dice, y se dice que NO bloquea. Callarlo hace que alguien de por
  // terminado un dia que todavia vive adentro de un telefono; convertirlo en
  // un impedimento dejaria al chofer sin poder cerrar arriba del cerro.
  if (enElTelefono > 0) {
    partes.push(fmt.plural(enElTelefono, 'parada sigue guardada', 'paradas siguen guardadas') +
      ' solo en este teléfono. Podés cerrar igual: se envían cuando haya señal.');
  }

  $('cierre-detalle').textContent = partes.join(' ');
}

const escapar = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ------------------------------------------------------------------
// Acciones
// ------------------------------------------------------------------
async function manejar(accion, orden) {
  const j = jornadaActual();
  const p = j?.paradas.find((x) => x.orden === orden);
  if (!p) return;

  if (accion === 'hecha') {
    // El diálogo encola las fotos y la firma por su cuenta: para cuando
    // vuelve, la evidencia ya está guardada en el teléfono.
    const r = await cerrarParada(p, { jornada_id: j.id, posicion: gps.ultima });
    if (r === null) return;

    await aplicarLocal(p, {
      estado: 'ejecutada',
      cantidad_real: r.cantidad_real,
      origen: 'manual',
      arribo_utc: p.arribo_utc,
      firmada: r.firmada,
      fotos: r.fotos,
      conformidad: r.conformidad?.resultado ?? null,
    });
    await cola.encolar('parada_cerrada', {
      cantidad_real: r.cantidad_real,
      origen: 'manual',
      arribo_ms: p.arribo_utc ? Date.parse(p.arribo_utc + 'Z') : Date.now(),
      firmante: r.firmante,
      firmada: r.firmada,
      fotos: r.fotos,
      adjuntos: r.adjuntos.map((a) => a.uuid),
      conformidad: r.conformidad,
    }, { jornada_id: j.id, sitio_id: p.sitio_id, parada_orden: p.orden, ronda: p.ronda ?? 0 });
  }

  if (accion === 'no-hecha') {
    const motivo = await pedirMotivo(p);
    if (motivo === null) return;
    await aplicarLocal(p, { estado: 'no_ejecutada', motivo, origen: 'manual', conformidad: null });
    await cola.encolar('parada_no_ejecutada', { motivo },
      { jornada_id: j.id, sitio_id: p.sitio_id, parada_orden: p.orden, ronda: p.ronda ?? 0 });
  }

  if (accion === 'reabrir') {
    // La reapertura viaja con la ronda en la que ocurrió, y todo lo que venga
    // después de ella va en la siguiente: así el servidor no confunde el
    // segundo cierre con un reenvío del primero.
    const ronda = p.ronda ?? 0;
    await aplicarLocal(p, { estado: 'planificada', cantidad_real: null, motivo: null, arribo_utc: null, origen: 'ninguno', bloqueada: true, ronda: ronda + 1, conformidad: null, firmada: false });
    // El GPS no la vuelve a tildar hoy: el chofer ya dijo que no la hizo.
    geocerca.olvidar(orden);
    await cola.encolar('parada_reabierta', {}, { jornada_id: j.id, sitio_id: p.sitio_id, parada_orden: p.orden, ronda });
  }

  pintar();
  sincronizarAhora().catch(() => {});
}

/** Escribe el cambio en el estado local y lo persiste. Se hace SIEMPRE antes
 *  de intentar enviar: si el envio falla, el trabajo ya esta guardado. */
async function aplicarLocal(p, cambios) {
  Object.assign(p, cambios);
  await guardarEstado();
}

// ------------------------------------------------------------------
// Auto-tildado por permanencia
// ------------------------------------------------------------------
async function alTildarAutomatico(parada, info) {
  const j = jornadaActual();
  const p = j?.paradas.find((x) => x.orden === parada.orden);
  if (!p || p.estado !== 'planificada') return;

  await aplicarLocal(p, {
    estado: 'ejecutada',
    cantidad_real: p.cantidad_plan,
    origen: 'telefono',
    arribo_utc: new Date(info.arribo_ms).toISOString().slice(0, 19),
    ambiguo: info.ambiguo,
  });

  await cola.encolar('parada_cerrada', {
    cantidad_real: p.cantidad_plan,
    origen: 'telefono',
    arribo_ms: info.arribo_ms,
    precision_m: info.precision_m,
    edad_fix_seg: info.edad_fix_seg,
    ambiguo: info.ambiguo,
  }, { jornada_id: j.id, sitio_id: p.sitio_id, parada_orden: p.orden, ronda: p.ronda ?? 0 });

  pintar();
  if (navigator.vibrate) navigator.vibrate(120);
  sincronizarAhora().catch(() => {});
}

// Progreso de la permanencia, para que el chofer vea que la app lo esta contando.
setInterval(() => {
  const j = jornadaActual();
  if (!j) return;
  for (const p of j.paradas) {
    const barra = document.querySelector('[data-dwell="' + p.orden + '"]');
    if (!barra) continue;
    const pr = geocerca.progreso(p.orden);
    // visibility, NO hidden: la ranura ya tiene su lugar reservado y los
    // botones no se mueven cuando el contador arranca.
    barra.dataset.activo = pr ? '1' : '0';
    if (!pr) continue;
    barra.querySelector('.dwell-fill').style.transform = 'scaleX(' + Math.min(1, pr.porcentaje / 100) + ')';
    const faltan = Math.max(0, pr.total - pr.segundos);
    barra.querySelector('.dwell-seg').textContent = 'faltan ' + faltan + ' s acá';
  }
}, 1000);

// ------------------------------------------------------------------
// Avisos
// ------------------------------------------------------------------
window.addEventListener('camca:cola', async (e) => {
  const n = e.detail;
  const el = $('pendientes');
  if (!n || n.total === 0) el.hidden = true;
  else {
    el.hidden = false;
    // Se dice QUE falta, no "cosas": el chofer necesita saber si lo que
    // espera es un registro liviano o una foto de 200 kB.
    const partes = [];
    if (n.eventos) partes.push(fmt.plural(n.eventos, 'marca', 'marcas'));
    if (n.adjuntos) partes.push(fmt.plural(n.adjuntos, 'foto o firma', 'fotos o firmas'));
    el.innerHTML = ico('nubesube') + '<span>Faltan enviar ' + partes.join(' y ') +
      '. Se mandan solas cuando haya señal: no hace falta que hagas nada.</span>';
  }

  // La barra de avance sale de la cola: si no se repinta aca, sigue mostrando
  // como enviado algo que recien se encolo, o como pendiente algo que ya salio.
  const j = jornadaActual();
  if (!j) return;
  const pend = await ordenesEnElTelefono(j);
  pintarResumen(j, pend);
  pintarCierre(j, pend);
});

window.addEventListener('camca:gps-error', (e) => {
  const el = $('aviso-gps');
  el.hidden = false;
  $('aviso-gps-icono').innerHTML = ico('gps');
  $('aviso-gps-texto').textContent = {
    denegado: 'El GPS está bloqueado para esta app. Podés marcar las paradas a mano, pero no se van a tildar solas.',
    'sin-soporte': 'Este teléfono no tiene GPS disponible. Marcá las paradas a mano.',
    'sin-senial': 'El GPS no encuentra señal. Marcá las paradas a mano mientras tanto.',
  }[e.detail.motivo] ?? 'Problema con el GPS. Marcá las paradas a mano.';
});

window.addEventListener('camca:sincronizado', async () => {
  try {
    const datos = await api.dia();
    estado.hoy = datos.hoy;
    estado.base = datos.base;
    estado.jornadas = await fusionarConPendientes(datos.jornadas);
    await guardarEstado();
    pintar();
  } catch {
    // Si falla, la pantalla sigue mostrando lo local, que es correcto.
  }
});

// ------------------------------------------------------------------
// Cierre de jornada
// ------------------------------------------------------------------
$('modo-lista')?.addEventListener('click', () => cambiarModo('lista'));
$('modo-mapa')?.addEventListener('click', () => cambiarModo('mapa'));

$('btn-cerrar')?.addEventListener('click', async () => {
  const j = jornadaActual();
  if (!j) return;

  const sinResolver = j.paradas.filter((p) => p.estado === 'planificada');
  if (sinResolver.length) {
    // Nada de alert(): bloquea el hilo, no se puede leer con el dedo encima y
    // no dice CUAL parada falta. El aviso se queda en pantalla y ademas lleva
    // a la primera que falta, que es la accion que sigue.
    const el = $('cierre-error');
    el.hidden = false;
    const cuantas = sinResolver.length === 1
      ? 'Una parada no tiene'
      : sinResolver.length + ' paradas no tienen';
    el.innerHTML = ico('alerta') + '<span><b>Todavía no se puede cerrar.</b>' + cuantas +
      ' resultado. Marcá cada una como hecha o poné el motivo.</span>';
    const t = document.querySelector('.parada[data-orden="' + sinResolver[0].orden + '"]');
    t?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    t?.querySelector('[data-accion]')?.focus();
    return;
  }
  $('cierre-error').hidden = true;

  const { default: armarInforme } = await import('/app/js/informe.js');
  const n = await cola.cuantosPendientes();
  const texto = armarInforme(j, estado.base, n);

  await cola.encolar('jornada_cerrada', { fecha: j.fecha }, { jornada_id: j.id });
  sincronizarAhora().catch(() => {});

  // anchor en vez de window.open: en iOS, una PWA en modo standalone se traga
  // window.open y el chofer se queda mirando una pantalla que no hace nada.
  const a = document.createElement('a');
  a.href = 'https://wa.me/?text=' + encodeURIComponent(texto);
  a.target = '_blank';
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
});

// ------------------------------------------------------------------
// Arranque
// ------------------------------------------------------------------
(async function arrancar() {
  await pedirPersistencia();
  montarDialogo();
  montarMotivo();
  await cargar();
  gps.escuchar((pos) => geocerca.procesar(pos));
  gps.arrancar();
  arrancarSync();
  window.dispatchEvent(new CustomEvent('camca:cola', { detail: await cola.cuantosPendientes() }));
})();
