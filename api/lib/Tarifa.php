<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Tarifario. Obj. 3, paso F3.2.
 *
 * Tres reglas, cada una con su motivo en la migración 0021:
 *   · una tarifa no se edita: se cierra y se carga la siguiente;
 *   · dos tarifas del mismo cliente y servicio no valen el mismo día;
 *   · todo en centavos enteros, y el IVA no vive acá.
 *
 * Qué precio vale un día: la tarifa PROPIA del cliente si tiene una vigente
 * ese día; si no, la de la lista general. Las fechas son de Argentina y los
 * dos bordes de la vigencia son inclusivos.
 */
final class Tarifa
{
    public static function servicios(): array
    {
        return Db::todas('SELECT codigo, nombre, unidad, unidad_plural FROM servicio WHERE activo = 1 ORDER BY nombre');
    }

    public static function listar(?int $clienteId = null): array
    {
        $sql = 'SELECT t.*, c.nombre AS cliente, s.nombre AS servicio
                  FROM tarifa t
                  JOIN servicio s ON s.codigo = t.servicio_codigo
             LEFT JOIN cliente c ON c.id = t.cliente_id';
        $par = [];
        if ($clienteId !== null) { $sql .= ' WHERE t.cliente_id = :c OR t.cliente_id IS NULL'; $par[':c'] = $clienteId; }
        $sql .= ' ORDER BY c.nombre IS NOT NULL, c.nombre, s.nombre, t.vigente_desde DESC';
        return array_map([self::class, 'publica'], Db::todas($sql, $par));
    }

    private static function publica(array $t): array
    {
        return [
            'id'                   => (int) $t['id'],
            'cliente_id'           => $t['cliente_id'] === null ? null : (int) $t['cliente_id'],
            'cliente'              => $t['cliente'] ?? null,
            'servicio_codigo'      => $t['servicio_codigo'],
            'servicio'             => $t['servicio'] ?? null,
            'precio_unitario_cent' => (int) $t['precio_unitario_cent'],
            'minimo_cent'          => (int) $t['minimo_cent'],
            'adicional_km_cent'    => (int) $t['adicional_km_cent'],
            'adicional_hora_cent'  => (int) $t['adicional_hora_cent'],
            'vigente_desde'        => $t['vigente_desde'],
            'vigente_hasta'        => $t['vigente_hasta'],
            'nota'                 => $t['nota'],
        ];
    }

    // ------------------------------------------------------------------
    // Alta y cierre
    // ------------------------------------------------------------------

