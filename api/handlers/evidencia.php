<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/evidencia/{uuid}
 *
 * Sirve una foto o una firma. Los binarios viven en camca_priv/evidencias,
 * FUERA de public_html, por dos razones: el deploy FTP sincroniza y borraria
 * lo que no viaja en dist/, y si estuvieran bajo public_html serian
 * descargables por URL sin ninguna sesion. Adentro hay firmas y documentos de
 * personal de clientes mineros.
 *
 * Un chofer solo ve SUS evidencias. El supervisor ve todas.
 *
 * Ante una evidencia ajena se responde 404 y NO 403: un 403 confirmaria que
 * ese uuid existe, y eso ya es informacion.
 */

$partes = explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'));
$uuid = end($partes);

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $uuid)) {
    throw new ErrorNoEncontrado('Evidencia no encontrada.');
}

$u = Policy::usuario();

$e = Db::una(
    'SELECT ev.uuid, ev.ruta_relativa, ev.mime, ev.bytes, ev.completa, ev.tipo,
            j.chofer_id
       FROM evidencia ev
       LEFT JOIN jornada j ON j.id = ev.jornada_id
      WHERE ev.uuid = :u
      LIMIT 1',
    [':u' => strtolower((string) $uuid)]
);

if ($e === null || (int) $e['completa'] !== 1) {
    throw new ErrorNoEncontrado('Evidencia no encontrada.');
}

// Un chofer solo accede a lo suyo. 404, nunca 403.
if (Policy::esChofer() && $e['chofer_id'] !== null && (int) $e['chofer_id'] !== Policy::id()) {
    Log::aviso('evidencia_ajena', ['uuid' => $uuid, 'usuario' => Policy::id()]);
    throw new ErrorNoEncontrado('Evidencia no encontrada.');
}

$raiz = Config::get('rutas.evidencias', Config::priv() . '/evidencias');
$ruta = $raiz . '/' . $e['ruta_relativa'];

// Defensa contra un ruta_relativa manipulado: el archivo tiene que quedar
// DENTRO de la raiz de evidencias, sin excepcion.
$real = realpath($ruta);
$raizReal = realpath($raiz);
if ($real === false || $raizReal === false || !str_starts_with($real, $raizReal)) {
    Log::error('evidencia_fuera_de_raiz', ['uuid' => $uuid]);
    throw new ErrorNoEncontrado('Evidencia no encontrada.');
}

Hash::auditar('evidencia', null, 'descargada', ['uuid' => $uuid, 'usuario' => Policy::id()]);

$tamanio = (int) filesize($real);
$mime = $e['mime'] ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $e['uuid'] . '"');
header('X-Content-Type-Options: nosniff');
// Privada y de corta vida: es material sensible, no un asset publico.
header('Cache-Control: private, max-age=300');
header('Accept-Ranges: bytes');

// Rangos: el navegador los pide al mostrar imagenes grandes y al reanudar.
$rango = $_SERVER['HTTP_RANGE'] ?? '';
if ($rango !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rango, $m)) {
    $desde = $m[1] === '' ? 0 : (int) $m[1];
    $hasta = $m[2] === '' ? $tamanio - 1 : (int) $m[2];
    $desde = max(0, $desde);
    $hasta = min($tamanio - 1, $hasta);
    if ($desde > $hasta) {
        http_response_code(416);
        header('Content-Range: bytes */' . $tamanio);
        exit;
    }
    $largo = $hasta - $desde + 1;
    http_response_code(206);
    header('Content-Range: bytes ' . $desde . '-' . $hasta . '/' . $tamanio);
    // Content-Length tiene que ser EXACTAMENTE lo que se manda: si miente,
    // el navegador queda esperando bytes que no llegan.
    header('Content-Length: ' . $largo);

    $fh = fopen($real, 'rb');
    fseek($fh, $desde);
    $restan = $largo;
    while ($restan > 0 && !feof($fh)) {
        $trozo = fread($fh, (int) min(262144, $restan));
        if ($trozo === false) break;
        echo $trozo;
        $restan -= strlen($trozo);
        flush();
    }
    fclose($fh);
    exit;
}

header('Content-Length: ' . $tamanio);
readfile($real);
exit;
