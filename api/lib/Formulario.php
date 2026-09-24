<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * R23 y R28: definición versionada y emisión congelada. Obj. 1, paso F1.2.
 *
 * LA REGLA QUE ORDENA TODO ESTO:
 *
 *   Un registro emitido es una FOTO, no una consulta.
 *
 * Si el R28 se armara leyendo parada_ejecucion cada vez que alguien lo abre,
 * corregir una cantidad tres semanas después cambiaría —en silencio— un
 * documento que el cliente ya firmó. Por eso emitir() resuelve los valores
 * UNA vez y los guarda; leer() no vuelve a tocar las tablas vivas.
 *
 * Y por eso también el formulario se versiona: un registro apunta a la
 * definición exacta con la que se emitió, así agregar, sacar o renombrar un
 * campo no altera nada de lo ya emitido. Es el supuesto S4, el que más chances
 * tiene de estar equivocado, porque los formularios son de CAMCA y no
 * nuestros.
 */
final class Formulario
{
    /** Argentina no tiene horario de verano: el desfase es fijo. */
    private const ART = -3 * 3600;

    // ------------------------------------------------------------------
    // Definiciones
    // ------------------------------------------------------------------

    /** La definición vigente de un tipo, con el esquema ya decodificado. */
    public static function vigente(string $tipo): array
    {
        $f = Db::una(
            'SELECT * FROM formulario_def WHERE tipo = :t AND vigente = 1 ORDER BY version DESC LIMIT 1',
            [':t' => $tipo]
        );
        if ($f === null) throw new ErrorNoEncontrado("No hay formulario $tipo vigente.");
        return self::decodificar($f);
    }

    public static function porId(int $id): array
    {
        $f = Db::una('SELECT * FROM formulario_def WHERE id = :id', [':id' => $id]);
        if ($f === null) throw new ErrorNoEncontrado('No existe esa definición de formulario.');
        return self::decodificar($f);
    }

    private static function decodificar(array $fila): array
    {
        // Si el JSON guardado ya no coincide con su huella, alguien editó una
        // definición in situ en vez de escribir la versión siguiente. Eso
        // rompe la promesa de que un documento emitido no cambia, así que se
        // dice en vez de seguir como si nada.
        $sha = hash('sha256', (string) $fila['esquema_json']);
        if ($fila['esquema_sha'] !== '' && !hash_equals((string) $fila['esquema_sha'], $sha)) {
            Log::error('formulario_alterado', [
                'id' => (int) $fila['id'], 'tipo' => $fila['tipo'], 'version' => (int) $fila['version'],
            ]);
            throw new ErrorConflicto(
                "La definición {$fila['tipo']} v{$fila['version']} fue editada después de guardarse. " .
                'Las definiciones son inmutables: hay que cargar una versión nueva.',
                'FORMULARIO_ALTERADO'
            );
        }

        $esquema = json_decode((string) $fila['esquema_json'], true);
        if (!is_array($esquema) || !isset($esquema['secciones'])) {
            throw new ErrorConflicto('La definición de formulario no se pudo leer.', 'FORMULARIO_ILEGIBLE');
        }

        return [
            'id'      => (int) $fila['id'],
            'tipo'    => (string) $fila['tipo'],
            'version' => (int) $fila['version'],
            'nombre'  => (string) $fila['nombre'],
            'sha'     => $sha,
            'esquema' => $esquema,
        ];
    }

    /** Todos los campos de un esquema, aplanados, en orden de lectura. */
    public static function campos(array $esquema): array
    {
        $salida = [];
        foreach ($esquema['secciones'] ?? [] as $sec) {
            foreach ($sec['campos'] ?? [] as $c) {
                $c['seccion'] = $sec['clave'] ?? '';
                $salida[] = $c;
            }
        }
        return $salida;
    }

    // ------------------------------------------------------------------
    // Contexto: de dónde salen los valores
    // ------------------------------------------------------------------

