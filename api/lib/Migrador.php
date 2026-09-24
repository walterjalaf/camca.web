<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Aplica las migraciones pendientes. Lo usan el cron (`cli/migrar.php`) y el
 * instalador de la primera puesta en marcha (`handlers/instalar.php`): la
 * misma lógica y los mismos controles, en un solo lugar.
 *
 * Reglas que vienen de fallas concretas (ver cli/migrar.php):
 *  - SÓLO MIGRACIONES ADITIVAS: se rechazan DROP, RENAME y TRUNCATE.
 *  - Sin transacción para DDL (MySQL hace commit implícito): se registra
 *    «iniciada» antes y «terminada» después; una iniciada sin terminar NO se
 *    reintenta: se planta y se alerta.
 *  - El sha256 de cada .sql se compara contra MANIFEST.json en cada corrida.
 */
final class Migrador
{
    /**
     * @param callable(string):void $decir  progreso, línea por línea
     * @return list<string>|null los archivos aplicados en esta corrida; null si
     *                           no hay carpeta de migraciones (un deploy roto:
     *                           el cron no late, para que el health lo marque)
     * @throws ErrorMigracion con el motivo, si algo no se puede aplicar
     */
    public static function aplicar(callable $decir): ?array
    {
        $dir = dirname(__DIR__) . '/migraciones';
        if (!is_dir($dir)) { $decir('No hay carpeta de migraciones.'); return null; }

        $marcadorFallo = Config::priv() . '/MIGRACION_FALLIDA';
        if (is_file($marcadorFallo)) {
            throw new ErrorMigracion('Hay una migracion fallida sin resolver. Revisar ' . $marcadorFallo . ' y borrarlo a mano.');
        }
        $rutaManifiesto = $dir . '/MANIFEST.json';
        if (!is_file($rutaManifiesto)) throw new ErrorMigracion('Falta MANIFEST.json: no se puede verificar la integridad de los .sql.');
        $manifiesto = json_decode((string) file_get_contents($rutaManifiesto), true) ?: [];

        Db::q('CREATE TABLE IF NOT EXISTS migracion (
                version      INT UNSIGNED NOT NULL PRIMARY KEY,
                archivo      VARCHAR(190) NOT NULL,
                sha256       CHAR(64) NOT NULL,
                iniciada_utc DATETIME NOT NULL,
                terminada_utc DATETIME NULL,
                intentos     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                error        TEXT NULL
              ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $colgada = Db::una('SELECT version, archivo, intentos FROM migracion WHERE terminada_utc IS NULL ORDER BY version LIMIT 1');
        if ($colgada !== null) {
            @file_put_contents($marcadorFallo, json_encode($colgada) . PHP_EOL, FILE_APPEND);
            throw new ErrorMigracion("La migracion {$colgada['archivo']} quedo a medias. MySQL no hace rollback de DDL: hay que revisarla a mano.");
        }

        $aplicadas = [];
        foreach (Db::todas('SELECT version, sha256, archivo FROM migracion WHERE terminada_utc IS NOT NULL') as $f) {
            $aplicadas[(int) $f['version']] = $f;
        }
        foreach ($aplicadas as $f) {
            $hashActual = $manifiesto[$f['archivo']] ?? null;
            if ($hashActual !== null && !hash_equals($f['sha256'], $hashActual)) {
                throw new ErrorMigracion("La migracion {$f['archivo']} cambio DESPUES de aplicarse. Las migraciones son inmutables: escribi una nueva.");
            }
        }

        $archivos = array_values(array_filter(scandir($dir) ?: [], static fn($n) => (bool) preg_match('/^\d{4}_.+\.sql$/', $n)));
        sort($archivos);
        $pendientes = [];
        foreach ($archivos as $nombre) {
            $version = (int) substr($nombre, 0, 4);
            if (!isset($aplicadas[$version])) $pendientes[$version] = $nombre;
        }
        if ($pendientes === []) return [];

        $decir('Migraciones pendientes: ' . implode(', ', $pendientes));
        $hechas = [];
        foreach ($pendientes as $version => $nombre) {
            $sql = (string) file_get_contents($dir . '/' . $nombre);
            $hash = hash('sha256', $sql);
            $esperado = $manifiesto[$nombre] ?? null;
            if ($esperado === null)             throw new ErrorMigracion("$nombre no figura en MANIFEST.json.");
            if (!hash_equals($esperado, $hash)) throw new ErrorMigracion("$nombre no coincide con su hash: podria estar a medio subir.");
            if (!str_ends_with(rtrim($sql), '-- FIN')) throw new ErrorMigracion("$nombre no termina en '-- FIN'.");
            if (preg_match('/\b(DROP\s+(TABLE|DATABASE|COLUMN)|RENAME\s+TABLE|TRUNCATE)\b/i', $sql, $m)) {
                throw new ErrorMigracion("$nombre contiene '{$m[0]}'. Solo se aceptan migraciones aditivas.");
            }

            Db::q('INSERT INTO migracion (version, archivo, sha256, iniciada_utc, intentos) VALUES (:v, :a, :h, UTC_TIMESTAMP(), 1)',
                  [':v' => $version, ':a' => $nombre, ':h' => $hash]);
            try {
                foreach (preg_split('/;\s*$/m', $sql) as $sentencia) {
                    $sentencia = trim(preg_replace('/^--.*$/m', '', $sentencia) ?? '');
                    if ($sentencia === '') continue;
                    Db::pdo()->exec($sentencia);
                }
            } catch (Throwable $e) {
                Db::q('UPDATE migracion SET error = :e WHERE version = :v', [':e' => mb_substr($e->getMessage(), 0, 2000), ':v' => $version]);
                @file_put_contents($marcadorFallo, $nombre . ': ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                throw new ErrorMigracion("Fallo $nombre: " . $e->getMessage());
            }
            Db::q('UPDATE migracion SET terminada_utc = UTC_TIMESTAMP() WHERE version = :v', [':v' => $version]);
            $decir("Aplicada $nombre");
            $hechas[] = $nombre;
        }
        return $hechas;
    }
}

final class ErrorMigracion extends RuntimeException {}
