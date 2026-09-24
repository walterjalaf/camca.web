<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/lib/Pin.php';

/**
 * POST /api/v1/auth/pin
 *
 * Login del chofer: {dispositivo, pin}. Devuelve un token de sesion largo
 * (90 dias) porque una sesion corta deja la app inutilizable arriba del cerro,
 * donde no hay forma de renovarla.
 *
 * Devuelve ademas `verificador_offline`: el material con el que la app valida
 * el PIN LOCALMENTE cuando no hay senial. Sin eso, un lunes a las 6 de la
 * maniana en Tamberias el chofer no puede ni abrir la jornada, sale igual, y
 * el dia entero se pierde. Es la contracara de R2.
 *
 * El limite de tasa va por dispositivo y por usuario, NO por IP: con CGNAT los
 * seis choferes de San Juan son una sola IP y un limite por IP los bloquearia
 * a todos a la vez.
 */

$datos = Http::cuerpo();
$v = new Validar($datos);
$secreto = $v->texto('dispositivo', 32, 128);
$pin     = $v->texto('pin', 6, 6);
$v->fin();

Rate::consumir('pin|disp:' . substr(hash('sha256', (string) $secreto), 0, 24), 20, 900);

$sesion = Pin::autenticar((string) $secreto, (string) $pin);

$tok = Sesion::crear($sesion['usuario_id'], $sesion['rol'], $sesion['dispositivo_id']);

Hash::auditar('sesion', $sesion['usuario_id'], 'login_pin', [
    'dispositivo' => $sesion['dispositivo_id'],
    'rol'         => $sesion['rol'],
]);

// Material para la validacion offline del PIN. NO es el PIN ni el token:
// es una sal por dispositivo con la que el cliente deriva un verificador
// (PBKDF2) y lo guarda. Con eso puede comprobar el PIN sin red, pero el
// material por si solo no sirve para entrar al servidor.
$salOffline = Db::col('SELECT LEFT(secreto_hash, 32) FROM dispositivo WHERE id = :id', [':id' => $sesion['dispositivo_id']]);

Http::ok([
    'token'        => $tok['token'],
    'expira_dias'  => $tok['expira_dias'],
    'usuario'      => [
        'id'     => $sesion['usuario_id'],
        'nombre' => $sesion['nombre'],
        'legajo' => $sesion['legajo'],
        'rol'    => $sesion['rol'],
    ],
    'offline' => [
        'sal'         => $salOffline,
        'iteraciones' => 150000,
    ],
    'servidor_utc' => gmdate('c'),
]);
