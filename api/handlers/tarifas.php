<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Tarifario. Paso F3.2.
 *
 *   GET  /api/v1/tarifas[?cliente_id=]       tarifas, servicios y clientes para cargar
 *   GET  /api/v1/tarifa/cotizar?cliente_id=&servicio=&fecha=&cantidad=[&km=&horas=]
 *   POST /api/v1/tarifa                       carga una tarifa nueva
 *   POST /api/v1/tarifa/cerrar                cierra una vigente con fecha
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

if ($metodo === 'GET' && str_ends_with($ruta, '/cotizar')) {
    $v = new Validar($_GET);
    $cliente = $v->entero('cliente_id', 1, PHP_INT_MAX, false);
    $servicio = $v->texto('servicio', 1, 30);
    $fecha = $v->texto('fecha', 10, 10);
    $cantidad = $v->entero('cantidad', 0, 100000);
    $v->fin();
    $km = isset($_GET['km']) && is_numeric($_GET['km']) ? (float) $_GET['km'] : 0.0;
    $horas = isset($_GET['horas']) && is_numeric($_GET['horas']) ? (float) $_GET['horas'] : 0.0;
    $c = Tarifa::cotizar($cliente, $servicio, $fecha, $cantidad, $km, $horas);
    Http::ok($c + ['subtotal' => Tarifa::pesos($c['subtotal_cent'])]);
}

if ($metodo === 'GET') {
    $cliente = isset($_GET['cliente_id']) && ctype_digit((string) $_GET['cliente_id']) ? (int) $_GET['cliente_id'] : null;
    Http::ok([
        'tarifas'   => Tarifa::listar($cliente),
        'servicios' => Tarifa::servicios(),
        'clientes'  => Db::todas('SELECT id, nombre FROM cliente WHERE activo = 1 ORDER BY nombre'),
    ]);
}

$datos = Http::cuerpo();
if (str_ends_with($ruta, '/cerrar')) {
    $v = new Validar($datos);
    $id = $v->entero('id', 1, PHP_INT_MAX);
    $hasta = $v->texto('hasta', 10, 10);
    $v->fin();
    Http::ok(Tarifa::cerrar($id, $hasta, Policy::id()));
}
Http::creado(Tarifa::crear($datos, Policy::id()));
