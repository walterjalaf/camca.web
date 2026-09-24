<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/dia
 *
 * La hoja de ruta que el chofer se lleva al cerro. Es EL pedido que tiene que
 * salir bien antes de arrancar la jornada: despues puede no haber senial en
 * todo el dia.
 *
 * Devuelve la SEMANA entera, no solo hoy. Pesa poco (60 sitios) y cubre el
 * caso real de una cuadrilla que sale el martes y vuelve el viernes: si solo
 * trajera el dia, el miercoles a las 6 de la maniana la app no tendria nada
 * que mostrar.
 *
 * Incluye radio_m y permanencia_seg POR SITIO (H7). El prototipo usaba 50 m y
 * 90 s para todo: en un frente de obra grande de Los Azules el auto-tildado no
 * dispara nunca, y en una garita 50 m es demasiado.
 */

$u = Policy::usuario();

function camca_fecha_art(int $dias = 0): string
{
    return gmdate('Y-m-d', time() - 3 * 3600 + $dias * 86400);
}

$hoy = camca_fecha_art(0);

// Jornadas de hoy y de los proximos 6 dias.
$jornadas = Db::todas(
    "SELECT j.id, j.fecha, j.estado, j.chofer_id, j.km_odo, j.km_hav, j.km_fuente,
            r.nombre AS ruta, r.color, r.km_estimado, r.dia_semana
       FROM jornada j
       JOIN ruta_plantilla r ON r.id = j.ruta_id
      WHERE j.fecha BETWEEN :d1 AND :d2
      ORDER BY j.fecha",
    [':d1' => $hoy, ':d2' => camca_fecha_art(6)]
);

if ($jornadas === []) {
    // Ningun cron planifico todavia. Se avisa explicitamente en vez de
    // devolver una lista vacia que la app interpretaria como "dia libre".
    Http::ok([
        'hoy'      => $hoy,
        'jornadas' => [],
        'aviso'    => 'Todavia no hay jornadas planificadas. Avisá a coordinación.',
        'servidor_utc' => gmdate('c'),
    ]);
}

$ids = array_column($jornadas, 'id');
$marcas = implode(',', array_fill(0, count($ids), '?'));

$paradas = Db::todas(
    "SELECT pe.id, pe.jornada_id, pe.orden, pe.estado, pe.cantidad_plan, pe.cantidad_real,
            pe.motivo, pe.arribo_utc, pe.origen, pe.ambiguo, pe.bloqueada, pe.cluster_id,
            s.id AS sitio_id, s.codigo, s.nombre, s.direccion, s.lat, s.lon,
            s.radio_m, s.permanencia_seg, s.geo_calidad, s.geo_nota,
            c.nombre AS cliente,
            pp.responsable,
            (SELECT COUNT(*) FROM evento_sync es
              WHERE es.jornada_id = pe.jornada_id AND es.parada_orden = pe.orden
                AND es.tipo = 'parada_reabierta') AS ronda
       FROM parada_ejecucion pe
       JOIN sitio s ON s.id = pe.sitio_id
       LEFT JOIN cliente c ON c.id = s.cliente_id
       LEFT JOIN jornada j ON j.id = pe.jornada_id
       LEFT JOIN parada_plantilla pp ON pp.ruta_id = j.ruta_id AND pp.orden = pe.orden
      WHERE pe.jornada_id IN ($marcas)
      ORDER BY pe.jornada_id, pe.orden",
    $ids
);

$porJornada = [];
foreach ($paradas as $p) {
    $porJornada[(int) $p['jornada_id']][] = [
        'orden'           => (int) $p['orden'],
        'estado'          => $p['estado'],
        'sitio_id'        => (int) $p['sitio_id'],
        'codigo'          => $p['codigo'],
        'nombre'          => $p['nombre'],
        'direccion'       => $p['direccion'],
        'cliente'         => $p['cliente'],
        'responsable'     => $p['responsable'],
        'lat'             => (float) $p['lat'],
        'lon'             => (float) $p['lon'],
        'radio_m'         => (int) $p['radio_m'],
        'permanencia_seg' => (int) $p['permanencia_seg'],
        'cantidad_plan'   => (int) $p['cantidad_plan'],
        'cantidad_real'   => $p['cantidad_real'] === null ? null : (int) $p['cantidad_real'],
        'motivo'          => $p['motivo'],
        'arribo_utc'      => $p['arribo_utc'],
        'origen'          => $p['origen'],
        'ambiguo'         => (int) $p['ambiguo'] === 1,
        'bloqueada'       => (int) $p['bloqueada'] === 1,
        'cluster_id'      => $p['cluster_id'] === null ? null : (int) $p['cluster_id'],
        // La app avisa en pantalla cuando la coordenada no es confiable, en
        // vez de dejar que el chofer crea que el GPS esta roto.
        'geo_calidad'     => $p['geo_calidad'],
        'geo_nota'        => $p['geo_nota'],
        // Cuántas veces se reabrió. El teléfono la manda en cada evento de la
        // parada (ver 0017); un teléfono que se reinstala la retoma de acá.
        'ronda'           => (int) $p['ronda'],
    ];
}

$salida = [];
foreach ($jornadas as $j) {
    $lista = $porJornada[(int) $j['id']] ?? [];

    // Clusters presentes en ESTE dia: son las paradas que el GPS no puede
    // separar y que la app tiene que mandar a desambiguar a mano.
    $conteo = [];
    foreach ($lista as $p) {
        if ($p['cluster_id']) $conteo[$p['cluster_id']] = ($conteo[$p['cluster_id']] ?? 0) + 1;
    }
    $ambiguos = array_keys(array_filter($conteo, static fn($n) => $n > 1));

    $salida[] = [
        'id'             => (int) $j['id'],
        'fecha'          => $j['fecha'],
        'es_hoy'         => $j['fecha'] === $hoy,
        'estado'         => $j['estado'],
        'ruta'           => $j['ruta'],
        'color'          => $j['color'],
        'km_estimado'    => (float) $j['km_estimado'],
        'km_real'        => $j['km_odo'] !== null ? (float) $j['km_odo'] : ($j['km_hav'] !== null ? (float) $j['km_hav'] : null),
        'km_fuente'      => $j['km_fuente'],
        'paradas'        => $lista,
        'clusters_ambiguos' => $ambiguos,
        'banios_plan'    => array_sum(array_column($lista, 'cantidad_plan')),
    ];
}

$base = Db::una('SELECT nombre, direccion, lat, lon FROM base_operativa ORDER BY id LIMIT 1');

Http::ok([
    'hoy'      => $hoy,
    'usuario'  => ['id' => $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']],
    'base'     => $base ? [
        'nombre'    => $base['nombre'],
        'direccion' => $base['direccion'],
        'lat'       => (float) $base['lat'],
        'lon'       => (float) $base['lon'],
    ] : null,
    'jornadas' => $salida,
    // La app calcula con esto el desfase del reloj del telefono: sin eso, un
    // telefono con la hora corrida hace que las horas de arribo mientan.
    'servidor_utc' => gmdate('c'),
]);
