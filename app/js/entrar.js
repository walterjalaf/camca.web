// ============================================================
// Pantalla de entrada: enrolar el telefono o entrar con el PIN.
//
// La regla que ordena esta pantalla: SIN RED TAMBIEN SE ENTRA. Si el PIN
// coincide con el verificador local, la jornada arranca y el trabajo se
// acumula en la cola. Lo unico que no se puede hacer sin red es enrolar un
// telefono nuevo, porque el codigo lo tiene que canjear el servidor.
// ============================================================

import * as sesion from './sesion.js';
import * as api from './api.js';
import * as cola from './cola.js';
import { pedirPersistencia, siFalla } from './db.js';
import { ico } from './iconos.js';
import * as fmt from './formato.js';

const $ = (id) => document.getElementById(id);

const pantallas = {
  cargando: $('pantalla-cargando'),
  enrolar: $('pantalla-enrolar'),
  pin: $('pantalla-pin'),
  oficina: $('pantalla-oficina'),
};

function mostrar(cual) {
  for (const [nombre, el] of Object.entries(pantallas)) {
    if (el) el.hidden = nombre !== cual;
  }
}

function error(id, mensaje) {
  const el = $(id);
  if (el) el.textContent = mensaje || '';
}

// Si IndexedDB no abre, lo primero es ofrecer sacar el trabajo del telefono.
siFalla((e) => {
  const c = pantallas.cargando;
  if (!c) return;
  c.hidden = false;
  c.innerHTML =
    '<p class="app-titulo">No se pudo abrir el almacenamiento</p>' +
    '<p class="app-nota">' + String(e?.message || e) + '</p>' +
    '<p class="app-nota" style="margin-top:8px">Si tenías trabajo sin enviar, avisá a coordinación antes de reinstalar la app.</p>';
});

// ------------------------------------------------------------------
// Aviso de instalacion
// ------------------------------------------------------------------
// En iOS la PWA instalada y la pestania de Safari usan almacenamientos
// DISTINTOS: si el chofer arranca la jornada en el navegador y despues instala
// el icono, abre una app vacia y cree que perdio el dia. Conviene instalar antes.
let promptInstalar = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  promptInstalar = e;
  const aviso = $('aviso-instalar');
  if (aviso && !window.matchMedia('(display-mode: standalone)').matches) aviso.hidden = false;
});
$('btn-instalar')?.addEventListener('click', async () => {
  if (!promptInstalar) return;
  promptInstalar.prompt();
  await promptInstalar.userChoice;
  promptInstalar = null;
  $('aviso-instalar').hidden = true;
});

// ------------------------------------------------------------------
// Aviso de pendientes
// ------------------------------------------------------------------
async function refrescarPendientes() {
  const n = await cola.cuantosPendientes().catch(() => null);
  const el = $('aviso-pendientes');
  if (!el || !n) return;
  if (n.total === 0) { el.hidden = true; return; }
  el.hidden = false;
  const partes = [];
  if (n.eventos) partes.push(fmt.plural(n.eventos, 'marca', 'marcas'));
  if (n.adjuntos) partes.push(fmt.plural(n.adjuntos, 'foto o firma', 'fotos o firmas'));
  el.innerHTML = ico('nubesube') + '<span>Faltan enviar ' + partes.join(' y ') +
    '. Se mandan solas cuando haya señal: no hace falta que hagas nada.</span>';
}
window.addEventListener('camca:cola', refrescarPendientes);

