// ============================================================
// Portal del cliente (F3.10).
//
// Lee /portal y nada más. El aislamiento NO depende de esta pantalla: el
// servidor filtra por el cliente de la sesión y le contesta 404 a todo lo
// demás. Esto sólo muestra lo que el servidor ya decidió que es suyo.
// ============================================================

import * as api from './api.js';
import * as sesion from './sesion.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const fecha = (iso) => iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '';
const CONFORMIDAD = { conforme: 'conforme', rechazado: 'no conforme', pendiente: 'sin conformidad' };

// El indicador del encabezado arranca en «sin red» y sólo cambia con una
// respuesta real del servidor (AppLayout). Igual que en el panel de la oficina.
function marcarConexion(ok, texto) {
  window.dispatchEvent(new CustomEvent('camca:estado', { detail: { estado: ok ? 'en-linea' : 'error', texto } }));
}

function pintar(d) {
  $('portal-cliente').textContent = 'Servicios de ' + d.cliente;
  $('portal-remitos').innerHTML = d.remitos.length
    ? '<div class="tabla-envoltorio"><table class="tabla portal-tabla"><thead><tr><th>Fecha</th><th>Remito</th><th>Sitio</th><th>Estado</th><th></th></tr></thead><tbody>' +
      d.remitos.map((r) => '<tr><td class="trabajo-doc pt-fecha">' + fecha(r.fecha) + '</td><td class="trabajo-doc pt-num"><b>' + esc(r.numero) + '</b>' +
        (r.certificado && r.codigo ? '<div class="cli-falta">Código ' + esc(r.codigo.slice(0, 5) + '-' + r.codigo.slice(5)) + '</div>' : '') + '</td>' +
        '<td class="pt-sitio">' + esc(r.sitio) + '</td><td class="pt-estado">' +
        (r.estado === 'anulado' ? '<span class="sello sello--mal">anulado</span>'
          : (r.certificado ? '<span class="sello sello--ok">certificado</span> ' : '') + '<span class="sello sello--gris">' + esc(CONFORMIDAD[r.conformidad] ?? r.conformidad) + '</span>') +
        '</td><td class="pt-acc"><div class="portal-acciones"><button class="app-btn" type="button" data-remito="' + r.id + '" data-formato="html">Ver</button>' +
        '<button class="app-btn" type="button" data-remito="' + r.id + '" data-formato="pdf">PDF</button></div></td></tr>').join('') +
      '</tbody></table></div>'
    : '<p class="app-nota">Todavía no hay remitos a tu nombre.</p>';
  $('portal-desvios').innerHTML = d.desvios.length
    ? '<ul class="portal-desvios">' + d.desvios.map((x) => '<li><b>' + fecha(x.fecha) + '</b> · ' + esc(x.sitio ?? '') + ' · ' + esc(x.tipo) +
        '<div class="cli-falta">' + esc(x.detalle) + '</div></li>').join('') + '</ul>'
    : '<p class="app-nota">Sin desvíos en tus sitios.</p>';
}

// Sesión vencida o acceso quitado por la oficina: de vuelta a la entrada,
// sin un error que el cliente no puede resolver.
async function afuera() {
  await sesion.cerrar();
  location.replace('/app/');
}

async function abrir(b) {
  // La ventana se abre dentro del click: después del fetch el navegador la
  // bloquearía como popup no pedido.
  const ventana = window.open('', '_blank');
  try {
    const blob = await api.documento('/portal/remito/' + b.dataset.remito + '?formato=' + b.dataset.formato);
    const url = URL.createObjectURL(blob);
    if (ventana) ventana.location.href = url; else location.href = url;
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) {
    ventana?.close();
    if (e?.esAuth) { await afuera(); return; }
    $('portal-error').textContent = e?.message || 'No se pudo abrir el remito.';
    $('portal-error').hidden = false;
  }
}

(async function arrancar() {
  const u = await sesion.usuario();
  if (!u) { location.replace('/app/'); return; }
  if (u.rol !== 'cliente') { location.replace(u.rol === 'chofer' ? '/app/campo/' : '/app/supervisor/'); return; }
  $('portal-remitos').addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-remito]');
    if (b) abrir(b);
  });
  $('portal-salir').addEventListener('click', async () => {
    try { await api.pedir('/auth/salir', { metodo: 'POST' }); } catch { /* igual se sale */ }
    await afuera();
  });
  try {
    pintar(await api.pedir('/portal'));
    marcarConexion(true, 'conectado');
  } catch (e) {
    if (e?.esAuth) { await afuera(); return; }
    marcarConexion(false, 'sin conexión');
    $('portal-remitos').innerHTML = '<p class="app-nota">' + esc(e?.message || 'No se pudo cargar.') + '</p>';
  }
})();
