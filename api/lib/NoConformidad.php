<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * No conformidades y acciones correctivas (ISO 14001 §10.2). Obj. 5, F4.3.
 *
 * Lo que la norma pide, y cómo lo hace cumplir el código y no la voluntad:
 * reaccionar (corrección), buscar la causa, actuar sobre ella (acción
 * correctiva), comprobar que funcionó, y conservar la evidencia de todo. Cada
 * paso es una transición con su eslabón de auditoría; lo que falta para el
 * siguiente lo dice el servidor con un código propio.
 */
final class NoConformidad
{
    public const ORIGENES = [
        'ambiental' => 'Control ambiental', 'desvio' => 'Desvío de la operación', 'auditoria' => 'Auditoría interna',
        'reclamo' => 'Reclamo de un cliente', 'documento' => 'Documento del SGA', 'otro' => 'Otro',
    ];
    public const TIPOS_ACCION = ['correccion' => 'Corrección inmediata', 'correctiva' => 'Acción correctiva (sobre la causa)'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Lo que se acepta como archivo de evidencia, por su firma y no por el nombre. */
    private const FIRMAS = ['%PDF-' => 'application/pdf', "\xFF\xD8\xFF" => 'image/jpeg', "\x89PNG\r\n\x1A\n" => 'image/png'];

    public static function hoy(): string { return gmdate('Y-m-d', time() - 3 * 3600); }

    // ------------------------------------------------------------------
    // Alta y transiciones
    // ------------------------------------------------------------------

    public static function abrir(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $origen = (string) $v->enum('origen', array_keys(self::ORIGENES));
        $ref = $v->texto('origen_ref', 1, 80, false);
        $titulo = trim((string) $v->texto('titulo', 5, 160));
        $descripcion = trim((string) $v->texto('descripcion', 10, 2000));
        $gravedad = $v->enum('gravedad', ['menor', 'mayor'], false) ?? 'menor';
        $fecha = $v->texto('detectada_fecha', 10, 10, false);
        $responsable = $v->entero('responsable_id', 1, PHP_INT_MAX, false);
        $v->fin();
        $fecha = $fecha !== null ? Tarifa::fechaValida($fecha, 'detectada_fecha') : self::hoy();
        if ($fecha > self::hoy()) throw new ErrorValidacion(['detectada_fecha' => 'No se detecta algo que todavía no pasó.']);
        if ($responsable !== null) self::deCamca($responsable, 'responsable_id');

        return Db::txReintentable(static function () use ($origen, $ref, $titulo, $descripcion, $gravedad, $fecha, $responsable, $usuarioId): array {
            $n = Numerador::siguiente('SGA', 'NC');
            $numero = Numerador::formato('SGA', 'NC', $n);
            Db::q('INSERT INTO sga_nc (numero_seq, numero, origen, origen_ref, titulo, descripcion, gravedad, detectada_fecha, detectada_por,
                                       responsable_id, estado, creado_utc)
                   VALUES (:s, :n, :o, :r, :t, :d, :g, :f, :u, :resp, \'abierta\', UTC_TIMESTAMP())',
                  [':s' => $n, ':n' => $numero, ':o' => $origen, ':r' => $ref, ':t' => $titulo, ':d' => $descripcion, ':g' => $gravedad,
                   ':f' => $fecha, ':u' => $usuarioId, ':resp' => $responsable]);
            $id = Db::insertarId();
            Hash::auditar('sga_nc', $id, 'abierta', ['numero' => $numero, 'origen' => $origen, 'ref' => $ref, 'titulo' => $titulo,
                                                    'gravedad' => $gravedad, 'por' => $usuarioId]);
            return ['id' => $id, 'numero' => $numero];
        });
    }

    /** El análisis de causa. La pasa a tratamiento; se puede corregir mientras no esté en verificación. */
    public static function analizar(int $id, string $causa, ?int $usuarioId): array
    {
        $causa = trim($causa);
        if (mb_strlen($causa) < 10) throw new ErrorValidacion(['causa' => 'Contá la causa: qué la produjo, no qué pasó.']);
        return Db::txReintentable(static function () use ($id, $causa, $usuarioId): array {
            $nc = self::bloqueada($id);
            self::exigir($nc, ['abierta', 'en_tratamiento'], 'analizar la causa de');
            Db::q("UPDATE sga_nc SET causa = :c, estado = 'en_tratamiento' WHERE id = :i", [':c' => mb_substr($causa, 0, 2000), ':i' => $id]);
            Hash::auditar('sga_nc', $id, 'causa', ['causa' => $causa, 'por' => $usuarioId]);
            return self::detalle($id);
        });
    }

    public static function agregarAccion(int $id, array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $tipo = (string) $v->enum('tipo', array_keys(self::TIPOS_ACCION));
        $descripcion = trim((string) $v->texto('descripcion', 5, 1000));
        $responsable = $v->entero('responsable_id', 1, PHP_INT_MAX, false);
        $vence = Tarifa::fechaValida((string) $v->texto('vence', 10, 10), 'vence');
        $v->fin();
        if ($responsable !== null) self::deCamca($responsable, 'responsable_id');
        return Db::txReintentable(static function () use ($id, $tipo, $descripcion, $responsable, $vence, $usuarioId): array {
            $nc = self::bloqueada($id);
            self::exigir($nc, ['abierta', 'en_tratamiento'], 'agregar acciones a');
            Db::q("INSERT INTO sga_nc_accion (nc_id, tipo, descripcion, responsable_id, vence, estado, creado_por, creado_utc)
                   VALUES (:n, :t, :d, :r, :v, 'pendiente', :u, UTC_TIMESTAMP())",
                  [':n' => $id, ':t' => $tipo, ':d' => $descripcion, ':r' => $responsable, ':v' => $vence, ':u' => $usuarioId]);
            $accion = Db::insertarId();
            Hash::auditar('sga_nc_accion', $accion, 'alta', ['nc' => $nc['numero'], 'tipo' => $tipo, 'descripcion' => $descripcion,
                                                            'vence' => $vence, 'por' => $usuarioId]);
            return ['id' => $accion];
        });
    }

    /** Adjunta el archivo que prueba la acción (PDF, JPG o PNG), antes de darla por cumplida. */
    public static function adjuntar(int $accionId, string $bytes, string $nombre, ?int $usuarioId): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ErrorValidacion(['archivo' => 'El archivo tiene que pesar entre 1 byte y ' . (self::MAX_BYTES >> 20) . ' MB.']);
        }
        $mime = null;
        foreach (self::FIRMAS as $firma => $m) if (str_starts_with($bytes, $firma)) { $mime = $m; break; }
        if ($mime === null) throw new ErrorValidacion(['archivo' => 'Tiene que ser un PDF o una foto (JPG o PNG).']);
        // Antes de escribir en disco: que la acción exista, esté pendiente y su
        // NC siga viva (se vuelve a mirar bajo lock abajo).
        $previa = Db::una('SELECT a.estado, n.estado AS nc FROM sga_nc_accion a JOIN sga_nc n ON n.id = a.nc_id WHERE a.id = :i', [':i' => $accionId]);
        if ($previa === null) throw new ErrorNoEncontrado('No existe esa acción.');
        if ($previa['estado'] !== 'pendiente' || !in_array($previa['nc'], ['abierta', 'en_tratamiento'], true)) {
            throw new ErrorConflicto('Esa acción ya no admite evidencia nueva.', 'ACCION_CERRADA');
        }
        $sha = hash('sha256', $bytes);
        $nombre = mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', $nombre) ?? '') ?: 'evidencia', -160);
        $ruta = self::rutaEvidencia($sha);
        if (!is_file($ruta)) {
            @mkdir(dirname($ruta), 0750, true);
            $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $ruta)) {
                @unlink($tmp);
                throw new RuntimeException('No se pudo guardar la evidencia.');
            }
        }
        return Db::txReintentable(static function () use ($accionId, $sha, $nombre, $mime, $bytes, $usuarioId): array {
            [$nc, $a] = self::ncYAccion($accionId);
            self::exigir($nc, ['abierta', 'en_tratamiento'], 'cambiar la evidencia de');
            if ($a['estado'] !== 'pendiente') throw new ErrorConflicto('La acción ya está ' . $a['estado'] . ': su evidencia no se cambia.', 'ACCION_CERRADA');
            Db::q('UPDATE sga_nc_accion SET evidencia_sha256 = :s, evidencia_nombre = :n, evidencia_mime = :m, evidencia_bytes = :b WHERE id = :i',
                  [':s' => $sha, ':n' => $nombre, ':m' => $mime, ':b' => strlen($bytes), ':i' => $accionId]);
            Hash::auditar('sga_nc_accion', $accionId, 'evidencia_archivo', ['sha256' => $sha, 'nombre' => $nombre, 'por' => $usuarioId]);
            return ['id' => $accionId, 'sha256' => $sha];
        });
    }

    public static function cumplir(int $accionId, string $evidencia, ?int $usuarioId): array
    {
        $evidencia = trim($evidencia);
        if (mb_strlen($evidencia) < 10) {
            throw new ErrorValidacion(['evidencia' => 'Contá qué se hizo y dónde se puede comprobar: sin evidencia no hay acción cumplida.']);
        }
        return Db::txReintentable(static function () use ($accionId, $evidencia, $usuarioId): array {
            [$nc, $a] = self::ncYAccion($accionId);
            self::exigir($nc, ['abierta', 'en_tratamiento'], 'cumplir acciones de');
            if ($a['estado'] !== 'pendiente') throw new ErrorConflicto('La acción ya está ' . $a['estado'] . '.', 'ACCION_CERRADA');
            Db::q("UPDATE sga_nc_accion SET estado = 'cumplida', evidencia = :e, cumplida_por = :u, cumplida_utc = UTC_TIMESTAMP() WHERE id = :i",
                  [':e' => mb_substr($evidencia, 0, 2000), ':u' => $usuarioId, ':i' => $accionId]);
            Hash::auditar('sga_nc_accion', $accionId, 'cumplida', ['nc' => $nc['numero'], 'evidencia' => $evidencia,
                                                                  'archivo' => $a['evidencia_sha256'], 'por' => $usuarioId]);
            return ['id' => $accionId, 'estado' => 'cumplida'];
        });
    }

    public static function descartarAccion(int $accionId, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) throw new ErrorValidacion(['motivo' => 'El motivo es obligatorio.']);
        return Db::txReintentable(static function () use ($accionId, $motivo, $usuarioId): array {
            [$nc, $a] = self::ncYAccion($accionId);
            self::exigir($nc, ['abierta', 'en_tratamiento'], 'descartar acciones de');
            if ($a['estado'] !== 'pendiente') throw new ErrorConflicto('La acción ya está ' . $a['estado'] . '.', 'ACCION_CERRADA');
            Db::q("UPDATE sga_nc_accion SET estado = 'descartada', descartada_motivo = :m WHERE id = :i", [':m' => mb_substr($motivo, 0, 255), ':i' => $accionId]);
            Hash::auditar('sga_nc_accion', $accionId, 'descartada', ['nc' => $nc['numero'], 'motivo' => $motivo, 'por' => $usuarioId]);
            return ['id' => $accionId, 'estado' => 'descartada'];
        });
    }

    /** Pasa a verificación: con la causa escrita, al menos una correctiva cumplida y nada pendiente. */
    public static function aVerificacion(int $id, ?int $usuarioId): array
    {
        return Db::txReintentable(static function () use ($id, $usuarioId): array {
            $nc = self::bloqueada($id);
            self::exigir($nc, ['en_tratamiento'], 'enviar a verificación');
            if ($nc['causa'] === null) throw new ErrorConflicto('Falta el análisis de causa.', 'SIN_CAUSA');
            $pend = (int) Db::col("SELECT COUNT(*) FROM sga_nc_accion WHERE nc_id = :n AND estado = 'pendiente'", [':n' => $id]);
            if ($pend > 0) throw new ErrorConflicto('Hay ' . $pend . ' ' . ($pend === 1 ? 'acción pendiente' : 'acciones pendientes') . ': cumplilas o descartalas con motivo.', 'ACCIONES_PENDIENTES');
            $corr = (int) Db::col("SELECT COUNT(*) FROM sga_nc_accion WHERE nc_id = :n AND estado = 'cumplida' AND tipo = 'correctiva' AND sin_efecto = 0",
                                  [':n' => $id]);
            if ($corr === 0) {
                throw new ErrorConflicto('Falta una acción correctiva cumplida: corregir el efecto no elimina la causa.', 'SIN_CORRECTIVA');
            }
            Db::q("UPDATE sga_nc SET estado = 'en_verificacion' WHERE id = :i", [':i' => $id]);
            Hash::auditar('sga_nc', $id, 'a_verificacion', ['por' => $usuarioId]);
            return self::detalle($id);
        });
    }

    /**
     * Verificación de eficacia, por la dirección. Eficaz: se cierra. No eficaz:
     * vuelve a tratamiento y va a pedir otra correctiva, porque las cumplidas
     * no alcanzaron.
     */
    public static function verificar(int $id, bool $eficaz, string $comoSeVerifico, ?int $usuarioId): array
    {
        $como = trim($comoSeVerifico);
        if (mb_strlen($como) < 10) throw new ErrorValidacion(['verificacion' => 'Decí cómo se comprobó (qué se miró, cuándo, con qué resultado).']);
        return Db::txReintentable(static function () use ($id, $eficaz, $como, $usuarioId): array {
            $nc = self::bloqueada($id);
            self::exigir($nc, ['en_verificacion'], 'verificar la eficacia de');
            if ($eficaz) {
                Db::q("UPDATE sga_nc SET estado = 'cerrada', eficaz = 1, verificacion = :v, cerrada_por = :u, cerrada_utc = UTC_TIMESTAMP() WHERE id = :i",
                      [':v' => mb_substr($como, 0, 2000), ':u' => $usuarioId, ':i' => $id]);
            } else {
                // Las correctivas que no alcanzaron quedan como historia, marcadas,
                // y dejan de contar para volver a verificar: hace falta otra.
                Db::q("UPDATE sga_nc SET estado = 'en_tratamiento', eficaz = 0, verificacion = :v WHERE id = :i", [':v' => mb_substr($como, 0, 2000), ':i' => $id]);
                Db::q("UPDATE sga_nc_accion SET sin_efecto = 1 WHERE nc_id = :n AND tipo = 'correctiva' AND estado = 'cumplida'", [':n' => $id]);
            }
            Hash::auditar('sga_nc', $id, $eficaz ? 'cerrada' : 'no_eficaz', ['verificacion' => $como, 'por' => $usuarioId]);
            return self::detalle($id);
        });
    }

    public static function anular(int $id, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) throw new ErrorValidacion(['motivo' => 'El motivo es obligatorio.']);
        return Db::txReintentable(static function () use ($id, $motivo, $usuarioId): array {
            $nc = self::bloqueada($id);
            self::exigir($nc, ['abierta', 'en_tratamiento', 'en_verificacion'], 'anular');
            Db::q("UPDATE sga_nc SET estado = 'anulada', anulada_motivo = :m WHERE id = :i", [':m' => mb_substr($motivo, 0, 255), ':i' => $id]);
            // Sus acciones pendientes se descartan con el mismo motivo: si no,
            // una NC anulada seguía sumando «acciones vencidas» para siempre.
            Db::q("UPDATE sga_nc_accion SET estado = 'descartada', descartada_motivo = :m WHERE nc_id = :i AND estado = 'pendiente'",
                  [':m' => mb_substr('NC anulada: ' . $motivo, 0, 255), ':i' => $id]);
            Hash::auditar('sga_nc', $id, 'anulada', ['motivo' => $motivo, 'por' => $usuarioId]);
            return self::detalle($id);
        });
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    public static function listar(): array
    {
        $hoy = self::hoy();
        $filas = Db::todas(
            "SELECT n.id, n.numero, n.origen, n.origen_ref, n.titulo, n.gravedad, n.detectada_fecha, n.estado, r.nombre AS responsable,
                    (SELECT COUNT(*) FROM sga_nc_accion a WHERE a.nc_id = n.id AND a.estado = 'pendiente') AS pendientes,
                    (SELECT COUNT(*) FROM sga_nc_accion a WHERE a.nc_id = n.id AND a.estado = 'pendiente' AND a.vence < :h
                        AND n.estado IN ('abierta','en_tratamiento')) AS vencidas
               FROM sga_nc n LEFT JOIN usuario r ON r.id = n.responsable_id
              ORDER BY FIELD(n.estado, 'abierta', 'en_tratamiento', 'en_verificacion', 'cerrada', 'anulada'), n.numero_seq DESC",
            [':h' => $hoy]
        );
        return [
            'no_conformidades' => array_map(static fn($x) => [
                'id' => (int) $x['id'], 'numero' => $x['numero'], 'origen' => $x['origen'], 'origen_texto' => self::ORIGENES[$x['origen']],
                'origen_ref' => $x['origen_ref'], 'titulo' => $x['titulo'], 'gravedad' => $x['gravedad'], 'detectada_fecha' => $x['detectada_fecha'],
                'estado' => $x['estado'], 'responsable' => $x['responsable'], 'acciones_pendientes' => (int) $x['pendientes'],
                'acciones_vencidas' => (int) $x['vencidas'],
            ], $filas),
            'origenes' => self::ORIGENES, 'tipos_accion' => self::TIPOS_ACCION, 'hoy' => $hoy,
            'usuarios' => array_map(static fn($u) => ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']],
                Db::todas("SELECT id, nombre, rol FROM usuario WHERE activo = 1 AND rol IN ('chofer','supervisor','admin') ORDER BY nombre")),
        ];
    }

    public static function detalle(int $id): array
    {
        $n = Db::una('SELECT n.*, d.nombre AS detecto, r.nombre AS responsable, c.nombre AS cerro FROM sga_nc n
                         LEFT JOIN usuario d ON d.id = n.detectada_por LEFT JOIN usuario r ON r.id = n.responsable_id
                         LEFT JOIN usuario c ON c.id = n.cerrada_por WHERE n.id = :i', [':i' => $id]);
        if ($n === null) throw new ErrorNoEncontrado('No existe esa no conformidad.');
        $hoy = self::hoy();
        $acciones = array_map(static fn($a) => [
            'id' => (int) $a['id'], 'tipo' => $a['tipo'], 'tipo_texto' => self::TIPOS_ACCION[$a['tipo']], 'descripcion' => $a['descripcion'],
            'responsable' => $a['responsable'], 'vence' => $a['vence'], 'estado' => $a['estado'],
            'vencida' => $a['estado'] === 'pendiente' && $a['vence'] < $hoy, 'evidencia' => $a['evidencia'],
            'archivo' => $a['evidencia_sha256'] === null ? null : ['nombre' => $a['evidencia_nombre'], 'mime' => $a['evidencia_mime'],
                                                                  'bytes' => (int) $a['evidencia_bytes'], 'sha256' => $a['evidencia_sha256']],
            'cumplida_por' => $a['cumplio'], 'cumplida_utc' => $a['cumplida_utc'], 'descartada_motivo' => $a['descartada_motivo'],
            'sin_efecto' => (int) $a['sin_efecto'] === 1,
        ], Db::todas('SELECT a.*, r.nombre AS responsable, c.nombre AS cumplio FROM sga_nc_accion a
                        LEFT JOIN usuario r ON r.id = a.responsable_id LEFT JOIN usuario c ON c.id = a.cumplida_por
                       WHERE a.nc_id = :n ORDER BY a.id', [':n' => $id]));
        return [
            'id' => (int) $n['id'], 'numero' => $n['numero'], 'origen' => $n['origen'], 'origen_texto' => self::ORIGENES[$n['origen']],
            'origen_ref' => $n['origen_ref'], 'titulo' => $n['titulo'], 'descripcion' => $n['descripcion'], 'gravedad' => $n['gravedad'],
            'detectada_fecha' => $n['detectada_fecha'], 'detecto' => $n['detecto'], 'responsable' => $n['responsable'], 'estado' => $n['estado'],
            'causa' => $n['causa'], 'verificacion' => $n['verificacion'], 'eficaz' => $n['eficaz'] === null ? null : (int) $n['eficaz'] === 1,
            'cerro' => $n['cerro'], 'cerrada_utc' => $n['cerrada_utc'], 'anulada_motivo' => $n['anulada_motivo'], 'acciones' => $acciones,
        ];
    }

    /** El archivo de evidencia de una acción, para servirlo. */
    public static function archivo(int $accionId): array
    {
        $a = Db::una('SELECT evidencia_sha256, evidencia_nombre, evidencia_mime FROM sga_nc_accion WHERE id = :i', [':i' => $accionId]);
        if ($a === null || $a['evidencia_sha256'] === null) throw new ErrorNoEncontrado('Esa acción no tiene archivo.');
        $ruta = self::rutaEvidencia($a['evidencia_sha256']);
        if (!is_file($ruta)) throw new RuntimeException('Falta en disco la evidencia ' . $a['evidencia_sha256'] . '.');
        return ['ruta' => $ruta, 'nombre' => $a['evidencia_nombre'], 'mime' => $a['evidencia_mime']];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    public static function rutaEvidencia(string $sha): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha)) throw new LogicException('Huella inválida.');
        $raiz = (string) Config::get('rutas.documentos', Config::priv() . '/documentos');
        return $raiz . '/nc/' . substr($sha, 0, 2) . '/' . $sha;
    }

    private static function bloqueada(int $id): array
    {
        $n = Db::una('SELECT * FROM sga_nc WHERE id = :i FOR UPDATE', [':i' => $id]);
        if ($n === null) throw new ErrorNoEncontrado('No existe esa no conformidad.');
        return $n;
    }

    /**
     * La NC y la acción, bloqueadas en ese orden: el mismo que verificar() y
     * anular(), que bloquean la NC y después tocan sus acciones. Al revés se
     * trababan (revisión de la Fase 4). La NC de una acción no cambia: se lee
     * sin bloquear.
     *
     * @return array{0: array, 1: array}
     */
    private static function ncYAccion(int $accionId): array
    {
        $ncId = Db::col('SELECT nc_id FROM sga_nc_accion WHERE id = :i', [':i' => $accionId]);
        if ($ncId === null || $ncId === false) throw new ErrorNoEncontrado('No existe esa acción.');
        $nc = self::bloqueada((int) $ncId);
        $a = Db::una('SELECT * FROM sga_nc_accion WHERE id = :i FOR UPDATE', [':i' => $accionId]);
        return [$nc, $a];
    }

    private static function exigir(array $nc, array $estados, string $que): void
    {
        if (!in_array($nc['estado'], $estados, true)) {
            throw new ErrorConflicto('No se puede ' . $que . ' una no conformidad ' . str_replace('_', ' ', $nc['estado']) . '.', 'TRANSICION_INVALIDA');
        }
    }

    private static function deCamca(int $id, string $campo): void
    {
        $r = Db::col('SELECT rol FROM usuario WHERE id = :i AND activo = 1', [':i' => $id]);
        if (!in_array($r, ['chofer', 'supervisor', 'admin'], true)) throw new ErrorValidacion([$campo => 'Tiene que ser alguien activo de CAMCA.']);
    }
}
