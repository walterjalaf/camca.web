// ============================================================
// Verificación pública de un remito. Obj. 2, paso F2.4.
//
// Lee el código de la dirección (?c=… o /verificar/XXXXX-XXXXX), le pregunta
// al servidor y contesta en el primer renglón lo único que importa: si el
// papel es auténtico.
//
// Y después hace algo que no le pide a nadie que confíe en nosotros: si el
// navegador sabe Ed25519, recalcula la huella del eslabón y comprueba la
// firma él mismo, con la clave pública. El servidor podría mentir en el
// veredicto; no puede hacer que la matemática del navegador dé bien.
// ============================================================

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const VEREDICTO = {
  certificado: ['Remito auténtico y certificado',
    'Está firmado digitalmente por CAMCA, no fue alterado desde que se emitió, y el cliente dio su conformidad.'],
  firmado: ['Remito auténtico, sin conformidad del cliente',
    'Está firmado digitalmente y no fue alterado, pero el cliente no dio conformidad: no certifica un servicio aceptado.'],
  sin_firma: ['Remito registrado, sin firma digital',
    'El remito existe en la plataforma y no fue alterado, pero se emitió sin firma digital: no es un certificado.'],
  anulado: ['Remito ANULADO',
    'Este remito existió, pero se anuló. No tiene validez como constancia.'],
  no_verifica: ['Este remito NO verifica',
    'Lo que hay en la plataforma no coincide con lo que se firmó al emitirlo. No lo tome como constancia y consulte a CAMCA.'],
  no_existe: ['No hay ningún remito con ese código',
    'Revisá que el código esté bien escrito. Si lo copiaste de un papel, son 10 caracteres: 5, un guion y otros 5.'],
};

const CONFORMIDAD = { conforme: 'Conforme', rechazado: 'NO conforme', pendiente: 'Sin conformidad' };

function codigoDeLaDireccion() {
  const q = new URLSearchParams(location.search).get('c');
  if (q) return q;
  // /verificar/XXXXX-XXXXX, si el servidor lo reescribe a esta página.
  const m = location.pathname.match(/\/verificar\/([0-9A-Za-z-]{10,13})\/?$/);
  return m ? m[1] : '';
}

const legible = (c) => {
  const s = c.replace(/[\s-]/g, '').toUpperCase();
  return s.length === 10 ? s.slice(0, 5) + '-' + s.slice(5) : c;
};

function fechaLarga(iso) {
  if (!iso) return '—';
  const d = new Date(iso + 'T12:00:00Z');
  return d.toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
}

function veredicto(nivel, extra = '') {
  const [t, p] = VEREDICTO[nivel] ?? VEREDICTO.no_verifica;
  return '<div class="verif-veredicto" data-nivel="' + esc(nivel) + '"><h2>' + esc(t) + '</h2><p>' + esc(p) + '</p>' + extra + '</div>';
}

