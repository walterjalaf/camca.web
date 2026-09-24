<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Healthcheck. Dos niveles a proposito:
 *
 *  - Publico: solo build y ok. Es lo que consulta el pipeline para saber si
 *    el deploy entro, y no le cuenta a un desconocido como esta la maquina.
 *  - Detallado: con la cabecera X-Camca-Health correcta. Reporta version de
 *    esquema, migraciones pendientes o rotas, disco e inodos. El pipeline lo
 *    usa para FALLAR el job si el deploy quedo a medias.
 */

$detalle = false;
$secreto = Config::get('health_token', null);
if ($secreto !== null) {
    $enviado = $_SERVER['HTTP_X_CAMCA_HEALTH'] ?? '';
    $detalle = $enviado !== '' && hash_equals((string) $secreto, $enviado);
}

$salida = ['ok' => true, 'build' => Http::$build];

if (!$detalle) {
    Http::ok($salida);
}

$problemas = [];

// Base y version de esquema
try {
    $salida['schema_version'] = (int) (Db::col('SELECT MAX(version) FROM migracion WHERE terminada_utc IS NOT NULL') ?? 0);
    $rotas = (int) (Db::col('SELECT COUNT(*) FROM migracion WHERE terminada_utc IS NULL') ?? 0);
    $salida['migraciones_en_curso'] = $rotas;
    if ($rotas > 0) {
        $problemas[] = 'hay migraciones iniciadas sin terminar';
    }
} catch (Throwable $e) {
    $problemas[] = 'base no disponible';
    $salida['db'] = 'error';
}

// Marcador de migracion fallida: lo escribe el runner y NO se limpia solo.
if (is_file(Config::priv() . '/MIGRACION_FALLIDA')) {
    $problemas[] = 'MIGRACION_FALLIDA presente';
}

// Disco e inodos: con 6 choferes son ~38.000 archivos al anio solo de
// evidencias. El archivado en frio quedo fuera de Fase 0, asi que esto
// avisa antes de que el plan se llene en silencio.
$priv = Config::priv();
$libre = @disk_free_space($priv);
$total = @disk_total_space($priv);
if ($libre !== false && $total !== false && $total > 0) {
    $usado = 1 - ($libre / $total);
    $salida['disco_usado_pct'] = round($usado * 100, 1);
    if ($usado > 0.85) {
        $problemas[] = 'disco por encima del 85%';
    }
}

// Latido de los crons: si el poll de GPS o el backup dejaron de correr,
// nadie se entera hasta que hace falta el backup. Eso es tarde.
try {
    $latidos = Db::todas('SELECT tarea, UNIX_TIMESTAMP(visto_utc) AS visto FROM heartbeat');
    $ahora = time();
    foreach ($latidos as $l) {
        $edadMin = (int) round(($ahora - (int) $l['visto']) / 60);
        $salida['crons'][$l['tarea']] = $edadMin;
        $techo = match ($l['tarea']) {
            'gps_poll'  => 30,
            'alertas'   => 60,
            'backup'    => 60 * 30,
            default     => 60 * 26,
        };
        if ($edadMin > $techo) {
            $problemas[] = "cron {$l['tarea']} sin latir hace {$edadMin} min";
        }
    }
} catch (Throwable) {
    // heartbeat todavia no existe en el primer deploy: no es un problema.
}

// Firma de remitos (F2.3). Sin sodium o sin clave no hay certificación: no
// rompe nada, pero la oficina no puede certificar, y eso se tiene que ver acá
// antes de que alguien lo descubra con un cliente esperando.
$salida['firma'] = ['alg' => Firma::alg(), 'sodium' => Firma::sodium(), 'clave' => Firma::claveId()];

$salida['ok'] = $problemas === [];
if ($problemas) {
    $salida['problemas'] = $problemas;
}

Http::sobre($salida['ok'], $salida, $salida['ok'] ? 200 : 503);
