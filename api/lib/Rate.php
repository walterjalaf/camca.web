<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Límite de tasa por bucket con ventana fija.
 *
 * R4 — EL BUCKET ES POR IDENTIDAD, NO POR IP.
 * En San Juan los choferes salen por CGNAT de la telefónica: para el
 * servidor, seis teléfonos son UNA sola IP. Un límite por IP los bloquea
 * a todos a las 6 de la mañana, que es exactamente cuando arrancan.
 * La IP queda sólo como backstop, con un umbral alto.
 *
 * Se resuelve en UNA query con INSERT ... ON DUPLICATE KEY UPDATE: dos
 * queries (leer y después escribir) tienen carrera cuando seis clientes
 * reintentan en lockstep.
 */
final class Rate
{
    /**
     * @param string $bucket  p.ej. "login:chofer:12" o "ip:a1b2c3"
     * @param int    $maximo  eventos permitidos en la ventana
     * @param int    $ventana segundos
     */
    public static function consumir(string $bucket, int $maximo, int $ventana): void
    {
        $ahora  = time();
        $inicio = intdiv($ahora, $ventana) * $ventana;
        $clave  = substr($bucket, 0, 150) . '|' . $inicio;

        try {
            Db::q(
                'INSERT INTO rate_bucket (clave, ventana_inicio, contador)
                 VALUES (:c, :v, 1)
                 ON DUPLICATE KEY UPDATE contador = LAST_INSERT_ID(contador + 1)',
                [':c' => $clave, ':v' => gmdate('Y-m-d H:i:s', $inicio)]
            );
            $contador = (int) Db::pdo()->lastInsertId();
        } catch (PDOException $e) {
            // Si la tabla de límites falla, no se cierra el paso: la
            // disponibilidad de la operación vale más que el límite.
            Log::error('rate_falla', ['detalle' => $e->getMessage()]);
            return;
        }

        if ($contador > $maximo) {
            $reintentar = ($inicio + $ventana) - $ahora;
            header('Retry-After: ' . max(1, $reintentar));
            throw new ErrorLimite(max(1, $reintentar));
        }
    }
}
