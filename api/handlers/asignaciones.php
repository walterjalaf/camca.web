<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Asignación y traslados. Paso F3.7.
 *
 *   GET  /api/v1/asignaciones?desde=&hasta=     lo planificado, las campañas y las advertencias
 *   POST /api/v1/asignacion                     {fecha, cuadrilla_id, vehiculo_id?, jornada_id?, nota?}
 *   POST /api/v1/asignacion/cancelar            {id, motivo}
 *   POST /api/v1/campana                        {nombre, desde, hasta, cuadrilla_id, vehiculo_id?, cliente_id?, sitio_id?, activos?[], nota?}
 *   POST /api/v1/campana/cancelar               {id, motivo}
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

if ($metodo === 'GET') {
    $v = new Validar($_GET);
    $desde = $v->texto('desde', 10, 10);
    $hasta = $v->texto('hasta', 10, 10);
    $v->fin();
    Http::ok(Asignacion::listar($desde, $hasta));
}

$d = Http::cuerpo();
$u = Policy::id();
if (str_ends_with($ruta, '/campana/cancelar') || str_ends_with($ruta, '/asignacion/cancelar')) {
    $v = new Validar($d);
    $id = $v->entero('id', 1, PHP_INT_MAX);
    $motivo = $v->texto('motivo', 1, 255);
    $v->fin();
    str_contains($ruta, '/campana/') ? Asignacion::cancelarCampana($id, $motivo, $u) : Asignacion::cancelar($id, $motivo, $u);
    Http::ok(['id' => $id]);
}
if (str_ends_with($ruta, '/campana')) {
    Http::creado(Asignacion::crearCampana($d, $u));
}
$v = new Validar($d);
$fecha = $v->texto('fecha', 10, 10);
$cuadrilla = $v->entero('cuadrilla_id', 1, PHP_INT_MAX);
$vehiculo = $v->entero('vehiculo_id', 1, PHP_INT_MAX, false);
$jornada = $v->entero('jornada_id', 1, PHP_INT_MAX, false);
$nota = $v->texto('nota', 1, 255, false);
$v->fin();
Http::creado(Asignacion::asignar($fecha, $cuadrilla, $vehiculo, $jornada, $nota, $u));
