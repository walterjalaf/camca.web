<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Cuadrillas, personal y equipos. Obj. 4, paso F3.6.
 *
 * Tres cosas, cada una con la regla que la hace útil:
 *
 *  - ACTIVOS UNITARIOS (baños, módulos): dónde está cada uno, con historia.
 *    Nunca en dos lugares a la vez (UNIQUE en activo_ubicacion.abierta).
 *  - PERSONAL Y VEHÍCULOS con sus vencimientos (licencia, apto médico, VTV,
 *    seguro). Un documento vigente por tipo; renovarlo no borra el anterior.
 *  - CUADRILLAS: una persona en una sola a la vez.
 *
 * Las alertas no esperan a que alguien mire una fecha: un chofer con la
 * licencia vencida manejando en una mina es un problema de CAMCA, no del
 * chofer. Qué documento se exige a quién es el supuesto S14.
 */
final class Recursos
{
    /** Tipos de documento que se pueden cargar, por entidad. */
    public const TIPOS = [
        'persona'  => ['licencia' => 'Licencia de conducir', 'apto_medico' => 'Apto médico', 'induccion' => 'Inducción de seguridad'],
        'vehiculo' => ['vtv' => 'VTV', 'seguro' => 'Seguro', 'habilitacion' => 'Habilitación de transporte'],
    ];

    /** Lo que se EXIGE (S14): sin esto, vigente, la persona o el vehículo no está habilitado. */
    public const EXIGIDOS = [
        'chofer' => ['licencia', 'apto_medico'], 'ayudante' => ['apto_medico'], 'supervisor' => ['apto_medico'],
        'mecanico' => ['apto_medico'], 'otro' => [], 'vehiculo' => ['vtv', 'seguro'],
    ];

    public const ROLES = ['chofer' => 'Chofer', 'ayudante' => 'Ayudante', 'supervisor' => 'Supervisor', 'mecanico' => 'Mecánico', 'otro' => 'Otro'];
    public const TIPOS_ACTIVO = ['bano' => 'Baño químico', 'modulo_sanitario' => 'Módulo sanitario',
        'modulo_habitacional' => 'Módulo habitacional', 'garita' => 'Garita', 'biodigestor' => 'Biodigestor'];

    /** Días antes del vencimiento en que empieza a avisar, y en que el aviso sube de tono. */
    public const AVISO_DIAS = 30;
    public const URGENTE_DIAS = 7;

    public static function hoy(): string
    {
        return gmdate('Y-m-d', time() - 3 * 3600);
    }

    // ------------------------------------------------------------------
    // Personal y vehículos
    // ------------------------------------------------------------------

