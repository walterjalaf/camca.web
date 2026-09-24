<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * POST /api/v1/auth/clave
 *
 * Login de supervisor y administracion. El chofer NO usa esta ruta: entra con
 * PIN sobre dispositivo enrolado, porque tipear un email con guantes a 3.000 m
 * es inviable.
 *
 * Sesion corta (1 dia) a diferencia de los 90 dias del chofer: el supervisor
 * trabaja desde la oficina, con red, y puede volver a entrar cuando quiera.
 */

$datos = Http::cuerpo();
$v = new Validar($datos);
$email = $v->email('email');
$clave = $v->texto('clave', 8, 200);
$v->fin();

// Por identidad, no por IP: en la base pueden compartir la misma conexion.
Rate::consumir('clave|' . $email, 12, 900);

$u = Db::una(
    'SELECT id, rol, nombre, legajo, pass_hash, activo
       FROM usuario
      WHERE email = :e AND rol IN (:r1, :r2, :r3)
      LIMIT 1',
    [':e' => $email, ':r1' => 'supervisor', ':r2' => 'admin', ':r3' => 'cliente']
);

$falso = '$2y$10$abcdefghijklmnopqrstuvOaBcDeFgHiJkLmNoPqRsTuVwXyZ012';
$ok = password_verify((string) $clave, (string) ($u['pass_hash'] ?? $falso));

Db::q(
    'INSERT INTO intento_login (identidad, ip_hash, exito, creado_utc) VALUES (:i, :ip, :e, UTC_TIMESTAMP())',
    [':i' => 'mail:' . $email, ':ip' => Http::ipHash(), ':e' => $ok ? 1 : 0]
);

// Mismo cuerpo para "no existe" y para "clave incorrecta": si difieren, la
// respuesta se convierte en un oraculo de que emails estan dados de alta.
if ($u === null || (int) $u['activo'] !== 1 || !$ok) {
    throw new ErrorAuth('Email o contrasenia incorrectos.', 'CREDENCIAL_INVALIDA');
}

$tok = Sesion::crear((int) $u['id'], (string) $u['rol']);
Hash::auditar('sesion', (int) $u['id'], 'login_clave', ['rol' => $u['rol']]);

Http::ok([
    'token'       => $tok['token'],
    'csrf'        => $tok['csrf'],
    'expira_dias' => $tok['expira_dias'],
    'usuario'     => ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']],
]);
