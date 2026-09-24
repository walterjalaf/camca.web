<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';

/**
 * Verificador de la cadena de remitos y de la de auditoría. Pasos F2.3 y F2.5.
 *
 * Corre por cron una vez por día, y a mano cuando alguien pregunta. No arregla
 * nada: dice EXACTAMENTE qué remito, qué eslabón y qué cosa no coincide.
 *
 * Con --ancla verifica además contra un ancla que salió del servidor (la del
 * mail o la del backup): es la verificación que resiste a quien tenga la
 * clave de firma, porque esa huella ya no la puede cambiar nadie.
 *
 * Sale con código 1 si encuentra algo.
 *
 * Uso:
 *   php api/cli/verificar_cadena.php                         la cadena entera
 *   php api/cli/verificar_cadena.php --ancla=ancla.json      y además contra un ancla
 *   php api/cli/verificar_cadena.php --ancla=ancla.json --todas   contra todas las del archivo
 */

Cli::arrancar('verificar_cadena');

$opciones = getopt('', ['ancla:', 'todas']);
$problemas = [];

$r = Certificacion::verificarCadena();
Cli::decir(sprintf(
    'Cadena de remitos: %d eslabones sobre %d remitos, %d firmados con clave de confianza.',
    $r['eslabones'], $r['remitos'], $r['firmados']
));
if (!Firma::sodium()) {
    Cli::decir('AVISO: falta la extensión sodium. Las firmas no se pueden comprobar en esta máquina.');
}
$a = Auditoria::verificarCadena();
Cli::decir(sprintf('Cadena de auditoría: %d eslabones.', $a['eslabones']));
$problemas = array_merge($r['problemas'], $a['problemas']);

if (isset($opciones['ancla'])) {
    $archivo = (string) $opciones['ancla'];
    $json = is_readable($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
    if (!is_array($json)) Cli::fallar("No se pudo leer el ancla de $archivo.");

    // Acepta un ancla sola (la del mail) o el ancla.json del backup.
    $anclas = isset($json['contenido']) ? [$json]
        : (isset($opciones['todas']) ? ($json['todas'] ?? []) : [$json['ultima'] ?? null]);
    foreach (array_filter($anclas) as $an) {
        $v = Ancla::verificar($an);
        Cli::decir('Ancla del ' . ($v['fecha'] ?? '?') . ': ' . ($v['valida'] ? 'la historia anclada sigue intacta.' : 'NO COINCIDE.'));
        $problemas = array_merge($problemas, $v['problemas']);
    }
}

// Un mismo problema puede salir por la cadena y por el ancla: se dice una vez.
$vistos = [];
foreach ($problemas as $p) {
    $clave = $p['codigo'] . '|' . $p['detalle'];
    if (isset($vistos[$clave])) continue;
    $vistos[$clave] = true;
    Cli::decir('PROBLEMA [' . $p['codigo'] . '] ' . $p['detalle']);
}

if ($problemas === []) {
    Cli::decir('Todo coincide.');
    Cli::latir('verificar_cadena', 'ok ' . $r['eslabones'] . '/' . $a['eslabones']);
    exit(0);
}
Cli::latir('verificar_cadena', count($vistos) . ' problemas');
exit(1);