// ------------------------------------------------------------------
// Enrolar
// ------------------------------------------------------------------
$('form-enrolar')?.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  error('error-enrolar', '');

  const codigo = $('codigo').value.trim().toUpperCase();
  const pin = $('pin-nuevo').value;
  const repetir = $('pin-repetir').value;

  if (pin !== repetir) { error('error-enrolar', 'Los PIN no coinciden.'); return; }
  if (!/^[0-9]{6}$/.test(pin)) { error('error-enrolar', 'El PIN son 6 números.'); return; }
  if (!navigator.onLine) {
    error('error-enrolar', 'Activar un teléfono necesita señal. Buscá cobertura o pedí que lo activen en la base.');
    return;
  }

  const boton = ev.target.querySelector('button[type=submit]');
  boton.disabled = true;
  boton.textContent = 'Activando…';

  try {
    const datos = await api.enrolar({
      codigo,
      pin,
      etiqueta: navigator.userAgent.slice(0, 60),
      plataforma: /iPhone|iPad|iPod/.test(navigator.userAgent) ? 'ios' : 'android',
    });
    await sesion.establecer(datos, pin);
    await pedirPersistencia();
    location.href = '/app/campo/';
  } catch (e) {
    error('error-enrolar', e.campos?.codigo || e.campos?.pin || e.message || 'No se pudo activar.');
    boton.disabled = false;
    boton.textContent = 'Activar';
  }
});

// ------------------------------------------------------------------
// Entrar con PIN
// ------------------------------------------------------------------
$('form-pin')?.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  error('error-pin', '');

  const pin = $('pin').value;
  const boton = ev.target.querySelector('button[type=submit]');

  const bloqueo = await sesion.bloqueoLocal();
  if (bloqueo > 0) {
    const min = Math.ceil(bloqueo / 60);
    error('error-pin', 'Demasiados intentos. Esperá ' + (min > 1 ? min + ' minutos.' : 'un minuto.'));
    return;
  }

  boton.disabled = true;
  boton.textContent = 'Entrando…';

  // 1. Verificacion LOCAL primero. Es lo que permite entrar sin senial, y
  //    ademas evita gastar un intento contra el servidor por un dedo torpe.
  const secreto = await sesion.verificarLocal(pin);
  if (!secreto) {
    const n = await sesion.intentoFallidoLocal();
    error('error-pin', n >= 4 ? 'PIN incorrecto. Cuidado: a los 5 intentos se bloquea.' : 'PIN incorrecto.');
    boton.disabled = false;
    boton.textContent = 'Entrar';
    $('pin').value = '';
    return;
  }
  await sesion.limpiarIntentos();

  // 2. Si hay red, se renueva el token contra el servidor. Si no hay, se entra
  //    igual: el trabajo se acumula en la cola y sale cuando haya cobertura.
  if (navigator.onLine) {
    try {
      const datos = await api.entrarConPin(secreto, pin);
      await sesion.establecer(datos, pin);
    } catch (e) {
      if (e.esAuth) {
        // El servidor rechazo el dispositivo (revocado desde el panel).
        // Se cierra la sesion pero LA COLA NO SE TOCA.
        await sesion.cerrar();
        error('error-pin', 'Este teléfono fue dado de baja. Pedí un código nuevo.');
        boton.disabled = false;
        boton.textContent = 'Entrar';
        return;
      }
      // Cualquier otro problema (503, timeout, portal cautivo) no impide
      // trabajar: se entro con la verificacion local y eso alcanza.
      console.warn('[camca] no se pudo renovar la sesión, se entra offline:', e.codigo);
    }
  }

  location.href = '/app/campo/';
});

$('btn-otro-telefono')?.addEventListener('click', async () => {
  const n = await cola.cuantosPendientes().catch(() => ({ total: 0 }));
  const caja = $('confirmar-otro');

  // Se dice QUE hay sin enviar y, sobre todo, que NO se borra. El miedo real
  // del chofer en este boton es perder el dia de trabajo, y un confirm()
  // nativo no tiene lugar para desarmarlo.
  const partes = [];
  if (n.eventos) partes.push(fmt.plural(n.eventos, 'marca', 'marcas'));
  if (n.adjuntos) partes.push(fmt.plural(n.adjuntos, 'foto o firma', 'fotos o firmas'));

  $('confirmar-otro-icono').innerHTML = ico('alerta');
  $('confirmar-otro-texto').innerHTML = n.total > 0
    ? '<b>Tenés ' + partes.join(' y ') + ' sin enviar.</b>' +
      'No se borran: siguen guardadas en este teléfono y salen cuando haya señal. ' +
      'Aun así conviene mandarlas antes de cambiar de código.'
    : '<b>¿Activar este teléfono con otro código?</b>' +
      'Vas a tener que pedir un código nuevo para volver a entrar.';

  caja.hidden = false;
  $('btn-otro-si').focus();
});

