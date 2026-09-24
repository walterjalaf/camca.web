<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Usuarios de CAMCA y los teléfonos de los choferes (cierre, F4.6).
 *
 * Deuda de la Fase 0 que apareció al preparar la puesta en producción: el
 * runbook decía «Dispositivos → generar código / revocar» y esa pantalla no
 * existía. Sin ella, dar de alta un chofer o cortar un teléfono robado
 * necesitaba la consola del servidor.
 *
 * Reglas:
 *  - Da de alta administración; el código para activar el teléfono de un
 *    chofer lo emite la coordinación (ya existía: POST /dispositivo/codigo), y
 *    revocar un teléfono perdido también, en el acto.
 *  - La contraseña de la oficina se genera al azar y se muestra UNA vez, como
 *    los accesos del portal (S15). Cada uno la cambia después por la suya.
 *  - Nadie se da de baja a sí mismo, y nunca queda la plataforma sin un
 *    administrador activo.
 *  - Dar de baja corta en el acto las sesiones y los teléfonos; reactivar no
 *    revive ninguno.
 *  - Los clientes no se administran acá: sus accesos viven en la ficha del
 *    cliente (F3.10).
 */
final class Usuarios
{
    public const ROLES = ['chofer' => 'Chofer', 'supervisor' => 'Coordinación', 'admin' => 'Administración'];
    public const CLAVE_MINIMA = 12;

    public static function listar(): array
    {
        $usuarios = Db::todas("SELECT u.id, u.rol, u.nombre, u.legajo, u.email, u.activo, u.creado_utc, p.id AS persona_id, p.nombre AS persona
                                 FROM usuario u LEFT JOIN persona p ON p.usuario_id = u.id
                                WHERE u.rol IN ('chofer','supervisor','admin') ORDER BY u.activo DESC, u.rol, u.nombre");
        $disp = [];
        foreach (Db::todas('SELECT id, usuario_id, etiqueta, plataforma, enrolado_utc, visto_utc FROM dispositivo WHERE revocado_utc IS NULL ORDER BY enrolado_utc') as $d) {
            $disp[(int) $d['usuario_id']][] = ['id' => (int) $d['id'], 'etiqueta' => $d['etiqueta'], 'plataforma' => $d['plataforma'],
                                               'enrolado_utc' => $d['enrolado_utc'], 'visto_utc' => $d['visto_utc']];
        }
        $codigos = [];
        foreach (Db::todas('SELECT usuario_id, COUNT(*) AS n FROM codigo_enrolamiento WHERE usado_utc IS NULL AND expira_utc > UTC_TIMESTAMP() GROUP BY usuario_id') as $c) {
            $codigos[(int) $c['usuario_id']] = (int) $c['n'];
        }
        return [
            'usuarios' => array_map(static fn($u) => [
                'id' => (int) $u['id'], 'rol' => $u['rol'], 'rol_texto' => self::ROLES[$u['rol']], 'nombre' => $u['nombre'], 'legajo' => $u['legajo'],
                'email' => $u['email'], 'activo' => (int) $u['activo'] === 1, 'persona' => $u['persona'],
                'telefonos' => $disp[(int) $u['id']] ?? [], 'codigos_vigentes' => $codigos[(int) $u['id']] ?? 0,
            ], $usuarios),
            // Personas de Recursos (F3.6) sin usuario: un chofer nuevo se ata a
            // su ficha, así la planificación sabe si tiene la licencia al día.
            'personas_sin_usuario' => array_map(static fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'rol' => $p['rol_operativo']],
                Db::todas("SELECT id, nombre, rol_operativo FROM persona WHERE usuario_id IS NULL AND activa = 1 ORDER BY nombre")),
            'roles' => self::ROLES,
        ];
    }

