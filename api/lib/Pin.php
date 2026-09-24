<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * PIN de chofer sobre dispositivo enrolado.
 *
 * Un PIN de 6 digitos son un millon de combinaciones: por si solo es debil.
 * Lo que lo hace aceptable es que la credencial real es el PAR
 * {secreto de dispositivo de 256 bits, PIN}: el PIN nunca se evalua sin el
 * telefono enrolado en la mano.
 *
 * Por que PIN y no email + contrasenia: tipear un email con guantes, a 3.000 m,
 * con el teclado tapado por el sol, es inviable. La app tiene que abrirse en
 * dos segundos o el chofer vuelve al papel.
 *
 * El hash lleva un PEPPER que vive en camca_priv/config.php, fuera de la base.
 * Asi un dump robado no alcanza para atacar por fuerza bruta un espacio de
 * solo un millon de combinaciones.
 */
final class Pin
{
    /** Bloqueo escalonado: 5 fallos son un dedo torpe; 20 son un ataque. */
    private const ESCALA = [5 => 60, 8 => 300, 12 => 1800, 20 => 86400];

    public static function hashear(string $pin, string $secretoDispositivo): string
    {
        $pepper = (string) Config::get('pepper_pin');
        $material = hash_hmac('sha256', $pin . '|' . $secretoDispositivo, $pepper);

        $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($material, $algoritmo);
        if ($hash === false) {
            throw new ErrorMantenimiento('HASH_FALLO', 'No se pudo generar el hash.');
        }
        return $hash;
    }

    public static function verificar(string $pin, string $secretoDispositivo, string $hash): bool
    {
        $pepper = (string) Config::get('pepper_pin');
        $material = hash_hmac('sha256', $pin . '|' . $secretoDispositivo, $pepper);
        return password_verify($material, $hash);
    }

    public static function valido(string $pin): bool
    {
        return preg_match('/^[0-9]{6}$/', $pin) === 1 && !self::demasiadoObvio($pin);
    }

    /** 000000, 123456 y los seis digitos iguales no cuentan como PIN. */
    private static function demasiadoObvio(string $pin): bool
    {
        if (preg_match('/^(.)\1{5}$/', $pin)) return true;
        $asc = '01234567890';
        $desc = '09876543210';
        return str_contains($asc, $pin) || str_contains($desc, $pin);
    }

    /**
     * Autentica el par {dispositivo, PIN}.
     *
     * Siempre responde lo MISMO y en tiempo comparable ante un dispositivo
     * inexistente y ante un PIN equivocado: si no, la respuesta se convierte
     * en un oraculo que dice que secretos de dispositivo son validos.
     */
    public static function autenticar(string $secreto, string $pin): array
    {
        $huella = hash('sha256', $secreto);

        $d = Db::una(
            'SELECT d.id, d.usuario_id, d.pin_hash, d.intentos_pin, d.bloqueado_hasta, d.revocado_utc,
                    u.rol, u.nombre, u.legajo, u.activo
               FROM dispositivo d
               JOIN usuario u ON u.id = d.usuario_id
              WHERE d.secreto_hash = :h
              LIMIT 1',
            [':h' => $huella]
        );

        // Sólo un chofer entra por PIN (revisión de la Fase 3): un dispositivo
        // enrolado a nombre de alguien de la oficina o de un cliente sería una
        // puerta al rol de otro, con un PIN de seis dígitos como única llave.
        if ($d === null || $d['revocado_utc'] !== null || (int) $d['activo'] !== 1 || $d['rol'] !== 'chofer') {
            // Se gasta tiempo a proposito para no filtrar por temporizacion.
            password_verify('x', '$2y$10$abcdefghijklmnopqrstuvOaBcDeFgHiJkLmNoPqRsTuVwXyZ012');
            self::registrar($secreto, false);
            throw new ErrorAuth('PIN o dispositivo incorrectos.', 'PIN_INVALIDO');
        }

        if ($d['bloqueado_hasta'] !== null && strtotime((string) $d['bloqueado_hasta']) > time()) {
            $faltan = strtotime((string) $d['bloqueado_hasta']) - time();
            header('Retry-After: ' . $faltan);
            throw new ErrorLimite($faltan, 'Dispositivo bloqueado por intentos fallidos.');
        }

        if (!self::verificar($pin, $secreto, (string) $d['pin_hash'])) {
            $intentos = (int) $d['intentos_pin'] + 1;
            $bloqueo = null;
            foreach (self::ESCALA as $umbral => $segundos) {
                if ($intentos >= $umbral) $bloqueo = $segundos;
            }
            Db::q(
                'UPDATE dispositivo SET intentos_pin = :i,
                        bloqueado_hasta = CASE WHEN :b IS NULL THEN NULL
                                               ELSE UTC_TIMESTAMP() + INTERVAL :b2 SECOND END
                  WHERE id = :id',
                [':i' => $intentos, ':b' => $bloqueo, ':b2' => $bloqueo ?? 0, ':id' => $d['id']]
            );
            self::registrar($secreto, false);
            throw new ErrorAuth('PIN o dispositivo incorrectos.', 'PIN_INVALIDO');
        }

        Db::q(
            'UPDATE dispositivo SET intentos_pin = 0, bloqueado_hasta = NULL, visto_utc = UTC_TIMESTAMP() WHERE id = :id',
            [':id' => $d['id']]
        );
        self::registrar($secreto, true);

        return [
            'dispositivo_id' => (int) $d['id'],
            'usuario_id'     => (int) $d['usuario_id'],
            'rol'            => (string) $d['rol'],
            'nombre'         => (string) $d['nombre'],
            'legajo'         => $d['legajo'],
        ];
    }

    private static function registrar(string $secreto, bool $exito): void
    {
        try {
            Db::q(
                'INSERT INTO intento_login (identidad, ip_hash, exito, creado_utc)
                 VALUES (:i, :ip, :e, UTC_TIMESTAMP())',
                [':i' => 'disp:' . substr(hash('sha256', $secreto), 0, 24), ':ip' => Http::ipHash(), ':e' => $exito ? 1 : 0]
            );
        } catch (Throwable) {
            // Auditar no puede impedir entrar a trabajar.
        }
    }
}
