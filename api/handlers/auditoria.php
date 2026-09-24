<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * La auditoría, para la oficina. Paso F2.5.
 *
 *   GET /api/v1/auditoria?entidad=&entidad_id=&desde=&hasta=&antes_de=
 *        los movimientos, lo más nuevo primero
 *   GET /api/v1/auditoria?verificar=1
 *        además, las dos cadenas verificadas enteras y contra la última ancla
 *
 * Verificar recorre la historia entera, así que es a pedido y no en cada
 * consulta: el límite de la ruta es más bajo por eso.
 */

$f = [];
foreach (['entidad', 'entidad_id', 'desde', 'hasta', 'antes_de', 'limite'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $f[$k] = (string) $_GET[$k];
}
foreach (['desde', 'hasta'] as $k) {
    if (isset($f[$k]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
        throw new ErrorValidacion([$k => 'Formato esperado AAAA-MM-DD.']);
    }
}
if (isset($f['entidad']) && !preg_match('/^[a-z_]{1,60}$/', $f['entidad'])) {
    throw new ErrorValidacion(['entidad' => 'Entidad inválida.']);
}

$salida = [
    'movimientos' => Auditoria::consultar($f),
    'entidades'   => Auditoria::entidades(),
    'anclas'      => Ancla::listar(14),
    'firma'       => ['alg' => Firma::alg(), 'clave' => Firma::claveId()],
];

if (($_GET['verificar'] ?? '') === '1') {
    $r = Certificacion::verificarCadena();
    $a = Auditoria::verificarCadena();
    $ultima = Db::una('SELECT * FROM ancla ORDER BY fecha DESC LIMIT 1');
    $va = $ultima === null ? null : Ancla::verificar(Ancla::exportar($ultima));
    $salida['verificacion'] = [
        'remitos'   => ['eslabones' => $r['eslabones'], 'firmados' => $r['firmados'], 'problemas' => $r['problemas']],
        'auditoria' => ['eslabones' => $a['eslabones'], 'problemas' => $a['problemas']],
        'ancla'     => $va === null ? null : ['fecha' => $va['fecha'], 'valida' => $va['valida'], 'problemas' => $va['problemas']],
        'ok'        => $r['problemas'] === [] && $a['problemas'] === [] && ($va === null || $va['valida']),
    ];
}

Http::ok($salida);
