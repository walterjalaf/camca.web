<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Control operacional ambiental: disposición de efluentes de baños químicos.
 * Obj. 5, paso F4.2 (ISO 14001 §8.1).
 *
 * Lo que se tiene que poder contestar de cada servicio ejecutado es «¿a dónde
 * fue a parar lo que se sacó de esos baños?». La respuesta es la descarga en
 * una planta habilitada, con su manifiesto, atada a la jornada que la
 * recolectó. Si todavía no hay respuesta, el servicio lleva una alerta que lo
 * dice: nunca queda un servicio sin una cosa ni la otra.
 *
 * Lo recolectado no se mide en el baño: se ESTIMA con los baños atendidos por
 * los litros por baño de la configuración (S17), y se compara contra lo que la
 * planta dice que recibió. Una diferencia grande no prueba nada por sí sola,
 * pero es exactamente lo que un auditor ambiental pregunta.
 */
final class Ambiental
{
    public const MAX_DIAS = 92;
    /** Días antes del vencimiento de la habilitación de una planta en que se avisa. */
    public const AVISO_PLANTA_DIAS = 30;

    public static function hoy(): string { return gmdate('Y-m-d', time() - 3 * 3600); }

    public static function litrosPorBano(): int { return max(1, (int) Config::get('ambiental.litros_por_bano', 200)); }
    public static function plazoDias(): int { return max(0, (int) Config::get('ambiental.plazo_dias', 2)); }
    public static function tolerancia(): float { return min(0.9, max(0.0, (float) Config::get('ambiental.tolerancia', 0.35))); }

    // ------------------------------------------------------------------
    // Plantas
    // ------------------------------------------------------------------

    public static function guardarPlanta(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $id = $v->entero('id', 1, PHP_INT_MAX, false);
        $nombre = trim((string) $v->texto('nombre', 3, 120));
        $operador = $v->texto('operador', 2, 120, false);
        $habilitacion = trim((string) $v->texto('habilitacion', 2, 60));
        $vence = $v->texto('habilitacion_vence', 10, 10, false);
        $direccion = $v->texto('direccion', 3, 200, false);
        $activa = array_key_exists('activa', $d) ? (bool) $d['activa'] : true;
        $v->fin();
        if ($vence !== null) $vence = Tarifa::fechaValida($vence, 'habilitacion_vence');

        return Db::txReintentable(static function () use ($id, $nombre, $operador, $habilitacion, $vence, $direccion, $activa, $usuarioId): array {
            $datos = [':n' => $nombre, ':o' => $operador !== null ? trim($operador) : null, ':h' => $habilitacion, ':v' => $vence,
                      ':d' => $direccion !== null ? trim($direccion) : null, ':a' => $activa ? 1 : 0];
            try {
                if ($id === null) {
                    Db::q('INSERT INTO amb_planta (nombre, operador, habilitacion, habilitacion_vence, direccion, activa, creado_por, creado_utc)
                           VALUES (:n, :o, :h, :v, :d, :a, :u, UTC_TIMESTAMP())', $datos + [':u' => $usuarioId]);
                    $id = Db::insertarId();
                    $accion = 'alta';
                } else {
                    $antes = Db::una('SELECT * FROM amb_planta WHERE id = :i FOR UPDATE', [':i' => $id]);
                    if ($antes === null) throw new ErrorNoEncontrado('No existe esa planta.');
                    Db::q('UPDATE amb_planta SET nombre = :n, operador = :o, habilitacion = :h, habilitacion_vence = :v, direccion = :d, activa = :a
                            WHERE id = :i', $datos + [':i' => $id]);
                    $accion = 'modificada';
                }
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) throw new ErrorConflicto('Ya hay una planta con ese nombre.', 'PLANTA_DUPLICADA');
                throw $e;
            }
            Hash::auditar('amb_planta', $id, $accion, ['nombre' => $nombre, 'habilitacion' => $habilitacion, 'vence' => $vence,
                                                      'activa' => $activa, 'por' => $usuarioId]);
            return ['id' => $id];
        });
    }

    /** null si está en fecha; «vencida» o «por_vencer» si no. */
    public static function estadoPlanta(?string $vence, string $hoy): ?string
    {
        if ($vence === null) return null;
        if ($vence < $hoy) return 'vencida';
        return Asignacion::cantidadDias($hoy, $vence) - 1 <= self::AVISO_PLANTA_DIAS ? 'por_vencer' : null;
    }

    // ------------------------------------------------------------------
    // Descargas
    // ------------------------------------------------------------------

    /**
     * Registra una descarga en planta, atada a las jornadas que vacía.
     *
     * Se rechaza (no se anota con aviso) lo que no puede ser cierto: una planta
     * sin habilitación vigente ese día, un manifiesto ya usado, una descarga
     * anterior a la jornada que dice vaciar, o una jornada sin servicios.
     */
    public static function registrar(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $fecha = Tarifa::fechaValida((string) $v->texto('fecha', 10, 10), 'fecha');
        $plantaId = (int) $v->entero('planta_id', 1, PHP_INT_MAX);
        $vehiculoId = $v->entero('vehiculo_id', 1, PHP_INT_MAX, false);
        $litros = (int) $v->entero('litros', 1, 60000);
        $manifiesto = (string) $v->texto('manifiesto', 1, 60);
        $obs = $v->texto('observaciones', 2, 255, false);
        $v->fin();
        $jornadas = $d['jornadas'] ?? null;
        if (!is_array($jornadas) || $jornadas === [] || count($jornadas) > 20
            || array_filter($jornadas, static fn($x) => !is_int($x) || $x < 1) !== []) {
            throw new ErrorValidacion(['jornadas' => 'Elegí de qué jornadas es lo que se descargó (entre 1 y 20).']);
        }
        $jornadas = array_values(array_unique($jornadas));
        if ($fecha > self::hoy()) throw new ErrorValidacion(['fecha' => 'La descarga no puede ser de un día que todavía no pasó.']);
        // El manifiesto se compara como lo escribiría cualquiera: sin espacios
        // de más ni diferencias de mayúsculas.
        $manifiesto = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($manifiesto)) ?? '');
        if ($manifiesto === '') throw new ErrorValidacion(['manifiesto' => 'Falta el número de manifiesto.']);

