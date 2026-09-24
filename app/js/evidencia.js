// ============================================================
// Dialogo de cierre de parada: cantidad, fotos y conformidad del cliente.
//
// Reemplaza los prompt() que habia antes. Un prompt() nativo no deja poner
// varias cosas a la vez, no valida, no muestra lo que ya cargaste, y en el
// navegador bloquea todo. Con tres datos por parada hace falta un formulario.
//
// Las fotos y la firma NO se suben desde aca: se encolan. La subida es
// problema de sync.js, y esa separacion es lo que permite cerrar una parada
// sin senial y que el trabajo igual quede guardado.
// ============================================================

import * as camara from './camara.js';
import { PadFirma } from './firma.js';
import * as cola from './cola.js';

const $ = (id) => document.getElementById(id);

let estado = null;

/**
 * Abre el dialogo. Devuelve una promesa que resuelve con los datos del cierre
 * o con null si se cancela.
 */
export function cerrarParada(parada, contexto = {}) {
  return new Promise((resolver) => {
    estado = {
      parada,
      contexto,
      fotos: [],
      pad: null,
      camaraViva: null,
      resolver,
    };

    $('dlg-titulo').textContent = parada.nombre;
    $('dlg-sub').textContent = parada.direccion || ('Parada ' + parada.orden);
    $('dlg-cantidad').value = String(parada.cantidad_plan ?? 1);
    $('dlg-error').textContent = '';
    $('dlg-miniaturas').innerHTML = '';
    $('dlg-camara-error').textContent = '';
    $('dlg-firmante').value = '';
    $('dlg-receptor-doc').value = '';
    $('dlg-motivo-rechazo').value = '';
    $('dlg-observaciones').value = '';

    // EL BOTON SE REARMA ACA, EN CADA APERTURA.
    //
    // El camino exitoso lo deja en "Guardando…" y deshabilitado, y cerrar el
    // dialogo no lo restauraba: la SEGUNDA parada abria el dialogo con el
    // boton muerto. El chofer podia cerrar exactamente una parada por sesion
    // de app, y la pantalla no decia por que — el boton se ve apenas mas
    // palido y dice "Guardando…", que parece que esta trabajando.
    //
    // Lo encontro la aceptacion de punta a punta (F1.10). No lo encontro
    // ninguna prueba anterior porque todas cierran UNA sola parada.
    const confirmar = $('dlg-confirmar');
    confirmar.disabled = false;
    confirmar.textContent = 'Confirmar';

    // El diálogo se muestra ANTES de construir el pad, y el orden importa:
    // getBoundingClientRect() sobre un elemento oculto devuelve 0×0, el canvas
    // queda con buffer 0×0 y la firma se dibuja "en la nada". El trazo se
    // registra igual, así que no falla nada visible desde el código: el
    // cliente firma, no ve nada, y vuelve a firmar o se niega.
    $('dialogo').hidden = false;

    // El pad de firma NO se arma acá: vive adentro del bloque de conformidad,
    // que arranca oculto, y un canvas oculto mide 0×0 (ver arriba). Se arma
    // la primera vez que ese bloque se muestra.
    elegirConformidad('nadie');

    $('dlg-cantidad').focus();
  });
}

/**
 * Conformidad del cliente: 'conforme', 'rechazado' o 'nadie' (no había quien
 * firme). Muestra lo que cada opción necesita y nada más.
 */
function elegirConformidad(valor) {
  if (!estado) return;
  estado.conformidad = valor;
  for (const b of document.querySelectorAll('[data-conf]')) {
    b.setAttribute('aria-pressed', String(b.dataset.conf === valor));
  }
  const conDatos = valor !== 'nadie';
  $('dlg-conf-datos').hidden = !conDatos;
  $('dlg-conf-motivo').hidden = valor !== 'rechazado';
  $('dlg-error').textContent = '';

  if (conDatos && !estado.pad) {
    estado.pad = new PadFirma($('dlg-firma'), {
      alCambiar: (hay) => {
        $('dlg-limpiar-firma').disabled = !hay;
        // Un aviso de «falta la firma» que sigue ahí después de firmar hace
        // pensar que la firma no tomó, y el cliente firma de nuevo.
        if (hay) $('dlg-error').textContent = '';
      },
    });
    estado.pad.limpiar();
  }
}

/**
 * Lo que falta para poder confirmar, dicho en castellano. Null si está bien.
 *
 * «Conforme» sin firma o sin nombre no es una conformidad: es un casillero
 * tildado, y es exactamente lo que después el cliente niega haber dado.
 */
