<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';

/**
 * Ancla diaria. Paso F2.5.
 *
 * Toma la cabeza de las cadenas de remitos y de auditoría, la firma y la
 * manda por mail a quien custodia. El backup de la noche la vuelve a llevar,
 * cifrada, fuera del proveedor: son dos caminos independientes a propósito.
 *
 * Corre ANTES del backup (02:20 ART), para que el paquete de esa noche ya la
 * lleve. Si el mail falla, el ancla igual queda y viaja en el backup: se
 * avisa, no se frena.
 *
 * Uso:  php api/cli/anclar.php [AAAA-MM-DD]
 */

Cli::arrancar('anclar');

$fecha = $argv[1] ?? null;
if ($fecha !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    Cli::fallar('La fecha va como AAAA-MM-DD.');
}

$a = Ancla::generar($fecha);
$c = json_decode($a['contenido'], true);
Cli::decir(sprintf(
    'Ancla del %s: remitos %s · auditoría %s · %s',
    $c['fecha'],
    substr((string) ($c['remito']['hash'] ?? '—'), 0, 16),
    substr((string) ($c['auditoria']['hash'] ?? '—'), 0, 16),
    $a['firma'] === null ? 'SIN FIRMA (no hay clave: ver S10)' : 'firmada ' . $a['clave_id']
));

$destinos = array_filter((array) Config::get('ancla.destinatarios', []));
if ($destinos === []) {
    Cli::decir('AVISO: no hay destinatarios del ancla configurados (ancla.destinatarios). Sólo viaja en el backup.');
} else {
    $cuerpo = "Ancla diaria de la plataforma CAMCA — {$c['fecha']}\n\n" .
        "Este mensaje es un respaldo. No hay que hacer nada con él salvo GUARDARLO:\n" .
        "si algún día alguien reescribe la historia de los remitos, esta huella deja\n" .
        "de aparecer, y es la prueba de que la historia cambió.\n\n" .
        "Para verificarla contra una base:  php api/cli/verificar_cadena.php --ancla=archivo.json\n" .
        "(guardá el bloque de abajo, entre las líneas, como archivo.json)\n\n" .
        "----------------------------------------------------------------\n" .
        json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" .
        "----------------------------------------------------------------\n";
    $enviados = 0;
    foreach ($destinos as $d) {
        try {
            Mailer::enviar('Ancla CAMCA ' . $c['fecha'], $cuerpo, null, null, (string) $d);
            $enviados++;
        } catch (Throwable $e) {
            Cli::decir('AVISO: no salió el mail a ' . $d . ': ' . $e->getMessage());
        }
    }
    if ($enviados > 0) {
        Db::q('UPDATE ancla SET mail_enviado_utc = UTC_TIMESTAMP() WHERE fecha = :f', [':f' => $c['fecha']]);
        Cli::decir("Ancla enviada por mail a $enviados destinatario(s).");
    }
}

Cli::latir('anclar', $c['fecha']);
