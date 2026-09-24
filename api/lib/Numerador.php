<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Correlativo sin huecos por (tipo, serie). Obj. 1, paso F1.4.
 *
 * No se usa AUTO_INCREMENT a propósito: deja huecos por diseño, porque una
 * transacción que aborta consume el número igual y MySQL no lo devuelve. Para
 * un id interno da lo mismo; para un correlativo que después alguien tiene que
 * explicar —"por qué falta el R28-A-000042"— no da lo mismo en absoluto.
 *
 * REGLA DE USO: siguiente() se llama SIEMPRE dentro de la misma transacción
 * que inserta el documento. Si se llamara antes, un fallo posterior dejaría el
 * número consumido y el hueco que se quería evitar.
 */
final class Numerador
{
    /** Cuántos dígitos tiene la parte numérica. */
    private const ANCHO = 6;

    /**
     * Toma el número siguiente de una serie y lo consume.
     *
     * El SELECT ... FOR UPDATE serializa a quien emita la misma serie al mismo
     * tiempo. Es el precio de que no haya huecos, y es barato: emitir no es una
     * operación de alta frecuencia.
     */
    public static function siguiente(string $tipo, string $serie): int
    {
        if (!Db::pdo()->inTransaction()) {
            throw new LogicError_NumeradorFueraDeTx();
        }

        // UN SOLO statement para incrementar, y recién después leer.
        //
        // La versión anterior hacía INSERT ... ON DUPLICATE KEY UPDATE y
        // después SELECT ... FOR UPDATE. Con 50 emisiones simultáneas eso
        // producía deadlocks de verdad (SQLSTATE 40001, error 1213), y no por
        // mala suerte: un INSERT ... ON DUPLICATE KEY UPDATE sobre una clave
        // que ya existe toma primero un lock COMPARTIDO y después lo sube a
        // exclusivo. Dos transacciones haciendo lo mismo a la vez se quedan
        // cada una esperando que la otra suelte el compartido. Es el patrón de
        // deadlock más conocido de ese statement.
        //
        // El UPDATE directo toma el lock exclusivo de una, sin escalón
        // intermedio, y el SELECT posterior ve el cambio propio porque estamos
        // en la misma transacción. El lock se suelta al commitear.
        $st = Db::q(
            'UPDATE numerador SET ultimo = ultimo + 1, actualizado_utc = UTC_TIMESTAMP()
              WHERE tipo = :t AND serie = :s',
            [':t' => $tipo, ':s' => $serie]
        );

        if ($st->rowCount() === 0) {
            // Serie nueva: la fila todavía no existe. Este camino se recorre
            // una sola vez en la vida de cada serie y no es concurrente en la
            // práctica; aun así el INSERT IGNORE tolera que dos lleguen juntos.
            Db::q(
                'INSERT IGNORE INTO numerador (tipo, serie, ultimo, actualizado_utc)
                 VALUES (:t, :s, 0, UTC_TIMESTAMP())',
                [':t' => $tipo, ':s' => $serie]
            );
            Db::q(
                'UPDATE numerador SET ultimo = ultimo + 1, actualizado_utc = UTC_TIMESTAMP()
                  WHERE tipo = :t AND serie = :s',
                [':t' => $tipo, ':s' => $serie]
            );
        }

        return (int) Db::col(
            'SELECT ultimo FROM numerador WHERE tipo = :t AND serie = :s',
            [':t' => $tipo, ':s' => $serie]
        );
    }

    /** R28-A-000042 */
    public static function formato(string $tipo, string $serie, int $n): string
    {
        return $tipo . '-' . $serie . '-' . str_pad((string) $n, self::ANCHO, '0', STR_PAD_LEFT);
    }