function faltaEnConformidad() {
  const hayFirma = !!estado.pad?.hayFirma();
  const nombre = $('dlg-firmante').value.trim();
  if (estado.conformidad === 'conforme') {
    if (!nombre) return 'Falta la aclaración: el nombre de quien recibe.';
    if (!hayFirma) return 'Falta la firma de quien recibe.';
  }
  if (estado.conformidad === 'rechazado' && !$('dlg-motivo-rechazo').value.trim()) {
    return 'Anotá por qué no está conforme: es lo primero que se va a preguntar.';
  }
  if (estado.conformidad === 'nadie' && hayFirma) {
    return 'Hay una firma cargada: elegí «Conforme» o «No conforme».';
  }
  return null;
}

function cerrarDialogo(resultado) {
  if (!estado) return;
  estado.camaraViva?.cerrar();
  estado.pad?.destruir();
  $('dialogo').hidden = true;
  const r = estado.resolver;
  estado = null;
  r(resultado);
}

// ------------------------------------------------------------------
// Fotos
// ------------------------------------------------------------------
function selloDe(parada, contexto) {
  const ahora = new Date();
  const coord = contexto.posicion
    ? contexto.posicion.lat.toFixed(5) + ', ' + contexto.posicion.lon.toFixed(5)
    : 'sin GPS';
  return {
    sitio: parada.nombre,
    linea2: ahora.toLocaleDateString('es-AR') + ' ' +
            ahora.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false }) +
            ' · ' + coord + ' · CAMCA',
  };
}

async function agregarFoto(origen) {
  if (!estado) return;
  $('dlg-camara-error').textContent = '';

  // Se frena ANTES de procesar: quedarse sin espacio a mitad de una foto deja
  // un registro a medias, y el chofer no entiende por que.
  if (!(await camara.hayEspacio())) {
    $('dlg-camara-error').textContent = 'Queda poco espacio en el equipo. Sincronizá antes de sacar más fotos.';
    return;
  }
  if (estado.fotos.length >= 6) {
    $('dlg-camara-error').textContent = 'Seis fotos por parada es suficiente.';
    return;
  }

  try {
    const foto = await camara.procesar(origen, selloDe(estado.parada, estado.contexto));
    estado.fotos.push(foto);
    pintarMiniaturas();
  } catch (e) {
    $('dlg-camara-error').textContent = 'No se pudo procesar la imagen: ' + e.message;
  }
}

function pintarMiniaturas() {
  const cont = $('dlg-miniaturas');
  cont.innerHTML = '';
  estado.fotos.forEach((f, i) => {
    const div = document.createElement('div');
    div.className = 'miniatura';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f.blob);
    img.alt = 'Foto ' + (i + 1);
    // Se libera el objeto al cargar: sin esto cada foto queda retenida en
    // memoria hasta recargar la pagina.
    img.onload = () => URL.revokeObjectURL(img.src);
    const quitar = document.createElement('button');
    quitar.type = 'button';
    quitar.textContent = '×';
    quitar.title = 'Quitar';
    quitar.onclick = () => { estado.fotos.splice(i, 1); pintarMiniaturas(); };
    div.append(img, quitar);
    cont.appendChild(div);
  });
  $('dlg-fotos-cuenta').textContent = estado.fotos.length
    ? estado.fotos.length + (estado.fotos.length === 1 ? ' foto' : ' fotos')
    : 'Sin fotos';
}