$('btn-otro-no')?.addEventListener('click', () => {
  $('confirmar-otro').hidden = true;
  $('btn-otro-telefono')?.focus();
});

$('btn-otro-si')?.addEventListener('click', async () => {
  $('confirmar-otro').hidden = true;
  await sesion.cerrar();   // borra credenciales; la cola queda intacta
  mostrar('enrolar');
  $('codigo')?.focus();
});

// ------------------------------------------------------------------
// Oficina (supervisor y administracion)
// ------------------------------------------------------------------
$('form-oficina')?.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  error('error-oficina', '');

  const boton = ev.target.querySelector('button[type=submit]');
  boton.disabled = true;
  boton.textContent = 'Entrando…';

  try {
    const datos = await api.pedir('/auth/clave', {
      metodo: 'POST',
      sinSesion: true,
      cuerpo: { email: $('email').value.trim(), clave: $('clave').value },
    });
    // La oficina no enrola dispositivo: guarda la sesion y entra. Por eso NO
    // se usa sesion.establecer(), que exige el secreto del dispositivo.
    await guardarSesionOficina(datos);
    location.href = datos.usuario?.rol === 'cliente' ? '/app/cliente/' : '/app/supervisor/';
  } catch (e) {
    error('error-oficina', e.estado === 401
      ? 'Email o contraseña incorrectos.'
      : (e.message || 'No se pudo entrar.'));
    boton.disabled = false;
    boton.textContent = 'Entrar';
  }
});

async function guardarSesionOficina(datos) {
  const { guardar } = await import('/app/js/db.js');
  await guardar('meta', {
    token: datos.token,
    csrf: datos.csrf,
    usuario: datos.usuario,
    establecida_ms: Date.now(),
    ultimo_online_ms: Date.now(),
  }, 'sesion');
}

function alternarModo(aOficina) {
  mostrar(aOficina ? 'oficina' : (pantallas.pin.hidden === false ? 'pin' : 'enrolar'));
  $('btn-modo').textContent = aOficina ? 'Entrar como chofer' : 'Entrar a la oficina';
  $('btn-modo').dataset.oficina = String(aOficina);
}

$('btn-modo')?.addEventListener('click', () => {
  alternarModo($('btn-modo').dataset.oficina !== 'true');
});

// ------------------------------------------------------------------
// Arranque
// ------------------------------------------------------------------
(async function arrancar() {
  try {
    await refrescarPendientes();

    if (!navigator.onLine) $('aviso-offline').hidden = false;

    // Sesion de oficina ya iniciada: va derecho al panel.
    const actual = await sesion.actual();
    if (actual && !actual.secreto && actual.usuario &&
        ['supervisor', 'admin', 'cliente'].includes(actual.usuario.rol)) {
      location.replace(actual.usuario.rol === 'cliente' ? '/app/cliente/' : '/app/supervisor/');
      return;
    }

    $('cambiar-modo').hidden = false;

    if (await sesion.hayDispositivo()) {
      const u = await sesion.usuario();
      if (u?.nombre) $('saludo').textContent = 'Hola, ' + u.nombre.split(' ')[0];

      // R6 — Se avisa, pero NUNCA se bloquea. Una campania de varios dias sin
      // senial no puede dejar al chofer con una app que no abre.
      const dias = await sesion.diasSinServidor();
      if (dias > 3) {
        $('aviso-offline').hidden = false;
        $('aviso-offline').textContent =
          'Hace ' + Math.floor(dias) + ' días que no se conecta con la oficina. ' +
          'Podés seguir trabajando: todo se guarda y se envía cuando haya señal.';
      }
      mostrar('pin');
      $('pin')?.focus();
    } else {
      mostrar('enrolar');
      $('codigo')?.focus();
    }
  } catch (e) {
    console.error('[camca] fallo el arranque', e);
  }
})();
