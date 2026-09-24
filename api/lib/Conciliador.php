<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Conciliación teléfono vs. flota, parada por parada. Obj. 1, paso F1.5.
 *
 * El motor de permanencia corre ACÁ, en el servidor, sobre gps_posicion. Es el
 * mismo algoritmo que geocerca.js pero con una diferencia que lo cambia todo:
 * acá se conoce la jornada completa y se puede mirar hacia atrás. El del
 * teléfono decide en vivo, con lo que tiene en ese instante y con la pantalla
 * apagada la mitad del tiempo.
 *
 * LA REGLA DE FONDO, que viene de la corrección de diseño de la Fase 0:
 *
 *   El arribo autoritativo sale del rastro de FLOTA. No depende de que el
 *   teléfono tenga pantalla encendida ni batería. El dwell del teléfono
 *   CORROBORA, y sólo es fuente cuando la flota no cubre esa unidad.
 *
 * Y LA REGLA QUE ORDENA LA CLASIFICACIÓN:
 *
 *   Ausencia de evidencia NO es evidencia de ausencia.
 *
 * Que el rastro de flota no muestre al camión en un sitio significa algo muy
 * distinto según si esa unidad estaba reportando o no. Por eso 'contradicción'
 * exige COBERTURA: sin fixes suficientes en la ventana, lo que hay es
 * 'sólo teléfono', no una contradicción. Confundir las dos cosas produciría
 * alertas falsas todos los días, y una alerta que casi siempre se ignora deja
 * de ser una alerta.
 */
final class Conciliador
{
    /**
     * Agujero máximo tolerado dentro de una permanencia, en segundos.
     *
     * Si entre dos posiciones pasan más de esto, no se puede afirmar que el
     * camión estuvo ahí todo el tiempo intermedio: la permanencia se descarta
     * y se empieza de nuevo. Es la misma regla del motor del teléfono.
     */
    private const HUECO_MAX_SEG = 300;

    /** Fixes mínimos EN LA FRANJA DE LA PARADA para poder afirmar que NO estuvo. */
    private const COBERTURA_MIN_FIXES = 4;

    /** Cuánto se mira antes y después de la marca del teléfono, en segundos. */
    private const FRANJA_SEG = 15 * 60;

    /** Margen alrededor de la jornada para buscar posiciones. */
    private const MARGEN_SEG = 2 * 3600;

    /**
     * Concilia una jornada entera. Devuelve el conteo por clase.
     *
     * Es idempotente y se puede volver a correr: el rastro de flota llega por
     * poll y puede completarse horas después de cerrada la jornada.
     */
    public static function jornada(int $jornadaId): array
    {
        $j = Db::una(
            'SELECT j.id, j.fecha, j.abierta_utc, j.cerrada_utc, j.vehiculo_id
               FROM jornada j WHERE j.id = :id',
            [':id' => $jornadaId]
        );
        if ($j === null) throw new ErrorNoEncontrado('No existe esa jornada.');

        $unidadId = null;
        if ($j['vehiculo_id'] !== null) {
            $unidadId = Db::col(
                'SELECT id FROM gps_unidad WHERE vehiculo_id = :v AND activa = 1 LIMIT 1',
                [':v' => (int) $j['vehiculo_id']]
            );
            $unidadId = $unidadId === null || $unidadId === false ? null : (int) $unidadId;
        }

        [$desde, $hasta] = self::ventana($j);
        $posiciones = $unidadId === null ? [] : self::posiciones($unidadId, $desde, $hasta);

        $paradas = Db::todas(
            'SELECT pe.id, pe.orden, pe.estado, pe.origen, pe.motivo, pe.arribo_utc, pe.ambiguo,
                    s.lat, s.lon, s.radio_m, s.permanencia_seg, s.nombre AS sitio, s.cluster_id
               FROM parada_ejecucion pe
               JOIN sitio s ON s.id = pe.sitio_id
              WHERE pe.jornada_id = :j
              ORDER BY pe.orden',
            [':j' => $jornadaId]
        );

        $conteo = array_fill_keys(
            ['doble', 'solo_flota', 'solo_telefono', 'contradiccion', 'sin_evidencia'], 0
        );

        // Primero se calcula la permanencia de flota de TODAS las paradas, y
        // recién después se clasifica. El orden importa: hay que saber si dos
        // paradas se están disputando la misma parada del camión antes de
        // decidir a cuál se le acredita.
        $flotas = [];
        foreach ($paradas as $p) {
            $flotas[(int) $p['id']] = self::permanenciaFlota(
                $posiciones, (float) $p['lat'], (float) $p['lon'],
                (int) $p['radio_m'], (int) $p['permanencia_seg']
            );
        }
        $ambiguas = self::coordenadasDisputadas($paradas, $flotas);

        foreach ($paradas as $p) {
            $id = (int) $p['id'];
            // La cobertura se mide POR PARADA, en la franja de esa parada.
            //
            // Antes se medía sobre la jornada entera: con el poll cada dos
            // minutos, cuatro fixes son ocho minutos de rastro en un día de
            // doce horas. Una unidad que reportó de 9:00 a 9:10 y después se
            // quedó sin señal daba "hay cobertura" para TODA la jornada, y
            // las catorce paradas de la tarde salían como contradicción —con
            // una distancia calculada sobre fixes de la mañana, a kilómetros
            // de ahí— y como catorce desvíos de severidad alta.
            $r = self::conciliarParada(
                $p, $flotas[$id], $ambiguas[$id] ?? null,
                self::cobertura($posiciones, $p)
            );
            $conteo[$r['clase']]++;
            self::guardar($jornadaId, $p, $r);
        }

        return [
            'jornada_id' => $jornadaId,
            'unidad_id'  => $unidadId,
            'posiciones' => count($posiciones),
            'paradas'    => count($paradas),
            'conteo'     => $conteo,
            // Sin esto, un día entero sin rastro de flota se leería como
            // "todas sólo teléfono" sin que nadie sepa que faltó el GPS.
            'cobertura'  => count($posiciones) >= self::COBERTURA_MIN_FIXES,
        ];
    }

