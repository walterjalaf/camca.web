<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Desvíos: lo planificado contra lo ejecutado. Obj. 1, paso F1.6.
 *
 * NO GUARDA NADA. Es una consulta, no una tabla derivada, y eso es deliberado:
 * una tabla de desvíos se desactualiza en silencio cada vez que alguien
 * corrige una cantidad o llega tarde el rastro de flota, y entonces el panel
 * muestra un desvío que ya no existe. Se calcula al mirar.
 *
 * LO QUE CUENTA COMO DESVÍO Y LO QUE NO:
 *
 * Una parada no hecha CON motivo es una desviación del plan, pero es operación
 * normal: el portón estaba cerrado. Una no hecha SIN motivo es otra cosa. Las
 * dos aparecen, con severidad distinta, porque meterlas en la misma bolsa
 * obliga a quien coordina a releer las cincuenta para encontrar las tres que
 * importan.
 *
 * Y el orden de las paradas: salirse del orden planificado casi nunca es un
 * problema —un corte de calle, un cliente que pidió más tarde— así que va como
 * severidad baja. Está porque en la suma del mes dice algo sobre si la ruta
 * está bien armada, no porque haya que actuar cada vez.
 */
final class Desvios
{
    /** Ventana operativa por defecto, en hora de Argentina. */
    private const HORA_DESDE = 6;
    private const HORA_HASTA = 21;

    /** Cuánto se puede apartar el kilometraje real del estimado, en tanto por uno. */
    private const KM_TOLERANCIA = 0.20;

    /** Argentina no tiene horario de verano: el desfase es fijo. */
    public const ART_SEGUNDOS = -3 * 3600;
    private const ART = self::ART_SEGUNDOS;

    /**
     * Los motivos de la lista del teléfono (public/app/js/motivo.js). Son los
     * únicos que el portal le muestra al cliente tal cual: uno escrito a mano
     * es texto del chofer sin revisar y puede nombrar gente u otro cliente
     * (S15). prueba_portal controla que las dos listas sean la misma.
     */
    public const MOTIVOS_FRECUENTES = [
        'Portón cerrado', 'No había nadie', 'Camino cortado', 'Sin acceso al sector',
        'El cliente pidió pasar otro día', 'Vehículo bloqueando el acceso', 'Ya lo había hecho otro móvil',
    ];

    public const TIPOS = [
        'omitida'           => 'Parada no hecha',
        'fuera_de_horario'  => 'Fuera del horario de operación',
        'cantidad_distinta' => 'Cantidad distinta de la planificada',
        'fuera_de_orden'    => 'Fuera del orden planificado',
        'km'                => 'Kilometraje fuera de lo estimado',
        'evidencia'         => 'Evidencia insuficiente o contradictoria',
    ];

