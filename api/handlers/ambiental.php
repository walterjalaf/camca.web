<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Control operacional ambiental: disposición de efluentes. Paso F4.2.
 *
 *   GET  /api/v1/ambiental?desde=&hasta=                 panel del período
 *   GET  /api/v1/ambiental/trazabilidad?desde=&hasta=    cada servicio con su descarga o su alerta
 *   GET  /api/v1/ambiental/registro?desde=&hasta=        registro imprimible (HTML)
 *   POST /api/v1/ambiental/descarga                      registra una descarga en planta
 *   POST /api/v1/ambiental/descarga/anular               la anula con motivo (admin)
 *   POST /api/v1/ambiental/planta                        alta o cambio de una planta (admin)
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$yo = Policy::id();

if ($metodo === 'GET') {
    $v = new Validar($_GET);
    $desde = (string) $v->texto('desde', 10, 10);
    $hasta = (string) $v->texto('hasta', 10, 10);
    $v->fin();
    if (str_ends_with($ruta, '/ambiental/trazabilidad')) Http::ok(Ambiental::trazabilidad($desde, $hasta));
    if (str_ends_with($ruta, '/ambiental/registro')) {
        $html = Ambiental::registroHtml($desde, $hasta);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        echo $html;
        exit;
    }
    Http::ok(Ambiental::panel($desde, $hasta));
}

$datos = Http::cuerpo();
if (str_ends_with($ruta, '/ambiental/descarga')) Http::creado(Ambiental::registrar($datos, $yo));
if (str_ends_with($ruta, '/ambiental/descarga/anular')) {
    $v = new Validar($datos);
    $id = (int) $v->entero('id', 1, PHP_INT_MAX);
    $motivo = (string) $v->texto('motivo', 3, 255);
    $v->fin();
    Http::ok(Ambiental::anular($id, $motivo, $yo));
}
if (str_ends_with($ruta, '/ambiental/planta')) Http::ok(Ambiental::guardarPlanta($datos, $yo));

throw new ErrorNoEncontrado('Ruta no encontrada.');