    /**
     * ¿Hay rastro suficiente EN LA FRANJA DE ESTA PARADA para poder afirmar
     * que el camión no estuvo?
     *
     * Sin esto, la ausencia de fixes en una franja se confunde con la ausencia
     * del camión, que es exactamente lo que el encabezado de esta clase dice
     * que no hay que hacer.
     */
    private static function cobertura(array $posiciones, array $p): bool
    {
        $ancla = $p['arribo_utc'] !== null ? strtotime((string) $p['arribo_utc'] . ' UTC') : null;
        if ($ancla === null || $ancla === false) {
            // Sin hora de referencia no se puede acotar la franja, así que no
            // se afirma nada: se cae del lado conservador.
            return false;
        }
        $desde = $ancla - self::FRANJA_SEG;
        $hasta = $ancla + (int) $p['permanencia_seg'] + self::FRANJA_SEG;

        $n = 0;
        foreach ($posiciones as $q) {
            if ($q['t'] >= $desde && $q['t'] <= $hasta) $n++;
            if ($n >= self::COBERTURA_MIN_FIXES) return true;
        }
        return false;
    }

    /** Ventana de búsqueda: la jornada, con margen, o el día entero. */
    private static function ventana(array $j): array
    {
        $fecha = (string) $j['fecha'];
        // El día operativo argentino en UTC: de 03:00 del día a 03:00 del
        // siguiente. Una jornada que arranca 6 AM en Tamberías es 09:00 UTC.
        $desde = gmdate('Y-m-d H:i:s', strtotime($fecha . ' 00:00:00 UTC') + 3 * 3600);
        $hasta = gmdate('Y-m-d H:i:s', strtotime($fecha . ' 00:00:00 UTC') + 27 * 3600);

        if ($j['abierta_utc'] !== null) {
            $t = strtotime((string) $j['abierta_utc'] . ' UTC') - self::MARGEN_SEG;
            $desde = gmdate('Y-m-d H:i:s', $t);
        }
        if ($j['cerrada_utc'] !== null) {
            $t = strtotime((string) $j['cerrada_utc'] . ' UTC') + self::MARGEN_SEG;
            $hasta = gmdate('Y-m-d H:i:s', $t);
        }
        return [$desde, $hasta];
    }