    /**
     * Desvíos de un rango de fechas.
     *
     * @return array{desvios: array, resumen: array, jornadas: int}
     */
    public static function entre(string $desde, string $hasta, array $filtros = []): array
    {
        // Se valida el CALENDARIO, no sólo la forma. '2026-13-45' pasa el
        // regex y strtotime() devuelve false; si el que compara el rango usa
        // esa resta, false - false da 0 y el tope de días se evade. Y la fecha
        // inválida llega igual al BETWEEN.
        foreach ([$desde, $hasta] as $f) {
            if (!self::fechaValida($f)) {
                throw new ErrorValidacion(['fecha' => 'Fecha inválida. Formato esperado AAAA-MM-DD.']);
            }
        }
        if ($desde > $hasta) {
            throw new ErrorValidacion(['hasta' => 'La fecha de fin es anterior a la de inicio.']);
        }

        $where = ['j.fecha BETWEEN :desde AND :hasta'];
        $par = [':desde' => $desde, ':hasta' => $hasta];
        if (isset($filtros['ruta_id'])) { $where[] = 'j.ruta_id = :ruta'; $par[':ruta'] = (int) $filtros['ruta_id']; }
        if (isset($filtros['cliente_id'])) { $where[] = 's.cliente_id = :cli'; $par[':cli'] = (int) $filtros['cliente_id']; }

        $paradas = Db::todas(
            'SELECT pe.id, pe.jornada_id, pe.orden, pe.estado, pe.motivo,
                    pe.cantidad_plan, pe.cantidad_real, pe.arribo_utc, pe.cierre_utc,
                    pe.conciliacion, pe.flujo,
                    j.fecha, j.estado AS jornada_estado, j.km_odo, j.km_hav,
                    r.id AS ruta_id, r.nombre AS ruta, r.km_estimado,
                    s.id AS sitio_id, s.nombre AS sitio,
                    c.id AS cliente_id, c.nombre AS cliente,
                    u.nombre AS chofer
               FROM parada_ejecucion pe
               JOIN jornada j        ON j.id = pe.jornada_id
               JOIN ruta_plantilla r ON r.id = j.ruta_id
               JOIN sitio s          ON s.id = pe.sitio_id
          LEFT JOIN cliente c        ON c.id = s.cliente_id
          LEFT JOIN usuario u        ON u.id = j.chofer_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY j.fecha, pe.jornada_id, pe.orden',
            $par
        );

        $desvios = [];
        $porJornada = [];

        foreach ($paradas as $p) {
            $porJornada[(int) $p['jornada_id']] ??= $p;
            foreach (self::deParada($p) as $d) $desvios[] = $d;
        }

        // El kilometraje es de la RUTA ENTERA, no del cliente. En un informe
        // filtrado por cliente, mostrar "+90 km" de una ruta que atiende a
        // cinco clientes le carga a uno solo un desvío que no es suyo.
        $filtradoPorCliente = isset($filtros['cliente_id']);
        if (!$filtradoPorCliente) {
            foreach ($porJornada as $j) {
                $d = self::deJornada($j);
                if ($d !== null) $desvios[] = $d;
            }
        }

        // Una jornada CERRADA sin una sola parada no aparecía en ningún lado:
        // el JOIN con parada_ejecucion es interno, así que el día en que el
        // cron de planificación falló era justamente el único invisible. Es el
        // que más hay que mirar.
        foreach (self::jornadasVacias($desde, $hasta, $filtros) as $d) $desvios[] = $d;

        // Fuera de orden: hace falta mirar la jornada entera, no una parada.
        foreach (self::fueraDeOrden($paradas) as $d) $desvios[] = $d;

        usort($desvios, static function ($a, $b) {
            $peso = ['alta' => 0, 'media' => 1, 'baja' => 2];
            return [$a['fecha'], $peso[$a['severidad']], $a['orden'] ?? 0]
               <=> [$b['fecha'], $peso[$b['severidad']], $b['orden'] ?? 0];
        });

        return [
            'desde'    => $desde,
            'hasta'    => $hasta,
            'jornadas' => count($porJornada),
            'paradas'  => count($paradas),
            'desvios'  => $desvios,
            'resumen'  => self::resumir($desvios),
        ];
    }

