<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Maestro de clientes. Paso F3.1.
 *
 *   GET  /api/v1/clientes                  lista, sugerencias de fusión y fusiones hechas
 *   POST /api/v1/cliente                   guarda los datos fiscales de uno
 *   POST /api/v1/clientes/fusion           sin «confirmacion»: previsualiza;
 *                                          con la clave de la previsualización: fusiona
 *   POST /api/v1/clientes/fusion/deshacer  deshace una fusión
 *   POST /api/v1/clientes/acceso           da acceso al portal (F3.10); la contraseña sale una vez
 *   POST /api/v1/clientes/acceso/baja      lo quita y corta sus sesiones
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

if ($metodo === 'GET') {
    Http::ok([
        'clientes'    => Cliente::listar(),
        'sugerencias' => Cliente::sugerencias(),
        'fusiones'    => Cliente::fusiones(),
    ]);
}

$datos = Http::cuerpo();

if (str_ends_with($ruta, '/clientes/acceso/baja')) {
    $v = new Validar($datos);
    $usuario = $v->entero('usuario_id', 1, PHP_INT_MAX);
    $v->fin();
    Http::ok(Cliente::quitarAcceso($usuario, Policy::id()));
}

if (str_ends_with($ruta, '/clientes/acceso')) {
    $v = new Validar($datos);
    $cliente = $v->entero('cliente_id', 1, PHP_INT_MAX);
    $email = $v->texto('email', 3, 190);
    $nombre = $v->texto('nombre', 1, 120, false) ?? '';
    $v->fin();
    Http::creado(Cliente::crearAcceso($cliente, $nombre, $email, Policy::id()));
}

if (str_ends_with($ruta, '/fusion/deshacer')) {
    $v = new Validar($datos);
    $id = $v->entero('fusion_id', 1, PHP_INT_MAX);
    $v->fin();
    Http::ok(Cliente::deshacer($id, Policy::id()));
}

if (str_ends_with($ruta, '/fusion')) {
    $v = new Validar($datos);
    $origen = $v->entero('origen_id', 1, PHP_INT_MAX);
    $destino = $v->entero('destino_id', 1, PHP_INT_MAX);
    $confirmacion = $v->texto('confirmacion', 64, 64, false);
    $motivo = $v->texto('motivo', 3, 255, false);
    $v->fin();

    if ($confirmacion === null) Http::ok(Cliente::previsualizar($origen, $destino));
    Http::ok(Cliente::fusionar($origen, $destino, $confirmacion, (string) $motivo, Policy::id()));
}

// POST /cliente
$v = new Validar($datos);
$id = $v->entero('id', 1, PHP_INT_MAX);
$v->fin();
$campos = array_intersect_key($datos, array_flip(
    ['razon_social', 'cuit', 'tipo', 'condicion_iva', 'domicilio_fiscal', 'email_facturacion']));
Http::ok(Cliente::actualizar($id, $campos, Policy::id()));
