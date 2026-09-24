<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/lib/Mailer.php';

/**
 * Reintenta las consultas del formulario cuyo mail no salio.
 *
 * Existe porque la consulta se guarda ANTES de intentar el envio: si el SMTP
 * estaba caido, la consulta esta en la base con estado 'fallido' y hay que
 * mandarla igual cuando el correo vuelva. Sin este cron, "guardamos siempre"
 * seria apenas la mitad de la promesa.
 *
 * Backoff por cantidad de intentos: 5 min, 30 min, 2 h, 12 h, y despues se
 * abandona. Seis reintentos contra un SMTP roto no lo arreglan, y a la cuarta
 * conviene que alguien mire la casilla en vez de seguir golpeando.
 */

Cli::arrancar('reintentar_mail');

const MAX_INTENTOS = 5;
$esperaMin = [1 => 5, 2 => 30, 3 => 120, 4 => 720];

$pendientes = Db::todas(
    "SELECT id, nombre, email, telefono, empresa, servicio, mensaje, intentos, creado_utc
       FROM consulta_web
      WHERE estado = 'fallido' AND intentos < :max
      ORDER BY id
      LIMIT 20",
    [':max' => MAX_INTENTOS]
);

if ($pendientes === []) {
    Cli::latir('reintentar_mail', 'sin pendientes');
    exit(0);
}

$enviados = 0;
$omitidos = 0;

foreach ($pendientes as $c) {
    $intentos = (int) $c['intentos'];
    $espera = $esperaMin[$intentos] ?? 720;

    $listo = Db::col(
        'SELECT 1 FROM consulta_web
          WHERE id = :id AND (enviado_utc IS NULL)
            AND creado_utc <= UTC_TIMESTAMP() - INTERVAL :min MINUTE',
        [':id' => $c['id'], ':min' => $espera]
    );
    if ($listo === null) { $omitidos++; continue; }

    $cuerpo = "Nueva consulta desde camcasoluciones.com.ar\n"
        . "(reenvio automatico: el primer intento no salio)\n"
        . str_repeat('-', 46) . "\n\n"
        . "Nombre:   {$c['nombre']}\n"
        . 'Email:    ' . ($c['email'] ?: '(no dejo)') . "\n"
        . 'Telefono: ' . ($c['telefono'] ?: '(no dejo)') . "\n"
        . 'Empresa:  ' . ($c['empresa'] ?: '(no indico)') . "\n"
        . 'Servicio: ' . ($c['servicio'] ?: '(no indico)') . "\n\n"
        . "Mensaje:\n{$c['mensaje']}\n\n"
        . str_repeat('-', 46) . "\n"
        . "Consulta #{$c['id']} · recibida {$c['creado_utc']} UTC\n";

    try {
        Mailer::enviar('Consulta web: ' . $c['nombre'], $cuerpo, $c['email'] ?: null, $c['nombre']);
        Db::q(
            "UPDATE consulta_web SET estado = 'enviado', enviado_utc = UTC_TIMESTAMP(), intentos = intentos + 1, ultimo_error = NULL WHERE id = :id",
            [':id' => $c['id']]
        );
        $enviados++;
    } catch (Throwable $e) {
        Db::q(
            'UPDATE consulta_web SET intentos = intentos + 1, ultimo_error = :err WHERE id = :id',
            [':err' => mb_substr($e->getMessage(), 0, 255), ':id' => $c['id']]
        );
        Cli::decir('Consulta ' . $c['id'] . ' sigue fallando: ' . $e->getMessage());
    }
}

// Las que agotaron los reintentos quedan visibles: siguen en la base y el
// supervisor las ve en el panel. Una consulta nunca desaparece.
$abandonadas = (int) Db::col("SELECT COUNT(*) FROM consulta_web WHERE estado = 'fallido' AND intentos >= :m", [':m' => MAX_INTENTOS]);

Cli::latir('reintentar_mail', $enviados . ' enviadas, ' . $abandonadas . ' sin resolver');
Cli::decir("Reenviadas: $enviados · omitidas por backoff: $omitidos · abandonadas: $abandonadas");
