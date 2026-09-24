<?php
declare(strict_types=1);

// GUARDA DE SAPI — primera linea util del archivo, sin excepciones.
// Sin esto, /api/cli/<script>.php seria ejecutable por HTTP: sin bootstrap,
// sin sesion y sin rate limit. scripts/guard.mjs verifica que este presente
// en TODOS los archivos de cli/ y rompe el build si falta en alguno.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Base comun de los scripts de cron.
 */
final class Cli
{
    private static $lock = null;

    /** Escribe a stdout con marca de tiempo (queda en el log del cron de hPanel). */
    public static function decir(string $msg): void
    {
        fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . PHP_EOL);
    }

    public static function fallar(string $msg, int $codigo = 1): never
    {
        fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . 'Z] ERROR: ' . $msg . PHP_EOL);
        Log::error('cron_error', ['msg' => $msg]);
        exit($codigo);
    }

    /**
     * Arranque de todo cron.
     *
     * Si hay un deploy en curso, SALE SIN HACER NADA. Un cron que corre en
     * medio de un sync FTP puede leer un .sql a medio subir o una libreria
     * incompleta. Vale para todos los crons, no solo para migrar.
     */
    public static function arrancar(string $tarea): void
    {
        if (camca_centinela_mantenimiento()) {
            self::decir("[$tarea] deploy en curso, se omite esta corrida.");
            exit(0);
        }
        if (!self::tomarLock($tarea)) {
            self::decir("[$tarea] ya hay una corrida viva, se omite.");
            exit(0);
        }
    }

    /**
     * Lock por archivo, NO bloqueante: si la corrida anterior sigue viva,
     * esta se va en silencio en vez de apilarse. Con un cron cada 2 minutos
     * y una corrida lenta, apilarse es como se llega al error 508.
     */
    private static function tomarLock(string $tarea): bool
    {
        $dir = Config::get('rutas.estado', Config::priv() . '/estado');
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $fh = @fopen($dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $tarea) . '.lock', 'c');
        if ($fh === false) return true;   // sin lock es peor no correr
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        self::$lock = $fh;
        return true;
    }

    /**
     * Latido. _health mira esta tabla: si el poll de GPS o el backup dejan
     * de correr, nadie se entera hasta que hace falta el backup, y eso es tarde.
     */
    public static function latir(string $tarea, string $detalle = ''): void
    {
        try {
            Db::q(
                'INSERT INTO heartbeat (tarea, visto_utc, detalle) VALUES (:t, UTC_TIMESTAMP(), :d)
                 ON DUPLICATE KEY UPDATE visto_utc = UTC_TIMESTAMP(), detalle = VALUES(detalle)',
                [':t' => $tarea, ':d' => mb_substr($detalle, 0, 255)]
            );
        } catch (Throwable $e) {
            self::decir("[$tarea] no se pudo registrar el latido: " . $e->getMessage());
        }
    }
}
