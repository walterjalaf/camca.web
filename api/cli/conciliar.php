<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';

/**
 * Concilia teléfono vs. flota de las jornadas recientes. Paso F1.5.
 *
 * Corre por cron unas horas después del cierre, y vuelve a correr sobre los
 * días anteriores: el rastro de flota llega por poll y puede completarse
 * mucho después de que el chofer cerró la jornada. Conciliar una sola vez, a
 * la hora del cierre, dejaría como "sólo teléfono" paradas que dos horas más
 * tarde tendrían doble evidencia.
 *
 * Es idempotente: volver a correrlo sobre la misma jornada recalcula y pisa.
 */

Cli::arrancar('conciliar');

$dias = (int) ($argv[1] ?? 7);
if ($dias < 1 || $dias > 60) $dias = 7;

$jornadas = Db::todas(
    "SELECT id, fecha FROM jornada
      WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
        AND estado IN ('cerrada','cerrada_confirmada','en_curso')
      ORDER BY fecha DESC, id",
    [':d' => $dias]
);

if ($jornadas === []) {
    Cli::decir('No hay jornadas para conciliar.');
    Cli::latir('conciliar', 'sin jornadas');
    exit(0);
}

$totales = array_fill_keys(
    ['doble', 'solo_flota', 'solo_telefono', 'contradiccion', 'sin_evidencia'], 0
);
$sinCobertura = 0;

foreach ($jornadas as $j) {
    try {
        $r = Conciliador::jornada((int) $j['id']);
    } catch (Throwable $e) {
        // Una jornada rota no puede dejar sin conciliar a las demás.
        Log::error('conciliar_jornada', ['jornada' => (int) $j['id'], 'msg' => $e->getMessage()]);
        Cli::decir("Jornada {$j['id']} ({$j['fecha']}): FALLO — " . $e->getMessage());
        continue;
    }

    foreach ($r['conteo'] as $clase => $n) $totales[$clase] += $n;
    if (!$r['cobertura']) $sinCobertura++;

    $partes = [];
    foreach ($r['conteo'] as $clase => $n) if ($n > 0) $partes[] = "$n $clase";

    Cli::decir(sprintf(
        'Jornada %d (%s): %d paradas, %d posiciones de flota — %s%s',
        $j['id'], $j['fecha'], $r['paradas'], $r['posiciones'],
        $partes === [] ? 'nada que conciliar' : implode(', ', $partes),
        $r['cobertura'] ? '' : ' [SIN COBERTURA DE FLOTA]'
    ));

    // Una contradicción no se resuelve sola: alguien la tiene que mirar.
    if ($r['conteo']['contradiccion'] > 0) {
        Log::aviso('conciliacion_contradiccion', [
            'jornada' => (int) $j['id'], 'fecha' => $j['fecha'], 'n' => $r['conteo']['contradiccion'],
        ]);
    }
}

Cli::decir('Totales: ' . json_encode($totales, JSON_UNESCAPED_UNICODE) .
    ($sinCobertura > 0 ? " · $sinCobertura jornadas sin cobertura de flota" : ''));
Cli::latir('conciliar', count($jornadas) . ' jornadas');
exit(0);
