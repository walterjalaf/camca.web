<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/lib/WialonClient.php';

/**
 * Muestreo del rastro de flota. Cron cada 2 minutos.
 *
 * Es la red de seguridad del sistema: si el telefono del chofer se rompe a las
 * 16:30 con ocho paradas sin subir, esto es lo unico que permite reconstruir
 * por donde anduvo el camion y a que hora estuvo en cada lugar.
 *
 * Y es la fuente AUTORITATIVA del arribo, porque no depende de que la pantalla
 * del telefono este encendida ni de cuanta bateria quede. El dwell del
 * telefono corrobora; este manda.
 *
 * Todo es idempotente: UNIQUE (unidad, ts) hace que correrlo dos veces no
 * agregue filas, lo que permite reintentar sin pensar.
 */

Cli::arrancar('gps_poll');

$cliente = new WialonClient();

try {
    $cliente->conectar();
} catch (Throwable $e) {
    WialonClient::registrarFallo($e);
    Cli::decir('No se pudo conectar con Wialon: ' . $e->getMessage());
    // Salida 0 a proposito: que Wialon este caido no es un fallo del cron, y
    // no tiene que llenar de alertas rojas el panel. La app de campo sigue
    // andando con el GPS del propio telefono.
    exit(0);
}

$nuevas = 0;
$posiciones = 0;

try {
    foreach ($cliente->unidades() as $u) {
        $wialonId = (int) ($u['id'] ?? 0);
        $nombre = (string) ($u['nm'] ?? '');
        if ($wialonId === 0) continue;

        // Alta automatica de la unidad, enlazada al vehiculo si la patente coincide.
        $unidadId = Db::col('SELECT id FROM gps_unidad WHERE wialon_id = :w', [':w' => $wialonId]);
        if ($unidadId === null) {
            $vehiculoId = Db::col(
                'SELECT id FROM vehiculo WHERE patente = :p',
                [':p' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nombre) ?? '')]
            );
            Db::q(
                'INSERT INTO gps_unidad (wialon_id, nombre, vehiculo_id, activa, creado_utc)
                 VALUES (:w, :n, :v, 1, UTC_TIMESTAMP())',
                [':w' => $wialonId, ':n' => $nombre, ':v' => $vehiculoId]
            );
            $unidadId = Db::insertarId();
            $nuevas++;
            Cli::decir("Unidad nueva: $nombre");
        }

        // pos viene en el ultimo mensaje (flag 1024). y=lat, x=lon, s=velocidad,
        // c=curso, sc=satelites, t=timestamp. Asi lo usaba el prototipo.
        $pos = $u['pos'] ?? null;
        if (!is_array($pos) || !isset($pos['y'], $pos['x'], $pos['t'])) continue;

        // El odometro viene en los contadores (flag 8192), en metros.
        $odo = null;
        if (isset($u['cnm']) && is_numeric($u['cnm'])) $odo = (int) $u['cnm'];
        elseif (isset($u['prms']['odo']['v']) && is_numeric($u['prms']['odo']['v'])) $odo = (int) $u['prms']['odo']['v'];

        try {
            Db::q(
                'INSERT INTO gps_posicion (unidad_id, ts_utc, lat, lon, velocidad, curso, satelites, odo_m)
                 VALUES (:u, FROM_UNIXTIME(:t), :la, :lo, :v, :c, :s, :o)',
                [
                    ':u'  => $unidadId,
                    ':t'  => (int) $pos['t'],
                    ':la' => (float) $pos['y'],
                    ':lo' => (float) $pos['x'],
                    ':v'  => isset($pos['s']) ? (int) $pos['s'] : null,
                    ':c'  => isset($pos['c']) ? (int) $pos['c'] : null,
                    ':s'  => isset($pos['sc']) ? (int) $pos['sc'] : null,
                    ':o'  => $odo,
                ]
            );
            $posiciones++;

            // Primera vez que se ve el odometro en esta unidad: se anota si
            // reporta o no. Sin odometro los km son estimados por posiciones y
            // el haversine infla ~9% por ruido: hay que decirlo asi y no
            // presentarlo como dato de odometro.
            if ($odo !== null) {
                Db::q('UPDATE gps_unidad SET reporta_odo = 1 WHERE id = :u AND reporta_odo IS NULL', [':u' => $unidadId]);
            }
        } catch (PDOException $e) {
            // Duplicado = la posicion no cambio desde la corrida anterior.
            // Es lo normal con el camion detenido, no un error.
            if (!Db::esDuplicado($e)) throw $e;
        }
    }

    Db::q(
        'INSERT INTO gps_estado (id, ultimo_ok_utc, ultimo_error, backoff_hasta)
         VALUES (1, UTC_TIMESTAMP(), NULL, NULL)
         ON DUPLICATE KEY UPDATE ultimo_ok_utc = UTC_TIMESTAMP(), ultimo_error = NULL, backoff_hasta = NULL'
    );
} catch (Throwable $e) {
    WialonClient::registrarFallo($e);
    Cli::decir('Fallo el muestreo: ' . $e->getMessage());
} finally {
    $cliente->desconectar();
}

Cli::latir('gps_poll', "$posiciones posiciones, $nuevas unidades nuevas");
Cli::decir("Posiciones nuevas: $posiciones · unidades nuevas: $nuevas");
