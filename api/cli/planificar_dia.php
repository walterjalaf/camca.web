<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';

/**
 * Materializa la jornada del dia siguiente expandiendo la plantilla de ruta.
 *
 * POR QUE ES UN CRON Y NO SE CREA AL SINCRONIZAR:
 * Sin una jornada 'planificada' en el servidor no existe el plan contra el
 * cual medir el desvio, que es literalmente el Objetivo 1 del presupuesto. Y
 * peor: si el telefono del chofer se rompe a las 16:30 con ocho paradas
 * cargadas y sin sincronizar, el supervisor abre el panel y ve un dia VACIO,
 * indistinguible de "el chofer no salio". Con la jornada materializada ve
 * catorce paradas planificadas y ninguna ejecutada, que es una historia muy
 * distinta y la correcta.
 *
 * Idempotente: correrlo diez veces deja una sola jornada por dia y ruta.
 */

Cli::arrancar('planificar_dia');

/** Fecha operativa en ART (UTC-3). Usar la fecha local del servidor daria el
 *  dia equivocado entre las 21 y la medianoche. */
function fechaArt(int $desplazamientoDias = 0): string
{
    return gmdate('Y-m-d', time() - 3 * 3600 + $desplazamientoDias * 86400);
}

$dias = [fechaArt(0), fechaArt(1)];   // hoy (por si nunca corrio) y maniana
$creadas = 0;
$paradas = 0;

foreach ($dias as $fecha) {
    // ISO: 1 = lunes .. 7 = domingo. El domingo no hay ruta.
    $diaSemana = (int) date('N', strtotime($fecha));
    if ($diaSemana === 7) continue;

    $ruta = Db::una(
        'SELECT id, nombre, km_estimado FROM ruta_plantilla WHERE dia_semana = :d AND activa = 1',
        [':d' => $diaSemana]
    );
    if ($ruta === null) {
        Cli::decir("Sin ruta activa para el dia $diaSemana ($fecha).");
        continue;
    }

    $jornadaId = Db::col(
        'SELECT id FROM jornada WHERE fecha = :f AND ruta_id = :r',
        [':f' => $fecha, ':r' => $ruta['id']]
    );

    if ($jornadaId === null) {
        Db::q(
            'INSERT INTO jornada (fecha, ruta_id, estado, creado_utc)
             VALUES (:f, :r, :e, UTC_TIMESTAMP())',
            [':f' => $fecha, ':r' => $ruta['id'], ':e' => 'planificada']
        );
        $jornadaId = Db::insertarId();
        $creadas++;
        Cli::decir("Jornada creada: $fecha · {$ruta['nombre']}");
    }

    // Las paradas se insertan con INSERT IGNORE sobre (jornada_id, orden):
    // si la plantilla cambio, las nuevas entran y las ya ejecutadas no se pisan.
    $plantilla = Db::todas(
        'SELECT orden, sitio_id, cantidad FROM parada_plantilla
          WHERE ruta_id = :r AND activa = 1 ORDER BY orden',
        [':r' => $ruta['id']]
    );

    foreach ($plantilla as $p) {
        $existe = Db::col(
            'SELECT id FROM parada_ejecucion WHERE jornada_id = :j AND orden = :o',
            [':j' => $jornadaId, ':o' => $p['orden']]
        );
        if ($existe !== null) continue;

        // El cluster viaja a la parada para que la app sepa, sin consultar
        // nada mas, que esta parada comparte coordenada con otra del dia y
        // que hay que desambiguar a mano.
        $cluster = Db::col('SELECT cluster_id FROM sitio WHERE id = :s', [':s' => $p['sitio_id']]);

        Db::q(
            'INSERT INTO parada_ejecucion (jornada_id, sitio_id, orden, cantidad_plan, estado, cluster_id)
             VALUES (:j, :s, :o, :c, :e, :cl)',
            [
                ':j'  => $jornadaId,
                ':s'  => $p['sitio_id'],
                ':o'  => $p['orden'],
                ':c'  => $p['cantidad'],
                ':e'  => 'planificada',
                ':cl' => $cluster,
            ]
        );
        $paradas++;
    }
}

Cli::latir('planificar_dia', "$creadas jornadas, $paradas paradas");
Cli::decir("Listo: $creadas jornadas nuevas, $paradas paradas planificadas.");
