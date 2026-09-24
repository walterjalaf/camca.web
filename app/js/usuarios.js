// ============================================================
// Usuarios y teléfonos (cierre, F4.6).
//
// Lo que hace falta para poner a trabajar a alguien: administración da de
// alta al chofer o a la persona de la oficina; la coordinación emite el
// código que activa el teléfono del chofer (se dicta por teléfono o VHF) y,
// si el teléfono se pierde, lo revoca en el acto. Cada uno cambia su propia
// contraseña. Nada destructivo pasa con un solo clic: primero se confirma en
// la pantalla, nunca con un diálogo del sistema.
// ============================================================

import * as api from './api.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const opcion = (v, t, sel) => '<option value="' + esc(v) + '"' + (String(sel) === String(v) ? ' selected' : '') + '>' + esc(t) + '</option>';
const diaHoraArt = (utc) => utc ? new Date(Date.parse(utc.replace(' ', 'T') + 'Z')).toLocaleString('es-AR', {
  day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'America/Argentina/San_Juan' }) : '';

let ctx = { soyAdmin: false, yo: null, marcarConexion: () => {}, error: () => {} };
let datos = null;

export function iniciar(opciones) {
  ctx = { ...ctx, ...opciones };
  for (const id of ['usuarios', 'usu-panel']) {
    $(id).addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-usu]');
      if (b) accion(b);
    });
  }
}

export async function cargar() {
  try {
    datos = await api.pedir('/usuarios');
    ctx.marcarConexion(true, 'conectado');
    pintar();
  } catch (e) {
    ctx.error('usuarios', e);
  }
}

function pintar() {
  const adm = ctx.soyAdmin;
  const choferes = datos.usuarios.filter((u) => u.rol === 'chofer');
  const oficina = datos.usuarios.filter((u) => u.rol !== 'chofer');
  const estado = (u) => u.activo ? '' : ' <span class="sello sello--gris">de baja</span>';

  const tablaChoferes = choferes.length
    ? '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Chofer</th><th>Teléfonos activos</th><th></th></tr></thead><tbody>' +
      choferes.map((u) => '<tr data-usuario="' + u.id + '"><td><b>' + esc(u.nombre) + '</b>' + estado(u) +
          '<div class="cli-falta">' + [u.legajo ? 'legajo ' + esc(u.legajo) : '', u.persona ? 'ficha de Recursos: ' + esc(u.persona) : 'sin ficha de Recursos'].filter(Boolean).join(' · ') + '</div></td>' +
        '<td>' + (u.telefonos.length
          ? u.telefonos.map((t) => '<div class="usu-tel">' + esc(t.etiqueta || 'Teléfono') + ' <span class="cli-falta">' + esc(t.plataforma ?? '') +
              (t.visto_utc ? ' · visto ' + diaHoraArt(t.visto_utc) : ' · activado ' + diaHoraArt(t.enrolado_utc)) + '</span> ' +
              '<button class="app-btn rec-mini" type="button" data-usu="pedir-revocar" data-id="' + t.id + '" data-nombre="' + esc(u.nombre) + '">Revocar</button></div>').join('')
          : '<span class="cli-falta">Ninguno</span>') +
          (u.codigos_vigentes ? '<div class="cli-falta">' + u.codigos_vigentes + ' código' + (u.codigos_vigentes > 1 ? 's' : '') + ' sin usar</div>' : '') + '</td>' +
        '<td><div class="cli-acciones">' + (u.activo ? '<button class="app-btn rec-mini" type="button" data-usu="codigo" data-id="' + u.id + '">Código para activar un teléfono</button>' : '') +
          (adm ? botonBaja(u) : '') + '</div></td></tr>').join('') + '</tbody></table></div>'
    : '<p class="app-nota">Todavía no hay choferes. ' + (adm ? 'Agregá uno con «Agregar un usuario».' : 'Los da de alta administración.') + '</p>';

  const tablaOficina = '<div class="tabla-envoltorio"><table class="tabla"><thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th></th></tr></thead><tbody>' +
    oficina.map((u) => '<tr data-usuario="' + u.id + '"><td><b>' + esc(u.nombre) + '</b>' + estado(u) + '</td><td>' + esc(u.email ?? '') + '</td><td>' + esc(u.rol_texto) + '</td>' +
      '<td>' + (adm && u.id !== ctx.yo ? '<div class="cli-acciones">' + (u.activo ? '<button class="app-btn rec-mini" type="button" data-usu="clave" data-id="' + u.id + '">Nueva contraseña</button>' : '') +
        botonBaja(u) + '</div>' : (u.id === ctx.yo ? '<span class="cli-falta">vos</span>' : '')) + '</td></tr>').join('') + '</tbody></table></div>';

  $('usuarios').innerHTML =
    '<div class="app-tarjeta tarjeta-filtro"><div class="tar-cabeza"><p class="app-titulo">Choferes y sus teléfonos</p>' +
    (adm ? '<button class="app-btn" type="button" data-usu="nuevo">Agregar un usuario</button>' : '') + '</div>' +
    '<p class="app-nota">El chofer entra con un código que se le dicta (vale 30 días y sirve una vez) y un PIN que elige él. Si pierde el teléfono, revocalo: deja de entrar en el acto.</p>' +
    tablaChoferes + '</div>' +
    '<div class="app-tarjeta tarjeta-filtro"><p class="app-titulo">Oficina</p>' + tablaOficina + '</div>' +
    '<div class="app-tarjeta"><p class="app-titulo">Mi contraseña</p>' +
    '<form class="cli-form"><div class="app-campo"><label for="usu-actual">Contraseña actual</label><input id="usu-actual" type="password" autocomplete="current-password" /></div>' +
    '<div class="app-campo"><label for="usu-nueva">Nueva (12 caracteres o más)</label><input id="usu-nueva" type="password" autocomplete="new-password" /></div>' +
    '<div class="app-campo"><label for="usu-repetir">Repetila</label><input id="usu-repetir" type="password" autocomplete="new-password" /></div></form>' +
    '<p class="trabajo-error" id="usu-error-clave" role="alert" hidden></p><p class="app-nota" id="usu-ok-clave" role="status" aria-live="polite"></p>' +
    '<div class="cli-acciones"><button class="app-btn" type="button" data-usu="mi-clave">Cambiar mi contraseña</button></div></div>';
}