    private static function posiciones(int $unidadId, string $desde, string $hasta): array
    {
        $filas = Db::todas(
            'SELECT ts_utc, lat, lon FROM gps_posicion
              WHERE unidad_id = :u AND ts_utc BETWEEN :d AND :h
              ORDER BY ts_utc',
            [':u' => $unidadId, ':d' => $desde, ':h' => $hasta]
        );
        // Se convierte una sola vez: el motor recorre esto por cada parada.
        return array_map(static fn($f) => [
            't'   => strtotime((string) $f['ts_utc'] . ' UTC'),
            'lat' => (float) $f['lat'],
            'lon' => (float) $f['lon'],
        ], $filas);
    }

    /**
     * Motor de permanencia sobre el rastro de flota.
     *
     * Hay que estar DENTRO del radio durante N segundos CONTINUOS. Pasar por
     * la puerta camino a otro lado no cuenta como servicio, que es justamente
     * lo que hace un camión seis veces por día en una ruta urbana.
     *
     * Si sale del radio, lo acumulado se DESCARTA: no se suma por tramos. Y si
     * hay un agujero largo sin posiciones tampoco, porque no se puede afirmar
     * que estuvo ahí durante el agujero.
     */
    public static function permanenciaFlota(array $posiciones, float $lat, float $lon, int $radioM, int $permanenciaSeg): array
    {
        // Una permanencia de cero segundos convertiría una pasada de largo en
        // un servicio: un único fix adentro del radio cumpliría. El esquema
        // permite 0 porque la columna es SMALLINT UNSIGNED; acá se pone piso.
        $permanenciaSeg = max(1, $permanenciaSeg);

        $dentroDesde = null;
        $ultimo = null;
        $minDistancia = null;
        $fixesDentro = 0;
        $tramos = [];

        $cerrar = static function () use (&$tramos, &$dentroDesde, &$ultimo, &$fixesDentro, $permanenciaSeg): void {
            if ($dentroDesde === null || $ultimo === null) return;
            $seg = $ultimo - $dentroDesde;
            if ($seg >= $permanenciaSeg) {
                $tramos[] = ['desde' => $dentroDesde, 'hasta' => $ultimo, 'segundos' => $seg, 'fixes' => $fixesDentro];
            }
        };

        foreach ($posiciones as $p) {
            $d = self::distancia($lat, $lon, $p['lat'], $p['lon']);
            if ($minDistancia === null || $d < $minDistancia) $minDistancia = $d;

            $dentro = $d <= $radioM;
            $hueco = $ultimo !== null && ($p['t'] - $ultimo) > self::HUECO_MAX_SEG;

            if (!$dentro || $hueco) {
                // Antes de descartar, se cierra lo que se venía acumulando.
                $cerrar();
                $dentroDesde = $dentro ? $p['t'] : null;
                $fixesDentro = $dentro ? 1 : 0;
            } else {
                if ($dentroDesde === null) { $dentroDesde = $p['t']; $fixesDentro = 0; }
                $fixesDentro++;
            }

            $ultimo = $p['t'];
        }
        $cerrar();   // el último tramo, si quedó abierto

        // El más largo es el que se devuelve como resumen, pero NO es
        // necesariamente el de la visita: un camión estacionado cuarenta
        // minutos en una playa de maniobras a menos de 50 m del sitio deja un
        // tramo más largo que los doce minutos del servicio de la tarde. Quién
        // elige cuál es la visita es conciliarParada(), que tiene la hora del
        // teléfono; acá sólo se ordena.
        $mejor = null;
        foreach ($tramos as $t) if ($mejor === null || $t['segundos'] > $mejor['segundos']) $mejor = $t;

        return [
            'estuvo'       => $mejor !== null,
            'desde'        => $mejor['desde'] ?? null,
            'hasta'        => $mejor['hasta'] ?? null,
            'segundos'     => $mejor['segundos'] ?? null,
            'fixes'        => $mejor['fixes'] ?? 0,
            'min_distancia'=> $minDistancia === null ? null : (int) round($minDistancia),
            // Todos los tramos que cumplen, no sólo el más largo: cuál es el
            // de la visita lo decide quien tenga la hora del teléfono.
            'tramos'       => $tramos,
        ];
    }