        return Db::txReintentable(static function () use ($fecha, $plantaId, $vehiculoId, $litros, $manifiesto, $obs, $jornadas, $usuarioId): array {
            $planta = Db::una('SELECT id, nombre, activa, habilitacion, habilitacion_vence FROM amb_planta WHERE id = :p', [':p' => $plantaId]);
            if ($planta === null) throw new ErrorValidacion(['planta_id' => 'No existe esa planta.']);
            if ((int) $planta['activa'] !== 1) throw new ErrorConflicto('La planta ' . $planta['nombre'] . ' está dada de baja.', 'PLANTA_NO_HABILITADA');
            if ($planta['habilitacion_vence'] !== null && $planta['habilitacion_vence'] < $fecha) {
                throw new ErrorConflicto('La habilitación de ' . $planta['nombre'] . ' (' . $planta['habilitacion'] . ') venció el ' .
                    $planta['habilitacion_vence'] . ': no puede recibir efluentes del ' . $fecha . '.', 'PLANTA_NO_HABILITADA');
            }
            if ($vehiculoId !== null && Db::col('SELECT id FROM vehiculo WHERE id = :v', [':v' => $vehiculoId]) === null) {
                throw new ErrorValidacion(['vehiculo_id' => 'No existe ese vehículo.']);
            }
            foreach ($jornadas as $j) {
                $jor = Db::una("SELECT j.id, j.fecha, (SELECT COUNT(*) FROM parada_ejecucion pe WHERE pe.jornada_id = j.id AND pe.estado = 'ejecutada') AS hechas
                                  FROM jornada j WHERE j.id = :j", [':j' => $j]);
                if ($jor === null) throw new ErrorValidacion(['jornadas' => 'No existe la jornada ' . $j . '.']);
                if ((int) $jor['hechas'] === 0) {
                    throw new ErrorConflicto('La jornada del ' . $jor['fecha'] . ' no tiene servicios hechos: no hay nada que descargar.', 'JORNADA_SIN_SERVICIOS');
                }
                if ($jor['fecha'] > $fecha) {
                    throw new ErrorConflicto('La descarga es del ' . $fecha . ' y la jornada del ' . $jor['fecha'] . ': no se descarga antes de recolectar.', 'DESCARGA_ANTERIOR');
                }
            }
            try {
                Db::q("INSERT INTO amb_disposicion (fecha, planta_id, vehiculo_id, litros, manifiesto, manifiesto_clave, observaciones, estado, registrada_por, creado_utc)
                       VALUES (:f, :p, :v, :l, :m, :c, :o, 'vigente', :u, UTC_TIMESTAMP())",
                      [':f' => $fecha, ':p' => $plantaId, ':v' => $vehiculoId, ':l' => $litros, ':m' => $manifiesto,
                       ':c' => $plantaId . ':' . $manifiesto, ':o' => $obs !== null ? trim($obs) : null, ':u' => $usuarioId]);
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) {
                    throw new ErrorConflicto('El manifiesto ' . $manifiesto . ' de ' . $planta['nombre'] . ' ya está registrado en otra descarga.', 'MANIFIESTO_DUPLICADO');
                }
                throw $e;
            }
            $id = Db::insertarId();
            foreach ($jornadas as $j) {
                Db::q('INSERT INTO amb_disposicion_jornada (disposicion_id, jornada_id) VALUES (:d, :j)', [':d' => $id, ':j' => $j]);
            }
            Hash::auditar('amb_disposicion', $id, 'registrada', [
                'fecha' => $fecha, 'planta' => $planta['nombre'], 'habilitacion' => $planta['habilitacion'], 'litros' => $litros,
                'manifiesto' => $manifiesto, 'jornadas' => $jornadas, 'vehiculo_id' => $vehiculoId, 'por' => $usuarioId,
            ]);
            return ['id' => $id, 'manifiesto' => $manifiesto];
        });
    }

    /** Anula una descarga mal cargada. No se borra: queda con el motivo, y el manifiesto se libera. */
    public static function anular(int $id, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) throw new ErrorValidacion(['motivo' => 'El motivo es obligatorio.']);
        return Db::txReintentable(static function () use ($id, $motivo, $usuarioId): array {
            $d = Db::una('SELECT id, estado, manifiesto FROM amb_disposicion WHERE id = :i FOR UPDATE', [':i' => $id]);
            if ($d === null) throw new ErrorNoEncontrado('No existe esa descarga.');
            if ($d['estado'] !== 'vigente') throw new ErrorConflicto('Ya estaba anulada.', 'YA_ANULADA');
            Db::q("UPDATE amb_disposicion SET estado = 'anulada', manifiesto_clave = NULL, anulada_motivo = :m, anulada_utc = UTC_TIMESTAMP() WHERE id = :i",
                  [':m' => mb_substr($motivo, 0, 255), ':i' => $id]);
            Hash::auditar('amb_disposicion', $id, 'anulada', ['manifiesto' => $d['manifiesto'], 'motivo' => $motivo, 'por' => $usuarioId]);
            return ['id' => $id, 'estado' => 'anulada'];
        });
    }

    // ------------------------------------------------------------------
    // Trazabilidad
    // ------------------------------------------------------------------

    /**
     * Cada servicio ejecutado del período con su disposición, o con la alerta
     * que dice por qué todavía no la tiene. Esa es la propiedad que prueba
     * tools/prueba_ambiental.php sobre toda la base.
     *
     * @return array{servicios: list<array>, jornadas: array<int,array>}
     */
    public static function trazabilidad(string $desde, string $hasta, ?string $hoy = null): array
    {
        [$desde, $hasta] = self::rango($desde, $hasta);
        $hoy ??= self::hoy();
        $lxb = self::litrosPorBano();
        $plazo = self::plazoDias();
        $tol = self::tolerancia();

        $servicios = Db::todas(
            "SELECT pe.id AS parada_id, pe.jornada_id, j.fecha, r.nombre AS ruta, s.nombre AS sitio, c.nombre AS cliente,
                    COALESCE(pe.cantidad_real, pe.cantidad_plan) AS banos
               FROM parada_ejecucion pe
               JOIN jornada j         ON j.id = pe.jornada_id
               JOIN ruta_plantilla r  ON r.id = j.ruta_id
               JOIN sitio s           ON s.id = pe.sitio_id
               LEFT JOIN cliente c    ON c.id = s.cliente_id
              WHERE pe.estado = 'ejecutada' AND j.fecha BETWEEN :d AND :h
              ORDER BY j.fecha, pe.jornada_id, pe.orden",
            [':d' => $desde, ':h' => $hasta]
        );

        $jornadas = [];
        foreach ($servicios as $s) {
            $j = (int) $s['jornada_id'];
            $jornadas[$j] ??= ['jornada_id' => $j, 'fecha' => $s['fecha'], 'ruta' => $s['ruta'], 'servicios' => 0, 'banos' => 0,
                               'litros_estimados' => 0, 'litros_dispuestos' => 0, 'disposiciones' => [], 'alertas' => []];
            $jornadas[$j]['servicios']++;
            $jornadas[$j]['banos'] += (int) $s['banos'];
            $jornadas[$j]['litros_estimados'] += (int) $s['banos'] * $lxb;
        }

        if ($jornadas !== []) {
            // Las descargas vigentes de esas jornadas, y lo estimado de TODAS
            // las jornadas de cada descarga (también las de fuera del período):
            // lo descargado se reparte entre ellas en proporción a lo estimado.
            $ids = implode(',', array_map('intval', array_keys($jornadas)));
            $disp = Db::todas(
                "SELECT d.id, d.fecha, d.litros, d.manifiesto, p.nombre AS planta, dj.jornada_id
                   FROM amb_disposicion_jornada dj
                   JOIN amb_disposicion d ON d.id = dj.disposicion_id AND d.estado = 'vigente'
                   JOIN amb_planta p      ON p.id = d.planta_id
                  WHERE dj.jornada_id IN ($ids)
                  ORDER BY d.fecha, d.id"
            );
            $estPorDisp = [];
            if ($disp !== []) {
                $dids = implode(',', array_unique(array_map(static fn($x) => (int) $x['id'], $disp)));
                foreach (Db::todas(
                    "SELECT dj.disposicion_id, dj.jornada_id,
                            COALESCE(SUM(CASE WHEN pe.estado = 'ejecutada' THEN COALESCE(pe.cantidad_real, pe.cantidad_plan) END), 0) AS banos
                       FROM amb_disposicion_jornada dj
                       LEFT JOIN parada_ejecucion pe ON pe.jornada_id = dj.jornada_id
                      WHERE dj.disposicion_id IN ($dids)
                      GROUP BY dj.disposicion_id, dj.jornada_id"
                ) as $x) {
                    $estPorDisp[(int) $x['disposicion_id']][(int) $x['jornada_id']] = (int) $x['banos'] * $lxb;
                }
            }
            $reparto = [];
            foreach ($disp as $x) {
                $j = (int) $x['jornada_id'];
                $d = (int) $x['id'];
                $reparto[$d] ??= self::repartir((int) $x['litros'], $estPorDisp[$d] ?? []);
                $parte = $reparto[$d][$j] ?? 0;
                $jornadas[$j]['litros_dispuestos'] += $parte;
                $jornadas[$j]['disposiciones'][] = ['id' => $d, 'fecha' => $x['fecha'], 'planta' => $x['planta'],
                                                    'manifiesto' => $x['manifiesto'], 'litros' => (int) $x['litros'], 'litros_asignados' => $parte];
            }
        }

        foreach ($jornadas as $j => $x) {
            $limite = gmdate('Y-m-d', strtotime($x['fecha'] . ' 12:00:00 UTC') + $plazo * 86400);
            if ($x['disposiciones'] === []) {
                $jornadas[$j]['alertas'][] = $hoy > $limite
                    ? ['codigo' => 'sin_disposicion', 'nivel' => 'alta',
                       'texto' => 'Sin descarga registrada: el plazo venció el ' . $limite . '.']
                    : ['codigo' => 'pendiente', 'nivel' => 'baja',
                       'texto' => 'Todavía sin descarga; hay plazo hasta el ' . $limite . '.'];
                continue;
            }
            $primera = min(array_column($x['disposiciones'], 'fecha'));
            if ($primera > $limite) {
                $jornadas[$j]['alertas'][] = ['codigo' => 'fuera_de_plazo', 'nivel' => 'media',
                    'texto' => 'Descargada el ' . $primera . ', fuera del plazo de ' . $plazo . ' días.'];
            }
            if ($x['litros_dispuestos'] < $x['litros_estimados'] * (1 - $tol)) {
                $jornadas[$j]['alertas'][] = ['codigo' => 'volumen_bajo', 'nivel' => 'media',
                    'texto' => 'La planta recibió ' . $x['litros_dispuestos'] . ' L de unos ' . $x['litros_estimados'] . ' L estimados.'];
            }
        }

        $filas = array_map(static function (array $s) use ($jornadas, $lxb): array {
            $j = $jornadas[(int) $s['jornada_id']];
            return [
                'parada_id' => (int) $s['parada_id'], 'jornada_id' => (int) $s['jornada_id'], 'fecha' => $s['fecha'], 'ruta' => $s['ruta'],
                'sitio' => $s['sitio'], 'cliente' => $s['cliente'], 'banos' => (int) $s['banos'], 'litros_estimados' => (int) $s['banos'] * $lxb,
                'disposiciones' => array_map(static fn($d) => ['id' => $d['id'], 'fecha' => $d['fecha'], 'planta' => $d['planta'],
                                                              'manifiesto' => $d['manifiesto']], $j['disposiciones']),
                'alertas' => $j['alertas'],
            ];
        }, $servicios);

        return ['desde' => $desde, 'hasta' => $hasta, 'servicios' => $filas, 'jornadas' => array_values($jornadas)];
    }

    /**
     * Lo que muestra el panel: las jornadas del período con su estado, las
     * alertas, las descargas, las plantas y lo que hace falta para cargar una
     * descarga nueva.
     */
    public static function panel(string $desde, string $hasta): array
    {
        $t = self::trazabilidad($desde, $hasta);
        $hoy = self::hoy();
        $alertas = [];
        foreach ($t['jornadas'] as $j) {
            foreach ($j['alertas'] as $a) {
                if ($a['nivel'] !== 'baja') $alertas[] = $a + ['jornada_id' => $j['jornada_id'], 'fecha' => $j['fecha'], 'ruta' => $j['ruta']];
            }
        }
        $plantas = array_map(static fn($p) => [
            'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'operador' => $p['operador'], 'habilitacion' => $p['habilitacion'],
            'habilitacion_vence' => $p['habilitacion_vence'], 'direccion' => $p['direccion'], 'activa' => (int) $p['activa'] === 1,
            'estado' => (int) $p['activa'] === 1 ? self::estadoPlanta($p['habilitacion_vence'], $hoy) : null,
        ], Db::todas('SELECT * FROM amb_planta ORDER BY activa DESC, nombre'));
        foreach ($plantas as $p) {
            if ($p['activa'] && $p['estado'] !== null) {
                $alertas[] = ['codigo' => 'planta_' . $p['estado'], 'nivel' => $p['estado'] === 'vencida' ? 'alta' : 'media',
                              'texto' => 'Habilitación de ' . $p['nombre'] . ' (' . $p['habilitacion'] . ') ' .
                                         ($p['estado'] === 'vencida' ? 'vencida' : 'por vencer') . ' el ' . $p['habilitacion_vence'] . '.',
                              'planta_id' => $p['id']];
            }
        }
        usort($alertas, static fn($a, $b) => [$a['nivel'] === 'alta' ? 0 : 1, $a['fecha'] ?? ''] <=> [$b['nivel'] === 'alta' ? 0 : 1, $b['fecha'] ?? '']);

        // Una descarga puede vaciar hasta 20 jornadas: con el tope por defecto
        // de GROUP_CONCAT (1.024 bytes) la lista se cortaba sin avisar.
        Db::q('SET SESSION group_concat_max_len = 65535');
        $descargas = array_map(static fn($d) => [
            'id' => (int) $d['id'], 'fecha' => $d['fecha'], 'planta' => $d['planta'], 'patente' => $d['patente'], 'litros' => (int) $d['litros'],
            'manifiesto' => $d['manifiesto'], 'estado' => $d['estado'], 'anulada_motivo' => $d['anulada_motivo'], 'observaciones' => $d['observaciones'],
            'jornadas' => $d['jornadas'] ?? '',
        ], Db::todas(
            "SELECT d.*, p.nombre AS planta, v.patente,
                    (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(j.fecha, '%d/%m'), ' ', r.nombre) ORDER BY j.fecha SEPARATOR ' · ')
                       FROM amb_disposicion_jornada dj JOIN jornada j ON j.id = dj.jornada_id JOIN ruta_plantilla r ON r.id = j.ruta_id
                      WHERE dj.disposicion_id = d.id) AS jornadas
               FROM amb_disposicion d JOIN amb_planta p ON p.id = d.planta_id LEFT JOIN vehiculo v ON v.id = d.vehiculo_id
              WHERE d.fecha BETWEEN :d AND :h ORDER BY d.fecha DESC, d.id DESC",
            [':d' => $t['desde'], ':h' => $t['hasta']]
        ));

        $sumar = static fn(string $k) => array_sum(array_column($t['jornadas'], $k));
        $conDisp = count(array_filter($t['servicios'], static fn($s) => $s['disposiciones'] !== []));
        $pend = count(array_filter($t['servicios'], static fn($s) => $s['disposiciones'] === [] && ($s['alertas'][0]['codigo'] ?? '') === 'pendiente'));
        return [
            'desde' => $t['desde'], 'hasta' => $t['hasta'], 'hoy' => $hoy,
            'resumen' => [
                'servicios' => count($t['servicios']), 'con_disposicion' => $conDisp, 'pendientes' => $pend,
                'sin_disposicion' => count($t['servicios']) - $conDisp - $pend,
                'litros_estimados' => $sumar('litros_estimados'), 'litros_dispuestos' => $sumar('litros_dispuestos'),
            ],
            'jornadas' => $t['jornadas'], 'alertas' => $alertas, 'descargas' => $descargas, 'plantas' => $plantas,
            'vehiculos' => array_map(static fn($v) => ['id' => (int) $v['id'], 'patente' => $v['patente']],
                                     Db::todas('SELECT id, patente FROM vehiculo WHERE activo = 1 ORDER BY patente')),
            'config' => ['litros_por_bano' => self::litrosPorBano(), 'plazo_dias' => self::plazoDias(), 'tolerancia' => self::tolerancia()],
        ];
    }

    /**
     * Registro de disposición de efluentes del período, para imprimir y
     * archivar: es el registro ambiental que deriva de la ejecución.
     */
    public static function registroHtml(string $desde, string $hasta): string
    {
        $p = self::panel($desde, $hasta);
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fc = static fn(?string $f) => $f ? substr($f, 8, 2) . '/' . substr($f, 5, 2) . '/' . substr($f, 0, 4) : '';
        $emisor = Formulario::emisor();
        $filas = '';
        foreach ($p['jornadas'] as $j) {
            $desc = $j['disposiciones'] === [] ? '—' : implode('<br>', array_map(static fn($d) =>
                $e($fc($d['fecha'])) . ' · ' . $e($d['planta']) . ' · manifiesto ' . $e($d['manifiesto']) . ' · ' . $e($d['litros_asignados']) . ' L', $j['disposiciones']));
            $alertas = $j['alertas'] === [] ? 'Trazada' : implode('<br>', array_map(static fn($a) => $e($a['texto']), $j['alertas']));
            $filas .= '<tr><td>' . $e($fc($j['fecha'])) . '</td><td>' . $e($j['ruta']) . '</td><td class="n">' . $j['servicios'] . '</td>' .
                      '<td class="n">' . $j['banos'] . '</td><td class="n">' . $j['litros_estimados'] . '</td><td>' . $desc . '</td><td>' . $alertas . '</td></tr>';
        }
        if ($filas === '') $filas = '<tr><td colspan="7">No hubo servicios ejecutados en el período.</td></tr>';
        $plantas = implode('', array_map(static fn($x) => '<tr><td>' . $e($x['nombre']) . '</td><td>' . $e($x['operador'] ?? '') . '</td><td>' .
            $e($x['habilitacion']) . '</td><td>' . $e($x['habilitacion_vence'] ? $fc($x['habilitacion_vence']) : 'sin vencimiento declarado') . '</td></tr>', $p['plantas']));
        $r = $p['resumen'];
        $css = Documento::css() . '
table.datos { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: .3cm; }
table.datos th, table.datos td { border-bottom: 1px solid #ddd8cd; padding: 4px 5px; text-align: left; vertical-align: top; }
table.datos th { font-size: 8pt; text-transform: uppercase; letter-spacing: .04em; color: #5b6660; }
table.datos td.n { text-align: right; font-variant-numeric: tabular-nums; }
.resumen { display: flex; gap: .6cm; flex-wrap: wrap; margin: .3cm 0 0; font-size: 10pt; }
@page { size: A4 landscape; margin: 1cm; }';
        $logo = Documento::logo();
        $generado = gmdate('Y-m-d H:i', time() - 3 * 3600);
        return <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registro de disposición de efluentes {$e($fc($p['desde']))} a {$e($fc($p['hasta']))}</title>
<style>{$css}</style></head>
<body>
<article class="hoja">
  <header class="cabeza">
    <div>{$logo}<p class="emisor">{$e($emisor['razon_social'] ?? '')}</p><p class="emisor-dato">CUIT {$e($emisor['cuit'] ?? '')} · {$e($emisor['domicilio'] ?? '')}</p></div>
    <div class="identificacion"><p class="tipo">Registro de disposición de efluentes</p>
      <p class="numero">{$e($fc($p['desde']))} al {$e($fc($p['hasta']))}</p><p class="version">SGA · ISO 14001 §8.1 · control operacional</p></div>
  </header>
  <section class="bloque"><h2>Resumen</h2>
    <p class="resumen"><span>{$r['servicios']} servicios ejecutados</span><span>{$r['con_disposicion']} con descarga trazada</span>
      <span>{$r['pendientes']} en plazo</span><span>{$r['sin_disposicion']} sin descarga</span>
      <span>{$r['litros_estimados']} L estimados</span><span>{$r['litros_dispuestos']} L recibidos en planta</span></p>
    <p>Litros estimados: baños atendidos × {$p['config']['litros_por_bano']} L. Plazo para descargar: {$p['config']['plazo_dias']} días desde la jornada.</p>
  </section>
  <section class="bloque"><h2>Jornadas</h2>
    <table class="datos"><thead><tr><th>Fecha</th><th>Ruta</th><th>Servicios</th><th>Baños</th><th>Litros est.</th><th>Descarga en planta</th><th>Estado</th></tr></thead>
    <tbody>{$filas}</tbody></table></section>
  <section class="bloque"><h2>Plantas de tratamiento</h2>
    <table class="datos"><thead><tr><th>Planta</th><th>Operador</th><th>Habilitación</th><th>Vence</th></tr></thead><tbody>{$plantas}</tbody></table></section>
  <footer class="pie"><p>Registro derivado de la ejecución: cada fila sale de los servicios cerrados en el campo y de las descargas cargadas con su manifiesto.</p>
    <p class="pie-nota">Generado {$e($generado)} (hora de Argentina)</p></footer>
</article>
</body>
</html>
HTML;
    }

    /**
     * Reparte $litros entre las claves de $pesos en proporción, en enteros que
     * suman EXACTAMENTE $litros (método del mayor resto). Redondear cada parte
     * por separado daba 333 + 333 + 333 = 999 de 1.000, o 501 + 501 = 1.002
     * de 1.001 (revisión de la Fase 4).
     *
     * @param array<int,int> $pesos
     * @return array<int,int>
     */
    public static function repartir(int $litros, array $pesos): array
    {
        $total = array_sum($pesos);
        if ($total <= 0) return array_fill_keys(array_keys($pesos), 0);
        $partes = [];
        $restos = [];
        foreach ($pesos as $k => $p) {
            $exacto = $litros * $p / $total;
            $partes[$k] = (int) floor($exacto);
            $restos[$k] = $exacto - $partes[$k];
        }
        $faltan = $litros - array_sum($partes);
        arsort($restos);
        foreach (array_keys($restos) as $k) {
            if ($faltan-- <= 0) break;
            $partes[$k]++;
        }
        return $partes;
    }

    /** @return array{0:string,1:string} */
    private static function rango(string $desde, string $hasta): array
    {
        $desde = Tarifa::fechaValida($desde, 'desde');
        $hasta = Tarifa::fechaValida($hasta, 'hasta');
        if ($hasta < $desde) throw new ErrorValidacion(['hasta' => 'El período termina antes de empezar.']);
        if (Asignacion::cantidadDias($desde, $hasta) > self::MAX_DIAS) throw new ErrorValidacion(['hasta' => 'Hasta ' . self::MAX_DIAS . ' días por consulta.']);
        return [$desde, $hasta];
    }
}
