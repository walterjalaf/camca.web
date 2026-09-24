<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/flota
 *
 * Última posición conocida de cada unidad, el rastro de la última hora y qué
 * cuadrilla lleva hoy cada camioneta. Es el panel de flota en vivo (F3.8).
 *
 * WIALON NUNCA LLEGA AL NAVEGADOR. El token vive en camca_priv/config.php y
 * sólo lo usa el cron (api/cli/gps_poll.php) del lado del servidor; esta
 * respuesta sale de la base, y la pantalla la pide cada 30 segundos. La CSP
 * (connect-src 'self') impide además que el navegador hable con Wialon aunque
 * alguien lo intentara.
 *
 * La posición de un camión con un chofer adentro es DATO PERSONAL (Ley
 * 25.326): cada consulta queda en gps_acceso_log. Con el refresco automático
 * eso serían 120 filas por hora y por unidad de una sola persona mirando la
 * misma pantalla, que no dicen nada más que una; se anota una vez cada diez
 * minutos por persona y unidad. La pregunta que ese registro responde —¿quién
 * miró dónde estaba el camión el martes?— sigue teniendo respuesta.
 */

const RASTRO_MIN = 60;
const LOG_CADA_MIN = 10;

$u = Policy::usuario();

$unidades = Db::todas(
    'SELECT gu.id, gu.nombre, gu.reporta_odo, gu.vehiculo_id, v.patente,
            p.ts_utc, p.lat, p.lon, p.velocidad, p.curso, p.satelites, p.odo_m
       FROM gps_unidad gu
       LEFT JOIN vehiculo v ON v.id = gu.vehiculo_id
       LEFT JOIN gps_posicion p ON p.id = (
            SELECT id FROM gps_posicion x WHERE x.unidad_id = gu.id ORDER BY x.ts_utc DESC LIMIT 1
       )
      WHERE gu.activa = 1
      ORDER BY gu.nombre'
);

$recientes = [];
foreach (Db::todas('SELECT unidad_id FROM gps_acceso_log WHERE usuario_id = :u AND creado_utc > UTC_TIMESTAMP() - INTERVAL ' . LOG_CADA_MIN . ' MINUTE',
                   [':u' => $u['id']]) as $x) {
    $recientes[(int) $x['unidad_id']] = true;
}
foreach ($unidades as $un) {
    if (isset($recientes[(int) $un['id']])) continue;
    Db::q('INSERT INTO gps_acceso_log (usuario_id, unidad_id, creado_utc) VALUES (:u, :n, UTC_TIMESTAMP())',
          [':u' => $u['id'], ':n' => $un['id']]);
}

// El rastro: las posiciones de la última hora, para ver hacia dónde va.
$rastro = [];
foreach (Db::todas('SELECT unidad_id, lat, lon FROM gps_posicion
                     WHERE ts_utc > UTC_TIMESTAMP() - INTERVAL ' . RASTRO_MIN . ' MINUTE ORDER BY unidad_id, ts_utc') as $p) {
    $rastro[(int) $p['unidad_id']][] = [(float) $p['lat'], (float) $p['lon']];
}

// Quién lleva hoy cada camioneta (F3.7).
$hoy = gmdate('Y-m-d', time() - 3 * 3600);
$asignado = [];
foreach (Db::todas("SELECT a.vehiculo_id, cu.nombre AS cuadrilla, r.nombre AS ruta, ca.nombre AS campana
                      FROM asignacion a JOIN cuadrilla cu ON cu.id = a.cuadrilla_id
                      LEFT JOIN jornada j ON j.id = a.jornada_id LEFT JOIN ruta_plantilla r ON r.id = j.ruta_id
                      LEFT JOIN campana ca ON ca.id = a.campana_id
                     WHERE a.estado = 'planificada' AND a.fecha = :f AND a.vehiculo_id IS NOT NULL", [':f' => $hoy]) as $a) {
    $asignado[(int) $a['vehiculo_id']] = $a['cuadrilla'] . ($a['ruta'] ? ' · ruta ' . $a['ruta'] : ($a['campana'] ? ' · ' . $a['campana'] : ''));
}

$estado = Db::una('SELECT ultimo_ok_utc, ultimo_error, backoff_hasta FROM gps_estado WHERE id = 1');
$vivo = $estado !== null && $estado['ultimo_ok_utc'] !== null
     && strtotime((string) $estado['ultimo_ok_utc']) > time() - 1800;

Http::ok([
    'generado'     => gmdate('c'),
    'rastreo_vivo' => $vivo,
    // Si el enlace está caído se dice, en vez de mostrar posiciones viejas
    // como si fueran actuales.
    'ultimo_ok'    => $estado['ultimo_ok_utc'] ?? null,
    'ultimo_error' => $vivo ? null : ($estado['ultimo_error'] ?? null),
    'bases'        => array_map(static fn($b) => ['nombre' => $b['nombre'], 'lat' => (float) $b['lat'], 'lon' => (float) $b['lon']],
                                Db::todas('SELECT nombre, lat, lon FROM base_operativa')),
    'unidades' => array_map(static function ($un) use ($rastro, $asignado) {
        $edadMin = $un['ts_utc'] === null ? null : (int) round((time() - strtotime((string) $un['ts_utc'] . ' UTC')) / 60);
        return [
            'id'          => (int) $un['id'],
            'nombre'      => $un['nombre'],
            'patente'     => $un['patente'],
            'lat'         => $un['lat'] === null ? null : (float) $un['lat'],
            'lon'         => $un['lon'] === null ? null : (float) $un['lon'],
            'velocidad'   => $un['velocidad'] === null ? null : (int) $un['velocidad'],
            'curso'       => $un['curso'] === null ? null : (int) $un['curso'],
            'ts'          => $un['ts_utc'],
            'edad_min'    => $edadMin,
            // El prototipo mostraba la señal como vieja pasados 15 min. Se
            // conserva ese criterio: una posición de hace media hora no dice
            // dónde está el camión, dice dónde estaba.
            'senial_vieja' => $edadMin !== null && $edadMin > 15,
            'reporta_odo' => $un['reporta_odo'] === null ? null : (int) $un['reporta_odo'] === 1,
            'rastro'      => $rastro[(int) $un['id']] ?? [],
            'asignado'    => $un['vehiculo_id'] !== null ? ($asignado[(int) $un['vehiculo_id']] ?? null) : null,
        ];
    }, $unidades),
]);