function botonBaja(u) {
  return u.activo
    ? '<button class="app-btn rec-mini app-btn--peligro" type="button" data-usu="pedir-baja" data-id="' + u.id + '" data-nombre="' + esc(u.nombre) + '">Dar de baja</button>'
    : '<button class="app-btn rec-mini" type="button" data-usu="reactivar" data-id="' + u.id + '">Reactivar</button>';
}

function panel(html) {
  $('usu-panel').innerHTML = html ? '<div class="app-tarjeta tarjeta-filtro">' + html + '</div>' : '';
  if (html) {
    $('usu-panel').scrollIntoView({ block: 'start' });
    $('usu-panel').querySelector('input, select, button.app-btn--principal')?.focus({ preventScroll: true });
  }
}

const cerrar = '<button class="app-btn" type="button" data-usu="cerrar">Cerrar</button>';
// Lo que toca cuentas de administración, o regenera una contraseña, pide la
// propia (el servidor la exige): una sesión robada no alcanza.
const campoMiClave = (nota) => '<div class="app-campo"><label for="usu-mi-clave">Tu contraseña <span class="conf-opcional">(' + nota + ')</span></label>' +
  '<input id="usu-mi-clave" type="password" autocomplete="current-password" /></div>';
const miClave = () => $('usu-mi-clave')?.value || undefined;
const rolDe = (id) => datos.usuarios.find((u) => u.id === Number(id))?.rol;