    public static function altaPersona(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $nombre = $v->texto('nombre', 2, 120);
        $dniTxt = $v->texto('dni', 7, 12);
        $legajo = $v->texto('legajo', 1, 30, false);
        $rol = $v->texto('rol_operativo', 1, 20);
        $usuario = $v->entero('usuario_id', 1, PHP_INT_MAX, false);
        $v->fin();
        $dni = preg_replace('/\D/', '', (string) $dniTxt) ?? '';
        if (strlen($dni) < 7 || strlen($dni) > 8) throw new ErrorValidacion(['dni' => 'El DNI tiene 7 u 8 dígitos.']);
        if (!isset(self::ROLES[$rol])) throw new ErrorValidacion(['rol_operativo' => 'Rol desconocido.']);
        try {
            Db::q('INSERT INTO persona (nombre, dni, legajo, rol_operativo, usuario_id, activa, creado_utc)
                   VALUES (:n, :d, :l, :r, :u, 1, UTC_TIMESTAMP())',
                  [':n' => trim((string) $nombre), ':d' => $dni, ':l' => $legajo, ':r' => $rol, ':u' => $usuario]);
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) throw new ErrorConflicto('Ya hay una persona con ese DNI, legajo o usuario.', 'PERSONA_DUPLICADA');
            throw $e;
        }
        $id = Db::insertarId();
        Hash::auditar('persona', $id, 'alta', ['nombre' => $nombre, 'rol' => $rol, 'por' => $usuarioId]);
        return ['id' => $id];
    }

    /** AB123CD (Mercosur) o ABC123 (anterior), en mayúsculas y sin espacios. */
    public static function patente(string $p): ?string
    {
        $p = strtoupper(preg_replace('/[\s-]/', '', $p) ?? '');
        return preg_match('/^([A-Z]{2}\d{3}[A-Z]{2}|[A-Z]{3}\d{3})$/', $p) ? $p : null;
    }

    public static function altaVehiculo(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $pat = $v->texto('patente', 6, 12);
        $desc = $v->texto('descripcion', 1, 120, false);
        $v->fin();
        $patente = self::patente((string) $pat);
        if ($patente === null) throw new ErrorValidacion(['patente' => 'Patente inválida (AB123CD o ABC123).']);
        try {
            Db::q('INSERT INTO vehiculo (patente, descripcion, activo, creado_utc) VALUES (:p, :d, 1, UTC_TIMESTAMP())',
                  [':p' => $patente, ':d' => $desc]);
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) throw new ErrorConflicto("Ya hay un vehículo con la patente $patente.", 'PATENTE_DUPLICADA');
            throw $e;
        }
        $id = Db::insertarId();
        Hash::auditar('vehiculo', $id, 'alta', ['patente' => $patente, 'por' => $usuarioId]);
        return ['id' => $id, 'patente' => $patente];
    }

    /**
     * Carga un documento con su vencimiento. Si ya había uno vigente del
     * mismo tipo, queda como historia: se renueva, no se pisa.
     */
    public static function cargarVencimiento(string $entidad, int $entidadId, string $tipo, string $vence, ?string $documento, ?int $usuarioId): array
    {
        if (!isset(self::TIPOS[$entidad][$tipo])) {
            throw new ErrorValidacion(['tipo' => 'Ese documento no corresponde a ' . ($entidad === 'persona' ? 'una persona' : 'un vehículo') . '.']);
        }
        $vence = Tarifa::fechaValida($vence, 'vence');
        $existe = Db::col($entidad === 'persona' ? 'SELECT id FROM persona WHERE id = :i' : 'SELECT id FROM vehiculo WHERE id = :i', [':i' => $entidadId]);
        if ($existe === null) throw new ErrorNoEncontrado('No existe.');
        $clave = "$entidad:$entidadId:$tipo";
        return Db::txReintentable(static function () use ($entidad, $entidadId, $tipo, $vence, $documento, $usuarioId, $clave): array {
            // Lo que ordena dos cargas de la misma persona es SU fila: un lock
            // de registro, sin hueco. Buscar el vigente con FOR UPDATE sobre
            // una clave que todavía no existe tomaba un lock de HUECO, y dos
            // cargas de personas distintas se trababan entre sí al insertar
            // (revisión de la Fase 3: 5 deadlocks en 16 cargas simultáneas).
            Db::q($entidad === 'persona' ? 'SELECT id FROM persona WHERE id = :i FOR UPDATE' : 'SELECT id FROM vehiculo WHERE id = :i FOR UPDATE',
                  [':i' => $entidadId]);
            $previo = Db::una('SELECT id, vence FROM vencimiento WHERE vigente = :c', [':c' => $clave]);
            if ($previo !== null) Db::q('UPDATE vencimiento SET vigente = NULL WHERE id = :i', [':i' => $previo['id']]);
            Db::q('INSERT INTO vencimiento (entidad, entidad_id, tipo, vence, documento, vigente, cargado_por, creado_utc)
                   VALUES (:e, :i, :t, :v, :d, :c, :u, UTC_TIMESTAMP())',
                  [':e' => $entidad, ':i' => $entidadId, ':t' => $tipo, ':v' => $vence,
                   ':d' => $documento !== null && trim($documento) !== '' ? mb_substr(trim($documento), 0, 120) : null,
                   ':c' => $clave, ':u' => $usuarioId]);
            $id = Db::insertarId();
            Hash::auditar('vencimiento', $id, $previo ? 'renovado' : 'cargado', [
                'entidad' => $entidad, 'entidad_id' => $entidadId, 'tipo' => $tipo, 'vence' => $vence,
                'anterior' => $previo['vence'] ?? null,
            ]);
            return ['id' => $id, 'vence' => $vence];
        });
    }

    /**
     * Qué documentos le faltan, están vencidos o por vencer. Es la lista con
     * la que se abre la pantalla.
     *
     * nivel: 'vencido' y 'falta' inhabilitan; 'urgente' (≤ 7 días) y
     * 'proximo' (≤ 30) avisan.
     */
    public static function alertas(?string $hoy = null): array
    {
        $hoy ??= self::hoy();
        $vig = [];
        foreach (Db::todas('SELECT entidad, entidad_id, tipo, vence FROM vencimiento WHERE vigente IS NOT NULL') as $x) {
            $vig[$x['entidad'] . ':' . $x['entidad_id']][$x['tipo']] = $x['vence'];
        }
        $sujetos = [];
        foreach (Db::todas('SELECT id, nombre, rol_operativo FROM persona WHERE activa = 1') as $p) {
            $sujetos[] = ['entidad' => 'persona', 'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'exigidos' => self::EXIGIDOS[$p['rol_operativo']] ?? []];
        }
        foreach (Db::todas('SELECT id, patente, descripcion FROM vehiculo WHERE activo = 1') as $v) {
            $sujetos[] = ['entidad' => 'vehiculo', 'id' => (int) $v['id'], 'nombre' => $v['patente'], 'exigidos' => self::EXIGIDOS['vehiculo']];
        }
        $salida = [];
        $t0 = strtotime($hoy . ' 00:00:00 UTC');
        foreach ($sujetos as $s) {
            $docs = $vig[$s['entidad'] . ':' . $s['id']] ?? [];
            foreach (array_unique(array_merge($s['exigidos'], array_keys($docs))) as $tipo) {
                $base = ['entidad' => $s['entidad'], 'id' => $s['id'], 'nombre' => $s['nombre'], 'tipo' => $tipo,
                         'documento' => self::TIPOS[$s['entidad']][$tipo] ?? $tipo, 'exigido' => in_array($tipo, $s['exigidos'], true)];
                if (!isset($docs[$tipo])) {
                    $salida[] = $base + ['nivel' => 'falta', 'vence' => null, 'dias' => null];
                    continue;
                }
                $dias = (int) round((strtotime($docs[$tipo] . ' 00:00:00 UTC') - $t0) / 86400);
                $nivel = $dias < 0 ? 'vencido' : ($dias <= self::URGENTE_DIAS ? 'urgente' : ($dias <= self::AVISO_DIAS ? 'proximo' : null));
                if ($nivel !== null) $salida[] = $base + ['nivel' => $nivel, 'vence' => $docs[$tipo], 'dias' => $dias];
            }
        }
        $orden = ['vencido' => 0, 'falta' => 1, 'urgente' => 2, 'proximo' => 3];
        usort($salida, static fn($a, $b) => [$orden[$a['nivel']], !$a['exigido'], $a['dias'] ?? -99999, $a['nombre']]
                                          <=> [$orden[$b['nivel']], !$b['exigido'], $b['dias'] ?? -99999, $b['nombre']]);
        return $salida;
    }

    /**
     * ¿Puede trabajar (o salir a la ruta) esa persona o vehículo en esa fecha?
     * Devuelve los motivos por los que NO; vacío = habilitado.
     */
    public static function inhabilitaciones(string $entidad, int $id, string $fecha): array
    {
        $exigidos = $entidad === 'vehiculo'
            ? self::EXIGIDOS['vehiculo']
            : (self::EXIGIDOS[(string) Db::col('SELECT rol_operativo FROM persona WHERE id = :i', [':i' => $id])] ?? []);
        $motivos = [];
        foreach ($exigidos as $tipo) {
            $nombre = self::TIPOS[$entidad][$tipo];
            $vence = Db::col('SELECT vence FROM vencimiento WHERE vigente = :c', [':c' => "$entidad:$id:$tipo"]);
            // «Falta apto médico», pero «Falta VTV»: una sigla no se baja.
            if ($vence === null) $motivos[] = 'Falta ' . ($nombre === mb_strtoupper($nombre) ? $nombre : mb_strtolower($nombre)) . '.';
            elseif ($vence < $fecha) $motivos[] = $nombre . ' vencida el ' . date('d/m/Y', strtotime($vence . ' 12:00:00 UTC')) . '.';
        }
        return $motivos;
    }

    // ------------------------------------------------------------------
    // Activos
    // ------------------------------------------------------------------

    /**
     * Alta de un lote: «B-» del 1 al 40 son B-001 … B-040. Todos nacen en
     * una base propia, disponibles. Todo o nada.
     */
    public static function altaActivos(string $tipo, string $prefijo, int $desde, int $hasta, int $baseId, ?int $usuarioId): array
    {
        if (!isset(self::TIPOS_ACTIVO[$tipo])) throw new ErrorValidacion(['tipo' => 'Tipo de equipo desconocido.']);
        $prefijo = strtoupper(trim($prefijo));
        if (!preg_match('/^[A-Z0-9-]{1,20}$/', $prefijo)) throw new ErrorValidacion(['prefijo' => 'Letras, números y guiones.']);
        if ($desde < 1 || $hasta < $desde || $hasta - $desde >= 500) throw new ErrorValidacion(['hasta' => 'Entre 1 y 500 equipos por vez.']);
        if (Db::col('SELECT id FROM base_operativa WHERE id = :b', [':b' => $baseId]) === null) throw new ErrorNoEncontrado('No existe esa base.');
        // Mientras se da de alta un lote, nadie más da de alta con ese prefijo:
        // el control de números repetidos de abajo tiene que ver todo.
        $lock = 'camca_activo_' . $prefijo;
        if ((int) Db::col('SELECT GET_LOCK(:n, 10)', [':n' => $lock]) !== 1) {
            throw new ErrorConflicto('Otra persona está dando de alta equipos con ese prefijo. Probá de nuevo en un momento.', 'ALTA_OCUPADA');
        }
        try {
            // Los repetidos se buscan por NÚMERO, no por texto: con el ancho
            // sacado de «hasta», un lote 1-40 daba B-001… y otro 35-1000 daba
            // B-0035…, el mismo baño con dos nombres, y el UNIQUE no lo veía
            // (revisión de la Fase 3). El ancho es el que ya usa ese prefijo.
            $usados = [];
            $ancho = null;
            foreach (Db::todas('SELECT identificador FROM activo WHERE identificador LIKE :p', [':p' => $prefijo . '%']) as $x) {
                $resto = substr((string) $x['identificador'], strlen($prefijo));
                if ($resto === '' || !ctype_digit($resto)) continue;
                $usados[(int) $resto] = $x['identificador'];
                $ancho = max($ancho ?? 0, strlen($resto));
            }
            $ancho ??= max(3, strlen((string) $hasta));
            $repetidos = array_values(array_intersect_key($usados, array_flip(range($desde, $hasta))));
            if ($repetidos !== []) {
                throw new ErrorConflicto('Ya existen ' . implode(', ', array_slice($repetidos, 0, 5)) . (count($repetidos) > 5 ? '…' : '') .
                    ': no se dio de alta ninguno.', 'ACTIVO_DUPLICADO');
            }
            return self::altaLote($tipo, $prefijo, $desde, $hasta, $baseId, $usuarioId, $ancho);
        } finally {
            Db::col('SELECT RELEASE_LOCK(:n)', [':n' => $lock]);
        }
    }

    private static function altaLote(string $tipo, string $prefijo, int $desde, int $hasta, int $baseId, ?int $usuarioId, int $ancho): array
    {
        try {
            return Db::txReintentable(static function () use ($tipo, $prefijo, $desde, $hasta, $baseId, $usuarioId, $ancho): array {
                $ids = [];
                for ($n = $desde; $n <= $hasta; $n++) {
                    $ident = $prefijo . str_pad((string) $n, $ancho, '0', STR_PAD_LEFT);
                    Db::q("INSERT INTO activo (tipo, identificador, estado, sitio_id, base_id, creado_utc, actualizado_utc)
                           VALUES (:t, :i, 'disponible', NULL, :b, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                          [':t' => $tipo, ':i' => $ident, ':b' => $baseId]);
                    $id = Db::insertarId();
                    Db::q('INSERT INTO activo_ubicacion (activo_id, sitio_id, base_id, desde_utc, abierta, motivo, usuario_id)
                           VALUES (:a, NULL, :b, UTC_TIMESTAMP(), :a2, \'Alta\', :u)',
                          [':a' => $id, ':b' => $baseId, ':a2' => $id, ':u' => $usuarioId]);
                    $ids[] = $id;
                }
                Hash::auditar('activo', null, 'alta_lote', ['tipo' => $tipo, 'desde' => $prefijo . $desde, 'hasta' => $prefijo . $hasta,
                                                           'cantidad' => count($ids), 'base' => $baseId]);
                return ['creados' => count($ids), 'ids' => $ids];
            });
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) throw new ErrorConflicto('Alguno de esos identificadores ya existe: no se dio de alta ninguno.', 'ACTIVO_DUPLICADO');
            throw $e;
        }
    }

    /**
     * Mueve un activo a un sitio de cliente o a una base. Cierra la ubicación
     * anterior y abre la nueva en la misma transacción.
     */
    public static function mover(int $activoId, ?int $sitioId, ?int $baseId, string $motivo, ?int $usuarioId): array
    {
        if (($sitioId === null) === ($baseId === null)) throw new ErrorValidacion(['destino' => 'Un sitio o una base, uno de los dos.']);
        if ($sitioId !== null && Db::col('SELECT id FROM sitio WHERE id = :s', [':s' => $sitioId]) === null) throw new ErrorNoEncontrado('No existe ese sitio.');
        if ($baseId !== null && Db::col('SELECT id FROM base_operativa WHERE id = :b', [':b' => $baseId]) === null) throw new ErrorNoEncontrado('No existe esa base.');
        try {
            return Db::txReintentable(static function () use ($activoId, $sitioId, $baseId, $motivo, $usuarioId): array {
                $a = Db::una('SELECT * FROM activo WHERE id = :a FOR UPDATE', [':a' => $activoId]);
                if ($a === null) throw new ErrorNoEncontrado('No existe ese equipo.');
                if ($a['estado'] === 'baja') throw new ErrorConflicto("{$a['identificador']} está dado de baja: no se mueve.", 'ACTIVO_DE_BAJA');
                $ab = Db::una('SELECT * FROM activo_ubicacion WHERE abierta = :a', [':a' => $activoId]);
                if ($ab !== null && (int) $ab['sitio_id'] === (int) $sitioId && (int) $ab['base_id'] === (int) $baseId) {
                    throw new ErrorConflicto("{$a['identificador']} ya está ahí.", 'YA_ESTA_AHI');
                }
                Db::q('UPDATE activo_ubicacion SET hasta_utc = UTC_TIMESTAMP(), abierta = NULL WHERE abierta = :a', [':a' => $activoId]);
                Db::q('INSERT INTO activo_ubicacion (activo_id, sitio_id, base_id, desde_utc, abierta, motivo, usuario_id)
                       VALUES (:a, :s, :b, UTC_TIMESTAMP(), :a2, :m, :u)',
                      [':a' => $activoId, ':s' => $sitioId, ':b' => $baseId, ':a2' => $activoId,
                       ':m' => trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 255) : null, ':u' => $usuarioId]);
                // En un sitio de cliente está instalado; en una base, disponible
                // (salvo que esté en mantenimiento, que se respeta).
                $estado = $sitioId !== null ? 'instalado' : ($a['estado'] === 'mantenimiento' ? 'mantenimiento' : 'disponible');
                Db::q('UPDATE activo SET sitio_id = :s, base_id = :b, estado = :e, actualizado_utc = UTC_TIMESTAMP() WHERE id = :a',
                      [':s' => $sitioId, ':b' => $baseId, ':e' => $estado, ':a' => $activoId]);
                Hash::auditar('activo', $activoId, 'movido', [
                    'desde' => $ab ? ($ab['sitio_id'] !== null ? 'sitio:' . $ab['sitio_id'] : 'base:' . $ab['base_id']) : null,
                    'hacia' => $sitioId !== null ? 'sitio:' . $sitioId : 'base:' . $baseId, 'motivo' => $motivo,
                ]);
                return ['id' => $activoId, 'estado' => $estado];
            });
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) throw new ErrorConflicto('Otro movimiento de este equipo se hizo al mismo tiempo. Volvé a mirar dónde está.', 'MOVIMIENTO_SIMULTANEO');
            throw $e;
        }
    }

    /** Mantenimiento, baja o de vuelta disponible. La baja exige motivo. */
    public static function cambiarEstado(int $activoId, string $estado, string $motivo, ?int $usuarioId): array
    {
        if (!in_array($estado, ['disponible', 'mantenimiento', 'baja'], true)) throw new ErrorValidacion(['estado' => 'Estado inválido.']);
        if ($estado === 'baja' && trim($motivo) === '') throw new ErrorValidacion(['motivo' => 'Una baja sin motivo no se puede explicar después.']);
        return Db::txReintentable(static function () use ($activoId, $estado, $motivo, $usuarioId): array {
            $a = Db::una('SELECT * FROM activo WHERE id = :a FOR UPDATE', [':a' => $activoId]);
            if ($a === null) throw new ErrorNoEncontrado('No existe ese equipo.');
            if ($a['estado'] === 'baja') throw new ErrorConflicto("{$a['identificador']} ya está de baja.", 'ACTIVO_DE_BAJA');
            if ($estado !== 'baja' && $a['sitio_id'] !== null) {
                throw new ErrorConflicto("{$a['identificador']} está instalado en un sitio: primero se trae a una base.", 'ACTIVO_INSTALADO');
            }
            $nota = trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 255) : $a['nota'];
            if ($estado === 'baja') {
                // De baja no está en ningún lado: se cierra su ubicación. Antes
                // un baño dado de baja en una obra quedaba «ahí» para siempre
                // (mover rechaza lo que está de baja) y un conteo por unidad lo
                // seguía viendo instalado (revisión de la Fase 3).
                Db::q('UPDATE activo_ubicacion SET hasta_utc = UTC_TIMESTAMP(), abierta = NULL WHERE abierta = :a', [':a' => $activoId]);
                Db::q("UPDATE activo SET estado = 'baja', sitio_id = NULL, base_id = NULL, nota = :n, actualizado_utc = UTC_TIMESTAMP() WHERE id = :a",
                      [':n' => $nota, ':a' => $activoId]);
            } else {
                Db::q('UPDATE activo SET estado = :e, nota = :n, actualizado_utc = UTC_TIMESTAMP() WHERE id = :a',
                      [':e' => $estado, ':n' => $nota, ':a' => $activoId]);
            }
            Hash::auditar('activo', $activoId, 'estado:' . $estado, ['antes' => $a['estado'], 'motivo' => $motivo, 'por' => $usuarioId]);
            return ['id' => $activoId, 'estado' => $estado];
        });
    }

    // ------------------------------------------------------------------
    // Cuadrillas
    // ------------------------------------------------------------------

    public static function crearCuadrilla(string $nombre, ?int $usuarioId): array
    {
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) throw new ErrorValidacion(['nombre' => 'Entre 2 y 80 letras.']);
        try {
            Db::q('INSERT INTO cuadrilla (nombre, activa, creado_utc) VALUES (:n, 1, UTC_TIMESTAMP())', [':n' => $nombre]);
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) throw new ErrorConflicto('Ya hay una cuadrilla con ese nombre.', 'CUADRILLA_DUPLICADA');
            throw $e;
        }
        $id = Db::insertarId();
        Hash::auditar('cuadrilla', $id, 'creada', ['nombre' => $nombre, 'por' => $usuarioId]);
        return ['id' => $id];
    }

    /** Suma una persona a una cuadrilla. Si está en otra, no: primero se la saca. */
    public static function agregarMiembro(int $cuadrillaId, int $personaId, ?int $usuarioId): array
    {
        if (Db::col('SELECT id FROM cuadrilla WHERE id = :c AND activa = 1', [':c' => $cuadrillaId]) === null) throw new ErrorNoEncontrado('No existe esa cuadrilla.');
        $p = Db::una('SELECT id, nombre FROM persona WHERE id = :p AND activa = 1', [':p' => $personaId]);
        if ($p === null) throw new ErrorNoEncontrado('No existe esa persona.');
        try {
            Db::q('INSERT INTO cuadrilla_miembro (cuadrilla_id, persona_id, desde_utc, abierta) VALUES (:c, :p, UTC_TIMESTAMP(), :p2)',
                  [':c' => $cuadrillaId, ':p' => $personaId, ':p2' => $personaId]);
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) {
                $otra = Db::col('SELECT c.nombre FROM cuadrilla_miembro m JOIN cuadrilla c ON c.id = m.cuadrilla_id WHERE m.abierta = :p', [':p' => $personaId]);
                throw new ErrorConflicto("{$p['nombre']} ya está en la cuadrilla «{$otra}». Primero hay que sacarla de ahí.", 'EN_OTRA_CUADRILLA');
            }
            throw $e;
        }
        Hash::auditar('cuadrilla', $cuadrillaId, 'miembro_agregado', ['persona' => $personaId, 'por' => $usuarioId]);
        return ['cuadrilla_id' => $cuadrillaId, 'persona_id' => $personaId];
    }

    public static function quitarMiembro(int $personaId, ?int $usuarioId): void
    {
        $c = Db::col('SELECT cuadrilla_id FROM cuadrilla_miembro WHERE abierta = :p', [':p' => $personaId]);
        if ($c === null) throw new ErrorNoEncontrado('Esa persona no está en ninguna cuadrilla.');
        Db::q('UPDATE cuadrilla_miembro SET hasta_utc = UTC_TIMESTAMP(), abierta = NULL WHERE abierta = :p', [':p' => $personaId]);
        Hash::auditar('cuadrilla', (int) $c, 'miembro_quitado', ['persona' => $personaId, 'por' => $usuarioId]);
    }

    // ------------------------------------------------------------------
    // Lectura y verificación
    // ------------------------------------------------------------------

    public static function listar(): array
    {
        $docs = [];
        foreach (Db::todas('SELECT entidad, entidad_id, tipo, vence, documento FROM vencimiento WHERE vigente IS NOT NULL') as $x) {
            $docs[$x['entidad'] . ':' . $x['entidad_id']][$x['tipo']] = ['vence' => $x['vence'], 'documento' => $x['documento']];
        }
        $hoy = self::hoy();
        $personas = array_map(static fn($p) => [
            'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'dni' => $p['dni'], 'legajo' => $p['legajo'],
            'rol_operativo' => $p['rol_operativo'], 'cuadrilla' => $p['cuadrilla'],
            'documentos' => $docs['persona:' . $p['id']] ?? (object) [],
            'inhabilitada' => self::inhabilitaciones('persona', (int) $p['id'], $hoy),
        ], Db::todas('SELECT p.*, c.nombre AS cuadrilla FROM persona p
                        LEFT JOIN cuadrilla_miembro m ON m.abierta = p.id LEFT JOIN cuadrilla c ON c.id = m.cuadrilla_id
                       WHERE p.activa = 1 ORDER BY p.nombre'));
        $vehiculos = array_map(static fn($v) => [
            'id' => (int) $v['id'], 'patente' => $v['patente'], 'descripcion' => $v['descripcion'],
            'documentos' => $docs['vehiculo:' . $v['id']] ?? (object) [],
            'inhabilitado' => self::inhabilitaciones('vehiculo', (int) $v['id'], $hoy),
        ], Db::todas('SELECT * FROM vehiculo WHERE activo = 1 ORDER BY patente'));
        $activos = Db::todas('SELECT a.id, a.tipo, a.identificador, a.estado, a.nota, s.nombre AS sitio, c.nombre AS cliente, b.nombre AS base,
                                     u.desde_utc AS desde
                                FROM activo a
                                LEFT JOIN activo_ubicacion u ON u.abierta = a.id
                                LEFT JOIN sitio s ON s.id = u.sitio_id LEFT JOIN cliente c ON c.id = s.cliente_id
                                LEFT JOIN base_operativa b ON b.id = u.base_id
                               ORDER BY a.tipo, a.identificador');
        $cuadrillas = [];
        foreach (Db::todas('SELECT c.id, c.nombre, p.id AS pid, p.nombre AS persona, p.rol_operativo FROM cuadrilla c
                              LEFT JOIN cuadrilla_miembro m ON m.cuadrilla_id = c.id AND m.abierta IS NOT NULL
                              LEFT JOIN persona p ON p.id = m.persona_id
                             WHERE c.activa = 1 ORDER BY c.nombre, p.nombre') as $r) {
            $cuadrillas[$r['id']] ??= ['id' => (int) $r['id'], 'nombre' => $r['nombre'], 'miembros' => []];
            if ($r['pid'] !== null) $cuadrillas[$r['id']]['miembros'][] = ['id' => (int) $r['pid'], 'nombre' => $r['persona'], 'rol' => $r['rol_operativo']];
        }
        return [
            'alertas' => self::alertas($hoy),
            'personas' => $personas,
            'vehiculos' => $vehiculos,
            'activos' => $activos,
            'cuadrillas' => array_values($cuadrillas),
            'bases' => Db::todas('SELECT id, nombre FROM base_operativa ORDER BY nombre'),
            'sitios' => Db::todas('SELECT s.id, s.nombre, c.nombre AS cliente FROM sitio s LEFT JOIN cliente c ON c.id = s.cliente_id ORDER BY c.nombre, s.nombre'),
            'tipos' => self::TIPOS, 'roles' => self::ROLES, 'tipos_activo' => self::TIPOS_ACTIVO,
        ];
    }

    /** Lo que tiene que ser cierto siempre. Vacío = en orden. */
    public static function verificar(): array
    {
        $prob = [];
        foreach (Db::todas('SELECT a.id, a.identificador, a.estado, a.sitio_id, a.base_id, u.sitio_id AS us, u.base_id AS ub, u.id AS uid
                              FROM activo a LEFT JOIN activo_ubicacion u ON u.abierta = a.id') as $x) {
            if ($x['estado'] === 'baja') {
                if ($x['uid'] !== null) $prob[] = ['codigo' => 'BAJA_UBICADA', 'detalle' => "{$x['identificador']} está de baja y figura en un lugar."];
                continue;
            }
            if ($x['uid'] === null) $prob[] = ['codigo' => 'SIN_UBICACION', 'detalle' => "{$x['identificador']} no está en ningún lado."];
            elseif ((string) $x['sitio_id'] !== (string) $x['us'] || (string) $x['base_id'] !== (string) $x['ub']) {
                $prob[] = ['codigo' => 'UBICACION_DESFASADA', 'detalle' => "{$x['identificador']}: la ficha y la historia dicen lugares distintos."];
            }
        }
        foreach (Db::todas('SELECT a.activo_id, COUNT(*) AS n FROM activo_ubicacion a JOIN activo_ubicacion b
                              ON b.activo_id = a.activo_id AND b.id > a.id
                             AND b.desde_utc < COALESCE(a.hasta_utc, \'9999-12-31\') AND a.desde_utc < COALESCE(b.hasta_utc, \'9999-12-31\')
                             GROUP BY a.activo_id') as $x) {
            $prob[] = ['codigo' => 'DOS_LUGARES', 'detalle' => "El equipo {$x['activo_id']} figura en dos lugares en un mismo momento."];
        }
        return $prob;
    }
}
