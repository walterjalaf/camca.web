<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';

/**
 * Runner de migraciones.
 *
 * Corre por cron (no desde el pipeline): en Hostinger shared MySQL escucha
 * en localhost y la IP del runner de GitHub es efimera, asi que el pipeline
 * NO puede conectarse. Sube los .sql por FTP y este script los aplica.
 *
 * Reglas que vienen de fallas concretas:
 *
 *  - SOLO MIGRACIONES ADITIVAS. El codigo nuevo puede llegar hasta 5 minutos
 *    antes que su migracion; si una migracion borra o renombra, en esa ventana
 *    el codigo viejo corre contra un esquema que ya no existe. El runner
 *    rechaza DROP y RENAME.
 *  - NADA DE "transaccion" para DDL: MySQL hace commit implicito en cada DDL.
 *    Se registra 'iniciada' ANTES y 'terminada' DESPUES; si aparece una
 *    iniciada sin terminar, NO se reintenta: se planta y alerta.
 *  - El sha256 se compara contra MANIFEST.json en CADA corrida, no solo la
 *    primera. Un .sql truncado a medio FTP tiene otro hash, y sin este
 *    control quedaria aplicado a medias y sellado para siempre.
 */

Cli::arrancar('migrar');

// La lógica vive en lib/Migrador.php: la comparte el instalador de la primera
// puesta en marcha (handlers/instalar.php).
try {
    $hechas = Migrador::aplicar(static fn(string $m) => Cli::decir($m));
} catch (ErrorMigracion $e) {
    Cli::fallar($e->getMessage());
}

if ($hechas === null) exit(0);   // sin carpeta de migraciones: sin latido, como antes
if ($hechas === []) {
    Cli::latir('migrar', 'sin pendientes');
    exit(0);   // Sin dump, sin lock de MySQL, sin ruido: 720 corridas al dia.
}
Cli::latir('migrar', count($hechas) . ' aplicadas');
Cli::decir('Listo.');
