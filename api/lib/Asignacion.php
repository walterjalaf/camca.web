<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Asignación y traslados. Obj. 4, paso F3.7.
 *
 * Quién sale, con qué camioneta y a dónde, cada día; y las campañas de varios
 * días (Los Azules: cuatro días en altura, con sus baños).
 *
 * Dos clases de problema, tratadas distinto a propósito:
 *
 *  - CONFLICTOS: la misma cuadrilla, camioneta o persona en dos lugares el
 *    mismo día, o el mismo baño reservado para dos campañas que se pisan. Son
 *    imposibles, y se rechazan (la base los rechaza aunque PHP no los viera).
 *  - ADVERTENCIAS: alguien de la cuadrilla con la licencia vencida ese día, o
 *    la camioneta sin seguro. Se planifica igual —el documento puede llegar
 *    antes de la fecha—, pero se muestra hasta que se resuelva.
 */
final class Asignacion
{
    public const MAX_DIAS_CAMPANA = 60;

    /** 2026-10-05 → 05/10/2026, para los mensajes. */
    public static function fecha(string $iso): string
    {
        return substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
    }

    /**
     * Cuántos días van de $desde a $hasta, inclusive, SIN armar la lista.
     * El tope de un rango se controla con esto y recién después se llama a
     * dias(): al revés, un pedido del año 1 al 9999 armaba 3,6 millones de
     * fechas antes de rechazarse y se quedaba sin memoria (revisión de la Fase 3).
     */
    public static function cantidadDias(string $desde, string $hasta): int
    {
        return intdiv(strtotime($hasta . ' 12:00:00 UTC') - strtotime($desde . ' 12:00:00 UTC'), 86400) + 1;
    }

    /** Los días de un período, inclusive. */
    public static function dias(string $desde, string $hasta): array
    {
        $d = [];
        for ($t = strtotime($desde . ' 12:00:00 UTC'); $t <= strtotime($hasta . ' 12:00:00 UTC'); $t += 86400) $d[] = gmdate('Y-m-d', $t);
        return $d;
    }