    /**
     * Arma el contexto de una parada. Es la ÚNICA lectura de las tablas vivas
     * en todo el ciclo de un registro: después de emitir, nadie vuelve acá.
     */
    public static function contexto(int $paradaId): array
    {
        $p = Db::una(
            'SELECT pe.*, j.fecha, j.r23_numero, j.km_fuente,
                    r.nombre AS ruta,
                    s.nombre AS sitio_nombre, s.direccion AS sitio_direccion,
                    s.lat, s.lon, s.geo_calidad, s.geo_nota,
                    c.id AS cliente_id, c.nombre AS cliente_nombre, c.provisorio AS cliente_provisorio,
                    u.nombre AS chofer, v.patente AS vehiculo,
                    (SELECT COUNT(*) FROM evidencia e
                      WHERE e.parada_id = pe.id AND e.tipo = :foto AND e.completa = 1) AS fotos,
                    (SELECT COUNT(*) FROM evidencia e
                      WHERE e.parada_id = pe.id AND e.tipo = :firma AND e.completa = 1) AS firmas
               FROM parada_ejecucion pe
               JOIN jornada j        ON j.id = pe.jornada_id
               JOIN ruta_plantilla r ON r.id = j.ruta_id
               JOIN sitio s          ON s.id = pe.sitio_id
          LEFT JOIN cliente c        ON c.id = s.cliente_id
          LEFT JOIN usuario u        ON u.id = j.chofer_id
          LEFT JOIN vehiculo v       ON v.id = j.vehiculo_id
              WHERE pe.id = :id',
            [':id' => $paradaId, ':foto' => 'foto', ':firma' => 'firma']
        );
        if ($p === null) throw new ErrorNoEncontrado('No existe esa parada.');

        $resultado = [
            'ejecutada'    => 'Ejecutada',
            'no_ejecutada' => 'No ejecutada',
            'planificada'  => 'Sin resolver',
        ][$p['estado']] ?? 'Sin resolver';

        // El nombre dice el PESO de la evidencia, no la sigla interna. El
        // rastro de flota es autoritativo —corre en el servidor y no depende
        // de que el teléfono tenga pantalla encendida—; el del celular sólo
        // corrobora.
        $origen = [
            'flota'    => 'GPS del camión',
            'telefono' => 'GPS del celular',
            'manual'   => 'Marcada a mano',
            'ninguno'  => 'Sin evidencia de posición',
        ][$p['origen']] ?? 'Sin evidencia de posición';

        return [
            'jornada' => [
                'id'         => (int) $p['jornada_id'],
                'fecha'      => (string) $p['fecha'],
                'ruta'       => (string) $p['ruta'],
                'chofer'     => $p['chofer'],
                'vehiculo'   => $p['vehiculo'],
                'r23_numero' => $p['r23_numero'],
            ],
            'parada' => [
                'id'               => (int) $p['id'],
                'orden'            => (int) $p['orden'],
                'cantidad_plan'    => (int) $p['cantidad_plan'],
                'cantidad_real'    => $p['cantidad_real'] === null ? null : (int) $p['cantidad_real'],
                'resultado'        => $resultado,
                'motivo'           => $p['motivo'],
                'arribo_local'     => self::hora($p['arribo_utc']),
                'cierre_local'     => self::hora($p['cierre_utc']),
                'origen_etiqueta'  => $origen,
                'evidencia_doble'  => (bool) $p['evidencia_doble'],
                'ambiguo'          => (bool) $p['ambiguo'],
                'fotos'            => (int) $p['fotos'],
                'firmada'          => ((int) $p['firmas']) > 0,
            ],
            'sitio' => [
                'id'            => (int) $p['sitio_id'],
                'nombre'        => (string) $p['sitio_nombre'],
                'direccion'     => $p['sitio_direccion'],
                // Con punto decimal a propósito: esta cadena se copia, se pega
                // en un mapa y puede viajar en una URL.
                'coordenada'    => number_format((float) $p['lat'], 7, '.', '') . ', ' .
                                   number_format((float) $p['lon'], 7, '.', ''),
                'geo_confiable' => $p['geo_calidad'] === 'buena',
                'geo_nota'      => $p['geo_nota'],
            ],
            'cliente' => [
                'id'         => $p['cliente_id'] === null ? null : (int) $p['cliente_id'],
                'nombre'     => $p['cliente_nombre'],
                'provisorio' => (bool) ($p['cliente_provisorio'] ?? 0),
            ],
        ];
    }

