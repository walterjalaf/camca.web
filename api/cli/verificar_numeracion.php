<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';

/**
 * Detector de huecos y repetidos en la numeración de R23/R28. Paso F1.4.
 *
 * Corre por cron una vez por día. No arregla nada: avisa. Un hueco no siempre
 * es un error —puede haber una emisión que falló y quedó sin registrar— pero
 * siempre es algo que alguien tiene que poder explicar, y es mucho más barato
 * explicarlo al día siguiente que seis meses después, cuando el cliente
 * pregunta por qué falta el R28-A-000042.
 *
 * Un repetido, en cambio, sí es un error grave: dos documentos distintos con
 * el mismo número.
 */

Cli::arrancar('verificar_numeracion');

$series = Numerador::auditar();

if ($series === []) {
    Cli::decir('Todavía no se emitió ningún documento numerado.');
    Cli::latir('verificar_numeracion', 'sin series');
    exit(0);
}

$problemas = 0;

foreach ($series as $s) {
    $linea = sprintf(
        '%s serie %s: %d emitidos, del %d al %d',
        $s['tipo'], $s['serie'], $s['emitidos'], $s['desde'], $s['hasta']
    );

    if ($s['repetidos'] > 0) {
        $problemas++;
        Cli::decir($linea . ' — ' . $s['repetidos'] . ' NUMEROS REPETIDOS. Dos documentos con el mismo número.');
        Log::error('numeracion_repetida', ['tipo' => $s['tipo'], 'serie' => $s['serie'], 'n' => $s['repetidos']]);
    }

    if ($s['huecos'] !== []) {
        $problemas++;
        $muestra = array_slice($s['huecos'], 0, 20);
        Cli::decir($linea . ' — ' . count($s['huecos']) . ' HUECOS: ' . implode(', ', $muestra) .
            (count($s['huecos']) > count($muestra) ? ' …' : ''));
        Log::error('numeracion_con_huecos', [
            'tipo' => $s['tipo'], 'serie' => $s['serie'], 'cuantos' => count($s['huecos']),
        ]);
    }

    // El hueco de la COLA: números que el contador ya consumió y que no
    // quedaron registrados. No están entre el mínimo y el máximo emitido, así
    // que la detección de huecos no los ve; y con la comparación anterior
    // (contador >= máximo) la serie se reportaba como sana.
    if ($s['huecos_cola'] !== []) {
        $problemas++;
        Cli::decir($linea . ' — el contador está en ' . $s['contador'] . ' y el último emitido es ' .
            $s['hasta'] . '. FALTAN: ' . implode(', ', array_slice($s['huecos_cola'], 0, 20)) .
            '. Son números consumidos que no quedaron registrados.');
        Log::error('numeracion_hueco_cola', [
            'tipo' => $s['tipo'], 'serie' => $s['serie'], 'cuantos' => count($s['huecos_cola']),
        ]);
    } elseif (!$s['contador_coherente']) {
        $problemas++;
        Cli::decir($linea . ' — EL CONTADOR QUEDO ATRAS (' . var_export($s['contador'], true) .
            '). La próxima emisión repetiría número.');
        Log::error('numeracion_contador_atrasado', ['tipo' => $s['tipo'], 'serie' => $s['serie']]);
    }

    if ($s['repetidos'] === 0 && $s['huecos'] === [] && $s['huecos_cola'] === [] && $s['contador_coherente']) {
        Cli::decir($linea . ' — sin huecos ni repetidos.');
    }
}

Cli::latir('verificar_numeracion', $problemas === 0 ? 'todo en orden' : $problemas . ' problemas');

// Se sale con 0 igual: es una herramienta de auditoría y no una alarma que
// tenga que romper el cron. Lo que importa queda en el log y en el latido.
exit(0);