    /** Los integrantes actuales de una cuadrilla. */
    private static function miembros(int $cuadrillaId): array
    {
        return Db::todas('SELECT p.id, p.nombre FROM cuadrilla_miembro m JOIN persona p ON p.id = m.persona_id
                           WHERE m.cuadrilla_id = :c AND m.abierta IS NOT NULL ORDER BY p.nombre', [':c' => $cuadrillaId]);
    }

    /**
     * Qué choca con una asignación posible, en palabras. Vacío = nada.
     * Es la lectura amable; la garantía la dan los UNIQUE.
     */
    private static function choques(string $fecha, int $cuadrillaId, ?int $vehiculoId, array $personas, ?int $jornadaId): array
    {
        $c = [];
        $f = self::fecha($fecha);
        $otra = static fn(string $col, string $v) => Db::una(
            "SELECT a.id, cu.nombre AS cuadrilla, ca.nombre AS campana FROM asignacion a JOIN cuadrilla cu ON cu.id = a.cuadrilla_id
               LEFT JOIN campana ca ON ca.id = a.campana_id WHERE a.$col = :v", [':v' => $v]);
        $donde = static fn(array $x) => $x['campana'] ? "en la campaña «{$x['campana']}»" : 'en otra asignación';
        if ($x = $otra('cuadrilla_dia', "$cuadrillaId:$fecha")) $c[] = ['codigo' => 'CUADRILLA_OCUPADA', 'fecha' => $fecha, 'detalle' => "La cuadrilla ya está asignada el $f " . $donde($x) . '.'];
        if ($vehiculoId !== null && ($x = $otra('vehiculo_dia', "$vehiculoId:$fecha"))) {
            $pat = Db::col('SELECT patente FROM vehiculo WHERE id = :v', [':v' => $vehiculoId]);
            $c[] = ['codigo' => 'VEHICULO_OCUPADO', 'fecha' => $fecha, 'detalle' => "$pat ya sale el $f con {$x['cuadrilla']} " . $donde($x) . '.'];
        }
        if ($jornadaId !== null && ($x = Db::una('SELECT a.id, cu.nombre AS cuadrilla FROM asignacion a JOIN cuadrilla cu ON cu.id = a.cuadrilla_id WHERE a.jornada_viva = :j', [':j' => $jornadaId]))) {
            $c[] = ['codigo' => 'JORNADA_ASIGNADA', 'fecha' => $fecha, 'detalle' => "Esa ruta ya la tiene {$x['cuadrilla']}."];
        }
        foreach ($personas as $p) {
            $x = Db::una("SELECT cu.nombre AS cuadrilla, ca.nombre AS campana FROM asignacion_persona ap JOIN asignacion a ON a.id = ap.asignacion_id
                            JOIN cuadrilla cu ON cu.id = a.cuadrilla_id LEFT JOIN campana ca ON ca.id = a.campana_id
                           WHERE ap.persona_dia = :k", [':k' => $p['id'] . ':' . $fecha]);
            if ($x) $c[] = ['codigo' => 'PERSONA_OCUPADA', 'fecha' => $fecha,
                            'detalle' => "{$p['nombre']} ya trabaja el $f con {$x['cuadrilla']}" . ($x['campana'] ? " (campaña «{$x['campana']}»)" : '') . '.'];
        }
        return $c;
    }

    private static function rechazar(array $choques): never
    {
        throw new ErrorConflicto(implode(' ', array_unique(array_column($choques, 'detalle'))), $choques[0]['codigo']);
    }

    /** Inserta una asignación ya validada. Tiene que correr adentro de una transacción. */
    private static function insertar(string $fecha, int $cuadrillaId, ?int $vehiculoId, ?int $jornadaId, ?int $campanaId, ?string $nota, array $personas, ?int $usuarioId): int
    {
        Db::q("INSERT INTO asignacion (fecha, cuadrilla_id, vehiculo_id, jornada_id, campana_id, estado, nota,
                                       cuadrilla_dia, vehiculo_dia, jornada_viva, creado_por, creado_utc)
               VALUES (:f, :c, :v, :j, :ca, 'planificada', :n, :cd, :vd, :jv, :u, UTC_TIMESTAMP())",
              [':f' => $fecha, ':c' => $cuadrillaId, ':v' => $vehiculoId, ':j' => $jornadaId, ':ca' => $campanaId, ':n' => $nota,
               ':cd' => "$cuadrillaId:$fecha", ':vd' => $vehiculoId !== null ? "$vehiculoId:$fecha" : null, ':jv' => $jornadaId, ':u' => $usuarioId]);
        $id = Db::insertarId();
        foreach ($personas as $p) {
            Db::q('INSERT INTO asignacion_persona (asignacion_id, persona_id, fecha, persona_dia) VALUES (:a, :p, :f, :k)',
                  [':a' => $id, ':p' => $p['id'], ':f' => $fecha, ':k' => $p['id'] . ':' . $fecha]);
        }
        return $id;
    }

    private static function validarBase(int $cuadrillaId, ?int $vehiculoId): array
    {
        if (Db::col('SELECT id FROM cuadrilla WHERE id = :c AND activa = 1', [':c' => $cuadrillaId]) === null) throw new ErrorNoEncontrado('No existe esa cuadrilla.');
        if ($vehiculoId !== null && Db::col('SELECT id FROM vehiculo WHERE id = :v AND activo = 1', [':v' => $vehiculoId]) === null) throw new ErrorNoEncontrado('No existe ese vehículo.');
        $personas = self::miembros($cuadrillaId);
        if ($personas === []) throw new ErrorConflicto('Esa cuadrilla no tiene a nadie: no hay a quién mandar.', 'CUADRILLA_VACIA');
        return $personas;
    }

    /** Asigna una cuadrilla (y su camioneta) a un día, opcionalmente a una ruta de ese día. */
    public static function asignar(string $fecha, int $cuadrillaId, ?int $vehiculoId, ?int $jornadaId, ?string $nota, ?int $usuarioId): array
    {
        $fecha = Tarifa::fechaValida($fecha, 'fecha');
        $personas = self::validarBase($cuadrillaId, $vehiculoId);
        if ($jornadaId !== null) {
            $jf = Db::col('SELECT fecha FROM jornada WHERE id = :j', [':j' => $jornadaId]);
            if ($jf === null) throw new ErrorNoEncontrado('No existe esa ruta.');
            if ($jf !== $fecha) throw new ErrorValidacion(['jornada_id' => 'Esa ruta es del ' . self::fecha((string) $jf) . ', no del ' . self::fecha($fecha) . '.']);
        }
        try {
            return Db::txReintentable(static function () use ($fecha, $cuadrillaId, $vehiculoId, $jornadaId, $nota, $personas, $usuarioId): array {
                if ($c = self::choques($fecha, $cuadrillaId, $vehiculoId, $personas, $jornadaId)) self::rechazar($c);
                $id = self::insertar($fecha, $cuadrillaId, $vehiculoId, $jornadaId, null, $nota, $personas, $usuarioId);
                if ($jornadaId !== null && $vehiculoId !== null) {
                    Db::q('UPDATE jornada SET vehiculo_id = :v WHERE id = :j', [':v' => $vehiculoId, ':j' => $jornadaId]);
                }
                Hash::auditar('asignacion', $id, 'creada', ['fecha' => $fecha, 'cuadrilla' => $cuadrillaId, 'vehiculo' => $vehiculoId,
                                                          'jornada' => $jornadaId, 'personas' => array_column($personas, 'id')]);
                return ['id' => $id];
            });
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) {
                throw new ErrorConflicto('Otra persona asignó lo mismo para ese día en este momento. Volvé a mirar el día.', 'ASIGNACION_SIMULTANEA');
            }
            throw $e;
        }
    }

    public static function cancelar(int $id, string $motivo, ?int $usuarioId): void
    {
        if (trim($motivo) === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué se cancela.']);
        Db::txReintentable(static function () use ($id, $motivo, $usuarioId): void {
            $a = Db::una('SELECT * FROM asignacion WHERE id = :i FOR UPDATE', [':i' => $id]);
            if ($a === null) throw new ErrorNoEncontrado('No existe esa asignación.');
            if ($a['estado'] === 'cancelada') throw new ErrorConflicto('Ya estaba cancelada.', 'YA_CANCELADA');
            if ($a['campana_id'] !== null) throw new ErrorConflicto('Es un día de una campaña: se cancela la campaña entera.', 'ES_DE_CAMPANA');
            self::apagar($id, $motivo);
            Hash::auditar('asignacion', $id, 'cancelada', ['motivo' => $motivo, 'por' => $usuarioId]);
        });
    }

    private static function apagar(int $id, string $motivo): void
    {
        Db::q("UPDATE asignacion SET estado = 'cancelada', cuadrilla_dia = NULL, vehiculo_dia = NULL, jornada_viva = NULL,
                      cancelada_motivo = :m WHERE id = :i", [':m' => mb_substr(trim($motivo), 0, 255), ':i' => $id]);
        Db::q('UPDATE asignacion_persona SET persona_dia = NULL WHERE asignacion_id = :i', [':i' => $id]);
    }

    /**
     * Planifica una campaña de varios días: una asignación por día y los
     * equipos reservados por todo el período. Todo o nada: si un solo día
     * choca, no se crea ninguno, y se dicen todos los choques juntos.
     */
    public static function crearCampana(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $nombre = $v->texto('nombre', 2, 120);
        $cliente = $v->entero('cliente_id', 1, PHP_INT_MAX, false);
        $sitio = $v->entero('sitio_id', 1, PHP_INT_MAX, false);
        $desde = $v->texto('desde', 10, 10);
        $hasta = $v->texto('hasta', 10, 10);
        $cuadrilla = $v->entero('cuadrilla_id', 1, PHP_INT_MAX);
        $vehiculo = $v->entero('vehiculo_id', 1, PHP_INT_MAX, false);
        $nota = $v->texto('nota', 1, 255, false);
        $v->fin();
        $desde = Tarifa::fechaValida($desde, 'desde');
        $hasta = Tarifa::fechaValida($hasta, 'hasta');
        if ($hasta < $desde) throw new ErrorValidacion(['hasta' => 'La campaña termina antes de empezar.']);
        if (self::cantidadDias($desde, $hasta) > self::MAX_DIAS_CAMPANA) throw new ErrorValidacion(['hasta' => 'Hasta ' . self::MAX_DIAS_CAMPANA . ' días por campaña.']);
        $dias = self::dias($desde, $hasta);
        $activos = array_values(array_unique(array_map('intval', is_array($d['activos'] ?? null) ? $d['activos'] : [])));
        $personas = self::validarBase($cuadrilla, $vehiculo);

        try {
            return Db::txReintentable(static function () use ($nombre, $cliente, $sitio, $desde, $hasta, $dias, $cuadrilla, $vehiculo, $nota, $activos, $personas, $usuarioId): array {
                $choques = [];
                foreach ($dias as $f) array_push($choques, ...self::choques($f, $cuadrilla, $vehiculo, $personas, null));
                // Los equipos: se bloquean sus filas para que dos campañas no
                // reserven el mismo baño a la vez, y se buscan reservas que se pisen.
                foreach ($activos as $aid) {
                    $a = Db::una('SELECT id, identificador, estado FROM activo WHERE id = :a FOR UPDATE', [':a' => $aid]);
                    if ($a === null) throw new ErrorNoEncontrado("No existe el equipo $aid.");
                    if (in_array($a['estado'], ['baja', 'mantenimiento'], true)) {
                        $choques[] = ['codigo' => 'ACTIVO_NO_DISPONIBLE', 'fecha' => $desde, 'detalle' => "{$a['identificador']} está en {$a['estado']}."];
                        continue;
                    }
                    $otra = Db::una("SELECT c.nombre, c.desde, c.hasta FROM campana_activo ca JOIN campana c ON c.id = ca.campana_id
                                      WHERE ca.activo_id = :a AND c.estado = 'planificada' AND c.desde <= :h AND c.hasta >= :d LIMIT 1",
                                    [':a' => $aid, ':d' => $desde, ':h' => $hasta]);
                    if ($otra) $choques[] = ['codigo' => 'ACTIVO_RESERVADO', 'fecha' => $desde,
                        'detalle' => "{$a['identificador']} ya está reservado para «{$otra['nombre']}» (" . self::fecha($otra['desde']) . ' a ' . self::fecha($otra['hasta']) . ")."];
                }
                if ($choques) self::rechazar($choques);

                Db::q("INSERT INTO campana (nombre, cliente_id, sitio_id, desde, hasta, cuadrilla_id, vehiculo_id, estado, nota, creado_por, creado_utc)
                       VALUES (:n, :cl, :s, :d, :h, :c, :v, 'planificada', :no, :u, UTC_TIMESTAMP())",
                      [':n' => trim((string) $nombre), ':cl' => $cliente, ':s' => $sitio, ':d' => $desde, ':h' => $hasta,
                       ':c' => $cuadrilla, ':v' => $vehiculo, ':no' => $nota, ':u' => $usuarioId]);
                $id = Db::insertarId();
                foreach ($activos as $aid) Db::q('INSERT INTO campana_activo (campana_id, activo_id) VALUES (:c, :a)', [':c' => $id, ':a' => $aid]);
                $asig = [];
                foreach ($dias as $f) $asig[] = self::insertar($f, $cuadrilla, $vehiculo, null, $id, null, $personas, $usuarioId);
                Hash::auditar('campana', $id, 'planificada', ['nombre' => $nombre, 'desde' => $desde, 'hasta' => $hasta,
                    'cuadrilla' => $cuadrilla, 'vehiculo' => $vehiculo, 'activos' => $activos, 'dias' => count($dias)]);
                return ['id' => $id, 'asignaciones' => $asig];
            });
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) throw new ErrorConflicto('Otra planificación tomó alguno de esos días en este momento. Volvé a mirar.', 'ASIGNACION_SIMULTANEA');
            throw $e;
        }
    }

    public static function cancelarCampana(int $id, string $motivo, ?int $usuarioId): void
    {
        if (trim($motivo) === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué se cancela.']);
        Db::txReintentable(static function () use ($id, $motivo, $usuarioId): void {
            $c = Db::una('SELECT * FROM campana WHERE id = :i FOR UPDATE', [':i' => $id]);
            if ($c === null) throw new ErrorNoEncontrado('No existe esa campaña.');
            if ($c['estado'] === 'cancelada') throw new ErrorConflicto('Ya estaba cancelada.', 'YA_CANCELADA');
            Db::q("UPDATE campana SET estado = 'cancelada', cancelada_motivo = :m WHERE id = :i", [':m' => mb_substr(trim($motivo), 0, 255), ':i' => $id]);
            foreach (Db::todas("SELECT id FROM asignacion WHERE campana_id = :c AND estado = 'planificada'", [':c' => $id]) as $a) {
                self::apagar((int) $a['id'], 'Campaña cancelada: ' . $motivo);
            }
            Hash::auditar('campana', $id, 'cancelada', ['motivo' => $motivo, 'por' => $usuarioId]);
        });
    }

    /**
     * Lo planificado que tiene un problema de papeles en su fecha: quien
     * tenga un documento exigido vencido ESE día (no hoy), y la camioneta.
     */
    public static function advertencias(string $desde, string $hasta): array
    {
        $salida = [];
        $cache = [];
        $inh = static function (string $e, int $id, string $f) use (&$cache): array {
            return $cache["$e:$id:$f"] ??= Recursos::inhabilitaciones($e, $id, $f);
        };
        foreach (Db::todas("SELECT a.id, a.fecha, a.vehiculo_id, v.patente, cu.nombre AS cuadrilla FROM asignacion a
                              JOIN cuadrilla cu ON cu.id = a.cuadrilla_id LEFT JOIN vehiculo v ON v.id = a.vehiculo_id
                             WHERE a.estado = 'planificada' AND a.fecha BETWEEN :d AND :h ORDER BY a.fecha", [':d' => $desde, ':h' => $hasta]) as $a) {
            foreach (Db::todas('SELECT p.id, p.nombre FROM asignacion_persona ap JOIN persona p ON p.id = ap.persona_id WHERE ap.asignacion_id = :a',
                               [':a' => $a['id']]) as $p) {
                foreach ($inh('persona', (int) $p['id'], $a['fecha']) as $m) {
                    $salida[] = ['asignacion_id' => (int) $a['id'], 'fecha' => $a['fecha'], 'cuadrilla' => $a['cuadrilla'], 'quien' => $p['nombre'], 'detalle' => $m];
                }
            }
            if ($a['vehiculo_id'] !== null) {
                foreach ($inh('vehiculo', (int) $a['vehiculo_id'], $a['fecha']) as $m) {
                    $salida[] = ['asignacion_id' => (int) $a['id'], 'fecha' => $a['fecha'], 'cuadrilla' => $a['cuadrilla'], 'quien' => $a['patente'], 'detalle' => $m];
                }
            }
        }
        return $salida;
    }

    public static function listar(string $desde, string $hasta): array
    {
        $desde = Tarifa::fechaValida($desde, 'desde');
        $hasta = Tarifa::fechaValida($hasta, 'hasta');
        $asig = Db::todas("SELECT a.id, a.fecha, a.cuadrilla_id, cu.nombre AS cuadrilla, a.vehiculo_id, v.patente, a.jornada_id,
                                  r.nombre AS ruta, a.campana_id, ca.nombre AS campana, a.nota,
                                  (SELECT GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ', ') FROM asignacion_persona ap
                                     JOIN persona p ON p.id = ap.persona_id WHERE ap.asignacion_id = a.id) AS personas
                             FROM asignacion a JOIN cuadrilla cu ON cu.id = a.cuadrilla_id
                             LEFT JOIN vehiculo v ON v.id = a.vehiculo_id LEFT JOIN jornada j ON j.id = a.jornada_id
                             LEFT JOIN ruta_plantilla r ON r.id = j.ruta_id LEFT JOIN campana ca ON ca.id = a.campana_id
                            WHERE a.estado = 'planificada' AND a.fecha BETWEEN :d AND :h ORDER BY a.fecha, cu.nombre",
                          [':d' => $desde, ':h' => $hasta]);
        $campanas = Db::todas("SELECT c.*, cu.nombre AS cuadrilla, v.patente, cl.nombre AS cliente, s.nombre AS sitio,
                                      (SELECT GROUP_CONCAT(a.identificador ORDER BY a.identificador SEPARATOR ', ') FROM campana_activo x
                                         JOIN activo a ON a.id = x.activo_id WHERE x.campana_id = c.id) AS activos
                                 FROM campana c JOIN cuadrilla cu ON cu.id = c.cuadrilla_id LEFT JOIN vehiculo v ON v.id = c.vehiculo_id
                                 LEFT JOIN cliente cl ON cl.id = c.cliente_id LEFT JOIN sitio s ON s.id = c.sitio_id
                                WHERE c.estado = 'planificada' AND c.desde <= :h AND c.hasta >= :d ORDER BY c.desde",
                              [':d' => $desde, ':h' => $hasta]);
        return [
            'desde' => $desde, 'hasta' => $hasta,
            'asignaciones' => $asig,
            'campanas' => $campanas,
            'advertencias' => self::advertencias($desde, $hasta),
            'cuadrillas' => Db::todas('SELECT c.id, c.nombre, (SELECT COUNT(*) FROM cuadrilla_miembro m WHERE m.cuadrilla_id = c.id AND m.abierta IS NOT NULL) AS miembros
                                         FROM cuadrilla c WHERE c.activa = 1 ORDER BY c.nombre'),
            'vehiculos' => Db::todas('SELECT id, patente FROM vehiculo WHERE activo = 1 ORDER BY patente'),
            'jornadas' => Db::todas('SELECT j.id, j.fecha, r.nombre AS ruta FROM jornada j JOIN ruta_plantilla r ON r.id = j.ruta_id
                                      WHERE j.fecha BETWEEN :d AND :h ORDER BY j.fecha, r.nombre', [':d' => $desde, ':h' => $hasta]),
            'activos' => Db::todas("SELECT id, identificador, tipo FROM activo WHERE estado IN ('disponible','instalado') ORDER BY identificador"),
            'clientes' => Db::todas('SELECT id, nombre FROM cliente WHERE activo = 1 ORDER BY nombre'),
        ];
    }

    /** Las reglas, sobre toda la base. Vacío = en orden. */
    public static function verificar(): array
    {
        $p = [];
        foreach (Db::todas("SELECT cuadrilla_id, fecha, COUNT(*) n FROM asignacion WHERE estado = 'planificada' GROUP BY cuadrilla_id, fecha HAVING n > 1") as $x)
            $p[] = ['codigo' => 'CUADRILLA_DOBLE', 'detalle' => "Cuadrilla {$x['cuadrilla_id']} dos veces el {$x['fecha']}."];
        foreach (Db::todas("SELECT vehiculo_id, fecha, COUNT(*) n FROM asignacion WHERE estado = 'planificada' AND vehiculo_id IS NOT NULL GROUP BY vehiculo_id, fecha HAVING n > 1") as $x)
            $p[] = ['codigo' => 'VEHICULO_DOBLE', 'detalle' => "Vehículo {$x['vehiculo_id']} dos veces el {$x['fecha']}."];
        foreach (Db::todas("SELECT ap.persona_id, ap.fecha, COUNT(*) n FROM asignacion_persona ap JOIN asignacion a ON a.id = ap.asignacion_id
                             WHERE a.estado = 'planificada' GROUP BY ap.persona_id, ap.fecha HAVING n > 1") as $x)
            $p[] = ['codigo' => 'PERSONA_DOBLE', 'detalle' => "Persona {$x['persona_id']} dos veces el {$x['fecha']}."];
        foreach (Db::todas("SELECT x.activo_id FROM campana_activo x JOIN campana c ON c.id = x.campana_id
                              JOIN campana_activo y ON y.activo_id = x.activo_id AND y.campana_id > x.campana_id
                              JOIN campana d ON d.id = y.campana_id
                             WHERE c.estado = 'planificada' AND d.estado = 'planificada' AND c.desde <= d.hasta AND d.desde <= c.hasta") as $x)
            $p[] = ['codigo' => 'ACTIVO_DOBLE', 'detalle' => "El equipo {$x['activo_id']} está en dos campañas que se pisan."];
        return $p;
    }
}