    private static function hora(?string $utc): ?string
    {
        if ($utc === null || $utc === '') return null;
        $t = strtotime($utc . ' UTC');
        return $t === false ? null : gmdate('H:i', $t + self::ART);
    }

    // ------------------------------------------------------------------
    // Derivación
    // ------------------------------------------------------------------

    /**
     * Resuelve cada campo del esquema contra el contexto.
     *
     * Devuelve los valores y la lista de requeridos que quedaron vacíos. Los
     * faltantes NO frenan: una jornada ya ejecutada no se puede volver atrás
     * porque a un formulario le falte un campo, y esconder el documento sería
     * peor que emitirlo diciendo qué le falta.
     */
    public static function derivar(array $esquema, array $ctx, array $manual = []): array
    {
        $valores = [];
        $faltantes = [];

        foreach (self::campos($esquema) as $c) {
            $clave = (string) ($c['clave'] ?? '');
            if ($clave === '') continue;

            if (array_key_exists($clave, $manual)) {
                $v = $manual[$clave];
            } elseif (isset($c['origen'])) {
                $v = self::resolver($ctx, (string) $c['origen']);
            } else {
                $v = $c['predeterminado'] ?? null;
            }

            $v = self::coercer($v, (string) ($c['tipo'] ?? 'texto'));
            $valores[$clave] = $v;

            if (!empty($c['requerido']) && ($v === null || $v === '')) $faltantes[] = $clave;
        }

        return ['valores' => $valores, 'faltantes' => $faltantes];
    }

    /** Camino con puntos sobre el contexto: "parada.cantidad_real". */
    private static function resolver(array $ctx, string $camino): mixed
    {
        $nodo = $ctx;
        foreach (explode('.', $camino) as $paso) {
            if (!is_array($nodo) || !array_key_exists($paso, $nodo)) return null;
            $nodo = $nodo[$paso];
        }
        return $nodo;
    }

    private static function coercer(mixed $v, string $tipo): mixed
    {
        if ($v === null) return null;
        return match ($tipo) {
            'entero'   => is_numeric($v) ? (int) $v : null,
            'decimal'  => is_numeric($v) ? (float) $v : null,
            'booleano' => (bool) $v,
            'fecha'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null,
            'hora'     => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? (string) $v : null,
            default    => is_scalar($v) ? trim((string) $v) : null,
        };
    }

    // ------------------------------------------------------------------
    // Emisión
    // ------------------------------------------------------------------

    /**
     * La razón social con la que se emite HOY (supuesto S3). Se congela en
     * cada documento: cambiar la configuración no reescribe los viejos.
     */
    public static function emisor(): array
    {
        return [
            'razon_social' => Config::get('emisor.razon_social', 'CAMCA S.A.S.'),
            'cuit'         => Config::get('emisor.cuit', '30-71792648-6'),
            'domicilio'    => Config::get('emisor.domicilio', 'Calingasta, San Juan'),
        ];
    }