    /**
     * Busca huecos y repetidos en las series emitidas.
     *
     * Un hueco no siempre es un error —puede haber una emisión que falló y
     * quedó sin registrar— pero SIEMPRE es algo que alguien tiene que poder
     * explicar. Por eso esto es una herramienta de auditoría y no una alarma.
     */
    public static function auditar(): array
    {
        // Todo lo que consume numeración, de todas las tablas que la usan. Un
        // documento nuevo con número propio (el remito, F2.1) que no se sume
        // acá queda afuera de la auditoría sin que nadie lo note: pasó.
        $fuente = '(SELECT tipo, serie, numero_seq FROM registro WHERE numero_seq IS NOT NULL
                    UNION ALL
                    SELECT \'REM\' AS tipo, serie, numero_seq FROM remito
                    UNION ALL
                    SELECT \'PF\' AS tipo, serie, numero_seq FROM factura_propuesta WHERE numero_seq IS NOT NULL
                    UNION ALL
                    SELECT IF(tipo = \'credito\', \'NC\', \'ND\') AS tipo, serie, numero_seq FROM factura_ajuste
                    UNION ALL
                    SELECT \'SGA\' AS tipo, \'NC\' AS serie, numero_seq FROM sga_nc) AS emitidos';

        $series = Db::todas(
            "SELECT tipo, serie,
                    COUNT(*)            AS emitidos,
                    MIN(numero_seq)     AS minimo,
                    MAX(numero_seq)     AS maximo,
                    COUNT(DISTINCT numero_seq) AS distintos
               FROM $fuente
              GROUP BY tipo, serie
              ORDER BY tipo, serie"
        );

        $salida = [];
        foreach ($series as $s) {
            $tipo = (string) $s['tipo'];
            $serie = (string) $s['serie'];
            $emitidos = (int) $s['emitidos'];
            $min = (int) $s['minimo'];
            $max = (int) $s['maximo'];

            $huecos = [];
            if ($max - $min + 1 !== $emitidos) {
                // Se listan los faltantes de verdad, no sólo la cuenta: el que
                // audita necesita el número para ir a buscarlo.
                $usados = array_map('intval', array_column(Db::todas(
                    "SELECT numero_seq FROM $fuente WHERE tipo = :t AND serie = :s",
                    [':t' => $tipo, ':s' => $serie]
                ), 'numero_seq'));
                $set = array_flip($usados);
                for ($i = $min; $i <= $max; $i++) {
                    if (!isset($set[$i])) $huecos[] = $i;
                    if (count($huecos) >= 200) break;   // no se imprime un listín
                }
            }

            $contador = Db::col(
                'SELECT ultimo FROM numerador WHERE tipo = :t AND serie = :s',
                [':t' => $tipo, ':s' => $serie]
            );

            $salida[] = [
                'tipo'      => $tipo,
                'serie'     => $serie,
                'emitidos'  => $emitidos,
                'desde'     => $min,
                'hasta'     => $max,
                'huecos'    => $huecos,
                'repetidos' => $emitidos - (int) $s['distintos'],
                'contador'  => $contador === null ? null : (int) $contador,
                // El contador tiene que estar EXACTAMENTE en el maximo emitido.
                // Con >= se escondia el hueco de la cola: contador en 42 y
                // registros del 1 al 40 daba "sana", y nadie podia explicar
                // donde estaban el 41 y el 42 — que es literalmente la
                // pregunta para la que existe esta funcion.
                'contador_coherente' => $contador !== null && (int) $contador === $max,
                'huecos_cola' => $contador !== null && (int) $contador > $max
                    ? range($max + 1, min((int) $contador, $max + 200))
                    : [],
            ];
        }
        return $salida;
    }
}

/** Error de programación, no del usuario: por eso no hereda de ErrorApi. */
final class LogicError_NumeradorFueraDeTx extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'Numerador::siguiente() se llamó fuera de una transacción. Un número ' .
            'tomado afuera queda consumido si la emisión falla, y ese es justamente ' .
            'el hueco que este numerador existe para evitar.'
        );
    }
}
