<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Lee camca_priv/config.php, que vive FUERA de public_html.
 *
 * Se localiza subiendo desde __DIR__ en vez de hardcodear el uid de
 * Hostinger (uXXXXXXXX cambia entre cuentas y entre staging y producción).
 */
final class Config
{
    private static ?array $datos = null;
    private static ?string $priv = null;

    /**
     * Los lugares donde puede estar camca_priv/, en el orden en que priv()
     * los prueba. El instalador los recorre todos: una instalación en
     * cualquiera de ellos lo apaga.
     *
     * @return list<string>
     */
    public static function candidatos(): array
    {
        $c = [];
        if ($env = getenv('CAMCA_PRIV')) $c[] = rtrim($env, '/');
        $dir = __DIR__;
        for ($i = 0; $i < 7; $i++) {
            $dir = dirname($dir);
            $c[] = $dir . '/camca_priv';
        }
        return $c;
    }

    /**
     * Sólo para el instalador: trabaja con esta configuración antes de que
     * exista config.php, que se escribe recién cuando todo lo demás salió bien.
     */
    public static function precargar(array $datos): void
    {
        self::$datos = $datos;
    }

    /** Directorio camca_priv/, buscado hacia arriba hasta 7 niveles. */
    public static function priv(): string
    {
        if (self::$priv !== null) return self::$priv;

        if ($env = getenv('CAMCA_PRIV')) {
            if (is_dir($env)) return self::$priv = rtrim($env, '/');
        }
        $dir = __DIR__;
        for ($i = 0; $i < 7; $i++) {
            $dir = dirname($dir);
            $cand = $dir . '/camca_priv';
            if (is_dir($cand)) return self::$priv = $cand;
        }
        throw new ErrorMantenimiento('CONFIG_AUSENTE', 'No se encontró camca_priv/.');
    }

    private static function cargar(): array
    {
        if (self::$datos !== null) return self::$datos;
        $archivo = self::priv() . '/config.php';
        if (!is_readable($archivo)) {
            throw new ErrorMantenimiento('CONFIG_AUSENTE', 'config.php no es legible.');
        }
        $datos = require $archivo;
        if (!is_array($datos)) {
            throw new ErrorMantenimiento('CONFIG_INVALIDA', 'config.php no devolvió un array.');
        }
        return self::$datos = $datos;
    }

    /** Config::get('db.host'). Lanza si falta y no se dio un default. */
    public static function get(string $clave, mixed $default = null): mixed
    {
        $nodo = self::cargar();
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($nodo) || !array_key_exists($parte, $nodo)) {
                if (func_num_args() > 1) return $default;
                throw new ErrorMantenimiento('CONFIG_INCOMPLETA', "Falta la clave '$clave'.");
            }
            $nodo = $nodo[$parte];
        }
        return $nodo;
    }

    public static function esProduccion(): bool
    {
        return self::get('entorno', 'produccion') === 'produccion';
    }
}
