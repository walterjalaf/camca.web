<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/lib/Pin.php';

/**
 * Crea el primer usuario administrador.
 *
 * Pide la clave por stdin y NO la acepta por argumento: un argumento queda en
 * el historial del shell y en la lista de procesos. Y no existe ningun usuario
 * sembrado en los .sql a proposito: un PIN o una clave por defecto dentro de
 * un archivo versionado en git es una credencial publicada.
 */

function preguntar(string $texto, bool $oculto = false): string
{
    fwrite(STDOUT, $texto);
    if ($oculto && PHP_OS_FAMILY !== 'Windows') {
        @shell_exec('stty -echo 2>/dev/null');
        $v = trim((string) fgets(STDIN));
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
        return $v;
    }
    return trim((string) fgets(STDIN));
}

$nombre = preguntar('Nombre completo: ');
$email  = preguntar('Email: ');
$rol    = preguntar('Rol [admin|supervisor|chofer] (admin): ') ?: 'admin';

if (!in_array($rol, ['admin', 'supervisor', 'chofer'], true)) {
    Cli::fallar('Rol invalido.');
}
if ($nombre === '') {
    Cli::fallar('El nombre es obligatorio.');
}
if ($rol !== 'chofer' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Cli::fallar('Email invalido (obligatorio para admin y supervisor).');
}

$hash = null;
if ($rol !== 'chofer') {
    $clave = preguntar('Contrasenia (minimo 12 caracteres): ', true);
    if (strlen($clave) < 12) Cli::fallar('Demasiado corta.');
    if (preguntar('Repetir: ', true) !== $clave) Cli::fallar('No coinciden.');
    $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $hash = password_hash($clave, $algoritmo);
}

$legajo = preguntar('Legajo (opcional): ') ?: null;

if (Db::col('SELECT id FROM usuario WHERE email = :e', [':e' => $email]) !== null) {
    Cli::fallar('Ya existe un usuario con ese email.');
}

Db::q(
    'INSERT INTO usuario (rol, nombre, legajo, email, pass_hash, activo, creado_utc)
     VALUES (:r, :n, :l, :e, :p, 1, UTC_TIMESTAMP())',
    [':r' => $rol, ':n' => $nombre, ':l' => $legajo, ':e' => $email ?: null, ':p' => $hash]
);
$id = Db::insertarId();

Cli::decir("Usuario #$id creado ($rol).");
if ($rol === 'chofer') {
    Cli::decir('Para que entre, emiti un codigo de enrolamiento desde el panel del supervisor');
    Cli::decir('y que lo canjee en su telefono definiendo su PIN.');
}
