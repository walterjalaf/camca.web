<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Documentos controlados del sistema de gestión ambiental (ISO 14001
 * §7.5.3). Obj. 5, paso F4.1.
 *
 * Ciclo de una versión:
 *
 *   borrador ──enviar──▶ en_revision ──aprobar──▶ vigente ──(la siguiente se aprueba)──▶ obsoleta
 *      │  ▲                   │
 *      │  └─────devolver──────┘
 *      └──descartar──▶ descartada
 *
 * Una sola función mueve cada flecha, cada una dentro de su transacción y con
 * su eslabón de auditoría. La que aprueba no puede ser la misma persona que
 * elaboró (S16): la norma pide que alguien más revise la conveniencia del
 * documento, y en una empresa chica lo más fácil es saltearlo sin notarlo.
 *
 * El archivo (PDF) se guarda por su huella fuera de public_html y no cambia
 * una vez enviada la versión: lo que se aprobó es exactamente lo que se abre.
 */
final class SgaDocumento
{
    public const TIPOS = [
        'politica'      => 'Política',
        'manual'        => 'Manual',
        'procedimiento' => 'Procedimiento',
        'instructivo'   => 'Instructivo',
        'registro'      => 'Formato de registro',
        'plan'          => 'Plan',
        'externo'       => 'Documento externo',
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Días antes de la fecha de revisión en que el documento figura «por vencer». */
    public const AVISO_DIAS = 30;

    public static function hoy(): string
    {
        return gmdate('Y-m-d', time() - 3 * 3600);
    }

    // ------------------------------------------------------------------
    // Alta y versiones
    // ------------------------------------------------------------------

    /** Da de alta un documento y su versión 1 en borrador. */
    public static function alta(array $d, ?int $usuarioId): array
    {
        $v = new Validar($d);
        $codigo = $v->texto('codigo', 2, 30);
        $titulo = $v->texto('titulo', 3, 160);
        $tipo = $v->enum('tipo', array_keys(self::TIPOS));
        $proceso = $v->texto('proceso', 2, 80, false);
        $responsable = $v->entero('responsable_id', 1, PHP_INT_MAX, false);
        $meses = $v->entero('revision_meses', 1, 60, false) ?? 12;
        $cambios = $v->texto('cambios', 3, 1000, false) ?? 'Emisión inicial.';
        $v->fin();
        $codigo = mb_strtoupper(trim($codigo));
        if (!preg_match('/^[A-Z0-9][A-Z0-9.\-\/]{1,29}$/', $codigo)) {
            throw new ErrorValidacion(['codigo' => 'Código inválido: letras, números, punto, guion o barra (p. ej. PR-AMB-001).']);
        }
        if ($responsable !== null) self::usuarioDeOficina($responsable, 'responsable_id');

        return Db::tx(static function () use ($codigo, $titulo, $tipo, $proceso, $responsable, $meses, $cambios, $usuarioId): array {
            try {
                Db::q('INSERT INTO sga_documento (codigo, titulo, tipo, proceso, responsable_id, revision_meses, creado_por, creado_utc)
                       VALUES (:c, :t, :ti, :p, :r, :m, :u, UTC_TIMESTAMP())',
                      [':c' => $codigo, ':t' => trim($titulo), ':ti' => $tipo, ':p' => $proceso !== null ? trim($proceso) : null,
                       ':r' => $responsable, ':m' => $meses, ':u' => $usuarioId]);
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) throw new ErrorConflicto('Ya hay un documento con el código ' . $codigo . '.', 'CODIGO_DUPLICADO');
                throw $e;
            }
            $id = Db::insertarId();
            Db::q("INSERT INTO sga_version (documento_id, version, estado, cambios, elaborado_por, elaborado_utc)
                   VALUES (:d, 1, 'borrador', :c, :u, UTC_TIMESTAMP())",
                  [':d' => $id, ':c' => trim($cambios), ':u' => $usuarioId]);
            $ver = Db::insertarId();
            Hash::auditar('sga_documento', $id, 'alta', ['codigo' => $codigo, 'titulo' => trim($titulo), 'tipo' => $tipo, 'version_id' => $ver]);
            return ['id' => $id, 'codigo' => $codigo, 'version_id' => $ver];
        });
    }