    /**
     * Paradas que se están disputando la MISMA parada del camión.
     *
     * El dataset real tiene tres pares de paradas que comparten coordenada
     * dentro del mismo día (Olivos 164 y 17, La Ernestina 42 y 51, Aimara y
     * Melo). Para cualquier GPS son el mismo lugar: el camión estacionado en
     * una satisface la geocerca de las dos.
     *
     * Acreditarle la permanencia a las dos sería inventar evidencia. Se
     * detecta la disputa y se devuelve, por parada, con quién la tiene.
     *
     * @return array<int, array{con: string[]}>  id de parada -> nombres rivales
     */
    private static function coordenadasDisputadas(array $paradas, array $flotas): array
    {
        $salida = [];
        $n = count($paradas);

        // Primero, la disputa que NO depende del rastro: dos paradas del mismo
        // cluster en la misma jornada son indistinguibles para cualquier GPS,
        // haya o no rastro de flota. Antes se exigía permanencia en las dos,
        // así que con un vehículo sin unidad de GPS la ambigüedad
        // desaparecía del circuito en vez de quedar marcada.
        $porCluster = [];
        foreach ($paradas as $p) {
            if ($p['cluster_id'] === null) continue;
            $porCluster[(int) $p['cluster_id']][] = $p;
        }
        foreach ($porCluster as $grupo) {
            if (count($grupo) < 2) continue;
            foreach ($grupo as $a) {
                foreach ($grupo as $b) {
                    if ((int) $a['id'] === (int) $b['id']) continue;
                    $salida[(int) $a['id']]['con'][] = (string) $b['sitio'];
                }
            }
        }

        for ($i = 0; $i < $n; $i++) {
            $a = $paradas[$i];
            $fa = $flotas[(int) $a['id']];
            if (!$fa['estuvo']) continue;

            for ($k = $i + 1; $k < $n; $k++) {
                $b = $paradas[$k];
                $fb = $flotas[(int) $b['id']];
                if (!$fb['estuvo']) continue;

                // Misma ventana de tiempo Y a menos que la suma de los radios:
                // es físicamente la misma detención del camión.
                $seSolapan = $fa['desde'] <= $fb['hasta'] && $fb['desde'] <= $fa['hasta'];
                if (!$seSolapan) continue;

                $dist = self::distancia((float) $a['lat'], (float) $a['lon'], (float) $b['lat'], (float) $b['lon']);
                if ($dist > (int) $a['radio_m'] + (int) $b['radio_m']) continue;

                $salida[(int) $a['id']]['con'][] = (string) $b['sitio'];
                $salida[(int) $b['id']]['con'][] = (string) $a['sitio'];
            }
        }
        return $salida;
    }

