<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Dashboards de trazabilidad. Obj. 4, paso F3.9.
 *
 * Cinco preguntas, cada una con una sola fuente, para que el número del
 * tablero sea el mismo que sale mirando la base a mano (la prueba lo cruza
 * contra consultas SQL escritas aparte):
 *
 *  - CUMPLIMIENTO: de lo planificado en el período, qué se hizo, qué no y qué
 *    sigue sin resolver. Base: parada_ejecucion de las jornadas del período.
 *  - DESVÍOS: los mismos que la pestaña Desvíos (Desvios::entre), por tipo.
 *  - KM: estimados por ruta contra los medidos (odómetro o posiciones).
 *  - SERVICIOS: visitas hechas y baños atendidos.
 *  - FACTURABLE VS. FACTURADO: dónde está cada trabajo hecho en el circuito, y
 *    cuánta plata hay facturada, en propuesta y todavía por facturar.
 */
final class Indicadores
{
    public const MAX_DIAS = 92;

    public static function calcular(string $desde, string $hasta): array
    {
        $desde = Tarifa::fechaValida($desde, 'desde');
        $hasta = Tarifa::fechaValida($hasta, 'hasta');
        if ($hasta < $desde) throw new ErrorValidacion(['hasta' => 'El período termina antes de empezar.']);
        if (Asignacion::cantidadDias($desde, $hasta) > self::MAX_DIAS) throw new ErrorValidacion(['hasta' => 'Hasta ' . self::MAX_DIAS . ' días por consulta.']);
        $dias = Asignacion::dias($desde, $hasta);
        $p = [':d' => $desde, ':h' => $hasta];

        // --- Cumplimiento y servicios, por día ---------------------------------
        $porDia = array_fill_keys($dias, ['planificadas' => 0, 'hechas' => 0, 'no_hechas' => 0, 'sin_resolver' => 0, 'banos' => 0]);
        foreach (Db::todas("SELECT j.fecha, pe.estado, COUNT(*) AS n, COALESCE(SUM(CASE WHEN pe.estado = 'ejecutada' THEN pe.cantidad_real END), 0) AS banos
                              FROM parada_ejecucion pe JOIN jornada j ON j.id = pe.jornada_id
                             WHERE j.fecha BETWEEN :d AND :h GROUP BY j.fecha, pe.estado", $p) as $x) {
            $k = ['ejecutada' => 'hechas', 'no_ejecutada' => 'no_hechas', 'planificada' => 'sin_resolver'][$x['estado']] ?? 'sin_resolver';
            $porDia[$x['fecha']][$k] += (int) $x['n'];
            $porDia[$x['fecha']]['planificadas'] += (int) $x['n'];
            $porDia[$x['fecha']]['banos'] += (int) $x['banos'];
        }
        $tot = ['planificadas' => 0, 'hechas' => 0, 'no_hechas' => 0, 'sin_resolver' => 0, 'banos' => 0];
        foreach ($porDia as $d) foreach ($tot as $k => $_) $tot[$k] += $d[$k];

        // --- Km, por día ---------------------------------------------------------
        $km = array_fill_keys($dias, ['estimado' => 0.0, 'medido' => 0.0, 'jornadas' => 0, 'con_medicion' => 0]);
        foreach (Db::todas('SELECT j.fecha, r.km_estimado, COALESCE(j.km_odo, j.km_hav) AS medido
                              FROM jornada j JOIN ruta_plantilla r ON r.id = j.ruta_id WHERE j.fecha BETWEEN :d AND :h', $p) as $x) {
            $km[$x['fecha']]['estimado'] += (float) $x['km_estimado'];
            $km[$x['fecha']]['jornadas']++;
            if ($x['medido'] !== null) {
                $km[$x['fecha']]['medido'] += (float) $x['medido'];
                $km[$x['fecha']]['con_medicion']++;
            }
        }
        $kmTot = ['estimado' => 0.0, 'medido' => 0.0, 'jornadas' => 0, 'con_medicion' => 0];
        foreach ($km as $d) foreach ($kmTot as $k => $_) $kmTot[$k] += $d[$k];

        // --- Desvíos ------------------------------------------------------------
        $dv = Desvios::entre($desde, $hasta);

        // --- Facturable vs. facturado ------------------------------------------
        // Dónde está cada trabajo HECHO del período en el circuito.
        $etapas = array_fill_keys(['sin_verificar', 'verificado', 'certificado', 'en_propuesta', 'facturado'], 0);
        foreach (Db::todas("SELECT pe.flujo, COUNT(*) AS n FROM parada_ejecucion pe JOIN jornada j ON j.id = pe.jornada_id
                             WHERE j.fecha BETWEEN :d AND :h AND pe.estado = 'ejecutada' GROUP BY pe.flujo", $p) as $x) {
            $k = match ($x['flujo']) {
                'verificado' => 'verificado', 'certificado' => 'certificado', 'facturable' => 'en_propuesta', 'facturado' => 'facturado',
                default => 'sin_verificar',
            };
            $etapas[$k] += (int) $x['n'];
        }
        // Plata: lo facturado y lo que está en propuesta, por la fecha del
        // servicio de cada línea; lo certificado sin propuesta, cotizado con la
        // tarifa vigente de su cliente (lo que no tiene tarifa se cuenta aparte).
        $plata = static fn(string $estado) => (int) Db::col(
            "SELECT COALESCE(SUM(l.neto_cent), 0) FROM factura_linea l JOIN factura_propuesta fp ON fp.id = l.propuesta_id
              WHERE fp.estado = :e AND l.fecha BETWEEN :d AND :h", [':e' => $estado, ':d' => $p[':d'], ':h' => $p[':h']]);
        $facturado = $plata('aprobada');
        $enPropuesta = $plata('borrador');
        $destino = [];
        foreach (Db::todas('SELECT origen_id, destino_id FROM cliente_fusion WHERE deshecha_utc IS NULL ORDER BY id') as $f) $destino[(int) $f['origen_id']] = (int) $f['destino_id'];
        // Con el mismo criterio que Facturacion::previsualizar: lo que no tiene
        // cliente o no dice cuántos baños no se puede facturar, y no se suma
        // (revisión de la Fase 3: el tablero prometía plata que la facturación
        // no podía armar, y una cantidad vacía se cotizaba como cero y cobraba
        // el mínimo). Se cuenta aparte, para que se vea.
        $porFacturar = 0;
        $sinTarifa = 0;
        $sinCliente = 0;
        $sinCantidad = 0;
        foreach (Db::todas("SELECT r.cliente_id, r.fecha, r.datos_json FROM remito r JOIN parada_ejecucion pe ON pe.id = r.parada_id
                             WHERE pe.flujo = 'certificado' AND r.estado = 'emitido' AND r.unico_activo IS NOT NULL
                               AND r.fecha BETWEEN :d AND :h", $p) as $r) {
            $c = $r['cliente_id'] === null ? null : (int) $r['cliente_id'];
            for ($i = 0; $c !== null && isset($destino[$c]) && $i < 50; $i++) $c = $destino[$c];
            if ($c === null) { $sinCliente++; continue; }
            $cant = (json_decode((string) $r['datos_json'], true) ?: [])['items'][0]['cantidad'] ?? null;
            if (!is_int($cant) && !(is_string($cant) && ctype_digit($cant))) { $sinCantidad++; continue; }
            $cant = (int) $cant;
            try {
                $porFacturar += Tarifa::cotizar($c, Facturacion::SERVICIO_VISITA, (string) $r['fecha'], $cant)['subtotal_cent'];
            } catch (ErrorConflicto) {
                $sinTarifa++;
            }
        }

        return [
            'desde' => $desde, 'hasta' => $hasta,
            'cumplimiento' => [
                'total' => $tot,
                'porcentaje' => $tot['planificadas'] > 0 ? round(100 * $tot['hechas'] / $tot['planificadas'], 1) : null,
                'por_dia' => array_map(static fn($f, $d) => ['fecha' => $f] + $d, array_keys($porDia), $porDia),
            ],
            'km' => ['total' => $kmTot, 'por_dia' => array_map(static fn($f, $d) => ['fecha' => $f] + $d, array_keys($km), $km)],
            'desvios' => [
                'total' => count($dv['desvios']),
                'por_tipo' => array_map(static fn($t, $n) => ['tipo' => $t, 'nombre' => Desvios::TIPOS[$t], 'n' => $n],
                                        array_keys($dv['resumen']['por_tipo']), $dv['resumen']['por_tipo']),
                'por_severidad' => $dv['resumen']['por_severidad'],
            ],
            'servicios' => ['visitas' => $tot['hechas'], 'banos' => $tot['banos'],
                            'remitos' => (int) Db::col("SELECT COUNT(*) FROM remito WHERE estado = 'emitido' AND fecha BETWEEN :d AND :h", $p)],
            'facturacion' => [
                'etapas' => $etapas,
                'facturado_cent' => $facturado, 'en_propuesta_cent' => $enPropuesta,
                'por_facturar_cent' => $porFacturar, 'por_facturar_sin_tarifa' => $sinTarifa,
                'por_facturar_sin_cliente' => $sinCliente, 'por_facturar_sin_cantidad' => $sinCantidad,
            ],
        ];
    }
}
