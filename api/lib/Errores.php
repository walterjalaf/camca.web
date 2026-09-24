<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Excepciones tipadas. Cada una sabe su código HTTP; el front controller
 * las traduce al sobre JSON. Cualquier Throwable NO tipado sale como 500
 * genérico con el request id y nada más — nunca un stack trace al cliente.
 */
abstract class ErrorApi extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly array $campos = []
    ) {
        parent::__construct($mensaje);
    }
    abstract public function http(): int;
}

final class ErrorValidacion extends ErrorApi
{
    public function __construct(array $campos, string $mensaje = 'Datos inválidos.')
    { parent::__construct('VALIDACION', $mensaje, $campos); }
    public function http(): int { return 422; }
}

final class ErrorAuth extends ErrorApi
{
    public function __construct(string $mensaje = 'No autenticado.', string $codigo = 'NO_AUTENTICADO')
    { parent::__construct($codigo, $mensaje); }
    public function http(): int { return 401; }
}

final class ErrorProhibido extends ErrorApi
{
    public function __construct(string $mensaje = 'Sin permisos.')
    { parent::__construct('PROHIBIDO', $mensaje); }
    public function http(): int { return 403; }
}

final class ErrorNoEncontrado extends ErrorApi
{
    public function __construct(string $mensaje = 'No encontrado.')
    { parent::__construct('NO_ENCONTRADO', $mensaje); }
    public function http(): int { return 404; }
}

final class ErrorConflicto extends ErrorApi
{
    public function __construct(string $mensaje = 'Conflicto.', string $codigo = 'CONFLICTO')
    { parent::__construct($codigo, $mensaje); }
    public function http(): int { return 409; }
}

final class ErrorLimite extends ErrorApi
{
    public function __construct(public readonly int $reintentarEn, string $mensaje = 'Demasiados intentos.')
    { parent::__construct('LIMITE', $mensaje); }
    public function http(): int { return 429; }
}

/** 503: el servidor está en mantenimiento o mal configurado. Siempre con Retry-After. */
final class ErrorMantenimiento extends ErrorApi
{
    public function __construct(string $codigo, string $mensaje = 'Servicio no disponible.')
    { parent::__construct($codigo, $mensaje); }
    public function http(): int { return 503; }
}
