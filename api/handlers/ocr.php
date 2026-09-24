<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Piloto de OCR de remitos en papel. Paso F4.4.
 *
 *   GET  /api/v1/ocr/remitos                    fotos, estado y la medición del piloto
 *   GET  /api/v1/ocr/remito/{id}                ficha con el borrador
 *   GET  /api/v1/ocr/remito/{id}/imagen         la foto
 *   POST /api/v1/ocr/remito                     sube una foto (cuerpo crudo) y la procesa
 *   POST /api/v1/ocr/remito/{id}/reprocesar     vuelve a mandarla al modelo (tras un error)
 *   POST /api/v1/ocr/remito/{id}/validar        la validación humana: la única puerta al registro
 *   POST /api/v1/ocr/remito/{id}/descartar
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$yo = Policy::id();
$id = isset($params['id']) ? (int) $params['id'] : 0;

if ($metodo === 'GET') {
    if (str_ends_with($ruta, '/imagen')) {
        $a = Ocr::imagen($id);
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($a['ruta']));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        readfile($a['ruta']);
        exit;
    }
    Http::ok($id > 0 ? Ocr::detalle($id) : Ocr::listar());
}

// Una foto tarda unos segundos en el modelo; el tope de PHP del hosting
// compartido puede ser menor que el timeout de la llamada.
@set_time_limit(180);

if (preg_match('#/ocr/remito$#', $ruta)) {
    $bytes = (string) file_get_contents('php://input', false, null, 0, Ocr::MAX_BYTES + 1);
    Http::creado(Ocr::subir($bytes, rawurldecode((string) ($_SERVER['HTTP_X_CAMCA_NOMBRE'] ?? 'remito')), $yo));
}
if (str_ends_with($ruta, '/reprocesar')) Http::ok(Ocr::procesar($id));

$datos = Http::cuerpo();
if (str_ends_with($ruta, '/validar')) Http::ok(Ocr::validar($id, $datos, $yo));
if (str_ends_with($ruta, '/descartar')) {
    $v = new Validar($datos);
    $m = (string) $v->texto('motivo', 1, 255);
    $v->fin();
    Http::ok(Ocr::descartar($id, $m, $yo));
}

throw new ErrorNoEncontrado('Ruta no encontrada.');