async function verificar(codigo) {
  const caja = $('verif-resultado');
  caja.innerHTML = '<p class="verif-nota">Verificando…</p>';
  let res;
  try {
    res = await fetch('/api/v1/verificar/' + encodeURIComponent(codigo.replace(/\s/g, '')), {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    });
  } catch {
    caja.innerHTML = '<p class="verif-nota">No hay conexión con el servidor. Probá de nuevo en un momento.</p>';
    return;
  }
  if (res.status === 404) { caja.innerHTML = veredicto('no_existe'); return; }
  if (res.status === 429) {
    caja.innerHTML = '<p class="verif-nota">Demasiadas consultas desde esta conexión. Probá de nuevo en un rato.</p>';
    return;
  }
  let cuerpo = null;
  try { cuerpo = await res.json(); } catch { /* no era JSON */ }
  if (!res.ok || !cuerpo?.ok) {
    caja.innerHTML = '<p class="verif-nota">No se pudo verificar ahora. Probá de nuevo en un momento.</p>';
    return;
  }

  const d = cuerpo.datos;
  const r = d.remito;
  const v = d.verificacion;
  const problemas = v.problemas?.length
    ? '<ul>' + v.problemas.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul>' : '';

  const items = (r.items || []).map((i) =>
    esc(i.descripcion) + ': ' + esc(i.cantidad ?? '—') + ' ' + esc(i.cantidad === 1 && i.unidad === 'baños' ? 'baño' : i.unidad)
  ).join('<br>');

  const filas = [
    ['Remito', esc(r.numero)],
    ['Fecha del servicio', esc(fechaLarga(r.fecha_servicio))],
    ['Emitido por', esc(r.emisor.razon_social) + ' · CUIT ' + esc(r.emisor.cuit)],
    ['Cliente (iniciales)', r.cliente ? esc(r.cliente) : '—'],
    ['Servicio', items || '—'],
    ['Conformidad del cliente', esc(CONFORMIDAD[r.conformidad] ?? r.conformidad)],
    ['Estado', r.anulado ? 'Anulado' : 'Vigente'],
    ['Huella del contenido', '<code>' + esc(String(r.huella_contenido).slice(0, 32)) + '</code>'],
  ];

  const cripto = v.hash ? '<details class="verif-cripto"><summary>Verificalo por tu cuenta</summary>' +
    '<p class="verif-nota">La huella es SHA-256 de la huella previa, una barra vertical y el contenido. ' +
    'La firma es Ed25519 sobre el mensaje. Con estos datos cualquier herramienta criptográfica repite la cuenta.</p><dl>' +
    [['Algoritmo', v.algoritmo], ['Clave', v.clave_id], ['Clave pública (base64)', v.clave_publica],
     ['Huella previa', v.hash_previo ?? '(primer eslabón)'], ['Contenido', v.contenido], ['Huella', v.hash],
     ['Mensaje firmado', v.mensaje], ['Firma (base64)', v.firma]]
      .map(([k, x]) => '<dt>' + esc(k) + '</dt><dd>' + esc(x ?? '—') + '</dd>').join('') +
    '</dl><p class="verif-navegador" id="verif-navegador">Comprobando en tu navegador…</p></details>' : '';

  $('verif-resultado').innerHTML = veredicto(d.nivel, problemas) +
    '<table class="verif-datos"><tbody>' +
    filas.map(([k, x]) => '<tr><th>' + esc(k) + '</th><td>' + x + '</td></tr>').join('') +
    '</tbody></table>' + cripto;

  if (v.hash) comprobarEnElNavegador(v);
}

/**
 * La cuenta, hecha acá. Si el navegador no sabe Ed25519 (algunos todavía no),
 * se dice y se deja la cuenta para una herramienta de afuera.
 */
async function comprobarEnElNavegador(v) {
  const p = $('verif-navegador');
  const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
  const b64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
  const txt = (s) => new TextEncoder().encode(s);
  try {
    const huella = hex(await crypto.subtle.digest('SHA-256', txt((v.hash_previo ?? '0'.repeat(64)) + '|' + v.contenido)));
    if (huella !== v.hash) {
      p.dataset.ok = 'no';
      p.textContent = 'Tu navegador recalculó la huella y NO coincide con la informada.';
      return;
    }
    if (!v.firma || !v.clave_publica) {
      p.textContent = 'Tu navegador recalculó la huella y coincide. Este remito no tiene firma para comprobar.';
      return;
    }
    let clave;
    try {
      clave = await crypto.subtle.importKey('raw', b64(v.clave_publica), { name: 'Ed25519' }, false, ['verify']);
    } catch {
      p.textContent = 'Tu navegador recalculó la huella y coincide. No sabe comprobar firmas Ed25519: usá los datos de arriba con otra herramienta.';
      return;
    }
    const ok = await crypto.subtle.verify({ name: 'Ed25519' }, clave, b64(v.firma), txt(v.mensaje));
    p.dataset.ok = ok ? 'si' : 'no';
    p.textContent = ok
      ? 'Tu navegador recalculó la huella y comprobó la firma con la clave pública: coinciden.'
      : 'Tu navegador comprobó la firma y NO corresponde a la huella.';
  } catch {
    p.textContent = 'Tu navegador no pudo hacer la comprobación.';
  }
}

// ------------------------------------------------------------------
const inicial = codigoDeLaDireccion();
if (inicial) {
  $('verif-codigo').value = legible(inicial);
  verificar(inicial);
}
$('verif-form').addEventListener('submit', (ev) => {
  ev.preventDefault();
  const c = $('verif-codigo').value.trim();
  if (!c) return;
  history.replaceState(null, '', '/verificar/?c=' + encodeURIComponent(c.replace(/[\s-]/g, '').toUpperCase()));
  verificar(c);
});