    /**
     * Carga una tarifa nueva. Rechaza la que se superpone con otra del mismo
     * cliente (o de la lista general) y servicio.
     *
     * El chequeo y el alta van bajo un lock con nombre por (cliente, servicio):
     * sin él, dos personas cargando a la vez el mismo precio pasaban las dos el
     * chequeo y quedaban dos tarifas para el mismo día. Un FOR UPDATE no sirve
     * acá porque cuando todavía no hay ninguna tarifa no hay fila que bloquear.
     */
    public static function crear(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $cliente = $v->entero('cliente_id', 1, PHP_INT_MAX, false);
        $servicio = $v->texto('servicio_codigo', 1, 30);
        $unit = $v->entero('precio_unitario_cent', 0, 100_000_000_000);
        $min = $v->entero('minimo_cent', 0, 100_000_000_000, false) ?? 0;
        $km = $v->entero('adicional_km_cent', 0, 100_000_000_000, false) ?? 0;
        $hora = $v->entero('adicional_hora_cent', 0, 100_000_000_000, false) ?? 0;
        $desde = self::fecha($d['vigente_desde'] ?? null, 'vigente_desde');
        $hasta = isset($d['vigente_hasta']) && $d['vigente_hasta'] !== '' && $d['vigente_hasta'] !== null
            ? self::fecha($d['vigente_hasta'], 'vigente_hasta') : null;
        $nota = $v->texto('nota', 1, 255, false);
        $v->fin();

        if ($hasta !== null && $hasta < $desde) {
            throw new ErrorValidacion(['vigente_hasta' => 'La vigencia termina antes de empezar.']);
        }
        if (Db::col('SELECT codigo FROM servicio WHERE codigo = :s AND activo = 1', [':s' => $servicio]) === null) {
            throw new ErrorValidacion(['servicio_codigo' => 'Ese servicio no existe.']);
        }
        if ($cliente !== null) {
            $c = Db::una('SELECT activo, nombre FROM cliente WHERE id = :c', [':c' => $cliente]);
            if ($c === null) throw new ErrorNoEncontrado('No existe ese cliente.');
            if ((int) $c['activo'] !== 1) {
                throw new ErrorConflicto("«{$c['nombre']}» se fusionó en otro cliente: la tarifa va en el que quedó.", 'CLIENTE_FUSIONADO');
            }
        }

        return self::conLock($cliente, $servicio, static function () use ($cliente, $servicio, $unit, $min, $km, $hora, $desde, $hasta, $nota, $usuarioId): array {
            $choca = self::superpuesta($cliente, $servicio, $desde, $hasta);
            if ($choca !== null) {
                throw new ErrorConflicto(
                    'Ya hay una tarifa para ese servicio que vale del ' . $choca['vigente_desde'] .
                    ($choca['vigente_hasta'] ? ' al ' . $choca['vigente_hasta'] : ' en adelante') .
                    '. Primero se cierra esa, con la fecha en que deja de valer.',
                    'TARIFA_SUPERPUESTA'
                );
            }
            // Una tarifa propia del cliente con fecha para atrás cambiaría el
            // precio de días que ya se le facturaron con la general (0021;
            // revisión de la Fase 3). La general no puede: si esos días tenían
            // precio, era otra general, y esa ya choca arriba.
            if ($cliente !== null) {
                $facturado = Db::col("SELECT MAX(l.fecha) FROM factura_linea l JOIN factura_propuesta p ON p.id = l.propuesta_id
                                       WHERE p.cliente_id = :c AND p.estado = 'aprobada' AND l.servicio_codigo = :s
                                         AND l.fecha >= :d AND (:h IS NULL OR l.fecha <= :h2)",
                                     [':c' => $cliente, ':s' => $servicio, ':d' => $desde, ':h' => $hasta, ':h2' => $hasta]);
                if ($facturado !== null) {
                    throw new ErrorConflicto("A ese cliente ya se le facturó el $facturado con otro precio: la tarifa nueva empieza después de esa fecha.",
                        'TARIFA_RETROACTIVA');
                }
            }
            Db::q(
                'INSERT INTO tarifa (cliente_id, servicio_codigo, precio_unitario_cent, minimo_cent, adicional_km_cent,
                                     adicional_hora_cent, vigente_desde, vigente_hasta, nota, creado_por, creado_utc)
                 VALUES (:c, :s, :u, :m, :k, :h, :d, :ha, :n, :por, UTC_TIMESTAMP())',
                [':c' => $cliente, ':s' => $servicio, ':u' => $unit, ':m' => $min, ':k' => $km, ':h' => $hora,
                 ':d' => $desde, ':ha' => $hasta, ':n' => $nota, ':por' => $usuarioId]
            );
            $id = Db::insertarId();
            Hash::auditar('tarifa', $id, 'creada', [
                'cliente' => $cliente, 'servicio' => $servicio, 'unitario' => $unit, 'minimo' => $min,
                'km' => $km, 'hora' => $hora, 'desde' => $desde, 'hasta' => $hasta,
            ]);
            return self::publica(Db::una('SELECT t.*, c.nombre AS cliente, s.nombre AS servicio FROM tarifa t
                JOIN servicio s ON s.codigo = t.servicio_codigo LEFT JOIN cliente c ON c.id = t.cliente_id WHERE t.id = :id', [':id' => $id]));
        });
    }

    /**
     * Cierra una tarifa: deja de valer DESPUÉS de $hasta (inclusive). No se
     * puede cerrar antes de su comienzo, ni reabrir una ya cerrada: eso sería
     * editarla.
     */
    public static function cerrar(int $id, string $hasta, ?int $usuarioId): array
    {
        $hasta = self::fecha($hasta, 'hasta');
        $t = Db::una('SELECT * FROM tarifa WHERE id = :id', [':id' => $id]);
        if ($t === null) throw new ErrorNoEncontrado('No existe esa tarifa.');
        if ($t['vigente_hasta'] !== null) {
            throw new ErrorConflicto('Esa tarifa ya está cerrada al ' . $t['vigente_hasta'] . '. Para otro precio se carga una nueva.', 'TARIFA_CERRADA');
        }
        if ($hasta < $t['vigente_desde']) {
            throw new ErrorValidacion(['hasta' => 'No se cierra antes del día en que empezó (' . $t['vigente_desde'] . ').']);
        }
        return self::conLock($t['cliente_id'] === null ? null : (int) $t['cliente_id'], (string) $t['servicio_codigo'],
            static function () use ($id, $hasta, $usuarioId): array {
                // No se cierra por debajo de lo ya facturado con ella: la PF
                // aprobada diría ese precio para un día en que la tabla ya no
                // lo tendría (0021; revisión de la Fase 3).
                $ultima = Db::col("SELECT MAX(l.fecha) FROM factura_linea l JOIN factura_propuesta p ON p.id = l.propuesta_id
                                    WHERE l.tarifa_id = :t AND p.estado = 'aprobada'", [':t' => $id]);
                if ($ultima !== null && $ultima > $hasta) {
                    throw new ErrorConflicto("Con esta tarifa ya se facturó el $ultima: se cierra desde ese día en adelante, no antes.", 'TARIFA_FACTURADA');
                }
                $st = Db::q('UPDATE tarifa SET vigente_hasta = :h, cerrado_por = :u, cerrado_utc = UTC_TIMESTAMP()
                              WHERE id = :id AND vigente_hasta IS NULL', [':h' => $hasta, ':u' => $usuarioId, ':id' => $id]);
                if ($st->rowCount() !== 1) throw new ErrorConflicto('Alguien la cerró mientras tanto.', 'TARIFA_CERRADA');
                Hash::auditar('tarifa', $id, 'cerrada', ['hasta' => $hasta]);
                return ['id' => $id, 'vigente_hasta' => $hasta];
            });
    }

    // ------------------------------------------------------------------
    // Precio
    // ------------------------------------------------------------------

    /** La tarifa que vale para (cliente, servicio) un día, o null si no hay ninguna. */
    public static function vigente(?int $clienteId, string $servicio, string $fecha): ?array
    {
        $buscar = static fn(?int $c) => Db::una(
            'SELECT * FROM tarifa
              WHERE servicio_codigo = :s AND ' . ($c === null ? 'cliente_id IS NULL' : 'cliente_id = :c') . '
                AND vigente_desde <= :f AND (vigente_hasta IS NULL OR vigente_hasta >= :f2)
              ORDER BY vigente_desde DESC LIMIT 1',
            array_filter([':s' => $servicio, ':c' => $c, ':f' => $fecha, ':f2' => $fecha], static fn($x) => $x !== null)
        );
        $t = $clienteId !== null ? $buscar($clienteId) : null;
        return $t ?? $buscar(null);
    }

    /**
     * Cuánto se cobra: max(cantidad × unitario, mínimo) + km × adicional + horas × adicional.
     *
     * Los km y las horas pueden tener decimales (12,5 km); el producto se
     * redondea al centavo, mitad hacia arriba, UNA sola vez por concepto.
     * Todo lo demás es aritmética de enteros.
     *
     * @return array{tarifa_id:int, origen:string, unitario_cent:int, base_cent:int, minimo_aplicado:bool,
     *               km_cent:int, horas_cent:int, subtotal_cent:int}
     */
    public static function cotizar(?int $clienteId, string $servicio, string $fecha, int $cantidad, float $km = 0.0, float $horas = 0.0): array
    {
        if ($cantidad < 0) throw new ErrorValidacion(['cantidad' => 'La cantidad no puede ser negativa.']);
        $t = self::vigente($clienteId, $servicio, self::fecha($fecha, 'fecha'));
        if ($t === null) {
            throw new ErrorConflicto(
                "No hay tarifa de «{$servicio}» vigente el {$fecha}, ni propia del cliente ni en la lista general. " .
                'Sin precio no se factura: hay que cargarla.',
                'SIN_TARIFA'
            );
        }
        $unit = (int) $t['precio_unitario_cent'];
        $base = $cantidad * $unit;
        $min = (int) $t['minimo_cent'];
        $aplicaMin = $cantidad > 0 && $base < $min;
        $base = $aplicaMin ? $min : $base;
        $kmCent = (int) round(max(0.0, $km) * (int) $t['adicional_km_cent'], 0, PHP_ROUND_HALF_UP);
        $hsCent = (int) round(max(0.0, $horas) * (int) $t['adicional_hora_cent'], 0, PHP_ROUND_HALF_UP);
        return [
            'tarifa_id'       => (int) $t['id'],
            'origen'          => $t['cliente_id'] === null ? 'lista_general' : 'propia',
            'unitario_cent'   => $unit,
            'base_cent'       => $base,
            'minimo_aplicado' => $aplicaMin,
            'km_cent'         => $kmCent,
            'horas_cent'      => $hsCent,
            'subtotal_cent'   => $base + $kmCent + $hsCent,
        ];
    }

    /** Pesos argentinos para leer: $ 12.345,67. Los importes viajan en centavos. */
    public static function pesos(int $cent): string
    {
        $signo = $cent < 0 ? '-' : '';
        $cent = abs($cent);
        return $signo . '$ ' . number_format(intdiv($cent, 100), 0, ',', '.') . ',' . str_pad((string) ($cent % 100), 2, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------

    private static function superpuesta(?int $clienteId, string $servicio, string $desde, ?string $hasta): ?array
    {
        // Dos intervalos [a1, b1] y [a2, b2] (b NULL = infinito) se tocan si
        // a1 <= b2 y a2 <= b1.
        return Db::una(
            'SELECT id, vigente_desde, vigente_hasta FROM tarifa
              WHERE servicio_codigo = :s AND ' . ($clienteId === null ? 'cliente_id IS NULL' : 'cliente_id = :c') . '
                AND (vigente_hasta IS NULL OR vigente_hasta >= :d)
                ' . ($hasta === null ? '' : 'AND vigente_desde <= :h') . '
              LIMIT 1',
            array_filter([':s' => $servicio, ':c' => $clienteId, ':d' => $desde, ':h' => $hasta], static fn($x) => $x !== null)
        );
    }

    private static function conLock(?int $clienteId, string $servicio, callable $fn): mixed
    {
        return self::bloqueadas([[$clienteId, $servicio]], static fn() => Db::tx($fn));
    }

    /**
     * Corre $fn con las tarifas de esos (cliente, servicio) quietas: nadie las
     * carga ni las cierra mientras tanto. Es el mismo lock con nombre que toman
     * crear() y cerrar(), así que aprobar una propuesta (que vuelve a cotizar
     * sus líneas) y tocar una tarifa de ese cliente van de a uno.
     *
     * @param array<array{0: ?int, 1: string}> $claves
     */
    public static function bloqueadas(array $claves, callable $fn): mixed
    {
        $nombres = array_values(array_unique(array_map(
            static fn(array $k) => 'camca_tarifa_' . ($k[0] ?? 'general') . '_' . $k[1], $claves)));
        sort($nombres);   // siempre en el mismo orden: dos que esperan no se cruzan
        $tomados = [];
        try {
            foreach ($nombres as $n) {
                if ((int) Db::col('SELECT GET_LOCK(:n, 10)', [':n' => $n]) !== 1) {
                    throw new ErrorConflicto('Otra persona está cargando una tarifa para lo mismo. Probá de nuevo en un momento.', 'TARIFA_OCUPADA');
                }
                $tomados[] = $n;
            }
            return $fn();
        } finally {
            foreach ($tomados as $n) Db::col('SELECT RELEASE_LOCK(:n)', [':n' => $n]);
        }
    }

    /** Una fecha AAAA-MM-DD que exista. La usan también la facturación y sus filtros. */
    public static function fechaValida(mixed $v, string $campo): string
    {
        return self::fecha($v, $campo);
    }

    private static function fecha(mixed $v, string $campo): string
    {
        $s = (string) $v;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4))) {
            throw new ErrorValidacion([$campo => 'Fecha inválida (AAAA-MM-DD).']);
        }
        return $s;
    }
}
