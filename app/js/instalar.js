// ============================================================
// Primera puesta en marcha (cierre, F4.6). Ver src/pages/app/instalar.astro.
//
// Habla con /api/v1/instalar directo, con fetch y sin sesión: todavía no hay
// base ni usuarios. Si la API contesta 404, la plataforma ya está instalada.
// ============================================================

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const sello = (ok, si, no) => '<span class="sello ' + (ok ? 'sello--ok' : 'sello--mal') + '">' + (ok ? si : no) + '</span>';

async function estado() {
  let r;
  try {
    r = await fetch('/api/v1/instalar', { headers: { Accept: 'application/json' }, cache: 'no-store' });
  } catch {
    $('ins-estado').innerHTML = '<p class="app-titulo">Sin conexión con el servidor</p><p class="app-nota">Recargá la página.</p>';
    return;
  }
  if (r.status === 404) {
    $('ins-estado').innerHTML = '<p class="app-titulo">La plataforma ya está instalada</p>' +
      '<p class="app-nota">Esta pantalla sirve una sola vez. <a href="/app/">Entrar a la plataforma</a>.</p>';
    return;
  }
  // Una respuesta que no es JSON de la API (el hosting sin PHP, una página
  // de error del proveedor) no es un «listo para instalar».
  const d = await r.json().then((x) => x?.datos, () => null);
  if (!r.ok || !d) {
    $('ins-estado').innerHTML = '<p class="app-titulo">El servidor no contesta como se espera</p>' +
      '<p class="app-nota">La API de la plataforma no respondió (código ' + r.status + '). Revisá que el deploy haya subido la carpeta <code>api/</code> y que PHP esté activo.</p>';
    return;
  }
  const ext = Object.entries(d.extensiones).map(([k, ok]) =>
    '<li>' + sello(ok, 'está', k === 'sodium' ? 'falta: no se van a poder certificar remitos (S10)' : 'falta') + ' extensión <b>' + esc(k) + '</b></li>').join('');
  $('ins-estado').innerHTML = '<p class="app-titulo">Puesta en marcha de la plataforma</p>' +
    '<p class="app-nota">Se hace una sola vez. Va a escribir la configuración fuera del sitio, crear las tablas y el primer administrador.</p>' +
    '<ul class="ins-requisitos"><li>' + sello(d.php_ok, 'bien', 'hace falta 8.1 o más') + ' PHP ' + esc(d.php) + '</li>' + ext +
    '<li>' + sello(d.destino_escribible, 'se puede escribir', 'no se puede escribir') + ' la carpeta privada de la configuración, fuera del sitio</li></ul>';
  $('ins-formulario').hidden = false;
}

$('ins-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const error = $('ins-error');
  error.hidden = true;
  if ($('ins-clave').value !== $('ins-repetir').value) {
    error.textContent = 'Las dos contraseñas no coinciden.';
    error.hidden = false;
    return;
  }
  $('ins-enviar').disabled = true;
  $('ins-enviar').textContent = 'Instalando… (puede tardar un minuto)';
  let r, cuerpo;
  try {
    r = await fetch('/api/v1/instalar', {
      method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        token: $('ins-token').value.trim(), db_nombre: $('ins-db-nombre').value.trim(), db_usuario: $('ins-db-usuario').value.trim(),
        db_clave: $('ins-db-clave').value, admin_nombre: $('ins-nombre').value.trim(), admin_email: $('ins-email').value.trim(),
        admin_clave: $('ins-clave').value, url_publica: location.origin,
      }),
    });
    cuerpo = await r.json();
  } catch {
    cuerpo = null;
  }
  if (!r || !r.ok || !cuerpo?.ok) {
    const e = cuerpo?.error;
    error.textContent = e?.campos && Object.keys(e.campos).length ? Object.values(e.campos).join(' ') : (e?.mensaje || 'No se pudo instalar.');
    error.hidden = false;
    $('ins-enviar').disabled = false;
    $('ins-enviar').textContent = 'Instalar';
    return;
  }
  const d = cuerpo.datos;
  $('ins-formulario').hidden = true;
  $('ins-estado').hidden = true;
  $('ins-listo').hidden = false;
  $('ins-listo').innerHTML = '<p class="app-titulo">Listo: la plataforma está instalada</p>' +
    '<p class="app-nota">Se crearon las tablas (' + d.migraciones + ' migraciones) y el administrador <b>' + esc(d.admin) + '</b>. ' +
    'La configuración quedó en <code>' + esc(d.destino) + '</code>. Esta pantalla ya no vuelve a funcionar.</p>' +
    '<p class="app-titulo">Falta un paso en hPanel: los trabajos programados</p>' +
    '<p class="app-nota">hPanel → Avanzado → Trabajos cron. Uno por línea (los horarios están en UTC: 05:30 UTC son las 02:30 de San Juan):</p>' +
    '<div class="ins-codigo">' + d.crons.map(esc).join('\n') + '</div>' +
    (d.firma_publica
      ? '<p class="app-nota">Clave pública de firma de los remitos: guardala fuera del servidor (sirve para verificar los remitos aunque se cambie la clave).</p>' +
        '<div class="ins-codigo">' + esc(d.firma_publica) + '</div>'
      : '<p class="app-nota">El servidor no tiene la extensión sodium: la plataforma funciona, pero no certifica remitos hasta que la tenga.</p>') +
    '<p class="app-nota">Token para revisar la salud del servidor (<code>/api/v1/health</code>, cabecera <code>X-Camca-Health</code>):</p>' +
    '<div class="ins-codigo">' + esc(d.health_token) + '</div>' +
    '<a class="app-btn app-btn--principal" href="/app/">Entrar a la plataforma</a>';
});

estado();
