<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * No conformidades y acciones correctivas. Paso F4.3.
 *
 *   GET  /api/v1/sga/nc                            lista
 *   GET  /api/v1/sga/nc/{id}                       detalle con sus acciones
 *   POST /api/v1/sga/nc                            abrir
 *   POST /api/v1/sga/nc/{id}/causa                 análisis de causa
 *   POST /api/v1/sga/nc/{id}/accion                agregar una acción
 *   POST /api/v1/sga/nc/{id}/verificacion          enviar a verificación de eficacia
 *   POST /api/v1/sga/nc/{id}/eficacia              verificar: cerrar o volver a tratamiento (admin)
 *   POST /api/v1/sga/nc/{id}/anular                (admin)
 *   POST /api/v1/sga/nc/accion/{id}/archivo        archivo de evidencia, como cuerpo crudo
 *   GET  /api/v1/sga/nc/accion/{id}/archivo        lo baja
 *   POST /api/v1/sga/nc/accion/{id}/cumplir        con la evidencia
 *   POST /api/v1/sga/nc/accion/{id}/descartar      con motivo
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$yo = Policy::id();
$id = isset($params['id']) ? (int) $params['id'] : 0;
$esAccion = str_contains($ruta, '/sga/nc/accion/');

if ($metodo === 'GET') {
    if ($esAccion && str_ends_with($ruta, '/archivo')) {
        $a = NoConformidad::archivo($id);
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($a['ruta']));
        header('Content-Disposition: inline; filename="' . (preg_replace('/[^A-Za-z0-9._-]/', '', $a['nombre']) ?: 'evidencia') . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        readfile($a['ruta']);
        exit;
    }
    Http::ok($id > 0 ? NoConformidad::detalle($id) : NoConformidad::listar());
}

if ($esAccion && str_ends_with($ruta, '/archivo')) {
    $bytes = (string) file_get_contents('php://input', false, null, 0, NoConformidad::MAX_BYTES + 1);
    $nombre = rawurldecode((string) ($_SERVER['HTTP_X_CAMCA_NOMBRE'] ?? 'evidencia'));
    Http::ok(NoConformidad::adjuntar($id, $bytes, $nombre, $yo));
}

$datos = Http::cuerpo();
if (!$esAccion && preg_match('#/sga/nc$#', $ruta)) Http::creado(NoConformidad::abrir($datos, $yo));

$ultimo = substr($ruta, strrpos($ruta, '/') + 1);
$v = new Validar($datos);
if ($esAccion) {
    switch ($ultimo) {
        case 'cumplir':
            $ev = (string) $v->texto('evidencia', 1, 2000);
            $v->fin();
            Http::ok(NoConformidad::cumplir($id, $ev, $yo));
        case 'descartar':
            $m = (string) $v->texto('motivo', 1, 255);
            $v->fin();
            Http::ok(NoConformidad::descartarAccion($id, $m, $yo));
    }
} else {
    switch ($ultimo) {
        case 'causa':
            $c = (string) $v->texto('causa', 1, 2000);
            $v->fin();
            Http::ok(NoConformidad::analizar($id, $c, $yo));
        case 'accion':
            Http::creado(NoConformidad::agregarAccion($id, $datos, $yo));
        case 'verificacion':
            $v->fin();
            Http::ok(NoConformidad::aVerificacion($id, $yo));
        case 'eficacia':
            if (!is_bool($datos['eficaz'] ?? null)) throw new ErrorValidacion(['eficaz' => 'Decí si fue eficaz (true o false).']);
            $como = (string) $v->texto('verificacion', 1, 2000);
            $v->fin();
            Http::ok(NoConformidad::verificar($id, $datos['eficaz'], $como, $yo));
        case 'anular':
            $m = (string) $v->texto('motivo', 1, 255);
            $v->fin();
            Http::ok(NoConformidad::anular($id, $m, $yo));
    }
}

throw new ErrorNoEncontrado('Ruta no encontrada.');
