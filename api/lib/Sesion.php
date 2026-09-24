<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Sesiones propias en tabla (no sesiones de archivo de PHP: en shared
 * hosting el recolector es agresivo y además no se pueden revocar).
 *
 * Dos transportes:
 *   · Bearer  — la app de campo. No viaja solo en un ataque CSRF.
 *   · Cookie  — el panel del supervisor, con token CSRF aparte.
 *
 * Duración por rol: el chofer necesita una sesión larga o la app queda
 * inutilizable arriba del cerro. El supervisor, corta.
 *
 * R6 — El vencimiento NUNCA bloquea la captura del lado cliente. Acá sólo
 * se decide si el servidor acepta la petición; que la app siga capturando
 * offline es decisión del cliente y es deliberada.
 */
final class Sesion
{
    public const DIAS_CHOFER     = 90;
    public const DIAS_SUPERVISOR = 1;

    private static ?array $cache = null;
    private static bool $resuelta = false;

    private static function tokenCrudo(): ?string
    {
        $cab = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9._-]{20,})$/', $cab, $m)) return $m[1];
        if (!empty($_COOKIE['camca_sid'])) return (string) $_COOKIE['camca_sid'];
        return null;
    }

    /** El token se guarda HASHEADO: un dump de la base no da sesiones usables. */
    private static function huella(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function actual(): ?array
    {
        if (self::$resuelta) return self::$cache;
        self::$resuelta = true;

        $token = self::tokenCrudo();
        if ($token === null) return self::$cache = null;

        $fila = Db::una(
            'SELECT s.id AS sesion_id, s.csrf, s.dispositivo_id, s.expira_utc,
                    u.id, u.rol, u.nombre, u.legajo, u.activo
               FROM sesion s
               JOIN usuario u ON u.id = s.usuario_id
              WHERE s.token_hash = :t AND s.revocada_utc IS NULL
              LIMIT 1',
            [':t' => self::huella($token)]
        );
        if ($fila === null) return self::$cache = null;
        if ((int) $fila['activo'] !== 1) return self::$cache = null;
        if (strtotime((string) $fila['expira_utc']) < time()) return self::$cache = null;

        // Toque de última actividad, como mucho una vez por hora: escribir en
        // cada petición multiplica los writes del pico de las 20:00 sin aportar nada.
        Db::q(
            'UPDATE sesion SET visto_utc = UTC_TIMESTAMP()
              WHERE id = :id AND (visto_utc IS NULL OR visto_utc < UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
            [':id' => $fila['sesion_id']]
        );

        return self::$cache = $fila;
    }

    public static function tokenCsrf(): ?string
    {
        $s = self::actual();
        return $s['csrf'] ?? null;
    }

    /** Crea una sesión y devuelve el token EN CLARO (única vez que existe). */
    public static function crear(int $usuarioId, string $rol, ?int $dispositivoId = null): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '.-'), '=');
        $csrf  = bin2hex(random_bytes(16));
        $dias  = $rol === Policy::CHOFER ? self::DIAS_CHOFER : self::DIAS_SUPERVISOR;

        Db::q(
            'INSERT INTO sesion (usuario_id, dispositivo_id, token_hash, csrf, creada_utc, expira_utc)
             VALUES (:u, :d, :t, :c, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :dias DAY)',
            [':u' => $usuarioId, ':d' => $dispositivoId, ':t' => self::huella($token), ':c' => $csrf, ':dias' => $dias]
        );

        return ['token' => $token, 'csrf' => $csrf, 'expira_dias' => $dias];
    }

    public static function revocar(string $token): void
    {
        Db::q('UPDATE sesion SET revocada_utc = UTC_TIMESTAMP() WHERE token_hash = :t', [':t' => self::huella($token)]);
    }

    /** Revoca todo lo de un dispositivo (teléfono perdido o robado). */
    public static function revocarDispositivo(int $dispositivoId): void
    {
        Db::q(
            'UPDATE sesion SET revocada_utc = UTC_TIMESTAMP()
              WHERE dispositivo_id = :d AND revocada_utc IS NULL',
            [':d' => $dispositivoId]
        );
    }
}
