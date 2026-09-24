<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/consultas   (supervisor)
 *
 * Las consultas del formulario del sitio.
 *
 * Esta pantalla existe por una razon concreta: hasta ahora el formulario
 * mandaba un POST a "/" siguiendo la convencion de Netlify Forms, pero
 * produccion es Hostinger. Ese POST devolvia 200 sirviendo el index.html
 * estatico, el visitante siempre veia "Gracias", y la consulta no llegaba a
 * ningun lado. Ahora se guardan todas, y aca se leen.
 *
 * Incluye las que fallaron al enviarse por mail: una consulta que no se pudo
 * mandar NO desaparece, queda visible con su motivo.
 */

$estado = $_GET['estado'] ?? 'todas';
$permitidos = ['todas', 'pendiente', 'enviado', 'fallido', 'spam'];
if (!in_array($estado, $permitidos, true)) {
    throw new ErrorValidacion(['estado' => 'Valor no permitido.']);
}

$where = $estado === 'todas' ? '' : ' WHERE estado = :e';
$params = $estado === 'todas' ? [] : [':e' => $estado];

$filas = Db::todas(
    'SELECT id, nombre, email, telefono, empresa, servicio, mensaje, estado,
            intentos, ultimo_error, creado_utc, enviado_utc
       FROM consulta_web' . $where . '
      ORDER BY id DESC
      LIMIT 200',
    $params
);

$conteos = [];
foreach (Db::todas('SELECT estado, COUNT(*) AS n FROM consulta_web GROUP BY estado') as $c) {
    $conteos[$c['estado']] = (int) $c['n'];
}

Http::ok([
    'estado'   => $estado,
    'conteos'  => $conteos,
    'consultas' => array_map(static fn($f) => [
        'id'        => (int) $f['id'],
        'nombre'    => $f['nombre'],
        'email'     => $f['email'],
        'telefono'  => $f['telefono'],
        'empresa'   => $f['empresa'],
        'servicio'  => $f['servicio'],
        'mensaje'   => $f['mensaje'],
        'estado'    => $f['estado'],
        'intentos'  => (int) $f['intentos'],
        'error'     => $f['ultimo_error'],
        'creado'    => $f['creado_utc'],
        'enviado'   => $f['enviado_utc'],
    ], $filas),
]);
