<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/sync/estado
 *
 * Le dice a la app que sabe el servidor de la jornada de hoy. Sirve para dos
 * cosas: que el chofer vea que su trabajo LLEGO (no solo que "se envio"), y
 * que la app detecte adjuntos a medias y los reanude desde el offset correcto.
 *
 * Incluye tambien el estado del enlace con Wialon, para que la app no le
 * mienta al chofer diciendo que hay doble evidencia cuando el rastreo esta
 * caido (token vencido, codigos 7 y 8).
 */

$u = Policy::usuario();
$hoy = gmdate('Y-m-d', time() - 3 * 3600);

$jornada = Db::una(
    'SELECT j.id, j.estado, j.km_odo, j.km_hav, j.km_fuente,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id) AS total,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id AND p.estado = :e1) AS hechas,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id AND p.estado = :e2) AS no_hechas
       FROM jornada j
      WHERE j.fecha = :f
      ORDER BY j.id LIMIT 1',
    [':e1' => 'ejecutada', ':e2' => 'no_ejecutada', ':f' => $hoy]
);

$incompletas = [];
if ($jornada !== null) {
    $incompletas = Db::todas(
        'SELECT uuid, offset_bytes, bytes_esperados FROM evidencia
          WHERE jornada_id = :j AND completa = 0',
        [':j' => $jornada['id']]
    );
}

$gps = Db::una('SELECT ultimo_ok_utc, ultimo_error, backoff_hasta FROM gps_estado WHERE id = 1');
$rastreoVivo = $gps !== null
    && $gps['ultimo_ok_utc'] !== null
    && strtotime((string) $gps['ultimo_ok_utc']) > time() - 1800;

Http::ok([
    'hoy'     => $hoy,
    'jornada' => $jornada === null ? null : [
        'id'        => (int) $jornada['id'],
        'estado'    => $jornada['estado'],
        'total'     => (int) $jornada['total'],
        'hechas'    => (int) $jornada['hechas'],
        'no_hechas' => (int) $jornada['no_hechas'],
        'km_real'   => $jornada['km_odo'] !== null ? (float) $jornada['km_odo'] : null,
        'km_fuente' => $jornada['km_fuente'],
    ],
    // La app reanuda estos adjuntos desde el offset que dice el SERVIDOR.
    'adjuntos_incompletos' => array_map(static fn($e) => [
        'uuid'   => $e['uuid'],
        'offset' => (int) $e['offset_bytes'],
        'bytes'  => (int) $e['bytes_esperados'],
    ], $incompletas),
    'rastreo_flota' => $rastreoVivo,
    'servidor_utc'  => gmdate('c'),
]);
