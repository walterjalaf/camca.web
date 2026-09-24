<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * POST /api/v1/auth/salir
 *
 * Cierra la sesion del lado del servidor. Del lado del cliente la app borra el
 * token, pero NUNCA la cola de salida: un cierre de sesion no puede llevarse
 * evidencia sin sincronizar (R1). El chofer vuelve a entrar con el PIN y la
 * cola sigue donde estaba.
 */

$cab = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+([A-Za-z0-9._-]{20,})$/', $cab, $m)) {
    Sesion::revocar($m[1]);
}

Hash::auditar('sesion', Policy::id(), 'logout', []);

Http::ok(['cerrada' => true]);