    /** ¿Es una fecha real del calendario y no sólo algo con forma de fecha? */
    public static function fechaValida(mixed $f): bool
    {
        if (!is_string($f)) return false;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f);
        return $d !== false && $d->format('Y-m-d') === $f;
    }

    /**
     * Jornadas cerradas sin ninguna parada.
     *
     * No salen de la consulta principal porque el JOIN es interno. Es el
     * síntoma de que el cron de planificación no corrió, y hasta ahora el día
     * quedaba fuera del informe en vez de encabezarlo.
     */
    private static function jornadasVacias(string $desde, string $hasta, array $filtros): array
    {
        if (isset($filtros['cliente_id'])) return [];   // no es de un cliente

        $par = [':desde' => $desde, ':hasta' => $hasta];
        $extra = '';
        if (isset($filtros['ruta_id'])) { $extra = ' AND j.ruta_id = :ruta'; $par[':ruta'] = (int) $filtros['ruta_id']; }

        $filas = Db::todas(
            "SELECT j.id, j.fecha, j.estado, r.id AS ruta_id, r.nombre AS ruta, u.nombre AS chofer
               FROM jornada j
               JOIN ruta_plantilla r ON r.id = j.ruta_id
          LEFT JOIN usuario u ON u.id = j.chofer_id
              WHERE j.fecha BETWEEN :desde AND :hasta" . $extra . "
                AND NOT EXISTS (SELECT 1 FROM parada_ejecucion pe WHERE pe.jornada_id = j.id)
              ORDER BY j.fecha",
            $par
        );

        return array_map(static fn($j) => [
            'jornada_id' => (int) $j['id'],
            'parada_id'  => null,
            'fecha'      => (string) $j['fecha'],
            'ruta_id'    => (int) $j['ruta_id'],
            'ruta'       => (string) $j['ruta'],
            'sitio_id'   => null,
            'sitio'      => null,
            'cliente_id' => null,
            'cliente'    => null,
            'chofer'     => $j['chofer'],
            'orden'      => null,
            'tipo'       => 'omitida',
            'severidad'  => 'alta',
            'detalle'    => 'La jornada existe pero NO tiene ninguna parada. ' .
                            'Es lo que pasa cuando el cron de planificación no corrió: ' .
                            'no hay plan contra el cual medir nada de ese día.',
            'valores'    => ['estado' => $j['estado']],
        ], $filas);
    }

    /** Desvíos que se ven mirando UNA parada. */
    private static function deParada(array $p): array
    {
        $salida = [];
        $base = [
            'jornada_id' => (int) $p['jornada_id'],
            'parada_id'  => (int) $p['id'],
            'fecha'      => (string) $p['fecha'],
            'ruta_id'    => (int) $p['ruta_id'],
            'ruta'       => (string) $p['ruta'],
            'sitio_id'   => (int) $p['sitio_id'],
            'sitio'      => (string) $p['sitio'],
            'cliente_id' => $p['cliente_id'] === null ? null : (int) $p['cliente_id'],
            'cliente'    => $p['cliente'],
            'chofer'     => $p['chofer'],
            'orden'      => (int) $p['orden'],
        ];

        // --- Omitida -------------------------------------------------------
        $cerrada = in_array($p['jornada_estado'], ['cerrada', 'cerrada_confirmada'], true);
        if ($p['estado'] === 'no_ejecutada') {
            $conMotivo = trim((string) $p['motivo']) !== '';
            $salida[] = $base + [
                'tipo' => 'omitida',
                // Con motivo es operación normal: el portón estaba cerrado.
                // Sin motivo, seis meses después nadie puede explicarla.
                'severidad' => $conMotivo ? 'media' : 'alta',
                'detalle' => $conMotivo
                    ? 'No se hizo: ' . mb_substr((string) $p['motivo'], 0, 120)
                    : 'No se hizo y NO tiene motivo cargado.',
                'valores' => ['motivo' => $p['motivo']],
            ];
        } elseif ($p['estado'] === 'planificada' && $cerrada) {
            $salida[] = $base + [
                'tipo' => 'omitida',
                'severidad' => 'alta',
                'detalle' => 'La jornada se cerró y esta parada quedó sin resolver: ni hecha ni con motivo.',
                'valores' => [],
            ];
        }

        // --- Cantidad distinta --------------------------------------------
        if ($p['estado'] === 'ejecutada' && $p['cantidad_real'] !== null
            && (int) $p['cantidad_real'] !== (int) $p['cantidad_plan']) {
            $dif = (int) $p['cantidad_real'] - (int) $p['cantidad_plan'];
            $salida[] = $base + [
                'tipo' => 'cantidad_distinta',
                'severidad' => 'media',
                'detalle' => 'Se planificaron ' . $p['cantidad_plan'] . ' y se atendieron ' . $p['cantidad_real'] .
                             ' (' . ($dif > 0 ? '+' : '') . $dif . ').',
                'valores' => ['plan' => (int) $p['cantidad_plan'], 'real' => (int) $p['cantidad_real'], 'diferencia' => $dif],
            ];
        }

        // --- Fuera de horario ---------------------------------------------
        // No hay hora planificada por parada en la plantilla, así que el
        // desvío se mide contra la ventana operativa. Decir "llegó tarde"
        // sin una hora planificada seria inventar una referencia.
        $ref = $p['arribo_utc'] ?? $p['cierre_utc'];
        if ($p['estado'] === 'ejecutada' && $ref !== null) {
            $hora = (int) gmdate('G', strtotime((string) $ref . ' UTC') + self::ART);
            $desde = (int) Config::get('operacion.hora_desde', self::HORA_DESDE);
            $hasta = (int) Config::get('operacion.hora_hasta', self::HORA_HASTA);
            if ($hora < $desde || $hora >= $hasta) {
                $salida[] = $base + [
                    'tipo' => 'fuera_de_horario',
                    'severidad' => 'media',
                    'detalle' => 'Se atendió a las ' . gmdate('H:i', strtotime((string) $ref . ' UTC') + self::ART) .
                                 ', fuera de la ventana de ' . sprintf('%02d:00 a %02d:00', $desde, $hasta) . '.',
                    'valores' => ['hora' => gmdate('H:i', strtotime((string) $ref . ' UTC') + self::ART)],
                ];
            }
        }

        // --- Evidencia -----------------------------------------------------
        if ($p['estado'] === 'ejecutada' && in_array($p['conciliacion'], ['contradiccion', 'sin_evidencia'], true)) {
            $salida[] = $base + [
                'tipo' => 'evidencia',
                'severidad' => $p['conciliacion'] === 'contradiccion' ? 'alta' : 'media',
                'detalle' => $p['conciliacion'] === 'contradiccion'
                    ? 'Las dos fuentes de posición se contradicen.'
                    : 'Figura como hecha sin evidencia de posición de ninguna fuente.',
                'valores' => ['conciliacion' => $p['conciliacion']],
            ];
        }

        return $salida;
    }

    /** Desvío de kilometraje: se mira la jornada, no la parada. */
    private static function deJornada(array $j): ?array
    {
        $estimado = (float) $j['km_estimado'];
        $real = $j['km_odo'] !== null ? (float) $j['km_odo'] : ($j['km_hav'] !== null ? (float) $j['km_hav'] : null);
        if ($real === null || $estimado <= 0) return null;

        $dif = $real - $estimado;
        if (abs($dif) / $estimado <= self::KM_TOLERANCIA) return null;

        // El haversine infla alrededor de un 9% por ruido de GPS. Si el km
        // real sale de ahi y no del odometro, se dice, porque la diferencia
        // puede ser del metodo y no del recorrido.
        $porOdometro = $j['km_odo'] !== null;

        return [
            'jornada_id' => (int) $j['jornada_id'],
            'parada_id'  => null,
            'fecha'      => (string) $j['fecha'],
            'ruta_id'    => (int) $j['ruta_id'],
            'ruta'       => (string) $j['ruta'],
            'sitio_id'   => null,
            'sitio'      => null,
            'cliente_id' => null,
            'cliente'    => null,
            'chofer'     => $j['chofer'],
            'orden'      => null,
            'tipo'       => 'km',
            'severidad'  => abs($dif) / $estimado > 0.5 ? 'alta' : 'media',
            'detalle'    => 'Estimados ' . self::km($estimado) . ' y recorridos ' . self::km($real) .
                            ' (' . ($dif > 0 ? '+' : '') . self::km($dif) . ')' .
                            ($porOdometro ? ' por odómetro.' : ' por posiciones, que inflan alrededor de un 9 % por ruido de GPS.'),
            'valores'    => ['estimado' => $estimado, 'real' => $real, 'diferencia' => $dif, 'por_odometro' => $porOdometro],
        ];
    }

    /**
     * Paradas atendidas fuera del orden planificado.
     *
     * Se compara la secuencia de arribos con la secuencia de `orden`. Cuenta
     * como desvío la parada que se atendió DESPUÉS de una que la sigue en el
     * plan, no cada par desordenado: de lo contrario una sola parada corrida
     * al final generaría doce desvíos.
     */
    private static function fueraDeOrden(array $paradas): array
    {
        $porJornada = [];
        foreach ($paradas as $p) {
            if ($p['estado'] !== 'ejecutada' || $p['arribo_utc'] === null) continue;
            $porJornada[(int) $p['jornada_id']][] = $p;
        }

        $salida = [];
        foreach ($porJornada as $lista) {
            usort($lista, static fn($a, $b) => strcmp((string) $a['arribo_utc'], (string) $b['arribo_utc']));

            // CUÁNTAS HAY QUE SACAR PARA QUE QUEDE ORDENADA, no cuántos pares
            // están desordenados.
            //
            // El criterio anterior —"su orden es menor que el máximo visto"—
            // cubría bien la parada corrida al final y fallaba en el simétrico.
            // Con la #12 hecha primero y después 1..11 en orden, marcaba ONCE
            // desvíos, uno por cada parada que SÍ se hizo en orden, cada uno
            // diciendo "se atendió después del 12". Once alertas falsas para
            // un solo hecho.
            //
            // Lo correcto es la subsecuencia creciente más larga: las que no
            // entran en ella son las que están fuera de lugar. Con 12 paradas
            // el costo cuadrático es irrelevante.
            $n = count($lista);
            $largo = array_fill(0, $n, 1);
            $previo = array_fill(0, $n, -1);
            $mejorFin = 0;
            for ($i = 1; $i < $n; $i++) {
                for ($k = 0; $k < $i; $k++) {
                    if ((int) $lista[$k]['orden'] < (int) $lista[$i]['orden'] && $largo[$k] + 1 > $largo[$i]) {
                        $largo[$i] = $largo[$k] + 1;
                        $previo[$i] = $k;
                    }
                }
                if ($largo[$i] > $largo[$mejorFin]) $mejorFin = $i;
            }
            $enOrden = [];
            for ($i = $mejorFin; $i >= 0; $i = $previo[$i]) {
                $enOrden[$i] = true;
                if ($previo[$i] === -1) break;
            }

            foreach ($lista as $idx => $p) {
                $orden = (int) $p['orden'];
                if (!isset($enOrden[$idx])) {
                    $salida[] = [
                        'jornada_id' => (int) $p['jornada_id'],
                        'parada_id'  => (int) $p['id'],
                        'fecha'      => (string) $p['fecha'],
                        'ruta_id'    => (int) $p['ruta_id'],
                        'ruta'       => (string) $p['ruta'],
                        'sitio_id'   => (int) $p['sitio_id'],
                        'sitio'      => (string) $p['sitio'],
                        'cliente_id' => $p['cliente_id'] === null ? null : (int) $p['cliente_id'],
                        'cliente'    => $p['cliente'],
                        'chofer'     => $p['chofer'],
                        'orden'      => $orden,
                        'tipo'       => 'fuera_de_orden',
                        // Casi nunca es un problema: un corte de calle, un
                        // cliente que pidió más tarde. Interesa en la suma del
                        // mes, no cada vez.
                        'severidad'  => 'baja',
                        'detalle'    => 'Estaba en el lugar ' . $orden . ' de la hoja de ruta y se atendió ' .
                                        ($idx === 0
                                            ? 'primera, antes que todas las anteriores.'
                                            : 'fuera de esa secuencia (' . ($idx + 1) . 'ª del día).'),
                        'valores'    => ['orden_plan' => $orden, 'posicion_real' => $idx + 1],
                    ];
                }
            }
        }
        return $salida;
    }

    /** Agrupa por lo que a alguien le sirve mirar: tipo, día, ruta y cliente. */
    private static function resumir(array $desvios): array
    {
        $porTipo = array_fill_keys(array_keys(self::TIPOS), 0);
        $porSeveridad = ['alta' => 0, 'media' => 0, 'baja' => 0];
        $porFecha = [];
        $porRuta = [];
        $porCliente = [];
        $porSemana = [];

        foreach ($desvios as $d) {
            $porTipo[$d['tipo']]++;
            $porSeveridad[$d['severidad']]++;
            $porFecha[$d['fecha']] = ($porFecha[$d['fecha']] ?? 0) + 1;
            $porRuta[$d['ruta']] = ($porRuta[$d['ruta']] ?? 0) + 1;
            if ($d['cliente'] !== null) $porCliente[$d['cliente']] = ($porCliente[$d['cliente']] ?? 0) + 1;

            // Semana ISO: es como se mira una ruta semanal.
            $sem = gmdate('o-\WW', strtotime($d['fecha'] . ' 12:00:00 UTC'));
            $porSemana[$sem] = ($porSemana[$sem] ?? 0) + 1;
        }

        arsort($porCliente);
        ksort($porFecha);
        ksort($porSemana);

        return [
            'total'       => count($desvios),
            'por_tipo'    => $porTipo,
            'por_severidad' => $porSeveridad,
            'por_fecha'   => $porFecha,
            'por_semana'  => $porSemana,
            'por_ruta'    => $porRuta,
            'por_cliente' => array_slice($porCliente, 0, 20, true),
        ];
    }

    private static function km(float $n): string
    {
        return number_format(abs($n), 1, ',', '.') . ' km';
    }
}
