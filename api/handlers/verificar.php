<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/verificar/{codigo} — verificación pública de un remito. F2.4.
 *
 * SIN LOGIN: la usa quien tiene el papel en la mano (el cliente, su auditor,
 * un inspector) escaneando el QR desde su propio teléfono.
 *
 * MUESTRA SÓLO LO NECESARIO para decir si el papel es auténtico y coincide:
 * número, fecha, emisor, servicio y cantidad, si hubo conformidad, si está
 * anulado, y la prueba criptográfica completa para que cualquiera la repita
 * sin confiar en nosotros. NO muestra quién recibió ni su documento, ni el
 * chofer, ni el sitio, ni coordenadas, ni fotos, ni la firma, ni el motivo de
 * un rechazo (que puede nombrar gente). El cliente va en iniciales: alcanza
 * para cotejar con el papel y no expone a nadie ante quien sólo tenga el
 * código (supuesto S11).
 *
 * Un código que no existe da 404, igual que uno mal escrito: el endpoint no
 * distingue «no existe» de «existe pero no te lo muestro», porque no hay nada
 * que no se muestre. Son 50 bits al azar: no se adivinan, y el límite por IP
 * hace inútil intentarlo.
 */

$partes = explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'));
$crudo = rawurldecode((string) end($partes));

// Crockford: sin guiones ni espacios, en mayúsculas, y lo que se confunde al
// dictarlo o al leerlo de un papel se corrige (I y L son 1, O es 0).
$codigo = strtr(strtoupper(preg_replace('/[\s-]+/', '', $crudo) ?? ''), ['I' => '1', 'L' => '1', 'O' => '0']);
if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{10}$/', $codigo)) {
    throw new ErrorNoEncontrado('No hay ningún remito con ese código.');
}

$fila = Db::una('SELECT * FROM remito WHERE codigo_verificacion = :c', [':c' => $codigo]);
if ($fila === null) throw new ErrorNoEncontrado('No hay ningún remito con ese código.');

$r = Remito::leer((int) $fila['id']);
$v = Certificacion::verificarRemito((int) $fila['id']);
$d = $r['datos'];

$iniciales = static function (?string $s): ?string {
    if ($s === null || trim($s) === '') return null;
    $palabras = preg_split('/\s+/u', trim($s)) ?: [];
    return implode(' ', array_map(static fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.', $palabras));
};

$items = array_map(static fn($it) => [
    'descripcion' => $it['descripcion'] ?? null,
    'cantidad'    => $it['cantidad'] ?? null,
    'unidad'      => $it['unidad'] ?? null,
], $d['items'] ?? []);

// Qué se puede afirmar, en una palabra, en el mismo orden que el papel.
$nivel = match (true) {
    !$v['valido']                    => 'no_verifica',
    $v['anulado']                    => 'anulado',
    !$v['firmado']                   => 'sin_firma',
    $r['conformidad'] !== 'conforme' => 'firmado',
    default                          => 'certificado',
};

$publica = $v['clave_id'] !== null ? (Firma::confiables()[$v['clave_id']] ?? null) : null;

Http::ok([
    'nivel'   => $nivel,
    'remito'  => [
        'numero'          => $r['numero'],
        'fecha_servicio'  => $d['fecha_servicio'] ?? $r['fecha'],
        'emisor'          => ['razon_social' => $r['emisor']['razon_social'] ?? null, 'cuit' => $r['emisor']['cuit'] ?? null],
        'cliente'         => $iniciales($d['cliente'] ?? null),
        'items'           => $items,
        'conformidad'     => $r['conformidad'],
        'anulado'         => $v['anulado'],
        'emitido_utc'     => $r['emitido'],
        'huella_contenido'=> $r['sha'],
    ],
    'verificacion' => [
        'valido'    => $v['valido'],
        'firmado'   => $v['firmado'],
        'problemas' => $v['problemas'],
        // Todo lo que hace falta para repetir la cuenta sin nuestro código:
        // huella = SHA-256(previa + "|" + contenido), y la firma Ed25519 es
        // sobre "CAMCA-REMITO-1|" + huella.
        'algoritmo'     => $v['firma_alg'],
        'clave_id'      => $v['clave_id'],
        'clave_publica' => $publica === null ? null : base64_encode($publica),
        'hash'          => $v['hash'],
        'hash_previo'   => $v['hash_prev'] ?? null,
        'contenido'     => $v['contenido'] ?? null,
        'firma'         => $v['firma'] ?? null,
        'mensaje'       => $v['hash'] === null ? null : Firma::DOMINIO_REMITO . $v['hash'],
    ],
]);
