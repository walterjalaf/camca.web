<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Jornadas.
 *
 *   POST /api/v1/jornada/cerrar   (chofer)     cierra el dia
 *   GET  /api/v1/jornadas         (supervisor) el cierre del dia de la flota
 *
 * El listado del supervisor es lo que en Fase 0 reemplaza al panel de flota en
 * vivo: que se hizo, a que hora, con que evidencia, y —sobre todo— QUE FALTA
 * SINCRONIZAR. Ese ultimo dato es el que evita que alguien de por cerrado un
 * dia que todavia vive adentro de un telefono.
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ------------------------------------------------------------------
// Cierre del dia (chofer)
// ------------------------------------------------------------------
if ($metodo === 'POST') {
    $datos = Http::cuerpo();
    $v = new Validar($datos);
    $jornadaId = $v->entero('jornada_id', 1, 4294967295);
    $v->fin();

    $j = Db::una('SELECT id, estado, fecha, chofer_id FROM jornada WHERE id = :id', [':id' => $jornadaId]);
    if ($j === null) throw new ErrorNoEncontrado('No existe esa jornada.');

    // PERTENENCIA. Sin esto, cualquier chofer con una sesion valida podia
    // enumerar ids y cerrar la jornada de otro; y como abajo se hace
    // COALESCE(chofer_id, :c), ademas se la ADJUDICABA, y la cadena de
    // auditoria quedaba firmando que la habia cerrado el.
    //
    // La jornada la materializa el cron SIN chofer, y el chofer la toma al
    // trabajarla: por eso se acepta tambien la que no tiene dueño. Lo que no
    // se acepta es tocar la de otro.
    //
    // Va 404 y no 403 a proposito: un 403 confirma que esa jornada existe, y
    // eso ya es informacion. Es la misma regla que aplica evidencia.php.
    $duenio = $j['chofer_id'] === null ? null : (int) $j['chofer_id'];
    if ($duenio !== null && $duenio !== Policy::id()) {
        Log::aviso('jornada_ajena', ['jornada' => $jornadaId, 'quien' => Policy::id()]);
        throw new ErrorNoEncontrado('No existe esa jornada.');
    }

    // No se puede cerrar con paradas sin resolver: cada una tiene que estar
    // hecha o tener un motivo. Es el dato que despues reclama el cliente.
    // Va DESPUES de la comprobacion de pertenencia: al reves, el mensaje
    // "quedan N paradas sin resolver" contaba el estado de jornadas ajenas.
    $sinResolver = (int) Db::col(
        "SELECT COUNT(*) FROM parada_ejecucion WHERE jornada_id = :j AND estado = 'planificada'",
        [':j' => $jornadaId]
    );
    if ($sinResolver > 0) {
        throw new ErrorConflicto(
            "Quedan $sinResolver paradas sin resolver. Cada una tiene que estar hecha o tener un motivo.",
            'PARADAS_SIN_RESOLVER'
        );
    }

    // Se cierra como 'cerrada', NO como 'cerrada_confirmada': todavia pueden
    // faltar adjuntos por subir. La confirmacion la da el servidor cuando ya
    // no queda ninguna evidencia incompleta.
    $incompletas = (int) Db::col(
        'SELECT COUNT(*) FROM evidencia WHERE jornada_id = :j AND completa = 0',
        [':j' => $jornadaId]
    );
    $estado = $incompletas === 0 ? 'cerrada_confirmada' : 'cerrada';

    Db::q(
        'UPDATE jornada SET estado = :e, cerrada_utc = COALESCE(cerrada_utc, UTC_TIMESTAMP()),
                            chofer_id = COALESCE(chofer_id, :c)
          WHERE id = :id',
        [':e' => $estado, ':c' => Policy::id(), ':id' => $jornadaId]
    );

    Hash::auditar('jornada', $jornadaId, 'cerrada', [
        'chofer' => Policy::id(),
        'evidencias_pendientes' => $incompletas,
    ]);

    Http::ok([
        'estado' => $estado,
        'evidencias_pendientes' => $incompletas,
        'mensaje' => $incompletas === 0
            ? 'Jornada cerrada y confirmada.'
            : "Jornada cerrada. Faltan $incompletas evidencias por subir: se mandan solas cuando haya señal.",
    ]);
}

