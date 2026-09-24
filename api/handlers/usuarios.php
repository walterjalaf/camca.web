<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Usuarios de CAMCA y teléfonos de los choferes (cierre, F4.6).
 *
 *   GET  /api/v1/usuarios                 lista, con los teléfonos activos de cada chofer
 *   POST /api/v1/usuario                  alta (admin); a la oficina le devuelve la contraseña, una vez
 *   POST /api/v1/usuario/clave            contraseña nueva para alguien de la oficina (admin)
 *   POST /api/v1/usuario/baja             (admin) corta sesiones y teléfonos
 *   POST /api/v1/usuario/reactivar        (admin)
 *   POST /api/v1/dispositivo/revocar      teléfono perdido o robado (coordinación)
 *   POST /api/v1/auth/clave/cambiar       cada uno, la suya
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$yo = Policy::id();

if ($metodo === 'GET') Http::ok(Usuarios::listar());

$datos = Http::cuerpo();
$v = new Validar($datos);
if (str_ends_with($ruta, '/auth/clave/cambiar')) {
    $actual = (string) $v->texto('actual', 1, 200);
    $nueva = (string) $v->texto('nueva', 1, 200);
    $v->fin();
    Http::ok(Usuarios::cambiarMiClave($yo, $actual, $nueva));
}
$miClave = is_string($datos['mi_clave'] ?? null) ? $datos['mi_clave'] : null;
unset($datos['mi_clave']);
if (str_ends_with($ruta, '/usuario')) {
    if (($datos['rol'] ?? null) === 'admin') Usuarios::confirmarClave($yo, $miClave);
    Http::creado(Usuarios::alta($datos, $yo));
}
if (str_ends_with($ruta, '/dispositivo/revocar')) {
    $id = (int) $v->entero('dispositivo_id', 1, PHP_INT_MAX);
    $v->fin();
    Http::ok(Usuarios::revocarTelefono($id, $yo));
}
$id = (int) $v->entero('usuario_id', 1, PHP_INT_MAX);
$v->fin();
if (str_ends_with($ruta, '/usuario/clave')) {
    Usuarios::confirmarClave($yo, $miClave);
    Http::ok(Usuarios::nuevaClave($id, $yo));
}
if (str_ends_with($ruta, '/usuario/baja')) {
    // Darse de baja a sí mismo se rechaza antes (BAJA_PROPIA), sin pedir nada.
    if ($id !== $yo && Usuarios::rolDe($id) === 'admin') Usuarios::confirmarClave($yo, $miClave);
    Http::ok(Usuarios::baja($id, $yo));
}
if (str_ends_with($ruta, '/usuario/reactivar')) {
    if (Usuarios::rolDe($id) === 'admin') Usuarios::confirmarClave($yo, $miClave);
    Http::ok(Usuarios::reactivar($id, $yo));
}

throw new ErrorNoEncontrado('Ruta no encontrada.');
