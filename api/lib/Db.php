<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Capa PDO. Conexión perezosa y NO persistente: en shared hosting las
 * conexiones persistentes consumen el cupo de procesos del plan y son
 * una de las vías al error 508 en el pico de las 20:00.
 *
 * Todo se guarda y se compara en UTC (time_zone='+00:00'); la conversión
 * a hora de Argentina se hace al mostrar, nunca al persistir.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            Config::get('db.host'),
            Config::get('db.nombre')
        );
        try {
            self::$pdo = new PDO($dsn, Config::get('db.usuario'), Config::get('db.clave'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
            ]);
        } catch (PDOException $e) {
            // El mensaje crudo de PDO trae usuario@host (y segun el driver, mas):
            // se registra solo el SQLSTATE, que es lo unico util para diagnosticar.
            Log::error('db_conexion', ['sqlstate' => $e->getCode(), 'driver' => (int) ($e->errorInfo[1] ?? 0)]);
            throw new ErrorMantenimiento('DB_NO_DISPONIBLE', 'Base de datos no disponible.');
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function una(string $sql, array $params = []): ?array
    {
        $fila = self::q($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    public static function todas(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function col(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insertarId(): int { return (int) self::pdo()->lastInsertId(); }

    /** Transacción con rollback automático. Ojo: el DDL auto-commitea en MySQL. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn($pdo);
        $pdo->beginTransaction();
        try {
            $r = $fn($pdo);
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Transacción que se reintenta si InnoDB la eligió como víctima.
     *
     * Un deadlock (1213) o un lock wait timeout (1205) NO son bugs: son la
     * forma normal en que InnoDB resuelve dos transacciones que se pisan. El
     * motor desarma una entera —incluido, por ejemplo, el número correlativo
     * que se había tomado— y el remedio estándar es volver a intentar.
     *
     * Se usa SÓLO donde la operación es segura de repetir: la transacción
     * revierte del todo, así que reintentar no duplica nada.
     *
     * Ojo: si ya hay una transacción abierta no se reintenta nada, porque el
     * rollback no sería nuestro. En ese caso se propaga.
     */
    public static function txReintentable(callable $fn, int $intentos = 3): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn($pdo);

        for ($i = 1; ; $i++) {
            try {
                return self::tx($fn);
            } catch (PDOException $e) {
                $codigo = (int) ($e->errorInfo[1] ?? 0);
                if (($codigo !== 1213 && $codigo !== 1205) || $i >= $intentos) throw $e;
                Log::aviso('tx_reintento', ['error' => $codigo, 'intento' => $i]);
                // Espera corta y creciente con algo de ruido: si las dos
                // transacciones esperaran lo mismo volverían a chocar.
                usleep(random_int(20000, 60000) * $i);
            }
        }
    }

    /** ¿La excepción es una violación de UNIQUE? Es la base de la idempotencia del sync. */
    public static function esDuplicado(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** Cierra antes de operaciones largas de disco (backup, empaquetado). */
    public static function cerrar(): void { self::$pdo = null; }
}
