<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * El circuito de cada trabajo, para la oficina. Pasos F2.1 a F2.3.
 *
 *   GET  /api/v1/trabajos?fecha=AAAA-MM-DD   las paradas del día con su flujo,
 *                                            sus documentos y lo que se puede hacer
 *   POST /api/v1/trabajo/mover               mueve un trabajo por el circuito
 *
 * Hasta acá Trabajo::mover() existía y estaba probado, pero ningún handler lo
 * llamaba: la oficina no tenía cómo verificar un trabajo, y sin trabajo
 * verificado no hay remito. Esto es la puerta.
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'POST') {
    $datos = Http::cuerpo();
    $v = new Validar($datos);
    $paradaId = $v->entero('parada_id', 1, PHP_INT_MAX);
    $hacia = $v->enum('hacia', Trabajo::ESTADOS);
    $motivo = $v->texto('motivo', 3, 255, false);
    $v->fin();

    // Verificar un trabajo que el chofer ya cerró como hecho no puede exigirle
    // a la oficina que antes lo pase a mano por en_curso y ejecutado: eso lo
    // dijo el campo. Se alinea por mover(), así cada paso queda en la historia.
    //
    // Todo va en una transacción: si la verificación se rechaza (por ejemplo,
    // porque la firma todavía no llegó), los pasos de alineación se deshacen
    // con ella. Si no, el endpoint contestaría «no» y el trabajo quedaría
    // igual movido dos casilleros.
    // «facturable» y «facturado» los mueve SÓLO la facturación (F3.3/F3.4),
    // que es de administración y deja una propuesta con número detrás. Por
    // esta puerta, un supervisor marcaba trabajo como facturado sin factura
    // (y nunca se cobraba) o trababa para siempre una propuesta en borrador
    // (revisión de la Fase 3).
    $soloFacturacion = ['facturable', 'facturado'];
    if (in_array($hacia, $soloFacturacion, true)) {
        throw new ErrorConflicto('Eso lo hace la facturación: se arma, aprueba o descarta la propuesta en Facturación.', 'SOLO_FACTURACION');
    }

    [$alineados, $r] = Db::txReintentable(static function () use ($paradaId, $hacia, $motivo, $soloFacturacion): array {
        $flujo = Db::col('SELECT flujo FROM parada_ejecucion WHERE id = :p FOR UPDATE', [':p' => $paradaId]);
        if (in_array($flujo, $soloFacturacion, true)) {
            throw new ErrorConflicto('Ese trabajo está en facturación: se saca quitando su línea o descartando la propuesta.', 'EN_FACTURACION');
        }
        $alineados = $hacia === 'verificado' ? Trabajo::alinearConCampo($paradaId, Policy::id()) : [];
        $r = Trabajo::mover($paradaId, $hacia, [
            'usuario_id' => Policy::id(),
            'motivo'     => $motivo,
        ]);
        return [$alineados, $r];
    });

    Http::ok([
        'movimiento' => $r,
        'alineados'  => $alineados,
        'permitidos' => Trabajo::permitidos($hacia),
    ]);
}

// ------------------------------------------------------------------
// Listado del día
// ------------------------------------------------------------------
$fecha = $_GET['fecha'] ?? gmdate('Y-m-d', time() - 3 * 3600);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha)) {
    throw new ErrorValidacion(['fecha' => 'Formato esperado AAAA-MM-DD.']);
}

$paradas = Db::todas(
    "SELECT pe.id, pe.jornada_id, pe.orden, pe.estado, pe.flujo, pe.motivo,
            pe.cantidad_plan, pe.cantidad_real, pe.conciliacion,
            s.nombre AS sitio, c.nombre AS cliente,
            r.nombre AS ruta,
            (SELECT COUNT(*) FROM evidencia e WHERE e.parada_id = pe.id AND e.completa = 1) AS ev_ok,
            (SELECT COUNT(*) FROM evidencia e WHERE e.parada_id = pe.id AND e.completa = 0) AS ev_incompletas,
            (SELECT g.id FROM registro g WHERE g.unico_activo = CONCAT('R28-', pe.id)) AS r28_id,
            (SELECT g.numero FROM registro g WHERE g.unico_activo = CONCAT('R28-', pe.id)) AS r28_numero,
            (SELECT m.id FROM remito m WHERE m.unico_activo = CONCAT('REM-', pe.id)) AS remito_id,
            (SELECT m.numero FROM remito m WHERE m.unico_activo = CONCAT('REM-', pe.id)) AS remito_numero,
            (SELECT m.conformidad FROM remito m WHERE m.unico_activo = CONCAT('REM-', pe.id)) AS remito_conformidad,
            (SELECT e.firma_alg FROM remito_eslabon e JOIN remito m ON m.id = e.remito_id
              WHERE m.unico_activo = CONCAT('REM-', pe.id) AND e.tipo = 'emision') AS remito_firma
       FROM parada_ejecucion pe
       JOIN jornada j        ON j.id = pe.jornada_id
       JOIN ruta_plantilla r ON r.id = j.ruta_id
       JOIN sitio s          ON s.id = pe.sitio_id
  LEFT JOIN cliente c        ON c.id = s.cliente_id
      WHERE j.fecha = :f
      ORDER BY r.dia_semana, pe.jornada_id, pe.orden",
    [':f' => $fecha]
);

$salida = array_map(static function (array $p): array {
    $flujo = (string) $p['flujo'];
    return [
        'id'            => (int) $p['id'],
        'jornada_id'    => (int) $p['jornada_id'],
        'ruta'          => $p['ruta'],
        'orden'         => (int) $p['orden'],
        'sitio'         => $p['sitio'],
        'cliente'       => $p['cliente'],
        'estado'        => $p['estado'],
        'flujo'         => $flujo,
        'motivo'        => $p['motivo'],
        'cantidad_plan' => (int) $p['cantidad_plan'],
        'cantidad_real' => $p['cantidad_real'] === null ? null : (int) $p['cantidad_real'],
        'conciliacion'  => $p['conciliacion'],
        'evidencias'    => ['ok' => (int) $p['ev_ok'], 'incompletas' => (int) $p['ev_incompletas']],
        'r28'    => $p['r28_id'] === null ? null : ['id' => (int) $p['r28_id'], 'numero' => $p['r28_numero']],
        'remito' => $p['remito_id'] === null ? null : [
            'id' => (int) $p['remito_id'], 'numero' => $p['remito_numero'], 'conformidad' => $p['remito_conformidad'],
            'firma' => $p['remito_firma'] ?? 'ninguna',
        ],
        // Lo que la pantalla puede ofrecer. Sale de la misma tabla que usa
        // mover(): la pantalla no puede ofrecer un camino que el servidor
        // después rechaza por inexistente.
        'permitidos'    => Trabajo::permitidos($flujo),
    ];
}, $paradas);

Http::ok([
    'fecha'    => $fecha,
    // La pantalla ofrece «Certificar» sólo si hay con qué: sin clave de
    // firma, el botón no existe, en vez de existir y fallar siempre.
    'firma'    => Firma::alg(),
    'trabajos' => $salida,
    'resumen'  => array_count_values(array_column($salida, 'flujo')),
]);
