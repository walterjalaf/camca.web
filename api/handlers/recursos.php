<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Cuadrillas, personal y equipos. Paso F3.6.
 *
 *   GET  /api/v1/recursos                         todo, con las alertas de vencimiento arriba
 *   POST /api/v1/recursos/persona                 alta {nombre, dni, legajo?, rol_operativo, usuario_id?}
 *   POST /api/v1/recursos/vehiculo                alta {patente, descripcion?}
 *   POST /api/v1/recursos/activos                 alta de un lote {tipo, prefijo, desde, hasta, base_id}
 *   POST /api/v1/recursos/activo/mover            {activo_id, sitio_id | base_id, motivo?}
 *   POST /api/v1/recursos/activo/estado           {activo_id, estado, motivo?}
 *   POST /api/v1/recursos/vencimiento             {entidad, entidad_id, tipo, vence, documento?}
 *   POST /api/v1/recursos/cuadrilla               {nombre}
 *   POST /api/v1/recursos/cuadrilla/miembro       {cuadrilla_id, persona_id}
 *   POST /api/v1/recursos/cuadrilla/quitar        {persona_id}
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$tramo = preg_replace('#^.*/recursos/?#', '', $ruta);

if ($metodo === 'GET') {
    Http::ok(Recursos::listar());
}

$d = Http::cuerpo();
$u = Policy::id();
switch ($tramo) {
    case 'persona':
        Http::creado(Recursos::altaPersona($d, $u));
    case 'vehiculo':
        Http::creado(Recursos::altaVehiculo($d, $u));
    case 'activos':
        $v = new Validar($d);
        $tipo = $v->texto('tipo', 1, 30);
        $prefijo = $v->texto('prefijo', 1, 20);
        $desde = $v->entero('desde', 1, 99999);
        $hasta = $v->entero('hasta', 1, 99999);
        $base = $v->entero('base_id', 1, PHP_INT_MAX);
        $v->fin();
        Http::creado(Recursos::altaActivos($tipo, $prefijo, $desde, $hasta, $base, $u));
    case 'activo/mover':
        $v = new Validar($d);
        $id = $v->entero('activo_id', 1, PHP_INT_MAX);
        $sitio = $v->entero('sitio_id', 1, PHP_INT_MAX, false);
        $base = $v->entero('base_id', 1, PHP_INT_MAX, false);
        $motivo = $v->texto('motivo', 1, 255, false) ?? '';
        $v->fin();
        Http::ok(Recursos::mover($id, $sitio, $base, $motivo, $u));
    case 'activo/estado':
        $v = new Validar($d);
        $id = $v->entero('activo_id', 1, PHP_INT_MAX);
        $estado = $v->texto('estado', 1, 20);
        $motivo = $v->texto('motivo', 1, 255, false) ?? '';
        $v->fin();
        Http::ok(Recursos::cambiarEstado($id, $estado, $motivo, $u));
    case 'vencimiento':
        $v = new Validar($d);
        $entidad = $v->texto('entidad', 1, 20);
        $id = $v->entero('entidad_id', 1, PHP_INT_MAX);
        $tipo = $v->texto('tipo', 1, 30);
        $vence = $v->texto('vence', 10, 10);
        $doc = $v->texto('documento', 1, 120, false);
        $v->fin();
        if (!in_array($entidad, ['persona', 'vehiculo'], true)) throw new ErrorValidacion(['entidad' => 'Persona o vehículo.']);
        Http::creado(Recursos::cargarVencimiento($entidad, $id, $tipo, $vence, $doc, $u));
    case 'cuadrilla':
        $v = new Validar($d);
        $nombre = $v->texto('nombre', 2, 80);
        $v->fin();
        Http::creado(Recursos::crearCuadrilla($nombre, $u));
    case 'cuadrilla/miembro':
        $v = new Validar($d);
        $c = $v->entero('cuadrilla_id', 1, PHP_INT_MAX);
        $p = $v->entero('persona_id', 1, PHP_INT_MAX);
        $v->fin();
        Http::creado(Recursos::agregarMiembro($c, $p, $u));
    case 'cuadrilla/quitar':
        $v = new Validar($d);
        $p = $v->entero('persona_id', 1, PHP_INT_MAX);
        $v->fin();
        Recursos::quitarMiembro($p, $u);
        Http::ok(['persona_id' => $p]);
}
Http::error(404, 'NO_ENCONTRADO', 'Ruta no encontrada.');
