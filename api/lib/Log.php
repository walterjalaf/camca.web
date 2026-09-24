<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Log NDJSON append-only en camca_priv/logs/ (fuera de public_html, para
 * que ni se sirva por HTTP ni lo borre el sync FTP).
 *
 * Redacción de PII obligatoria: por acá pasan firmas, documentos y datos
 * de personal de clientes mineros. Un log es un lugar donde los secretos
 * se filtran sin que nadie lo note durante meses.
 */
final class Log
{
    // La redaccion decide por el NOMBRE de la clave, nunca por el contenido.
    // Por eso hay que nombrar tambien las claves de texto libre donde se
    // vuelca el mensaje crudo de una excepcion: un PDOException trae el SQL
    // completo del statement, y un ErrorException puede traer el dato que
    // disparo el warning —que puede venir de datos_json.
    private const SENSIBLES = [
        'pin', 'clave', 'password', 'token', 'secreto', 'device_secret',
        'firma', 'foto', 'dni', 'documento', 'mensaje', 'pepper', 'authorization',
        'detalle', 'msg', 'error',
    ];

    private static function redactar(array $datos): array
    {
        $salida = [];
        foreach ($datos as $k => $v) {
            $clave = strtolower((string) $k);
            $sensible = false;
            foreach (self::SENSIBLES as $s) {
                if (str_contains($clave, $s)) { $sensible = true; break; }
            }
            if ($sensible)            $salida[$k] = '[redactado]';
            elseif (is_array($v))     $salida[$k] = self::redactar($v);
            elseif (is_scalar($v) || $v === null) $salida[$k] = $v;
            else                      $salida[$k] = '[objeto]';
        }
        return $salida;
    }

    private static function escribir(string $nivel, string $evento, array $datos): void
    {
        try {
            $dir = Config::get('rutas.logs', Config::priv() . '/logs');
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            $linea = json_encode([
                'ts'     => gmdate('c'),
                'nivel'  => $nivel,
                'evento' => $evento,
                'req'    => Http::requestId(),
                'ip'     => Http::ipHash(),
                'datos'  => self::redactar($datos),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.ndjson', $linea . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Un log que no se puede escribir nunca puede tumbar la petición.
        }
    }

    public static function info(string $evento, array $datos = []): void  { self::escribir('info', $evento, $datos); }
    public static function aviso(string $evento, array $datos = []): void { self::escribir('aviso', $evento, $datos); }
    public static function error(string $evento, array $datos = []): void { self::escribir('error', $evento, $datos); }
}