    /** Clasifica UNA parada. Es la función que decide, y está sola a propósito. */
    private static function conciliarParada(array $p, array $f, ?array $disputa, bool $hayCobertura): array
    {
        $radio = (int) $p['radio_m'];
        $permanencia = (int) $p['permanencia_seg'];

        // El teléfono "estuvo" sólo si SU motor de geocerca disparó. Una marca
        // a mano no es evidencia de posición: es la palabra del chofer, que
        // vale, pero no es lo mismo y no se cuenta como si lo fuera.
        $telefonoEstuvo = $p['origen'] === 'telefono' && $p['arribo_utc'] !== null;
        $telefonoDesde = $p['arribo_utc'] !== null ? strtotime((string) $p['arribo_utc'] . ' UTC') : null;

        // EL TRAMO DE LA VISITA, no el más largo del día.
        //
        // Un camión estacionado cuarenta minutos en una playa de maniobras a
        // menos de 50 m del sitio deja un tramo más largo que los doce minutos
        // del servicio de la tarde. Devolver el más largo hacía que el R28
        // dijera "arribo 08:00" con el celular marcando 15:00, y que la
        // explicación de la clase «doble» rematara con "coinciden, con 21600 s
        // de diferencia". Con la hora del teléfono se puede elegir bien.
        if ($telefonoDesde !== null && !empty($f['tramos'])) {
            $elegido = null;
            foreach ($f['tramos'] as $t) {
                $dist = $t['desde'] <= $telefonoDesde && $telefonoDesde <= $t['hasta']
                    ? 0
                    : min(abs($t['desde'] - $telefonoDesde), abs($t['hasta'] - $telefonoDesde));
                if ($elegido === null || $dist < $elegido['dist']) $elegido = $t + ['dist' => $dist];
            }
            if ($elegido !== null) {
                $f = array_merge($f, [
                    'desde' => $elegido['desde'], 'hasta' => $elegido['hasta'],
                    'segundos' => $elegido['segundos'], 'fixes' => $elegido['fixes'],
                ]);
            }
        }

        $desfase = ($f['estuvo'] && $telefonoDesde !== null) ? $f['desde'] - $telefonoDesde : null;

        // La permanencia del camión está en disputa con otra parada de la
        // misma jornada: esa detención no se le puede atribuir a ésta sola.
        //
        // El celular desempata SÓLO si su propia marca no es ambigua. El motor
        // del teléfono, ante dos paradas en el mismo punto, elige la de MENOR
        // ORDEN y la tilda con ambiguo=1: tomar eso como desempate sería
        // acreditarle doble evidencia al cliente equivocado a partir de un
        // ordenamiento, que es exactamente lo que ambiguo=1 vino a avisar.
        $disputada = $disputa !== null && $disputa['con'] !== [];
        $marcaAmbigua = (int) ($p['ambiguo'] ?? 0) === 1;
        $desempata = $telefonoEstuvo && !$marcaAmbigua;

        if ($disputada && !$desempata) {
            $rivales = implode(' y ', array_unique($disputa['con']));
            return [
                'clase' => 'sin_evidencia',
                'flota' => array_merge($f, ['estuvo' => false]),
                'telefono_estuvo' => $telefonoEstuvo,
                'telefono_desde'  => $telefonoDesde,
                'desfase' => null,
                'explicacion' => 'El camión estuvo en esta coordenada, pero la comparte con ' . $rivales .
                    ': el GPS no las distingue y ' .
                    ($marcaAmbigua
                        ? 'la marca del celular es la que el propio teléfono dejó como ambigua.'
                        : 'nadie marcó cuál se hizo.'),
                'radio_m' => $radio,
                'permanencia_seg' => $permanencia,
                'disputada' => true,
            ];
        }

        if ($f['estuvo'] && $telefonoEstuvo) {
            $clase = 'doble';
            $exp = 'El rastro del camión y el GPS del celular coinciden' .
                   ($desfase !== null ? ', con ' . abs($desfase) . ' s de diferencia' : '') . '.' .
                   ($disputada ? ' La coordenada la comparte con ' . implode(' y ', array_unique($disputa['con'])) .
                                 ', y lo que desempata es la marca del celular.' : '');
        } elseif ($f['estuvo'] && $p['estado'] === 'no_ejecutada') {
            // El caso que más vale la pena mirar: el camión estuvo el tiempo
            // necesario y la parada figura como no hecha.
            $clase = 'contradiccion';
            $exp = 'El camión estuvo ' . $f['segundos'] . ' s en el sitio, pero la parada figura como NO hecha' .
                   ($p['motivo'] ? ' («' . mb_substr((string) $p['motivo'], 0, 80) . '»)' : '') . '.';
        } elseif ($f['estuvo']) {
            $clase = 'solo_flota';
            $exp = 'Lo confirma el rastro del camión. El celular no lo corroboró: ' .
                   'pudo estar con la pantalla apagada o sin batería.';
        } elseif ($telefonoEstuvo && $hayCobertura) {
            $clase = 'contradiccion';
            $exp = 'El celular dice que estuvo, pero el camión venía reportando y no aparece en el sitio' .
                   ($f['min_distancia'] !== null ? '; lo más cerca que pasó fueron ' . $f['min_distancia'] . ' m' : '') . '.';
        } elseif ($telefonoEstuvo) {
            $clase = 'solo_telefono';
            $exp = 'Lo confirma el GPS del celular. No hay rastro de flota suficiente en esa franja ' .
                   'para corroborarlo ni para desmentirlo.';
        } else {
            $clase = 'sin_evidencia';
            $exp = $p['estado'] === 'ejecutada'
                ? 'Marcada como hecha sin evidencia de posición de ninguna de las dos fuentes.'
                : 'Ninguna de las dos fuentes puede afirmar nada sobre esta parada.';
        }

        return [
            'clase' => $clase,
            'flota' => $f,
            'telefono_estuvo' => $telefonoEstuvo,
            'telefono_desde'  => $telefonoDesde,
            'desfase' => $desfase,
            'explicacion' => $exp,
            'radio_m' => $radio,
            'permanencia_seg' => $permanencia,
            'disputada' => $disputada,
        ];
    }