// ------------------------------------------------------------------
// Cierre del dia de la flota (supervisor)
// ------------------------------------------------------------------
$fecha = $_GET['fecha'] ?? gmdate('Y-m-d', time() - 3 * 3600);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha)) {
    throw new ErrorValidacion(['fecha' => 'Formato esperado AAAA-MM-DD.']);
}

$jornadas = Db::todas(
    "SELECT j.id, j.fecha, j.estado, j.km_odo, j.km_hav, j.km_fuente, j.cerrada_utc,
            r.nombre AS ruta, r.km_estimado,
            u.nombre AS chofer,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id) AS total,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id AND p.estado = 'ejecutada') AS hechas,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id AND p.estado = 'no_ejecutada') AS no_hechas,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id AND p.estado = 'planificada') AS pendientes,
            (SELECT COUNT(*) FROM evidencia e WHERE e.jornada_id = j.id AND e.completa = 0) AS evidencias_incompletas,
            (SELECT COUNT(*) FROM evidencia e WHERE e.jornada_id = j.id AND e.completa = 1) AS evidencias_ok,
            (SELECT COUNT(*) FROM parada_ejecucion p WHERE p.jornada_id = j.id AND p.ambiguo = 1) AS ambiguas
       FROM jornada j
       JOIN ruta_plantilla r ON r.id = j.ruta_id
       LEFT JOIN usuario u ON u.id = j.chofer_id
      WHERE j.fecha = :f
      ORDER BY r.dia_semana",
    [':f' => $fecha]
);

$salida = array_map(static function ($j) {
    $pendientes = (int) $j['pendientes'];
    $incompletas = (int) $j['evidencias_incompletas'];

    // Se dice explicitamente por que un dia NO esta cerrado de verdad. Sin
    // esto, un supervisor archiva como cerrado un dia al que le faltan doce
    // fotos que todavia viven adentro de un telefono.
    $alertas = [];
    $plural = static fn(int $n, string $uno, string $varios): string => $n . ' ' . ($n === 1 ? $uno : $varios);
    if ($pendientes > 0) {
        $alertas[] = $plural($pendientes, 'parada sin resolver', 'paradas sin resolver');
    }
    if ($incompletas > 0) {
        $alertas[] = $plural($incompletas, 'evidencia sin terminar de subir', 'evidencias sin terminar de subir');
    }
    if ((int) $j['ambiguas'] > 0) {
        $alertas[] = $plural((int) $j['ambiguas'], 'parada marcada como ambigua', 'paradas marcadas como ambiguas')
            . ' (el GPS no pudo distinguirlas)';
    }

    return [
        'id'        => (int) $j['id'],
        'fecha'     => $j['fecha'],
        'ruta'      => $j['ruta'],
        'chofer'    => $j['chofer'],
        'estado'    => $j['estado'],
        'cerrada'   => $j['cerrada_utc'],
        'total'     => (int) $j['total'],
        'hechas'    => (int) $j['hechas'],
        'no_hechas' => (int) $j['no_hechas'],
        'pendientes' => $pendientes,
        'evidencias' => ['ok' => (int) $j['evidencias_ok'], 'incompletas' => $incompletas],
        'km_estimado' => (float) $j['km_estimado'],
        'km_real'     => $j['km_odo'] !== null ? (float) $j['km_odo'] : ($j['km_hav'] !== null ? (float) $j['km_hav'] : null),
        // Los km por haversine inflan ~9% por ruido de GPS. Se dice de donde
        // salen, porque es un numero que puede terminar en una factura.
        'km_fuente'   => $j['km_fuente'],
        'alertas'     => $alertas,
        'confiable'   => $alertas === [],
    ];
}, $jornadas);

Http::ok([
    'fecha'    => $fecha,
    'jornadas' => $salida,
    'resumen'  => [
        'jornadas'    => count($salida),
        'con_alertas' => count(array_filter($salida, static fn($j) => $j['alertas'] !== [])),
    ],
]);