    /**
     * Emite un registro de una parada y lo congela.
     *
     * $estado 'borrador' no consume numeración ni bloquea la unicidad; sirve
     * para revisar antes de emitir. 'emitido' sí: un solo registro vivo por
     * (tipo, parada), y la garantía la da el índice único de la base, no este
     * código, porque dos emisiones simultáneas del mismo R28 son exactamente
     * la clase de duplicado que después aparece en una factura.
     */
    public static function emitir(
        string $tipo,
        int $paradaId,
        array $manual = [],
        ?int $usuarioId = null,
        string $estado = 'emitido'
    ): array {
        if (!in_array($tipo, ['R23', 'R28'], true)) {
            throw new ErrorValidacion(['tipo' => 'Sólo R23 o R28.']);
        }
        if (!in_array($estado, ['borrador', 'emitido'], true)) {
            throw new ErrorValidacion(['estado' => 'Sólo borrador o emitido.']);
        }

        $def = self::vigente($tipo);
        $ctx = self::contexto($paradaId);
        $d   = self::derivar($def['esquema'], $ctx, $manual);

        // Canónico: claves ordenadas en profundidad. Sin esto, el mismo
        // contenido guardado dos veces daría dos huellas distintas y la
        // cadena de la Fase 2 no sería verificable por un tercero.
        $json = Hash::canonical($d['valores']);
        $sha  = hash('sha256', $json);

        $emisor = Hash::canonical(self::emisor());

        $serie = (string) Config::get('numeracion.serie', 'A');
        $vivo = $estado === 'emitido' ? $tipo . '-' . $paradaId : null;

        try {
            // Reintentable: emitir es seguro de repetir porque la transacción
            // revierte entera, numero incluido. Bajo 50 emisiones simultaneas
            // InnoDB elige victimas, y que un documento no salga porque dos
            // personas apretaron a la vez seria un mal resultado.
            $resultado = Db::txReintentable(static function () use ($tipo, $def, $ctx, $paradaId, $json, $sha, $emisor, $estado, $usuarioId, $vivo, $serie): array {
                // El número se toma ACÁ ADENTRO, no antes: si la inserción
                // falla, la transacción se lleva también el número y no queda
                // el hueco que el numerador existe para evitar.
                //
                // Un borrador NO consume numeración: sirve para revisar, y un
                // correlativo gastado en algo que después se descarta es
                // exactamente un hueco que alguien tendría que explicar.
                $seq = $estado === 'emitido' ? Numerador::siguiente($tipo, $serie) : null;
                $numero = $seq === null ? null : Numerador::formato($tipo, $serie, $seq);

                Db::q(
                    'INSERT INTO registro
                        (tipo, formulario_id, numero, numero_seq, serie, estado,
                         jornada_id, parada_id, cliente_id, sitio_id,
                         fecha, datos_json, datos_sha, emisor_json, emitido_utc, emitido_por,
                         unico_activo, creado_utc)
                     VALUES
                        (:tipo, :form, :numero, :seq, :serie, :estado,
                         :jornada, :parada, :cliente, :sitio,
                         :fecha, :datos, :sha, :emisor, :emitido, :por,
                         :vivo, UTC_TIMESTAMP())',
                    [
                        ':tipo'    => $tipo,
                        ':form'    => $def['id'],
                        ':numero'  => $numero,
                        ':seq'     => $seq,
                        ':serie'   => $serie,
                        ':estado'  => $estado,
                        ':jornada' => $ctx['jornada']['id'],
                        ':parada'  => $paradaId,
                        ':cliente' => $ctx['cliente']['id'],
                        ':sitio'   => $ctx['sitio']['id'],
                        ':fecha'   => $ctx['jornada']['fecha'],
                        ':datos'   => $json,
                        ':sha'     => $sha,
                        ':emisor'  => $emisor,
                        ':emitido' => $estado === 'emitido' ? gmdate('Y-m-d H:i:s') : null,
                        ':por'     => $usuarioId,
                        ':vivo'    => $vivo,
                    ]
                );
                $nuevo = Db::insertarId();

                // Las evidencias quedan anotadas por id Y por huella: si
                // mañana alguien reemplaza un archivo, el registro lo delata.
                //
                // Se lee primero y se inserta después, en vez de un
                // INSERT ... SELECT. Un INSERT ... SELECT toma next-key locks
                // sobre la tabla de origen, y cuando `evidencia` está vacía
                // eso significa que las 50 emisiones simultáneas bloquean el
                // MISMO hueco del índice y se matan entre ellas. Pasó de
                // verdad: un deadlock en 1 de cada 50 emisiones concurrentes.
                $evidencias = Db::todas(
                    'SELECT id, sha256 FROM evidencia WHERE parada_id = :p AND completa = 1',
                    [':p' => $paradaId]
                );
                foreach ($evidencias as $ev) {
                    Db::q(
                        'INSERT IGNORE INTO registro_evidencia (registro_id, evidencia_id, sha256)
                         VALUES (:r, :e, :s)',
                        [':r' => $nuevo, ':e' => (int) $ev['id'], ':s' => $ev['sha256']]
                    );
                }
                return ['id' => $nuevo, 'numero' => $numero, 'seq' => $seq];
            });
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) {
                $otro = Db::una(
                    'SELECT id, numero FROM registro WHERE unico_activo = :v',
                    [':v' => $vivo]
                );
                throw new ErrorConflicto(
                    "Esta parada ya tiene un $tipo emitido" .
                    ($otro !== null ? " (#{$otro['id']})" : '') .
                    '. Para rehacerlo hay que anular el anterior.',
                    'REGISTRO_DUPLICADO'
                );
            }
            throw $e;
        }