    /** Alta. Para la oficina devuelve la contraseña generada, que no se vuelve a mostrar. */
    public static function alta(array $d, ?int $por): array
    {
        $v = new Validar($d);
        $rol = (string) $v->enum('rol', array_keys(self::ROLES));
        $nombre = trim((string) $v->texto('nombre', 3, 120));
        $legajo = $v->texto('legajo', 1, 30, false);
        $email = $rol === 'chofer' ? null : $v->email('email');
        $persona = $v->entero('persona_id', 1, PHP_INT_MAX, false);
        $v->fin();
        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        $legajo = $legajo !== null ? trim($legajo) : null;
        $clave = $rol === 'chofer' ? null : self::generarClave();

        // La auditoría va DENTRO de la transacción (revisión de la Fase 4): si
        // el eslabón no se puede escribir, no queda un cambio de privilegios sin
        // rastro, ni una contraseña generada que nadie llegó a ver.
        $id = Db::txReintentable(static function () use ($rol, $nombre, $legajo, $email, $clave, $persona, $por): int {
            try {
                Db::q('INSERT INTO usuario (rol, nombre, legajo, email, pass_hash, activo, creado_utc) VALUES (:r, :n, :l, :e, :h, 1, UTC_TIMESTAMP())',
                      [':r' => $rol, ':n' => $nombre, ':l' => $legajo, ':e' => $email, ':h' => $clave !== null ? self::hash($clave) : null]);
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) throw new ErrorConflicto('Ya hay un usuario con ese email o legajo.', 'USUARIO_DUPLICADO');
                throw $e;
            }
            $id = Db::insertarId();
            if ($persona !== null) {
                $p = Db::una('SELECT id, usuario_id FROM persona WHERE id = :p FOR UPDATE', [':p' => $persona]);
                if ($p === null || $p['usuario_id'] !== null) throw new ErrorValidacion(['persona_id' => 'Esa persona no existe o ya tiene usuario.']);
                Db::q('UPDATE persona SET usuario_id = :u WHERE id = :p', [':u' => $id, ':p' => $persona]);
            }
            Hash::auditar('usuario', $id, 'alta', ['rol' => $rol, 'nombre' => $nombre, 'email' => $email, 'persona' => $persona, 'por' => $por]);
            return $id;
        });
        return ['id' => $id, 'rol' => $rol, 'nombre' => $nombre, 'email' => $email, 'clave' => $clave];
    }

    /** Contraseña nueva para alguien de la oficina (la perdió): corta sus sesiones. */
    public static function nuevaClave(int $id, ?int $por): array
    {
        $clave = self::generarClave();
        $u = Db::txReintentable(static function () use ($id, $clave, $por): array {
            $u = Db::una("SELECT id, nombre, email, rol FROM usuario WHERE id = :i AND rol IN ('supervisor','admin') FOR UPDATE", [':i' => $id]);
            if ($u === null) throw new ErrorNoEncontrado('No existe ese usuario de la oficina.');
            Db::q('UPDATE usuario SET pass_hash = :h WHERE id = :i', [':h' => self::hash($clave), ':i' => $id]);
            Db::q('UPDATE sesion SET revocada_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND revocada_utc IS NULL', [':i' => $id]);
            Hash::auditar('usuario', $id, 'clave_regenerada', ['por' => $por]);
            return $u;
        });
        return ['id' => $id, 'email' => $u['email'], 'clave' => $clave];
    }

    /** Cada uno cambia su propia contraseña, sabiendo la actual. */
    public static function cambiarMiClave(int $yo, string $actual, string $nueva): array
    {
        if (mb_strlen($nueva) < self::CLAVE_MINIMA) throw new ErrorValidacion(['nueva' => 'Tiene que tener ' . self::CLAVE_MINIMA . ' caracteres o más.']);
        if ($nueva === $actual) throw new ErrorValidacion(['nueva' => 'Tiene que ser distinta de la actual.']);
        $u = Db::una('SELECT pass_hash FROM usuario WHERE id = :i AND activo = 1', [':i' => $yo]);
        if ($u === null || $u['pass_hash'] === null || !password_verify($actual, (string) $u['pass_hash'])) {
            throw new ErrorValidacion(['actual' => 'La contraseña actual no es esa.']);
        }
        $actualSesion = Sesion::actual()['sesion_id'] ?? null;
        Db::txReintentable(static function () use ($yo, $nueva, $actualSesion): void {
            Db::q('UPDATE usuario SET pass_hash = :h WHERE id = :i', [':h' => self::hash($nueva), ':i' => $yo]);
            // Las otras sesiones de la misma persona (otra computadora) se
            // cortan; la de esta pantalla sigue.
            Db::q('UPDATE sesion SET revocada_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND revocada_utc IS NULL AND id <> :s',
                  [':i' => $yo, ':s' => (int) $actualSesion]);
            Hash::auditar('usuario', $yo, 'clave_cambiada', ['por' => $yo]);
        });
        return ['id' => $yo];
    }

    public static function baja(int $id, ?int $por): array
    {
        if ($id === $por) throw new ErrorConflicto('Nadie se da de baja a sí mismo: que lo haga otro administrador.', 'BAJA_PROPIA');
        Db::txReintentable(static function () use ($id, $por): void {
            // El rol no cambia nunca: se puede leer sin bloquear. Si es admin,
            // se bloquean TODOS los admins activos, en orden de id, antes que
            // nada: dos bajas cruzadas se ordenan en vez de trabarse, y la
            // segunda ve que queda uno solo.
            $rol = Db::col("SELECT rol FROM usuario WHERE id = :i AND rol IN ('chofer','supervisor','admin')", [':i' => $id]);
            if ($rol === null) throw new ErrorNoEncontrado('No existe ese usuario.');
            $admins = $rol === 'admin' ? Db::todas("SELECT id FROM usuario WHERE rol = 'admin' AND activo = 1 ORDER BY id FOR UPDATE") : [];
            $u = Db::una('SELECT id, rol, activo FROM usuario WHERE id = :i FOR UPDATE', [':i' => $id]);
            if ((int) $u['activo'] !== 1) throw new ErrorConflicto('Ya estaba dado de baja.', 'YA_DE_BAJA');
            if ($rol === 'admin' && count($admins) <= 1) {
                throw new ErrorConflicto('Es el último administrador activo: primero dá de alta otro.', 'ULTIMO_ADMIN');
            }
            Db::q('UPDATE usuario SET activo = 0 WHERE id = :i', [':i' => $id]);
            Db::q('UPDATE sesion SET revocada_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND revocada_utc IS NULL', [':i' => $id]);
            Db::q('UPDATE dispositivo SET revocado_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND revocado_utc IS NULL', [':i' => $id]);
            Db::q('UPDATE codigo_enrolamiento SET expira_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND usado_utc IS NULL AND expira_utc > UTC_TIMESTAMP()',
                  [':i' => $id]);
            Hash::auditar('usuario', $id, 'baja', ['por' => $por]);
        });
        return ['id' => $id, 'activo' => false];
    }

    public static function reactivar(int $id, ?int $por): array
    {
        Db::txReintentable(static function () use ($id, $por): void {
            $u = Db::una("SELECT id, activo FROM usuario WHERE id = :i AND rol IN ('chofer','supervisor','admin') FOR UPDATE", [':i' => $id]);
            if ($u === null) throw new ErrorNoEncontrado('No existe ese usuario.');
            if ((int) $u['activo'] === 1) throw new ErrorConflicto('Ya estaba activo.', 'YA_ACTIVO');
            Db::q('UPDATE usuario SET activo = 1 WHERE id = :i', [':i' => $id]);
            Hash::auditar('usuario', $id, 'reactivado', ['por' => $por]);
        });
        return ['id' => $id, 'activo' => true];
    }

    /** Teléfono perdido o robado: su secreto y todas sus sesiones dejan de valer. */
    public static function revocarTelefono(int $dispositivoId, ?int $por): array
    {
        Db::txReintentable(static function () use ($dispositivoId, $por): void {
            $d = Db::una('SELECT d.id, d.usuario_id, d.revocado_utc, u.rol FROM dispositivo d JOIN usuario u ON u.id = d.usuario_id
                           WHERE d.id = :d FOR UPDATE', [':d' => $dispositivoId]);
            if ($d === null) throw new ErrorNoEncontrado('No existe ese teléfono.');
            if ($d['revocado_utc'] !== null) throw new ErrorConflicto('Ese teléfono ya estaba revocado.', 'YA_REVOCADO');
            Db::q('UPDATE dispositivo SET revocado_utc = UTC_TIMESTAMP() WHERE id = :d', [':d' => $dispositivoId]);
            Sesion::revocarDispositivo($dispositivoId);
            Hash::auditar('dispositivo', $dispositivoId, 'revocado', ['usuario' => (int) $d['usuario_id'], 'por' => $por]);
        });
        return ['id' => $dispositivoId, 'revocado' => true];
    }

    /**
     * Lo que toca cuentas de administración (dar de alta una, dar de baja
     * una) y regenerar la contraseña de alguien pide la propia contraseña:
     * con una sesión robada no alcanza para dejar afuera a los demás
     * administradores (revisión de la Fase 4).
     */
    public static function confirmarClave(int $yo, ?string $clave): void
    {
        $h = Db::col('SELECT pass_hash FROM usuario WHERE id = :i AND activo = 1', [':i' => $yo]);
        if ($clave === null || $clave === '' || !is_string($h) || !password_verify($clave, $h)) {
            throw new ErrorValidacion(['mi_clave' => 'Confirmá con tu contraseña.']);
        }
    }

    public static function rolDe(int $id): ?string
    {
        $r = Db::col('SELECT rol FROM usuario WHERE id = :i', [':i' => $id]);
        return is_string($r) ? $r : null;
    }

    private static function generarClave(): string
    {
        // Sin letras que se confunden al dictarla (0/O, 1/l/I), como el portal.
        $alfabeto = 'abcdefghjkmnpqrstuvwxyz23456789';
        $g = static function () use ($alfabeto): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            return $s;
        };
        return 'Camca-' . $g() . '-' . $g() . '-' . $g();
    }

    private static function hash(string $clave): string
    {
        return password_hash($clave, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}