    private static function guardar(int $jornadaId, array $p, array $r): void
    {
        $f = $r['flota'];

        // Si la flota NO cuenta para esta parada —el caso de la coordenada
        // disputada— tampoco se guardan sus horas. Antes quedaba una fila con
        // flota_estuvo=0 y flota_desde_utc cargado, y parada_ejecucion con
        // arribo_flota_utc puesto en una parada declarada sin evidencia de
        // flota: dos afirmaciones opuestas en la misma fila.
        if (!$f['estuvo']) {
            $f['desde'] = null;
            $f['hasta'] = null;
            $f['segundos'] = null;
            $f['fixes'] = 0;
        }

        $arriboFlota = $f['desde'] !== null ? gmdate('Y-m-d H:i:s', $f['desde']) : null;

        Db::q(
            'UPDATE parada_ejecucion
                SET conciliacion = :c,
                    conciliado_utc = UTC_TIMESTAMP(),
                    arribo_flota_utc = :af,
                    permanencia_flota_seg = :ps,
                    evidencia_doble = :dob,
                    ambiguo = CASE WHEN :amb = 1 THEN 1 ELSE ambiguo END
              WHERE id = :id',
            [
                ':c'   => $r['clase'],
                ':af'  => $arriboFlota,
                ':ps'  => $f['segundos'] !== null ? min(65535, (int) $f['segundos']) : null,
                ':dob' => $r['clase'] === 'doble' ? 1 : 0,
                ':amb' => !empty($r['disputada']) ? 1 : 0,
                ':id'  => (int) $p['id'],
            ]
        );

        Db::q(
            'INSERT INTO conciliacion_detalle
                (parada_id, jornada_id, clase, flota_estuvo, flota_desde_utc, flota_hasta_utc,
                 flota_segundos, flota_fixes, flota_distancia_m, telefono_estuvo, telefono_desde_utc,
                 desfase_seg, explicacion, radio_m, permanencia_seg, calculado_utc)
             VALUES (:p, :j, :c, :fe, :fd, :fh, :fs, :ff, :fdm, :te, :td, :des, :exp, :r, :ps, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                clase = VALUES(clase), flota_estuvo = VALUES(flota_estuvo),
                flota_desde_utc = VALUES(flota_desde_utc), flota_hasta_utc = VALUES(flota_hasta_utc),
                flota_segundos = VALUES(flota_segundos), flota_fixes = VALUES(flota_fixes),
                flota_distancia_m = VALUES(flota_distancia_m), telefono_estuvo = VALUES(telefono_estuvo),
                telefono_desde_utc = VALUES(telefono_desde_utc), desfase_seg = VALUES(desfase_seg),
                explicacion = VALUES(explicacion), radio_m = VALUES(radio_m),
                permanencia_seg = VALUES(permanencia_seg), calculado_utc = VALUES(calculado_utc)',
            [
                ':p'   => (int) $p['id'],
                ':j'   => $jornadaId,
                ':c'   => $r['clase'],
                ':fe'  => $f['estuvo'] ? 1 : 0,
                ':fd'  => $arriboFlota,
                ':fh'  => $f['hasta'] !== null ? gmdate('Y-m-d H:i:s', $f['hasta']) : null,
                ':fs'  => $f['segundos'] !== null ? min(65535, (int) $f['segundos']) : null,
                ':ff'  => min(65535, (int) $f['fixes']),
                ':fdm' => $f['min_distancia'] === null ? null : min(65535, (int) $f['min_distancia']),
                ':te'  => $r['telefono_estuvo'] ? 1 : 0,
                ':td'  => $r['telefono_desde'] !== null ? gmdate('Y-m-d H:i:s', $r['telefono_desde']) : null,
                ':des' => $r['desfase'],
                ':exp' => mb_substr($r['explicacion'], 0, 255),
                ':r'   => $r['radio_m'],
                ':ps'  => $r['permanencia_seg'],
            ]
        );
    }

    /** Haversine en metros. */
    public static function distancia(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** El detalle de una jornada, para el panel y para el R28. */
    public static function detalle(int $jornadaId): array
    {
        return Db::todas(
            'SELECT cd.*, pe.orden, s.nombre AS sitio
               FROM conciliacion_detalle cd
               JOIN parada_ejecucion pe ON pe.id = cd.parada_id
               JOIN sitio s ON s.id = pe.sitio_id
              WHERE cd.jornada_id = :j
              ORDER BY pe.orden',
            [':j' => $jornadaId]
        );
    }
}