function formNuevo() {
  panel('<p class="app-titulo">Agregar un usuario</p>' +
    '<p class="app-nota">A un chofer se le activa el teléfono con un código; a la oficina se le genera una contraseña que se ve una sola vez.</p>' +
    '<form class="cli-form"><div class="app-campo"><label for="usu-rol">Rol</label><select id="usu-rol">' +
      Object.entries(datos.roles).map(([k, t]) => opcion(k, t, 'chofer')).join('') + '</select></div>' +
    '<div class="app-campo"><label for="usu-nombre">Nombre y apellido</label><input id="usu-nombre" maxlength="120" /></div>' +
    '<div class="app-campo"><label for="usu-legajo">Legajo <span class="conf-opcional">(opcional)</span></label><input id="usu-legajo" maxlength="30" /></div>' +
    '<div class="app-campo"><label for="usu-email">Email <span class="conf-opcional">(sólo la oficina)</span></label><input id="usu-email" type="email" /></div>' +
    '<div class="app-campo"><label for="usu-persona">Ficha de Recursos <span class="conf-opcional">(chofer, opcional)</span></label><select id="usu-persona"><option value="">—</option>' +
      datos.personas_sin_usuario.map((p) => opcion(p.id, p.nombre, '')).join('') + '</select></div>' +
    campoMiClave('sólo para dar de alta a alguien de administración') + '</form>' +
    '<p class="trabajo-error" id="usu-error" role="alert" hidden></p>' +
    '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-usu="crear">Agregar</button>' + cerrar + '</div>');
}

function mostrarClave(titulo, email, clave) {
  panel('<p class="app-titulo">' + esc(titulo) + '</p>' +
    '<div class="aviso aviso--decision usu-clave"><div><b>' + esc(email) + '</b><span class="usu-secreto">' + esc(clave) + '</span>' +
    'Anotala y pasala por un canal seguro: <strong>no se vuelve a mostrar</strong>. Al entrar, que la cambie por una propia.</div></div>' +
    '<div class="cli-acciones">' + cerrar + '</div>');
}

function mostrarError(e, id = 'usu-error') {
  const p = $(id);
  if (!p) return;
  p.textContent = e?.campos ? Object.values(e.campos).join(' ') : (e?.message || 'No se pudo.');
  p.hidden = false;
}