// ------------------------------------------------------------------
// Conexion con la pantalla
// ------------------------------------------------------------------
export function montarDialogo() {
  // --- Cámara en vivo ---
  $('dlg-abrir-camara')?.addEventListener('click', async () => {
    if (!estado) return;
    $('dlg-camara-error').textContent = '';
    try {
      estado.camaraViva = await camara.abrirCamara($('dlg-video'));
      $('dlg-camara-vacia').hidden = true;
      $('dlg-abrir-camara').hidden = true;
      $('dlg-disparar').hidden = false;
      $('dlg-cerrar-camara').hidden = false;
    } catch (e) {
      $('dlg-camara-error').textContent = e.message;
    }
  });

  $('dlg-disparar')?.addEventListener('click', async () => {
    if (!estado?.camaraViva) return;
    try {
      const foto = await estado.camaraViva.disparar(selloDe(estado.parada, estado.contexto));
      estado.fotos.push(foto);
      pintarMiniaturas();
    } catch (e) {
      $('dlg-camara-error').textContent = 'No se pudo tomar la foto: ' + e.message;
    }
  });

  $('dlg-cerrar-camara')?.addEventListener('click', () => {
    estado?.camaraViva?.cerrar();
    if (estado) estado.camaraViva = null;
    $('dlg-camara-vacia').hidden = false;
    $('dlg-abrir-camara').hidden = false;
    $('dlg-disparar').hidden = true;
    $('dlg-cerrar-camara').hidden = true;
  });

  // --- Archivo: elegir, arrastrar o pegar ---
  $('dlg-archivo')?.addEventListener('change', async (ev) => {
    for (const f of ev.target.files) await agregarFoto(f);
    ev.target.value = '';
  });

  const zona = $('dlg-soltar');
  zona?.addEventListener('click', () => $('dlg-archivo').click());
  ['dragenter', 'dragover'].forEach((e) =>
    zona?.addEventListener(e, (ev) => { ev.preventDefault(); zona.classList.add('encima'); }));
  ['dragleave', 'drop'].forEach((e) =>
    zona?.addEventListener(e, (ev) => { ev.preventDefault(); zona.classList.remove('encima'); }));
  zona?.addEventListener('drop', async (ev) => {
    for (const f of ev.dataTransfer.files) {
      if (f.type.startsWith('image/')) await agregarFoto(f);
    }
  });

  // Pegar desde el portapapeles: en escritorio es lo mas rapido si la foto ya
  // esta copiada de otro lado.
  document.addEventListener('paste', async (ev) => {
    if (!estado) return;
    for (const item of ev.clipboardData?.items ?? []) {
      if (item.type.startsWith('image/')) await agregarFoto(item.getAsFile());
    }
  });

  // Corregir cualquier campo borra el aviso de lo que faltaba.
  $('dialogo')?.addEventListener('input', () => { $('dlg-error').textContent = ''; });

  // --- Conformidad y firma ---
  for (const b of document.querySelectorAll('[data-conf]')) {
    b.addEventListener('click', () => elegirConformidad(b.dataset.conf));
  }
  $('dlg-limpiar-firma')?.addEventListener('click', () => estado?.pad?.limpiar());

  // --- Cerrar ---
  $('dlg-cancelar')?.addEventListener('click', () => cerrarDialogo(null));
  $('dialogo')?.addEventListener('click', (ev) => {
    if (ev.target.id === 'dialogo') cerrarDialogo(null);
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && estado) cerrarDialogo(null);
  });

  // --- Confirmar ---
  $('dlg-confirmar')?.addEventListener('click', async () => {
    if (!estado) return;
    const cantidad = parseInt($('dlg-cantidad').value, 10);
    if (!Number.isFinite(cantidad) || cantidad < 0 || cantidad > 99) {
      $('dlg-error').textContent = 'La cantidad tiene que ser un número entre 0 y 99.';
      return;
    }

    const falta = faltaEnConformidad();
    if (falta) {
      $('dlg-error').textContent = falta;
      return;
    }

    const boton = $('dlg-confirmar');
    boton.disabled = true;
    boton.textContent = 'Guardando…';

    try {
      const { parada, contexto } = estado;
      const adjuntos = [];

      for (const f of estado.fotos) {
        const uuid = await cola.encolarAdjunto('foto', f.blob, {
          jornada_id: contexto.jornada_id,
          parada_orden: parada.orden,
          sha256: f.sha256,
        });
        adjuntos.push({ uuid, tipo: 'foto' });
      }

      let firma = null;
      let firmaUuid = null;
      if (estado.conformidad !== 'nadie' && estado.pad?.hayFirma()) {
        firma = await estado.pad.aBlob({
          firmante: $('dlg-firmante').value.trim() || parada.responsable || '',
          sitio: parada.nombre,
        });
        const uuid = await cola.encolarAdjunto('firma', firma.blob, {
          jornada_id: contexto.jornada_id,
          parada_orden: parada.orden,
          sha256: firma.sha256,
        });
        adjuntos.push({ uuid, tipo: 'firma' });
        firmaUuid = uuid;
      }

      const texto = (id) => $(id).value.trim() || null;
      const conformidad = estado.conformidad === 'nadie' ? null : {
        resultado: estado.conformidad,
        receptor_nombre: texto('dlg-firmante'),
        receptor_documento: texto('dlg-receptor-doc'),
        observaciones: texto('dlg-observaciones'),
        motivo_rechazo: estado.conformidad === 'rechazado' ? texto('dlg-motivo-rechazo') : null,
        firma_uuid: firmaUuid,
      };

      cerrarDialogo({
        cantidad_real: cantidad,
        firmante: conformidad ? conformidad.receptor_nombre : null,
        firmada: !!firma,
        fotos: estado?.fotos.length ?? 0,
        adjuntos,
        conformidad,
      });
    } catch (e) {
      $('dlg-error').textContent = 'No se pudo guardar: ' + e.message;
      boton.disabled = false;
      boton.textContent = 'Confirmar';
    }
  });
}