    /**
     * Abre una versión nueva en borrador. Hay a lo sumo una versión en curso
     * (borrador o en revisión) por documento: dos borradores en paralelo
     * terminan con dos textos que se contradicen y nadie sabe cuál se aprobó.
     */
    public static function nuevaVersion(int $documentoId, string $cambios, ?int $usuarioId): array
    {
        $cambios = trim($cambios);
        if (mb_strlen($cambios) < 3) throw new ErrorValidacion(['cambios' => 'Decí qué cambia y por qué.']);
        return Db::txReintentable(static function () use ($documentoId, $cambios, $usuarioId): array {
            $doc = Db::una('SELECT id, activo FROM sga_documento WHERE id = :d FOR UPDATE', [':d' => $documentoId]);
            if ($doc === null) throw new ErrorNoEncontrado('No existe ese documento.');
            if ((int) $doc['activo'] !== 1) throw new ErrorConflicto('El documento está retirado.', 'DOCUMENTO_RETIRADO');
            $enCurso = Db::col("SELECT version FROM sga_version WHERE documento_id = :d AND estado IN ('borrador','en_revision')",
                               [':d' => $documentoId]);
            if ($enCurso !== null) {
                throw new ErrorConflicto('Ya hay una versión en curso (v' . $enCurso . '): terminala o descartala antes de abrir otra.', 'VERSION_EN_CURSO');
            }
            $n = (int) Db::col('SELECT COALESCE(MAX(version), 0) + 1 FROM sga_version WHERE documento_id = :d', [':d' => $documentoId]);
            Db::q("INSERT INTO sga_version (documento_id, version, estado, cambios, elaborado_por, elaborado_utc)
                   VALUES (:d, :n, 'borrador', :c, :u, UTC_TIMESTAMP())",
                  [':d' => $documentoId, ':n' => $n, ':c' => mb_substr($cambios, 0, 1000), ':u' => $usuarioId]);
            $id = Db::insertarId();
            Hash::auditar('sga_version', $id, 'borrador', ['documento_id' => $documentoId, 'version' => $n, 'cambios' => $cambios]);
            return ['version_id' => $id, 'version' => $n];
        });
    }