async function accion(b) {
  const a = b.dataset.usu;
  const id = Number(b.dataset.id);
  if (a === 'cerrar') { panel(''); return; }
  if (a === 'nuevo') { formNuevo(); return; }
  if (a === 'clave' || (a === 'reactivar' && rolDe(id) === 'admin')) {
    const u = datos.usuarios.find((x) => x.id === id);
    panel('<p class="app-titulo">' + (a === 'clave' ? 'Contraseña nueva para ' : 'Reactivar a ') + esc(u?.nombre ?? '') + '</p>' +
      '<p class="app-nota">' + (a === 'clave' ? 'La que tiene deja de servir y se cortan sus sesiones abiertas. La nueva se muestra una sola vez.'
                                               : 'Vuelve a poder entrar con su contraseña de siempre.') + '</p>' +
      '<form class="cli-form">' + campoMiClave('para confirmar') + '</form><p class="trabajo-error" id="usu-error" role="alert" hidden></p>' +
      '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-usu="' + (a === 'clave' ? 'clave-confirmar' : 'reactivar-confirmar') +
      '" data-id="' + id + '">' + (a === 'clave' ? 'Generar la nueva' : 'Reactivar') + '</button>' + cerrar + '</div>');
    return;
  }
  if (a === 'pedir-revocar' || a === 'pedir-baja') {
    const revocar = a === 'pedir-revocar';
    panel('<p class="app-titulo">' + (revocar ? 'Revocar el teléfono de ' : 'Dar de baja a ') + esc(b.dataset.nombre) + '</p>' +
      '<p class="app-nota">' + (revocar
        ? 'Deja de entrar en el acto. Lo que ya había sincronizado no se toca; lo que tenga sin subir en ese teléfono no llega. Para seguir trabajando necesita un código nuevo en otro teléfono.'
        : 'Se cortan sus sesiones y sus teléfonos, y los códigos sin usar dejan de servir. Se puede reactivar después, pero no revive ningún teléfono.') + '</p>' +
      (!revocar && rolDe(id) === 'admin' ? '<form class="cli-form">' + campoMiClave('para confirmar') + '</form>' : '') +
      '<p class="trabajo-error" id="usu-error" role="alert" hidden></p>' +
      '<div class="cli-acciones"><button class="app-btn app-btn--peligro" type="button" data-usu="' + (revocar ? 'revocar' : 'baja') + '" data-id="' + id + '">' +
      (revocar ? 'Sí, revocar' : 'Sí, dar de baja') + '</button>' + cerrar + '</div>');
    return;
  }
  b.disabled = true;
  try {
    if (a === 'codigo') {
      const r = await api.pedir('/dispositivo/codigo', { metodo: 'POST', cuerpo: { usuario_id: id } });
      panel('<p class="app-titulo">Código para ' + esc(r.para) + '</p>' +
        '<div class="aviso aviso--decision usu-clave"><div><span class="usu-secreto">' + esc(r.codigo) + '</span>' + esc(r.dictado) +
        '. Vale hasta el ' + diaHoraArt(r.expira.replace('T', ' ').slice(0, 19)) + ' y sirve una sola vez. En el teléfono: abrir camcasoluciones.com.ar/app/, tipear el código y elegir el PIN.</div></div>' +
        '<div class="cli-acciones">' + cerrar + '</div>');
      await cargar();
      return;
    }
    if (a === 'crear') {
      const rol = $('usu-rol').value;
      const r = await api.pedir('/usuario', { metodo: 'POST', cuerpo: { rol, nombre: $('usu-nombre').value.trim(), legajo: $('usu-legajo').value.trim() || undefined,
        email: rol === 'chofer' ? undefined : $('usu-email').value.trim(), persona_id: rol === 'chofer' ? (Number($('usu-persona').value) || undefined) : undefined,
        mi_clave: rol === 'admin' ? miClave() : undefined } });
      await cargar();
      if (r.clave) mostrarClave('Contraseña de ' + r.nombre, r.email, r.clave);
      else panel('<p class="app-titulo">' + esc(r.nombre) + ' ya está dado de alta</p><p class="app-nota">Ahora emitile el código para activar su teléfono.</p>' +
        '<div class="cli-acciones"><button class="app-btn app-btn--principal" type="button" data-usu="codigo" data-id="' + r.id + '">Código para activar un teléfono</button>' + cerrar + '</div>');
      return;
    }
    if (a === 'clave-confirmar') {
      const r = await api.pedir('/usuario/clave', { metodo: 'POST', cuerpo: { usuario_id: id, mi_clave: miClave() } });
      await cargar();
      mostrarClave('Contraseña nueva', r.email, r.clave);
      return;
    }
    if (a === 'mi-clave') {
      $('usu-error-clave').hidden = true;
      if ($('usu-nueva').value !== $('usu-repetir').value) { b.disabled = false; mostrarError({ message: 'Las dos nuevas no coinciden.' }, 'usu-error-clave'); return; }
      await api.pedir('/auth/clave/cambiar', { metodo: 'POST', cuerpo: { actual: $('usu-actual').value, nueva: $('usu-nueva').value } });
      for (const x of ['usu-actual', 'usu-nueva', 'usu-repetir']) $(x).value = '';
      $('usu-ok-clave').textContent = 'Listo: tu contraseña cambió. Las sesiones abiertas en otras computadoras se cerraron.';
      b.disabled = false;
      return;
    }
    if (a === 'revocar') await api.pedir('/dispositivo/revocar', { metodo: 'POST', cuerpo: { dispositivo_id: id } });
    else if (a === 'baja') await api.pedir('/usuario/baja', { metodo: 'POST', cuerpo: { usuario_id: id, mi_clave: miClave() } });
    else if (a === 'reactivar' || a === 'reactivar-confirmar') await api.pedir('/usuario/reactivar', { metodo: 'POST', cuerpo: { usuario_id: id, mi_clave: miClave() } });
    panel('');
    await cargar();
  } catch (e) {
    b.disabled = false;
    // Los botones de la lista (código, reactivar) no tienen dónde mostrar el
    // error: se abre el panel para decirlo.
    if (a !== 'mi-clave' && !$('usu-error')) {
      panel('<p class="app-titulo">No se pudo</p><p class="trabajo-error" id="usu-error" role="alert" hidden></p><div class="cli-acciones">' + cerrar + '</div>');
    }
    mostrarError(e, a === 'mi-clave' ? 'usu-error-clave' : 'usu-error');
  }
}
