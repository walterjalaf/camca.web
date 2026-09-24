<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Entrada y salida HTTP. Todas las respuestas de la API usan el mismo
 * sobre: { ok, datos } o { ok:false, error:{ codigo, mensaje, campos } }.
 *
 * El cliente CONFÍA en ese sobre: la PWA sólo da por buena una respuesta
 * si `res.ok && body.ok`. Ese contrato es lo que arregla el bug del
 * formulario de contacto, que hoy muestra "¡Gracias!" ante cualquier 200.
 */
final class Http
{
    public static string $requestId = '';
    public static string $build = 'dev';

    public static function requestId(): string
    {
        return self::$requestId ?: (self::$requestId = bin2hex(random_bytes(8)));
    }

    /** Cuerpo de la petición: JSON o form-urlencoded, siempre array. */
    public static function cuerpo(): array
    {
        $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($tipo, 'application/json')) {
            $crudo = file_get_contents('php://input') ?: '';
            if ($crudo === '') return [];
            $datos = json_decode($crudo, true);
            if (!is_array($datos)) throw new ErrorValidacion([], 'El cuerpo no es JSON válido.');
            return $datos;
        }
        return $_POST;
    }

    /**
     * IP real del cliente detrás del CDN de Hostinger (hcdn).
     * OJO: sólo se confía en la cabecera si la conexión viene del proxy;
     * si no, un cliente podría falsear su IP y evadir el rate limit.
     */
    public static function ip(): string
    {
        $remota = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Lo que el comentario de arriba prometía y el código no hacía
        // (revisión de la Fase 2): sin esto, cualquiera mandaba otra IP en la
        // cabecera y esquivaba el límite por IP. Las IP del proxy se cargan en
        // la configuración (red.proxies) cuando el preflight diga cuáles son
        // (H1); mientras la lista esté vacía, se usa la IP de la conexión.
        $proxies = (array) Config::get('red.proxies', []);
        if (!in_array($remota, $proxies, true)) return $remota;
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $cab) {
            if (!empty($_SERVER[$cab])) {
                $primera = trim(explode(',', $_SERVER[$cab])[0]);
                if (filter_var($primera, FILTER_VALIDATE_IP)) return $primera;
            }
        }
        return $remota;
    }

    /** Hash de IP para los logs: sirve para correlacionar sin guardar la IP. */
    public static function ipHash(): string
    {
        return substr(hash('sha256', self::ip() . '|camca'), 0, 16);
    }

    private static function cabeceras(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-Id: ' . self::requestId());
        header('X-Camca-Build: ' . self::$build);
    }

    public static function ok(mixed $datos = null, int $http = 200): never
    {
        http_response_code($http);
        self::cabeceras();
        echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Sobre con el 'ok' externo explicito: se usa cuando el estado NO es binario
     *  exito/error, como el healthcheck degradado. El 'ok' del sobre y el codigo
     *  HTTP tienen que decir siempre lo mismo o el cliente no puede confiar en ninguno. */
    public static function sobre(bool $ok, mixed $datos, int $http): never
    {
        http_response_code($http);
        self::cabeceras();
        echo json_encode(['ok' => $ok, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function creado(mixed $datos = null): never { self::ok($datos, 201); }

    public static function error(int $http, string $codigo, string $mensaje, array $campos = []): never
    {
        http_response_code($http);
        self::cabeceras();
        $error = ['codigo' => $codigo, 'mensaje' => $mensaje];
        if ($campos) $error['campos'] = $campos;
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