        Hash::auditar('registro', $resultado['id'], $estado === 'emitido' ? 'emitido' : 'borrador', [
            'tipo'       => $tipo,
            'numero'     => $resultado['numero'],
            'formulario' => $def['tipo'] . ' v' . $def['version'],
            'parada'     => $paradaId,
            'sha'        => $sha,
            'faltantes'  => $d['faltantes'],
        ]);

        return [
            'id'         => $resultado['id'],
            'tipo'       => $tipo,
            'numero'     => $resultado['numero'],
            'numero_seq' => $resultado['seq'],
            'serie'      => $serie,
            'estado'     => $estado,
            'formulario' => $def['tipo'] . ' v' . $def['version'],
            'sha'        => $sha,
            'valores'    => $d['valores'],
            'faltantes'  => $d['faltantes'],
        ];
    }

    /**
     * Anula un registro. NO libera su número (eso es F1.4) y sí libera la
     * unicidad, para que se pueda emitir el reemplazo.
     */
    public static function anular(int $registroId, string $motivo, ?int $usuarioId = null): void
    {
        $motivo = trim($motivo);
        if ($motivo === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué se anula.']);

        $r = Db::una('SELECT id, tipo, estado FROM registro WHERE id = :id', [':id' => $registroId]);
        if ($r === null) throw new ErrorNoEncontrado('No existe ese registro.');
        if ($r['estado'] === 'anulado') return;

        Db::q(
            "UPDATE registro
                SET estado = 'anulado', anulado_utc = UTC_TIMESTAMP(), anulado_por = :u,
                    anulado_motivo = :m, unico_activo = NULL
              WHERE id = :id",
            [':u' => $usuarioId, ':m' => mb_substr($motivo, 0, 255), ':id' => $registroId]
        );

        Hash::auditar('registro', $registroId, 'anulado', ['motivo' => $motivo, 'por' => $usuarioId]);
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * Devuelve el registro tal como se emitió: los valores congelados y la
     * definición con la que se emitió, NO la vigente.
     *
     * Acá no se toca ni una tabla viva. Es lo que hace que cambiar el
     * formulario, o corregir una cantidad en la jornada, no altere un
     * documento ya emitido.
     */
    public static function leer(int $registroId): array
    {
        $r = Db::una('SELECT * FROM registro WHERE id = :id', [':id' => $registroId]);
        if ($r === null) throw new ErrorNoEncontrado('No existe ese registro.');

        $sha = hash('sha256', (string) $r['datos_json']);
        $intacto = hash_equals((string) $r['datos_sha'], $sha);
        if (!$intacto) {
            Log::error('registro_alterado', ['id' => $registroId, 'tipo' => $r['tipo']]);
        }

        $def = self::porId((int) $r['formulario_id']);

        return [
            'id'         => (int) $r['id'],
            'tipo'       => (string) $r['tipo'],
            'numero'     => $r['numero'],
            'serie'      => (string) $r['serie'],
            'estado'     => (string) $r['estado'],
            'fecha'      => (string) $r['fecha'],
            'formulario' => ['tipo' => $def['tipo'], 'version' => $def['version'], 'nombre' => $def['nombre']],
            'esquema'    => $def['esquema'],
            'valores'    => json_decode((string) $r['datos_json'], true) ?: [],
            'emisor'     => json_decode((string) ($r['emisor_json'] ?? ''), true) ?: null,
            'emitido'    => $r['emitido_utc'],
            'anulado'    => $r['anulado_utc'],
            'motivo_anulacion' => $r['anulado_motivo'],
            'sha'        => (string) $r['datos_sha'],
            // Si esto viene en false, alguien tocó datos_json a mano en la
            // base. Se dice, no se disimula.
            'intacto'    => $intacto,
        ];
    }
}
