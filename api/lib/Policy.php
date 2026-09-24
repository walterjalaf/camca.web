<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Autorización centralizada.
 *
 * El front controller llama a Policy::exigir() ANTES de incluir el handler.
 * Así un handler no se puede "olvidar" de chequear permisos: si la ruta
 * declara que necesita rol, el router ya lo resolvió. Esa es la diferencia
 * entre una política y una convención.
 */
final class Policy
{
    public const PUBLICO    = 'publico';     // sin sesión (contacto, health, login)
    public const CHOFER     = 'chofer';
    public const SUPERVISOR = 'supervisor';
    public const ADMIN      = 'admin';
    public const CLIENTE    = 'cliente';     // portal del cliente (F3.10)

    /** Jerarquía: admin puede lo de supervisor; supervisor NO puede lo de chofer. */
    private const IMPLICA = [
        self::ADMIN      => [self::ADMIN, self::SUPERVISOR],
        self::SUPERVISOR => [self::SUPERVISOR],
        self::CHOFER     => [self::CHOFER],
        self::CLIENTE    => [self::CLIENTE],
    ];

    public static ?array $usuario = null;

    /**
     * @param string[] $roles Roles aceptados por la ruta. [PUBLICO] = sin sesión.
     */
    public static function exigir(array $roles): void
    {
        if (in_array(self::PUBLICO, $roles, true)) return;

        $usuario = Sesion::actual();
        if ($usuario === null) throw new ErrorAuth();
        self::$usuario = $usuario;

        $efectivos = self::IMPLICA[$usuario['rol']] ?? [];
        foreach ($roles as $r) {
            if (in_array($r, $efectivos, true)) return;
        }
        Log::aviso('policy_denegado', ['usuario' => $usuario['id'], 'rol' => $usuario['rol'], 'pedidos' => $roles]);
        // Un cliente no ve la trastienda: para él, lo que no es suyo no
        // existe. Un 403 le diría que la ruta está ahí y que hay algo que no
        // le dejan ver; un 404 no le dice nada (F3.10).
        if ($usuario['rol'] === self::CLIENTE) throw new ErrorNoEncontrado('Ruta no encontrada.');
        throw new ErrorProhibido();
    }

    public static function usuario(): array
    {
        if (self::$usuario === null) throw new ErrorAuth();
        return self::$usuario;
    }

    public static function id(): int { return (int) self::usuario()['id']; }
    public static function rol(): string { return (string) self::usuario()['rol']; }
    public static function esChofer(): bool { return self::rol() === self::CHOFER; }
}