    /** Carga (o reemplaza) el PDF de una versión en borrador. */
    public static function subirArchivo(int $versionId, string $bytes, string $nombre, ?int $usuarioId): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ErrorValidacion(['archivo' => 'El archivo tiene que pesar entre 1 byte y ' . (self::MAX_BYTES >> 20) . ' MB.']);
        }
        // Sólo PDF: es lo que se imprime y se distribuye igual en todos lados,
        // y lo que nadie edita sin querer al abrirlo.
        if (!str_starts_with($bytes, '%PDF-')) throw new ErrorValidacion(['archivo' => 'Tiene que ser un PDF.']);
        // Antes de escribir nada en disco: que la versión exista y sea un
        // borrador. Si no, con ids inventados se llenaba la cuota de archivos
        // huérfanos (revisión de la Fase 4). Se vuelve a mirar bajo lock abajo.
        $estado = Db::col('SELECT estado FROM sga_version WHERE id = :i', [':i' => $versionId]);
        if ($estado === null || $estado === false) throw new ErrorNoEncontrado('No existe esa versión.');
        if ($estado !== 'borrador') throw new ErrorConflicto('Sólo se cambia el archivo de un borrador: lo enviado a revisión ya no se toca.', 'VERSION_CERRADA');
        $sha = hash('sha256', $bytes);
        $nombre = trim(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', $nombre) ?? '');
        if ($nombre === '' || !str_ends_with(mb_strtolower($nombre), '.pdf')) $nombre = ($nombre !== '' ? $nombre : 'documento') . '.pdf';
        $nombre = mb_substr($nombre, -160);

        // Primero al disco, con su huella de nombre: si la transacción falla
        // queda un archivo huérfano inofensivo; al revés, una versión que
        // apunta a un archivo que no existe.
        $ruta = self::rutaArchivo($sha);
        if (!is_file($ruta)) {
            @mkdir(dirname($ruta), 0750, true);
            $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $ruta)) {
                @unlink($tmp);
                throw new RuntimeException('No se pudo guardar el archivo del documento.');
            }
        }

        return Db::txReintentable(static function () use ($versionId, $sha, $bytes, $nombre, $usuarioId): array {
            $v = self::versionBloqueada($versionId);
            if ($v['estado'] !== 'borrador') {
                throw new ErrorConflicto('Sólo se cambia el archivo de un borrador: lo enviado a revisión ya no se toca.', 'VERSION_CERRADA');
            }
            Db::q('UPDATE sga_version SET archivo_sha256 = :s, archivo_bytes = :b, archivo_nombre = :n, archivo_por = :u WHERE id = :i',
                  [':s' => $sha, ':b' => strlen($bytes), ':n' => $nombre, ':u' => $usuarioId, ':i' => $versionId]);
            Hash::auditar('sga_version', $versionId, 'archivo', ['sha256' => $sha, 'bytes' => strlen($bytes), 'nombre' => $nombre, 'por' => $usuarioId]);
            return ['version_id' => $versionId, 'sha256' => $sha, 'bytes' => strlen($bytes)];
        });
    }

    // ------------------------------------------------------------------
    // Transiciones
    // ------------------------------------------------------------------

    public static function enviar(int $versionId, ?int $usuarioId): array
    {
        return Db::txReintentable(static function () use ($versionId, $usuarioId): array {
            $v = self::versionBloqueada($versionId);
            self::exigirEstado($v, 'borrador', 'enviar a revisión');
            if ($v['archivo_sha256'] === null) throw new ErrorConflicto('Falta el archivo: no se revisa un documento que no se puede leer.', 'SIN_ARCHIVO');
            Db::q("UPDATE sga_version SET estado = 'en_revision', enviado_utc = UTC_TIMESTAMP(), enviado_por = :u, devuelta_motivo = NULL WHERE id = :i",
                  [':u' => $usuarioId, ':i' => $versionId]);
            Hash::auditar('sga_version', $versionId, 'enviada', ['por' => $usuarioId]);
            return self::version($versionId);
        });
    }

    public static function devolver(int $versionId, string $motivo, ?int $usuarioId): array
    {
        $motivo = self::motivo($motivo);
        return Db::txReintentable(static function () use ($versionId, $motivo, $usuarioId): array {
            $v = self::versionBloqueada($versionId);
            self::exigirEstado($v, 'en_revision', 'devolver');
            Db::q("UPDATE sga_version SET estado = 'borrador', devuelta_motivo = :m WHERE id = :i", [':m' => $motivo, ':i' => $versionId]);
            Hash::auditar('sga_version', $versionId, 'devuelta', ['motivo' => $motivo, 'por' => $usuarioId]);
            return self::version($versionId);
        });
    }

    public static function descartar(int $versionId, string $motivo, ?int $usuarioId): array
    {
        $motivo = self::motivo($motivo);
        return Db::txReintentable(static function () use ($versionId, $motivo, $usuarioId): array {
            $v = self::versionBloqueada($versionId);
            self::exigirEstado($v, 'borrador', 'descartar');
            Db::q("UPDATE sga_version SET estado = 'descartada', devuelta_motivo = :m WHERE id = :i", [':m' => $motivo, ':i' => $versionId]);
            Hash::auditar('sga_version', $versionId, 'descartada', ['motivo' => $motivo, 'por' => $usuarioId]);
            return self::version($versionId);
        });
    }

    /**
     * Aprueba: la versión pasa a vigente y la anterior a obsoleta, en la misma
     * transacción. La clave única de `vigente` es la que impide dos vigentes:
     * aunque dos personas aprueben a la vez, la segunda choca.
     */
    public static function aprobar(int $versionId, ?int $usuarioId): array
    {
        return Db::txReintentable(static function () use ($versionId, $usuarioId): array {
            // Primero el documento y después la versión, el mismo orden que
            // retirar(): al revés, aprobar y retirar a la vez se trababan
            // (revisión de la Fase 4). El documento de una versión no cambia:
            // se puede leer sin bloquear.
            $docId = Db::col('SELECT documento_id FROM sga_version WHERE id = :i', [':i' => $versionId]);
            if ($docId === null || $docId === false) throw new ErrorNoEncontrado('No existe esa versión.');
            $doc = Db::una('SELECT id, codigo, revision_meses, activo FROM sga_documento WHERE id = :d FOR UPDATE', [':d' => $docId]);
            $v = self::versionBloqueada($versionId);
            self::exigirEstado($v, 'en_revision', 'aprobar');
            // S16: otra persona que la que la elaboró, subió el PDF o la envió.
            // Con sólo «elaboró», alguien subía su propio archivo a una versión
            // que había abierto otro y la aprobaba él mismo.
            $intervinieron = array_map('intval', array_filter([$v['elaborado_por'], $v['archivo_por'], $v['enviado_por']], static fn($x) => $x !== null));
            if ($usuarioId !== null && in_array($usuarioId, $intervinieron, true) && !Config::get('sga.permitir_autoaprobacion', false)) {
                throw new ErrorConflicto('La aprueba otra persona, no quien la elaboró, subió o envió (S16).', 'AUTOAPROBACION');
            }
            if ((int) $doc['activo'] !== 1) throw new ErrorConflicto('El documento está retirado.', 'DOCUMENTO_RETIRADO');
            $hoy = self::hoy();
            $revisar = self::sumarMeses($hoy, (int) $doc['revision_meses']);

            // Sin FOR UPDATE: el orden lo da la fila del documento, bloqueada
            // arriba. Un FOR UPDATE sobre una vigente que todavía no existe
            // bloquea un hueco del índice (el deadlock de la revisión de la Fase 3).
            $anterior = Db::una('SELECT id, version FROM sga_version WHERE vigente = :d', [':d' => $doc['id']]);
            if ($anterior !== null) {
                Db::q("UPDATE sga_version SET estado = 'obsoleta', vigente = NULL, obsoleta_utc = UTC_TIMESTAMP() WHERE id = :i",
                      [':i' => $anterior['id']]);
            }
            try {
                Db::q("UPDATE sga_version SET estado = 'vigente', vigente = :d, aprobado_por = :u, aprobado_utc = UTC_TIMESTAMP(),
                              vigente_desde = :h, revisar_antes = :r
                        WHERE id = :i",
                      [':d' => $doc['id'], ':u' => $usuarioId, ':h' => $hoy, ':r' => $revisar, ':i' => $versionId]);
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) throw new ErrorConflicto('Otra versión de este documento se aprobó recién. Recargá.', 'OTRA_VIGENTE');
                throw $e;
            }
            // Distribución: cada destinatario activo tiene que tomar
            // conocimiento de ESTA versión, aunque haya leído la anterior.
            // Fila por fila y no INSERT … SELECT: ese statement toma locks
            // compartidos sobre lo que lee y ya dio deadlocks (F1.4).
            foreach (Db::todas('SELECT d.usuario_id FROM sga_destinatario d JOIN usuario u ON u.id = d.usuario_id
                                 WHERE d.documento_id = :d AND u.activo = 1', [':d' => $doc['id']]) as $x) {
                Db::q('INSERT INTO sga_distribucion (version_id, usuario_id, asignada_utc) VALUES (:v, :u, UTC_TIMESTAMP())',
                      [':v' => $versionId, ':u' => $x['usuario_id']]);
            }
            Hash::auditar('sga_version', $versionId, 'aprobada', [
                'documento' => $doc['codigo'], 'version' => (int) $v['version'], 'sha256' => $v['archivo_sha256'],
                'reemplaza' => $anterior !== null ? (int) $anterior['version'] : null, 'revisar_antes' => $revisar, 'por' => $usuarioId,
            ]);
            return self::version($versionId);
        });
    }

    /**
     * Revisión periódica sin cambios: quien aprueba confirma que la versión
     * vigente sigue siendo adecuada y corre la fecha de la próxima revisión.
     */
    public static function confirmarVigencia(int $documentoId, ?int $usuarioId): array
    {
        return Db::txReintentable(static function () use ($documentoId, $usuarioId): array {
            $doc = Db::una('SELECT id, revision_meses FROM sga_documento WHERE id = :d FOR UPDATE', [':d' => $documentoId]);
            if ($doc === null) throw new ErrorNoEncontrado('No existe ese documento.');
            $v = Db::una('SELECT id, version, revisar_antes FROM sga_version WHERE vigente = :d', [':d' => $documentoId]);
            if ($v === null) throw new ErrorConflicto('El documento no tiene versión vigente: se aprueba una, no se confirma.', 'SIN_VIGENTE');
            $revisar = self::sumarMeses(self::hoy(), (int) $doc['revision_meses']);
            Db::q('UPDATE sga_version SET revisar_antes = :r WHERE id = :i', [':r' => $revisar, ':i' => $v['id']]);
            Hash::auditar('sga_version', (int) $v['id'], 'vigencia_confirmada',
                          ['antes' => $v['revisar_antes'], 'ahora' => $revisar, 'por' => $usuarioId]);
            return self::version((int) $v['id']);
        });
    }

    /** Retira un documento entero: su vigente queda obsoleta y no se abren versiones nuevas. */
    public static function retirar(int $documentoId, string $motivo, ?int $usuarioId): array
    {
        $motivo = self::motivo($motivo);
        return Db::txReintentable(static function () use ($documentoId, $motivo, $usuarioId): array {
            $doc = Db::una('SELECT id, activo FROM sga_documento WHERE id = :d FOR UPDATE', [':d' => $documentoId]);
            if ($doc === null) throw new ErrorNoEncontrado('No existe ese documento.');
            if ((int) $doc['activo'] !== 1) throw new ErrorConflicto('Ya estaba retirado.', 'DOCUMENTO_RETIRADO');
            Db::q("UPDATE sga_version SET estado = 'obsoleta', vigente = NULL, obsoleta_utc = UTC_TIMESTAMP() WHERE vigente = :d", [':d' => $documentoId]);
            Db::q("UPDATE sga_version SET estado = 'descartada', devuelta_motivo = :m WHERE documento_id = :d AND estado IN ('borrador','en_revision')",
                  [':m' => 'Documento retirado: ' . $motivo, ':d' => $documentoId]);
            Db::q('UPDATE sga_documento SET activo = 0, retirado_motivo = :m WHERE id = :d', [':m' => $motivo, ':d' => $documentoId]);
            Hash::auditar('sga_documento', $documentoId, 'retirado', ['motivo' => $motivo, 'por' => $usuarioId]);
            return ['id' => $documentoId, 'activo' => false];
        });
    }

    // ------------------------------------------------------------------
    // Distribución
    // ------------------------------------------------------------------

    /**
     * Fija la lista de distribución. Los que se suman reciben la versión
     * vigente para tomar conocimiento; a los que se quitan no se les borra lo
     * que ya leyeron.
     */
    public static function destinatarios(int $documentoId, array $usuarioIds, ?int $usuarioId): array
    {
        $ids = array_values(array_unique(array_map('intval', $usuarioIds)));
        foreach ($ids as $u) self::usuarioDestinatario($u);
        return Db::txReintentable(static function () use ($documentoId, $ids, $usuarioId): array {
            $doc = Db::una('SELECT id FROM sga_documento WHERE id = :d FOR UPDATE', [':d' => $documentoId]);
            if ($doc === null) throw new ErrorNoEncontrado('No existe ese documento.');
            $antes = array_map('intval', array_column(Db::todas('SELECT usuario_id FROM sga_destinatario WHERE documento_id = :d', [':d' => $documentoId]), 'usuario_id'));
            // Se borran y se agregan sólo las diferencias, cada una por su clave
            // entera: un DELETE por rango bloquea huecos del índice.
            foreach (array_diff($antes, $ids) as $u) {
                Db::q('DELETE FROM sga_destinatario WHERE documento_id = :d AND usuario_id = :u', [':d' => $documentoId, ':u' => $u]);
            }
            foreach (array_diff($ids, $antes) as $u) {
                Db::q('INSERT INTO sga_destinatario (documento_id, usuario_id) VALUES (:d, :u)', [':d' => $documentoId, ':u' => $u]);
            }
            $vig = Db::col('SELECT id FROM sga_version WHERE vigente = :d', [':d' => $documentoId]);
            if ($vig !== null) {
                foreach (array_diff($ids, $antes) as $u) {
                    Db::q('INSERT IGNORE INTO sga_distribucion (version_id, usuario_id, asignada_utc) VALUES (:v, :u, UTC_TIMESTAMP())',
                          [':v' => $vig, ':u' => $u]);
                }
            }
            Hash::auditar('sga_documento', $documentoId, 'distribucion', ['antes' => $antes, 'ahora' => $ids, 'por' => $usuarioId]);
            return ['documento_id' => $documentoId, 'destinatarios' => $ids];
        });
    }

    /**
     * Toma de conocimiento. La hace la persona desde su panel, o la registra
     * la oficina (registradaPor ≠ usuario) cuando se la entregó en mano.
     */
    public static function tomarConocimiento(int $versionId, int $usuarioObjetivo, ?int $registradaPor): array
    {
        return Db::txReintentable(static function () use ($versionId, $usuarioObjetivo, $registradaPor): array {
            $fila = Db::una('SELECT d.leida_utc, v.estado FROM sga_distribucion d JOIN sga_version v ON v.id = d.version_id
                              WHERE d.version_id = :v AND d.usuario_id = :u FOR UPDATE',
                            [':v' => $versionId, ':u' => $usuarioObjetivo]);
            if ($fila === null) throw new ErrorNoEncontrado('Esa versión no está distribuida a esa persona.');
            // De una versión que ya no rige no se toma conocimiento (una
            // pantalla vieja registraba la entrega de una obsoleta).
            if ($fila['estado'] !== 'vigente' && $fila['leida_utc'] === null) {
                throw new ErrorConflicto('Esa versión ya no está vigente: se toma conocimiento de la que rige.', 'VERSION_NO_VIGENTE');
            }
            if ($fila['leida_utc'] !== null) return ['version_id' => $versionId, 'usuario_id' => $usuarioObjetivo, 'leida_utc' => $fila['leida_utc']];
            Db::q('UPDATE sga_distribucion SET leida_utc = UTC_TIMESTAMP(), registrada_por = :r WHERE version_id = :v AND usuario_id = :u',
                  [':r' => $registradaPor !== $usuarioObjetivo ? $registradaPor : null, ':v' => $versionId, ':u' => $usuarioObjetivo]);
            Hash::auditar('sga_version', $versionId, 'conocimiento', ['usuario' => $usuarioObjetivo, 'registrada_por' => $registradaPor]);
            return ['version_id' => $versionId, 'usuario_id' => $usuarioObjetivo,
                    'leida_utc' => Db::col('SELECT leida_utc FROM sga_distribucion WHERE version_id = :v AND usuario_id = :u', [':v' => $versionId, ':u' => $usuarioObjetivo])];
        });
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * «vencido» si pasó la fecha de revisión; «por_vencer» dentro de los
     * AVISO_DIAS previos; null si está en fecha.
     */
    public static function estadoRevision(?string $revisarAntes, string $hoy): ?string
    {
        if ($revisarAntes === null) return null;
        if ($revisarAntes < $hoy) return 'vencido';
        $dias = (int) round((strtotime($revisarAntes . ' 00:00:00 UTC') - strtotime($hoy . ' 00:00:00 UTC')) / 86400);
        return $dias <= self::AVISO_DIAS ? 'por_vencer' : null;
    }

    public static function listar(int $usuarioId, ?string $hoy = null): array
    {
        $hoy ??= self::hoy();
        $docs = Db::todas(
            "SELECT d.id, d.codigo, d.titulo, d.tipo, d.proceso, d.revision_meses, d.activo, d.retirado_motivo,
                    r.nombre AS responsable,
                    v.id AS version_id, v.version, v.vigente_desde, v.revisar_antes, v.aprobado_utc,
                    c.id AS curso_id, c.version AS curso_version, c.estado AS curso_estado,
                    (SELECT COUNT(*) FROM sga_distribucion x WHERE x.version_id = v.id) AS distribuidos,
                    (SELECT COUNT(*) FROM sga_distribucion x WHERE x.version_id = v.id AND x.leida_utc IS NOT NULL) AS leidos
               FROM sga_documento d
               LEFT JOIN usuario r     ON r.id = d.responsable_id
               LEFT JOIN sga_version v ON v.vigente = d.id
               LEFT JOIN sga_version c ON c.documento_id = d.id AND c.estado IN ('borrador','en_revision')
              ORDER BY d.activo DESC, d.codigo"
        );
        $salida = [];
        foreach ($docs as $d) {
            $salida[] = [
                'id' => (int) $d['id'], 'codigo' => $d['codigo'], 'titulo' => $d['titulo'], 'tipo' => $d['tipo'],
                'tipo_texto' => self::TIPOS[$d['tipo']] ?? $d['tipo'], 'proceso' => $d['proceso'], 'responsable' => $d['responsable'],
                'activo' => (int) $d['activo'] === 1, 'retirado_motivo' => $d['retirado_motivo'],
                'vigente' => $d['version_id'] === null ? null : [
                    'version_id' => (int) $d['version_id'], 'version' => (int) $d['version'], 'desde' => $d['vigente_desde'],
                    'revisar_antes' => $d['revisar_antes'], 'revision' => self::estadoRevision($d['revisar_antes'], $hoy),
                    'distribuidos' => (int) $d['distribuidos'], 'leidos' => (int) $d['leidos'],
                ],
                'en_curso' => $d['curso_id'] === null ? null
                    : ['version_id' => (int) $d['curso_id'], 'version' => (int) $d['curso_version'], 'estado' => $d['curso_estado']],
            ];
        }
        $pendientes = array_map(static fn($p) => [
            'version_id' => (int) $p['version_id'], 'documento_id' => (int) $p['documento_id'], 'codigo' => $p['codigo'],
            'titulo' => $p['titulo'], 'version' => (int) $p['version'], 'asignada_utc' => $p['asignada_utc'],
        ], Db::todas(
            "SELECT x.version_id, v.documento_id, d.codigo, d.titulo, v.version, x.asignada_utc
               FROM sga_distribucion x JOIN sga_version v ON v.id = x.version_id JOIN sga_documento d ON d.id = v.documento_id
              WHERE x.usuario_id = :u AND x.leida_utc IS NULL AND v.estado = 'vigente'
              ORDER BY x.asignada_utc",
            [':u' => $usuarioId]
        ));
        // Para armar la lista de distribución: la gente de CAMCA, nunca un cliente.
        $usuarios = array_map(static fn($u) => ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']], Db::todas(
            "SELECT id, nombre, rol FROM usuario WHERE activo = 1 AND rol IN ('chofer','supervisor','admin') ORDER BY nombre"
        ));
        return ['documentos' => $salida, 'mis_pendientes' => $pendientes, 'usuarios' => $usuarios,
                'tipos' => self::TIPOS, 'hoy' => $hoy];
    }

    /** Un documento con TODA su historia de versiones y su distribución. */
    public static function detalle(int $documentoId): array
    {
        $d = Db::una('SELECT d.*, r.nombre AS responsable FROM sga_documento d LEFT JOIN usuario r ON r.id = d.responsable_id WHERE d.id = :d',
                     [':d' => $documentoId]);
        if ($d === null) throw new ErrorNoEncontrado('No existe ese documento.');
        $hoy = self::hoy();
        $versiones = array_map(static fn($v) => [
            'version_id' => (int) $v['id'], 'version' => (int) $v['version'], 'estado' => $v['estado'], 'cambios' => $v['cambios'],
            'archivo' => $v['archivo_sha256'] === null ? null : ['nombre' => $v['archivo_nombre'], 'bytes' => (int) $v['archivo_bytes'], 'sha256' => $v['archivo_sha256']],
            'elaborado_por' => $v['elaborador'], 'elaborado_utc' => $v['elaborado_utc'], 'enviado_utc' => $v['enviado_utc'],
            'aprobado_por' => $v['aprobador'], 'aprobado_utc' => $v['aprobado_utc'], 'vigente_desde' => $v['vigente_desde'],
            'revisar_antes' => $v['revisar_antes'], 'obsoleta_utc' => $v['obsoleta_utc'], 'devuelta_motivo' => $v['devuelta_motivo'],
            'revision' => $v['estado'] === 'vigente' ? self::estadoRevision($v['revisar_antes'], $hoy) : null,
        ], Db::todas(
            'SELECT v.*, e.nombre AS elaborador, a.nombre AS aprobador
               FROM sga_version v LEFT JOIN usuario e ON e.id = v.elaborado_por LEFT JOIN usuario a ON a.id = v.aprobado_por
              WHERE v.documento_id = :d ORDER BY v.version DESC',
            [':d' => $documentoId]
        ));
        $distribucion = array_map(static fn($x) => [
            'version_id' => (int) $x['version_id'], 'usuario_id' => (int) $x['usuario_id'], 'nombre' => $x['nombre'],
            'asignada_utc' => $x['asignada_utc'], 'leida_utc' => $x['leida_utc'], 'registrada_por' => $x['registrador'],
        ], Db::todas(
            'SELECT x.*, u.nombre, r.nombre AS registrador
               FROM sga_distribucion x JOIN sga_version v ON v.id = x.version_id
               JOIN usuario u ON u.id = x.usuario_id LEFT JOIN usuario r ON r.id = x.registrada_por
              WHERE v.documento_id = :d ORDER BY v.version DESC, u.nombre',
            [':d' => $documentoId]
        ));
        $destinatarios = array_map(static fn($x) => ['usuario_id' => (int) $x['id'], 'nombre' => $x['nombre'], 'rol' => $x['rol']], Db::todas(
            'SELECT u.id, u.nombre, u.rol FROM sga_destinatario s JOIN usuario u ON u.id = s.usuario_id WHERE s.documento_id = :d ORDER BY u.nombre',
            [':d' => $documentoId]
        ));
        return [
            'id' => (int) $d['id'], 'codigo' => $d['codigo'], 'titulo' => $d['titulo'], 'tipo' => $d['tipo'],
            'tipo_texto' => self::TIPOS[$d['tipo']] ?? $d['tipo'], 'proceso' => $d['proceso'], 'responsable' => $d['responsable'],
            'revision_meses' => (int) $d['revision_meses'], 'activo' => (int) $d['activo'] === 1, 'retirado_motivo' => $d['retirado_motivo'],
            'versiones' => $versiones, 'distribucion' => $distribucion, 'destinatarios' => $destinatarios,
        ];
    }

    /** Ruta del archivo de una versión, para servirlo. */
    public static function archivo(int $versionId): array
    {
        $v = Db::una('SELECT v.archivo_sha256, v.archivo_nombre, v.version, d.codigo
                        FROM sga_version v JOIN sga_documento d ON d.id = v.documento_id WHERE v.id = :i', [':i' => $versionId]);
        if ($v === null || $v['archivo_sha256'] === null) throw new ErrorNoEncontrado('Esa versión no tiene archivo.');
        $ruta = self::rutaArchivo($v['archivo_sha256']);
        if (!is_file($ruta)) throw new RuntimeException('Falta en disco el archivo de ' . $v['codigo'] . ' v' . $v['version'] . '.');
        return ['ruta' => $ruta, 'nombre' => $v['codigo'] . '-v' . $v['version'] . '.pdf', 'sha256' => $v['archivo_sha256']];
    }

    public static function version(int $versionId): array
    {
        $v = Db::una('SELECT id, documento_id, version, estado, vigente_desde, revisar_antes FROM sga_version WHERE id = :i', [':i' => $versionId]);
        if ($v === null) throw new ErrorNoEncontrado('No existe esa versión.');
        return ['version_id' => (int) $v['id'], 'documento_id' => (int) $v['documento_id'], 'version' => (int) $v['version'],
                'estado' => $v['estado'], 'vigente_desde' => $v['vigente_desde'], 'revisar_antes' => $v['revisar_antes']];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    public static function rutaArchivo(string $sha): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha)) throw new LogicException('Huella inválida.');
        $raiz = (string) Config::get('rutas.documentos', Config::priv() . '/documentos');
        return $raiz . '/' . substr($sha, 0, 2) . '/' . $sha . '.pdf';
    }

    private static function versionBloqueada(int $versionId): array
    {
        $v = Db::una('SELECT * FROM sga_version WHERE id = :i FOR UPDATE', [':i' => $versionId]);
        if ($v === null) throw new ErrorNoEncontrado('No existe esa versión.');
        return $v;
    }

    private static function exigirEstado(array $v, string $estado, string $accion): void
    {
        if ($v['estado'] !== $estado) {
            throw new ErrorConflicto('No se puede ' . $accion . ' una versión ' . str_replace('_', ' ', $v['estado']) . '.', 'TRANSICION_INVALIDA');
        }
    }

    private static function motivo(string $m): string
    {
        $m = trim($m);
        if (mb_strlen($m) < 3) throw new ErrorValidacion(['motivo' => 'El motivo es obligatorio.']);
        return mb_substr($m, 0, 255);
    }

    private static function usuarioDeOficina(int $id, string $campo): void
    {
        $r = Db::col("SELECT rol FROM usuario WHERE id = :i AND activo = 1", [':i' => $id]);
        if (!in_array($r, ['supervisor', 'admin'], true)) throw new ErrorValidacion([$campo => 'Tiene que ser alguien de la oficina.']);
    }

    /** Cualquiera de CAMCA; un cliente no recibe documentos internos. */
    private static function usuarioDestinatario(int $id): void
    {
        $r = Db::col("SELECT rol FROM usuario WHERE id = :i AND activo = 1", [':i' => $id]);
        if (!in_array($r, ['chofer', 'supervisor', 'admin'], true)) {
            throw new ErrorValidacion(['usuarios' => 'Destinatario inválido: tiene que ser alguien activo de CAMCA.']);
        }
    }

    /** Suma meses a una fecha; el 31 que no existe cae al último día del mes. */
    public static function sumarMeses(string $fecha, int $meses): string
    {
        [$a, $m, $d] = array_map('intval', explode('-', $fecha));
        $m += $meses;
        $a += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        $ultimo = (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $a));
        return sprintf('%04d-%02d-%02d', $a, $m, min($d, $ultimo));
    }
}
