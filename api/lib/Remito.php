<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Remito digital. Obj. 2, paso F2.1.
 *
 * SE ARMA DESDE EL R28 CONGELADO, NUNCA DESDE LAS TABLAS VIVAS.
 *
 * Es la única manera de que el remito y el registro de ejecución no puedan
 * decir cosas distintas. Si el remito leyera parada_ejecucion y alguien
 * corrigiera la cantidad entre la emisión de uno y otro, el cliente tendría en
 * la mano dos papeles de CAMCA con dos cantidades distintas para el mismo
 * trabajo, y ese es el papel que después se discute contra una factura.
 *
 * Y sólo sale de trabajo VERIFICADO: la oficina tiene que haber mirado la
 * evidencia antes de que exista un documento que el cliente firma.
 *
 * No es un remito fiscal de traslado de bienes (supuesto S9): es la constancia
 * de un servicio prestado.
 */
final class Remito
{
    public const TIPO_NUMERADOR = 'REM';

    /** Versión del formato de datos_json. Cambia sólo si cambia la estructura. */
    public const FORMATO = 1;

    private const SERVICIO_PREDETERMINADO = 'Limpieza y mantenimiento de baño químico';

    // ------------------------------------------------------------------
    // Emisión
    // ------------------------------------------------------------------

    /**
     * Emite el remito de una parada verificada.
     *
     * Si la parada todavía no tiene R28 emitido, se emite acá mismo: el
     * remito se deriva del R28 y no hay otro camino para armarlo. Los dos
     * documentos salen con el mismo acto de la misma persona.
     */
    public static function emitir(int $paradaId, ?int $usuarioId = null): array
    {
        $p = Db::una(
            'SELECT id, jornada_id, flujo, estado FROM parada_ejecucion WHERE id = :id',
            [':id' => $paradaId]
        );
        if ($p === null) throw new ErrorNoEncontrado('No existe esa parada.');

        if ($p['flujo'] !== 'verificado') {
            throw new ErrorConflicto(
                $p['flujo'] === 'certificado' || $p['flujo'] === 'facturable' || $p['flujo'] === 'facturado'
                    ? 'Este trabajo ya está ' . $p['flujo'] . ': su remito ya salió.'
                    : "Este trabajo está en '{$p['flujo']}'. El remito sale de trabajo VERIFICADO: " .
                      'alguien de la oficina tiene que haber mirado la evidencia antes de que exista un ' .
                      'papel que el cliente firma.',
                'SIN_VERIFICAR'
            );
        }

        // Si ya hay un remito vivo se dice antes de gastar nada. El índice
        // único lo garantiza igual; esto es para que el mensaje sea claro y
        // para no emitir un R28 de más en el camino.
        $vivo = Db::una('SELECT id, numero FROM remito WHERE unico_activo = :v', [':v' => self::claveViva($paradaId)]);
        if ($vivo !== null) {
            throw new ErrorConflicto(
                "Esta parada ya tiene el remito {$vivo['numero']}. Para rehacerlo hay que anular el anterior.",
                'REMITO_DUPLICADO'
            );
        }

        $r28Id = Db::col("SELECT id FROM registro WHERE unico_activo = :v", [':v' => 'R28-' . $paradaId]);
        if ($r28Id === null || $r28Id === false) {
            $r28Id = Formulario::emitir('R28', $paradaId, [], $usuarioId)['id'];
        }
        $r28 = Formulario::leer((int) $r28Id);

        if (!$r28['intacto']) {
            throw new ErrorConflicto(
                "El R28 {$r28['numero']} no coincide con su propia huella: alguien lo tocó en la base. " .
                'Un remito derivado de un registro alterado heredaría la alteración.',
                'REGISTRO_ALTERADO'
            );
        }

        // Un R28 emitido antes de una reapertura dice lo del cierre anterior:
        // combinado con la conformidad del cierre nuevo daría un remito con
        // dos rondas distintas adentro. Se compara lo que el R28 congeló con
        // lo que la parada dice hoy, en los campos que el remito usa.
        // Con el MISMO esquema con que se emitió el R28, para comparar peras con peras.
        $hoy = Formulario::derivar($r28['esquema'], Formulario::contexto($paradaId))['valores'];
        foreach (['resultado', 'cantidad_real', 'cierre'] as $campo) {
            if (($r28['valores'][$campo] ?? null) !== ($hoy[$campo] ?? null)) {
                throw new ErrorConflicto(
                    "El R28 {$r28['numero']} es de antes del último cierre de la parada (" .
                    "$campo: «" . ($r28['valores'][$campo] ?? '—') . '» contra «' . ($hoy[$campo] ?? '—') . '» hoy). ' .
                    'Hay que anularlo y emitir el remito de nuevo, que emite el R28 que corresponde.',
                    'R28_DESACTUALIZADO'
                );
            }
        }

        $datos = self::derivar($paradaId, $r28);

        $json = Hash::canonical($datos);
        $sha  = hash('sha256', $json);
        // El emisor es el del R28: los dos papeles del mismo trabajo tienen
        // que salir con la misma razón social aunque la configuración haya
        // cambiado entre medio.
        $emisor = Hash::canonical($r28['emisor'] ?? Formulario::emisor());

        $ctx = Db::una(
            'SELECT pe.jornada_id, pe.sitio_id, s.cliente_id, j.fecha
               FROM parada_ejecucion pe
               JOIN jornada j ON j.id = pe.jornada_id
               JOIN sitio s   ON s.id = pe.sitio_id
              WHERE pe.id = :id',
            [':id' => $paradaId]
        );

        $serie = (string) Config::get('numeracion.serie', 'A');
        $clave = self::claveViva($paradaId);

        try {
            $res = Db::txReintentable(static function () use ($serie, $paradaId, $ctx, $r28, $json, $sha, $emisor, $usuarioId, $clave, $datos): array {
                // El número adentro de la transacción: si la inserción falla,
                // se lleva el número con ella y no queda hueco.
                // Se vuelve a mirar el circuito CON la fila bloqueada: el
                // chequeo de arriba es sin bloqueo, y alguien pudo devolver el
                // trabajo a «ejecutado» entre medio. Mover() bloquea la misma
                // fila, así que los dos no pueden pasar a la vez.
                $flujo = Db::col('SELECT flujo FROM parada_ejecucion WHERE id = :p FOR UPDATE', [':p' => $paradaId]);
                if ($flujo !== 'verificado') {
                    throw new ErrorConflicto("El trabajo dejó de estar verificado mientras se emitía (ahora: $flujo).", 'SIN_VERIFICAR');
                }

                $seq = Numerador::siguiente(self::TIPO_NUMERADOR, $serie);
                $numero = Numerador::formato(self::TIPO_NUMERADOR, $serie, $seq);

                Db::q(
                    'INSERT INTO remito
                        (numero, numero_seq, codigo_verificacion, serie, estado, conformidad,
                         parada_id, jornada_id, cliente_id, sitio_id, registro_id, fecha,
                         datos_json, datos_sha, emisor_json, emitido_utc, emitido_por,
                         unico_activo, creado_utc)
                     VALUES
                        (:numero, :seq, :codigo, :serie, :estado, :conf,
                         :parada, :jornada, :cliente, :sitio, :registro, :fecha,
                         :datos, :sha, :emisor, :emitido, :por,
                         :vivo, UTC_TIMESTAMP())',
                    [
                        ':numero'   => $numero,
                        ':seq'      => $seq,
                        ':codigo'   => Certificacion::nuevoCodigo(),
                        ':emitido'  => gmdate('Y-m-d H:i:s'),
                        ':serie'    => $serie,
                        ':estado'   => 'emitido',
                        ':conf'     => $datos['conformidad']['resultado'] ?? 'pendiente',
                        ':parada'   => $paradaId,
                        ':jornada'  => (int) $ctx['jornada_id'],
                        ':cliente'  => $ctx['cliente_id'] === null ? null : (int) $ctx['cliente_id'],
                        ':sitio'    => (int) $ctx['sitio_id'],
                        ':registro' => $r28['id'],
                        ':fecha'    => (string) $ctx['fecha'],
                        ':datos'    => $json,
                        ':sha'      => $sha,
                        ':emisor'   => $emisor,
                        ':por'      => $usuarioId,
                        ':vivo'     => $clave,
                    ]
                );
                $id = Db::insertarId();

                // El eslabón en la MISMA transacción: un remito que existe sin
                // su eslabón es justo lo que la cadena tiene que impedir. Se
                // arma leyendo la fila recién guardada, para encadenar lo que
                // quedó en la base y no lo que se creía mandar.
                $fila = Db::una('SELECT * FROM remito WHERE id = :id', [':id' => $id]);
                $esl = Certificacion::eslabonar($id, 'emision', Certificacion::contenidoEmision($fila));

                return ['id' => $id, 'numero' => $numero, 'seq' => $seq, 'eslabon' => $esl,
                        'codigo' => $fila['codigo_verificacion']];
            });
        } catch (PDOException $e) {
            if (Db::esDuplicado($e)) {
                throw new ErrorConflicto(
                    'Otra persona emitió el remito de esta parada al mismo tiempo. Volvé a mirarla.',
                    'REMITO_DUPLICADO'
                );
            }
            throw $e;
        }

        Hash::auditar('remito', $res['id'], 'emitido', [
            'numero' => $res['numero'],
            'parada' => $paradaId,
            'r28'    => $r28['numero'],
            'sha'    => $sha,
        ]);

        return [
            'id'          => $res['id'],
            'numero'      => $res['numero'],
            'numero_seq'  => $res['seq'],
            'r28'         => ['id' => $r28['id'], 'numero' => $r28['numero']],
            'conformidad' => $datos['conformidad']['resultado'] ?? 'pendiente',
            'sha'         => $sha,
            'codigo'      => Certificacion::codigoLegible($res['codigo']),
            'firma_alg'   => $res['eslabon']['firma_alg'],
            'eslabon'     => $res['eslabon']['hash'],
            'datos'       => $datos,
        ];
    }

    /**
     * El contenido del remito, a partir del R28 congelado.
     *
     * De las tablas vivas sale sólo lo que el R28 no tiene y que no puede
     * contradecirlo: la descripción del servicio (de la orden de trabajo R23,
     * si se emitió) y las huellas de la evidencia que el propio R28 ya anotó.
     */
    private static function derivar(int $paradaId, array $r28): array
    {
        $v = $r28['valores'];

        if (($v['resultado'] ?? null) !== 'Ejecutada') {
            // No debería pasar: verificado exige ejecutada. Pero el R28 es lo
            // que manda, y si dice otra cosa, el remito no sale.
            throw new ErrorConflicto(
                "El R28 {$r28['numero']} dice «" . ($v['resultado'] ?? 'sin resultado') . '». ' .
                'Un remito es la constancia de un servicio prestado: no se emite sobre uno que no se hizo.',
                'R28_NO_EJECUTADA'
            );
        }

        // La descripción del servicio sale de la orden de trabajo si existe;
        // si no, del valor por defecto de la configuración.
        $servicio = null;
        $r23Id = Db::col('SELECT id FROM registro WHERE unico_activo = :v', [':v' => 'R23-' . $paradaId]);
        if ($r23Id !== null && $r23Id !== false) {
            $r23 = Formulario::leer((int) $r23Id);
            if ($r23['intacto']) $servicio = $r23['valores']['servicio'] ?? null;
        }
        $servicio = $servicio ?: (string) Config::get('remito.servicio', self::SERVICIO_PREDETERMINADO);

        // Las evidencias son las que el R28 ya congeló, con la huella que
        // tenían en ese momento. No se vuelve a leer la tabla de evidencias:
        // si alguien reemplazó un archivo después, esta huella lo delata.
        $evidencias = array_map(
            static fn(array $e) => ['tipo' => (string) $e['tipo'], 'sha256' => (string) $e['sha256']],
            Db::todas(
                'SELECT e.tipo, re.sha256
                   FROM registro_evidencia re
                   JOIN evidencia e ON e.id = re.evidencia_id
                  WHERE re.registro_id = :r
                  ORDER BY e.tipo, e.id',
                [':r' => $r28['id']]
            )
        );

        $cantidad = $v['cantidad_real'] ?? null;

        return [
            'formato' => self::FORMATO,
            'r28' => [
                'numero' => $r28['numero'],
                'sha'    => $r28['sha'],
            ],
            'r23_numero' => $v['r23_numero'] ?? null,
            'fecha_servicio' => $v['fecha_servicio'] ?? $r28['fecha'],
            'ruta'  => $v['ruta'] ?? null,
            'orden' => $v['orden'] ?? null,
            'cliente' => $v['cliente'] ?? null,
            'sitio' => [
                'nombre'    => $v['sitio'] ?? null,
                'direccion' => $v['direccion'] ?? null,
            ],
            'items' => [[
                'descripcion'   => $servicio,
                'cantidad'      => $cantidad,
                'cantidad_plan' => $v['cantidad_plan'] ?? null,
                'unidad'        => 'baños',
            ]],
            'ejecucion' => [
                'arribo'          => $v['arribo'] ?? null,
                'cierre'          => $v['cierre'] ?? null,
                'origen_marca'    => $v['origen_marca'] ?? null,
                'doble_evidencia' => (bool) ($v['doble_evidencia'] ?? false),
            ],
            'responsables' => [
                'chofer'   => $v['chofer'] ?? null,
                'vehiculo' => $v['vehiculo'] ?? null,
            ],
            'evidencias' => $evidencias,
            'conformidad' => self::conformidad($paradaId),
        ];
    }

    /**
     * Lo que dijo el cliente en el sitio (F2.2), tal como llegó del teléfono.
     *
     * Es lo único del remito que no sale del R28: no es un dato de la
     * ejecución sino un acto del cliente. Se congela acá, al emitir, con la
     * huella de su firma. Una conformidad cuya firma todavía no llegó no se
     * congela: el remito no sale hasta que llegue (FIRMA_PENDIENTE).
     */
    private static function conformidad(int $paradaId): ?array
    {
        $c = Db::una(
            'SELECT resultado, receptor_nombre, receptor_documento, observaciones, motivo_rechazo,
                    firma_uuid, ocurrido_utc
               FROM conformidad WHERE parada_id = :p AND vigente = 1
              ORDER BY id DESC LIMIT 1',
            [':p' => $paradaId]
        );
        if ($c === null) return null;

        $firma = null;
        if ($c['firma_uuid'] !== null) {
            $ev = Db::una('SELECT uuid, sha256, completa, tipo, parada_id FROM evidencia WHERE uuid = :u', [':u' => $c['firma_uuid']]);
            // La firma tiene que ser una FIRMA, y de ESTA parada. Si no, un
            // chofer podía reusar la firma que un cliente le dio en otro sitio.
            if ($ev !== null && (int) $ev['completa'] === 1 && ($ev['tipo'] !== 'firma' || (int) $ev['parada_id'] !== $paradaId)) {
                throw new ErrorConflicto(
                    'La firma que acompaña la conformidad no es de esta parada. No se emite un remito conformado con una firma ajena.',
                    'FIRMA_AJENA'
                );
            }
            if ($ev === null || (int) $ev['completa'] !== 1) {
                throw new ErrorConflicto(
                    'La firma del cliente todavía no terminó de llegar desde el teléfono. ' .
                    'El remito sale cuando llegue: sin ella, la conformidad no tiene respaldo.',
                    'FIRMA_PENDIENTE'
                );
            }
            $firma = ['uuid' => (string) $ev['uuid'], 'sha256' => (string) $ev['sha256']];
        }

        $t = strtotime((string) $c['ocurrido_utc'] . ' UTC');

        return [
            'resultado'          => (string) $c['resultado'],
            'receptor_nombre'    => $c['receptor_nombre'],
            'receptor_documento' => $c['receptor_documento'],
            'observaciones'      => $c['observaciones'],
            'motivo_rechazo'     => $c['motivo_rechazo'],
            'firma'              => $firma,
            'hora'               => $t === false ? null : gmdate('H:i', $t - 3 * 3600),
        ];
    }

    // ------------------------------------------------------------------
    // La firma, dibujada sin confiar en el archivo
    // ------------------------------------------------------------------

    /**
     * Lee el SVG de la firma y devuelve SOLO sus trazos, si el archivo sigue
     * siendo el que se congeló.
     *
     * El SVG viene del teléfono: nunca se incrusta tal cual. Un SVG puede
     * traer scripts, y el remito se sirve desde el mismo origen que la app.
     * Se extraen los números de los caminos (M, Q, L, que es lo único que
     * produce firma.js) y se dibujan de nuevo. Cualquier otra cosa se ignora.
     *
     * @return array{ok: bool, motivo?: string, ancho?: float, alto?: float, caminos?: array}
     */
    private static function trazosFirma(array $firma): array
    {
        $ruta = Db::col('SELECT ruta_relativa FROM evidencia WHERE uuid = :u', [':u' => $firma['uuid']]);
        $raiz = (string) Config::get('rutas.evidencias', Config::priv() . '/evidencias');
        $archivo = $raiz . '/' . (string) $ruta;
        if ($ruta === null || $ruta === false || !is_file($archivo)) {
            return ['ok' => false, 'motivo' => 'El archivo de la firma no está disponible en el servidor.'];
        }
        $svg = (string) file_get_contents($archivo);
        if (!hash_equals($firma['sha256'], hash('sha256', $svg))) {
            return ['ok' => false, 'motivo' => 'El archivo de la firma NO coincide con la huella con que se emitió este remito.'];
        }

        if (!preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $vb)) {
            return ['ok' => false, 'motivo' => 'La firma no tiene un formato reconocible.'];
        }
        preg_match_all('/<path\b[^>]*\bd="([MQL0-9.,\s-]+)"/', $svg, $m);
        $caminos = [];
        foreach ($m[1] as $d) {
            preg_match_all('/([MQL])([^MQL]*)/', $d, $cmds, PREG_SET_ORDER);
            $camino = [];
            foreach ($cmds as [, $op, $args]) {
                $n = array_map('floatval', preg_split('/[\s,]+/', trim($args)) ?: []);
                $esperados = $op === 'Q' ? 4 : 2;
                if (count($n) !== $esperados) continue 2;   // camino raro: se descarta entero
                $camino[] = array_merge([$op], $n);
            }
            if ($camino !== []) $caminos[] = $camino;
        }
        if ($caminos === []) return ['ok' => false, 'motivo' => 'La firma no tiene trazos legibles.'];

        return ['ok' => true, 'ancho' => (float) $vb[1], 'alto' => (float) $vb[2], 'caminos' => $caminos];
    }

    /** Los trazos como SVG en línea, reconstruido: sólo caminos y números. */
    private static function firmaSvg(array $t): string
    {
        $d = '';
        foreach ($t['caminos'] as $camino) {
            foreach ($camino as $c) {
                $op = array_shift($c);
                $d .= $op . implode(' ', array_map(static fn($v) => rtrim(rtrim(sprintf('%.2f', $v), '0'), '.'), $c));
            }
        }
        return sprintf(
            '<svg class="firma" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" role="img" aria-label="Firma de quien recibió">' .
            '<path d="%s" fill="none" stroke="#1b2a24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            rtrim(rtrim(sprintf('%.2f', $t['ancho']), '0'), '.'),
            rtrim(rtrim(sprintf('%.2f', $t['alto']), '0'), '.'),
            $d
        );
    }

    /**
     * Los trazos como operadores de PDF, escalados a $anchoPt. Las curvas
     * cuadráticas del SVG se pasan a cúbicas, que es lo que PDF sabe dibujar.
     */
    private static function firmaPdf(array $t, float $anchoPt): array
    {
        $k = $anchoPt / max(1.0, $t['ancho']);
        $altoPt = $t['alto'] * $k;
        $X = static fn(float $x) => sprintf('%.2f', $x * $k);
        $Y = static fn(float $y) => sprintf('%.2f', $altoPt - $y * $k);   // el SVG crece hacia abajo

        $ops = "0.106 0.165 0.141 RG 1.2 w 1 J 1 j\n";
        foreach ($t['caminos'] as $camino) {
            $px = 0.0; $py = 0.0;
            foreach ($camino as $c) {
                if ($c[0] === 'M') {
                    $ops .= $X($c[1]) . ' ' . $Y($c[2]) . " m\n";
                    [$px, $py] = [$c[1], $c[2]];
                } elseif ($c[0] === 'L') {
                    $ops .= $X($c[1]) . ' ' . $Y($c[2]) . " l\n";
                    [$px, $py] = [$c[1], $c[2]];
                } else {
                    // Q (x1,y1 x,y) → C con los dos puntos de control a 2/3.
                    [, $x1, $y1, $x, $y] = $c;
                    $c1x = $px + 2 / 3 * ($x1 - $px); $c1y = $py + 2 / 3 * ($y1 - $py);
                    $c2x = $x + 2 / 3 * ($x1 - $x);   $c2y = $y + 2 / 3 * ($y1 - $y);
                    $ops .= $X($c1x) . ' ' . $Y($c1y) . ' ' . $X($c2x) . ' ' . $Y($c2y) . ' ' . $X($x) . ' ' . $Y($y) . " c\n";
                    [$px, $py] = [$x, $y];
                }
            }
            $ops .= "S\n";
        }
        return ['ops' => $ops, 'alto' => $altoPt];
    }

    private static function claveViva(int $paradaId): string
    {
        return self::TIPO_NUMERADOR . '-' . $paradaId;
    }

    // ------------------------------------------------------------------
    // Anulación
    // ------------------------------------------------------------------

    /**
     * Anula un remito. El número NO se libera, y sí la unicidad, para poder
     * emitir el reemplazo.
     *
     * Un remito de un trabajo ya certificado o facturado no se anula desde
     * acá: ese trabajo ya se le afirmó al cliente, y deshacerlo es una nota de
     * crédito (F3.4), no un botón.
     */
    public static function anular(int $remitoId, string $motivo, ?int $usuarioId = null): void
    {
        $motivo = trim($motivo);
        if ($motivo === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué se anula.']);

        $r = Db::una(
            'SELECT r.id, r.numero, r.estado, pe.flujo
               FROM remito r JOIN parada_ejecucion pe ON pe.id = r.parada_id
              WHERE r.id = :id',
            [':id' => $remitoId]
        );
        if ($r === null) throw new ErrorNoEncontrado('No existe ese remito.');
        if ($r['estado'] === 'anulado') return;

        if (in_array($r['flujo'], ['certificado', 'facturable', 'facturado'], true)) {
            throw new ErrorConflicto(
                "El trabajo del remito {$r['numero']} ya está {$r['flujo']}. " .
                'Lo que ya se certificó ante el cliente no se deshace anulando el papel.',
                'REMITO_CERTIFICADO'
            );
        }

        Db::txReintentable(static function () use ($remitoId, $usuarioId, $motivo): void {
            // Con la parada bloqueada, se vuelve a mirar el circuito: sin esto,
            // certificar y anular a la vez dejaban un trabajo certificado con
            // su remito anulado. Mover() bloquea la misma fila.
            $flujo = Db::col(
                'SELECT pe.flujo FROM parada_ejecucion pe JOIN remito r ON r.parada_id = pe.id WHERE r.id = :id FOR UPDATE',
                [':id' => $remitoId]
            );
            if (in_array($flujo, ['certificado', 'facturable', 'facturado'], true)) {
                throw new ErrorConflicto("El trabajo quedó $flujo mientras se anulaba: ya no se anula con un botón.", 'REMITO_CERTIFICADO');
            }
            $st = Db::q(
                "UPDATE remito
                    SET estado = 'anulado', anulado_utc = :t, anulado_por = :u,
                        anulado_motivo = :m, unico_activo = NULL
                  WHERE id = :id AND estado = 'emitido'",
                [':t' => gmdate('Y-m-d H:i:s'), ':u' => $usuarioId, ':m' => mb_substr($motivo, 0, 255), ':id' => $remitoId]
            );
            if ($st->rowCount() !== 1) return;   // otro lo anuló recién
            // La anulación también es un eslabón: poner el remito otra vez
            // como vigente en la base no puede borrar que se anuló.
            $fila = Db::una('SELECT * FROM remito WHERE id = :id', [':id' => $remitoId]);
            Certificacion::eslabonar($remitoId, 'anulacion', Certificacion::contenidoAnulacion($fila));
        });

        Hash::auditar('remito', $remitoId, 'anulado', ['numero' => $r['numero'], 'motivo' => $motivo]);
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    public static function leer(int $remitoId): array
    {
        $r = Db::una('SELECT * FROM remito WHERE id = :id', [':id' => $remitoId]);
        if ($r === null) throw new ErrorNoEncontrado('No existe ese remito.');
        return self::decodificar($r);
    }

    /** El remito vivo de una parada, o null. */
    public static function deParada(int $paradaId): ?array
    {
        $r = Db::una('SELECT * FROM remito WHERE unico_activo = :v', [':v' => self::claveViva($paradaId)]);
        return $r === null ? null : self::decodificar($r);
    }

    private static function decodificar(array $r): array
    {
        $intacto = hash_equals((string) $r['datos_sha'], hash('sha256', (string) $r['datos_json']));
        if (!$intacto) Log::error('remito_alterado', ['id' => (int) $r['id']]);

        return [
            'id'          => (int) $r['id'],
            'numero'      => (string) $r['numero'],
            'numero_seq'  => (int) $r['numero_seq'],
            'serie'       => (string) $r['serie'],
            'estado'      => (string) $r['estado'],
            'conformidad' => (string) $r['conformidad'],
            'parada_id'   => (int) $r['parada_id'],
            'jornada_id'  => (int) $r['jornada_id'],
            'registro_id' => (int) $r['registro_id'],
            'fecha'       => (string) $r['fecha'],
            'datos'       => json_decode((string) $r['datos_json'], true) ?: [],
            'emisor'      => json_decode((string) $r['emisor_json'], true) ?: [],
            'emitido'     => $r['emitido_utc'],
            'anulado'     => $r['anulado_utc'],
            'motivo_anulacion' => $r['anulado_motivo'],
            'sha'         => (string) $r['datos_sha'],
            'intacto'     => $intacto,
            'codigo'      => Certificacion::codigoLegible($r['codigo_verificacion'] ?? null),
        ];
    }

    // ------------------------------------------------------------------
    // Documento
    // ------------------------------------------------------------------

    /**
     * HTML imprimible y autocontenido, desde el remito congelado.
     * Mismas reglas que Documento::html(): sin JS, sin recursos externos, A4.
     */
    public static function html(int $remitoId): string
    {
        $r = self::leer($remitoId);
        $d = $r['datos'];
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $emisor = $r['emisor'];

        $items = '';
        foreach ($d['items'] ?? [] as $it) {
            $items .= '<tr><td>' . $e($it['descripcion'] ?? '') . '</td>' .
                '<td class="num">' . $e(self::cantidad($it['cantidad'] ?? null)) . '</td>' .
                '<td>' . $e(self::unidad($it['cantidad'] ?? null, (string) ($it['unidad'] ?? ''))) . '</td></tr>';
        }

        $filas = static function (array $pares) use ($e): string {
            $s = '';
            foreach ($pares as [$et, $val]) {
                if ($val === null || $val === '') continue;
                $s .= '<tr><th>' . $e($et) . '</th><td>' . $e($val) . '</td></tr>';
            }
            return $s;
        };

        $destino = $filas([
            ['Cliente', $d['cliente'] ?? null],
            ['Sitio', $d['sitio']['nombre'] ?? null],
            ['Dirección', $d['sitio']['direccion'] ?? null],
            ['Fecha del servicio', isset($d['fecha_servicio']) ? Documento::fecha((string) $d['fecha_servicio']) : null],
        ]);

        $ejec = $filas([
            ['Hora de arribo', $d['ejecucion']['arribo'] ?? null],
            ['Hora de cierre', $d['ejecucion']['cierre'] ?? null],
            ['Origen de la marca', $d['ejecucion']['origen_marca'] ?? null],
            ['Doble evidencia', ($d['ejecucion']['doble_evidencia'] ?? false) ? 'Sí' : 'No'],
            ['Chofer', $d['responsables']['chofer'] ?? null],
            ['Vehículo', $d['responsables']['vehiculo'] ?? null],
        ]);

        $refs = $filas([
            ['Registro de ejecución', $d['r28']['numero'] ?? null],
            ['Orden de trabajo', $d['r23_numero'] ?? null],
        ]);

        $evHtml = '';
        if (($d['evidencias'] ?? []) !== []) {
            $li = '';
            foreach ($d['evidencias'] as $x) {
                $li .= '<li>' . $e(self::nombreEvidencia((string) $x['tipo'])) .
                    ' <code>' . $e(substr((string) $x['sha256'], 0, 16)) . '</code></li>';
            }
            $evHtml = '<section class="bloque"><h2>Evidencia</h2><ul class="evidencias">' . $li .
                '</ul><p class="pie-nota">Se listan por huella SHA-256, tal como quedaron en el registro de ejecución.</p></section>';
        }

        $conf = self::bloqueConformidadHtml($d['conformidad'] ?? null, $e);

        $anulacion = $r['anulado'] !== null
            ? '<section class="bloque anulacion"><h2>Anulación</h2><p>' . $e($r['motivo_anulacion'] ?? '') .
              '</p><p class="pie-nota">Anulado el ' . $e(Documento::fechaHora($r['anulado'])) .
              '. El número NO se reutiliza.</p></section>'
            : '';

        $cert = self::certificacion($r);
        $certHtml = '<section class="bloque certificacion" data-nivel="' . $e($cert['nivel']) . '"><h2>' .
            $e($cert['titulo']) . '</h2><div class="cert-cuerpo"><div>';
        foreach ($cert['lineas'] as $l) $certHtml .= '<p>' . $e($l) . '</p>';
        if ($cert['huella'] !== null) {
            $certHtml .= '<p class="pie-nota">Huella en la cadena: <code>' . $e(substr($cert['huella'], 0, 32)) . '</code>' .
                ($cert['clave'] !== null ? ' · clave <code>' . $e($cert['clave']) . '</code>' : '') . '</p>';
        }
        $certHtml .= '</div>';
        $url = self::urlVerificacion($r);
        if ($url !== null) {
            // El QR lleva a la verificación pública (F2.4). Va en SVG en línea:
            // la CSP de la API no deja cargar imágenes, y un SVG en línea no
            // es una imagen para la CSP. Lo arma Qr.php con sus propios números.
            $certHtml .= '<div class="qr-caja">' . Qr::svg(Qr::matriz($url), 'Código QR para verificar este remito') .
                '<p>Escaneá o entrá a<br>' . $e(self::hostPublico()) . '/verificar</p></div>';
        }
        $certHtml .= '</div></section>';

        $marca = $r['estado'] === 'anulado' ? '<div class="marca">ANULADO</div>' : '';
        $leyendaFiscal = (string) Config::get('remito.leyenda', 'Remito de servicio. Documento no válido como factura.');
        $css = Documento::css() . self::cssExtra();
        $logo = Documento::logo();

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Remito {$e($r['numero'])}</title>
<style>{$css}</style>
</head>
<body>
<article class="hoja" data-estado="{$e($r['estado'])}" data-conformidad="{$e($r['conformidad'])}">
  <header class="cabeza">
    <div>
      {$logo}
      <p class="emisor">{$e($emisor['razon_social'] ?? '')}</p>
      <p class="emisor-dato">CUIT {$e($emisor['cuit'] ?? '')} · {$e($emisor['domicilio'] ?? '')}</p>
    </div>
    <div class="identificacion">
      <p class="tipo">Remito de servicio</p>
      <p class="numero">{$e($r['numero'])}</p>
      <p class="version">{$e(Documento::fecha($r['fecha']))}</p>
    </div>
  </header>

  <section class="bloque"><h2>Cliente y lugar</h2><table class="campos">{$destino}</table></section>

  <section class="bloque"><h2>Servicio prestado</h2>
    <table class="items">
      <thead><tr><th>Descripción</th><th class="num">Cantidad</th><th>Unidad</th></tr></thead>
      <tbody>{$items}</tbody>
    </table>
  </section>

  <section class="bloque"><h2>Ejecución</h2><table class="campos">{$ejec}</table></section>
  {$conf}
  {$evHtml}
  <section class="bloque"><h2>Referencias</h2><table class="campos">{$refs}</table></section>
  {$anulacion}
  {$certHtml}

  <footer class="pie">
    <p>{$e($leyendaFiscal)}</p>
    <p class="pie-nota">Huella del contenido: <code>{$e(substr($r['sha'], 0, 32))}</code>
       · Emitido {$e(Documento::fechaHora($r['emitido']))}</p>
  </footer>
</article>
{$marca}
</body>
</html>

HTML;
    }

    /**
     * La conformidad en el papel. Sin conformidad se dice que no la hay: un
     * hueco en blanco se completa a mano, y eso es justamente lo que un
     * documento digital tiene que impedir.
     */
    private static function bloqueConformidadHtml(?array $c, callable $e): string
    {
        if ($c === null) {
            return '<section class="bloque conformidad" data-resultado="pendiente"><h2>Conformidad del cliente</h2>' .
                '<p>El cliente no dejó conformidad en el sitio.</p></section>';
        }

        $rechazo = $c['resultado'] === 'rechazado';
        $filas = [
            ['Resultado', $rechazo ? 'NO CONFORME' : 'Conforme'],
            ['Motivo', $rechazo ? ($c['motivo_rechazo'] ?? 'Sin motivo informado') : null],
            ['Recibió', $c['receptor_nombre'] ?? 'Sin aclaración'],
            ['DNI o cargo', $c['receptor_documento'] ?? null],
            ['Observaciones', $c['observaciones'] ?? null],
            ['Hora', $c['hora'] ?? null],
        ];
        $tabla = '';
        foreach ($filas as [$et, $val]) {
            if ($val === null || $val === '') continue;
            $tabla .= '<tr><th>' . $e($et) . '</th><td>' . $e($val) . '</td></tr>';
        }

        if ($c['firma'] === null) {
            $firma = '<p class="pie-nota">Sin firma.</p>';
        } else {
            $t = self::trazosFirma($c['firma']);
            $firma = $t['ok']
                ? '<div class="firma-caja">' . self::firmaSvg($t) . '</div>'
                : '<p class="firma-alerta">' . $e($t['motivo']) . '</p>';
        }

        return '<section class="bloque conformidad" data-resultado="' . $e($c['resultado']) . '">' .
            '<h2>Conformidad del cliente</h2><table class="campos">' . $tabla . '</table>' . $firma . '</section>';
    }

    public static function pdf(int $remitoId): string
    {
        $r = self::leer($remitoId);
        $d = $r['datos'];
        $emisor = $r['emisor'];

        $pdf = new Pdf();
        $pdf->grafico(26, 26, Documento::cuboPdf(26));
        $pdf->texto((string) ($emisor['razon_social'] ?? ''), 15, true);
        $pdf->texto('CUIT ' . ($emisor['cuit'] ?? '') . '  ' . ($emisor['domicilio'] ?? ''), 9);
        $pdf->textoDerecha('Remito de servicio', 10);
        $pdf->textoDerecha($r['numero'], 17, true);
        $pdf->textoDerecha(Documento::fecha($r['fecha']), 9);
        $pdf->linea(1.2, Documento::VERDE_PDF);

        if ($r['estado'] === 'anulado') {
            $pdf->espacio(4);
            $pdf->texto('ANULADO', 14, true);
        }

        $seccion = static function (string $titulo, array $pares) use ($pdf): void {
            $pares = array_filter($pares, static fn($p) => $p[1] !== null && $p[1] !== '');
            if ($pares === []) return;
            $pdf->espacio(6);
            $pdf->texto(mb_strtoupper($titulo, 'UTF-8'), 9, true);
            $pdf->linea(0.4);
            foreach ($pares as [$et, $val]) $pdf->fila($et, (string) $val);
        };

        $seccion('Cliente y lugar', [
            ['Cliente', $d['cliente'] ?? null],
            ['Sitio', $d['sitio']['nombre'] ?? null],
            ['Dirección', $d['sitio']['direccion'] ?? null],
            ['Fecha del servicio', isset($d['fecha_servicio']) ? Documento::fecha((string) $d['fecha_servicio']) : null],
        ]);

        $seccion('Servicio prestado', array_map(
            static fn($it) => [(string) ($it['descripcion'] ?? ''),
                               self::cantidad($it['cantidad'] ?? null) . ' ' . self::unidad($it['cantidad'] ?? null, (string) ($it['unidad'] ?? ''))],
            $d['items'] ?? []
        ));

        $seccion('Ejecución', [
            ['Hora de arribo', $d['ejecucion']['arribo'] ?? null],
            ['Hora de cierre', $d['ejecucion']['cierre'] ?? null],
            ['Origen de la marca', $d['ejecucion']['origen_marca'] ?? null],
            ['Doble evidencia', ($d['ejecucion']['doble_evidencia'] ?? false) ? 'Sí' : 'No'],
            ['Chofer', $d['responsables']['chofer'] ?? null],
            ['Vehículo', $d['responsables']['vehiculo'] ?? null],
        ]);

        self::bloqueConformidadPdf($pdf, $d['conformidad'] ?? null);

        if (($d['evidencias'] ?? []) !== []) {
            $seccion('Evidencia', array_map(
                static fn($x) => [self::nombreEvidencia((string) $x['tipo']), substr((string) $x['sha256'], 0, 16)],
                $d['evidencias']
            ));
        }

        $seccion('Referencias', [
            ['Registro de ejecución', $d['r28']['numero'] ?? null],
            ['Orden de trabajo', $d['r23_numero'] ?? null],
        ]);

        if ($r['anulado'] !== null) {
            $seccion('Anulación', [
                ['Motivo', (string) ($r['motivo_anulacion'] ?? '')],
                ['Fecha', Documento::fechaHora($r['anulado'])],
            ]);
        }

        $cert = self::certificacion($r);
        $pdf->espacio(6);
        $pdf->texto(mb_strtoupper($cert['titulo'], 'UTF-8'), 9, true);
        $pdf->linea(0.4);
        foreach ($cert['lineas'] as $l) $pdf->texto($l, 9, $cert['nivel'] === 'alerta');
        if ($cert['huella'] !== null) {
            $pdf->texto('Huella en la cadena: ' . substr($cert['huella'], 0, 32) .
                        ($cert['clave'] !== null ? '   clave ' . $cert['clave'] : ''), 8);
        }
        $url = self::urlVerificacion($r);
        if ($url !== null) {
            $lado = 84.0;
            $pdf->espacio(4);
            $pdf->grafico($lado, $lado, Qr::pdf(Qr::matriz($url), $lado), Pdf::margen() + Pdf::anchoUtil() - $lado);
            $pdf->textoDerecha('Escanee o entre a ' . self::hostPublico() . '/verificar', 8);
        }

        $pdf->espacio(10);
        $pdf->linea(0.4);
        $pdf->texto((string) Config::get('remito.leyenda', 'Remito de servicio. Documento no válido como factura.'), 9);
        $pdf->texto('Huella del contenido: ' . substr($r['sha'], 0, 32) .
                    '   Emitido ' . Documento::fechaHora($r['emitido']), 8);

        return $pdf->salida();
    }

    private static function bloqueConformidadPdf(Pdf $pdf, ?array $c): void
    {
        $pdf->espacio(6);
        $pdf->texto('CONFORMIDAD DEL CLIENTE', 9, true);
        $pdf->linea(0.4);
        if ($c === null) {
            $pdf->texto('El cliente no dejó conformidad en el sitio.', 10);
            return;
        }

        $rechazo = $c['resultado'] === 'rechazado';
        $pdf->fila('Resultado', $rechazo ? 'NO CONFORME' : 'Conforme');
        if ($rechazo) $pdf->fila('Motivo', (string) ($c['motivo_rechazo'] ?? 'Sin motivo informado'));
        $pdf->fila('Recibió', (string) ($c['receptor_nombre'] ?? 'Sin aclaración'));
        if (!empty($c['receptor_documento'])) $pdf->fila('DNI o cargo', (string) $c['receptor_documento']);
        if (!empty($c['observaciones'])) $pdf->fila('Observaciones', (string) $c['observaciones']);
        if (!empty($c['hora'])) $pdf->fila('Hora', (string) $c['hora']);

        if ($c['firma'] === null) {
            $pdf->texto('Sin firma.', 9);
            return;
        }
        $t = self::trazosFirma($c['firma']);
        if (!$t['ok']) {
            $pdf->texto((string) $t['motivo'], 9, true);
            return;
        }
        $g = self::firmaPdf($t, 200.0);
        $pdf->espacio(4);
        $pdf->grafico(200.0, $g['alto'], $g['ops'], Pdf::margen() + 175.0);
    }

    /**
     * Qué se puede afirmar de este papel, dicho para quien lo lee.
     *
     * «Certificado» sólo cuando es verdad las tres veces: firmado con una
     * clave de confianza, entero en la cadena, y con la conformidad del
     * cliente. Un remito firmado pero rechazado es auténtico y está firmado,
     * pero no certifica nada que el cliente haya aceptado, y el papel lo dice.
     */
    private static function certificacion(array $r): array
    {
        $v = Certificacion::verificarRemito($r['id']);
        $codigo = $r['codigo'] !== null ? 'Código de verificación: ' . $r['codigo'] . '.' : null;
        $base = ['huella' => $v['hash'], 'clave' => $v['firmado'] ? $v['clave_id'] : null];

        if (!$v['valido']) {
            return $base + [
                'nivel'  => 'alerta',
                'titulo' => 'Atención: este remito NO verifica',
                'lineas' => array_merge($v['problemas'], ['No lo tome como constancia hasta aclararlo con CAMCA.']),
            ];
        }
        if (!$v['firmado']) {
            return $base + [
                'nivel'  => 'sin_firma',
                'titulo' => 'Sin firma digital',
                'lineas' => array_filter([
                    'Documento encadenado en la plataforma, sin firma criptográfica: no es un certificado.',
                    $codigo,
                ]),
            ];
        }
        if ($r['estado'] === 'anulado') {
            return $base + [
                'nivel'  => 'anulado',
                'titulo' => 'Firmado digitalmente · ANULADO',
                'lineas' => array_filter(['Este remito se anuló. La anulación también está firmada en la cadena.', $codigo]),
            ];
        }
        if ($r['conformidad'] !== 'conforme') {
            return $base + [
                'nivel'  => 'firmado',
                'titulo' => 'Firmado digitalmente',
                'lineas' => array_filter([
                    'Firmado con Ed25519 y encadenado: su contenido no se puede cambiar sin que se note.',
                    $r['conformidad'] === 'rechazado'
                        ? 'No certifica conformidad: el cliente NO estuvo conforme.'
                        : 'No certifica conformidad: el cliente no la dio en el sitio.',
                    $codigo,
                ]),
            ];
        }
        return $base + [
            'nivel'  => 'certificado',
            'titulo' => 'Remito certificado',
            'lineas' => array_filter([
                'Firmado digitalmente con Ed25519 y encadenado: certifica el servicio prestado y la conformidad del cliente.',
                $codigo,
            ]),
        ];
    }

    /**
     * La dirección que lleva el QR. Con el código en la consulta (?c=) y no en
     * la ruta: así funciona aunque el .htaccess de /verificar/ no esté, que es
     * la parte del despliegue que depende de una persona (H1).
     */
    public static function urlVerificacion(array $r): ?string
    {
        $codigo = str_replace('-', '', (string) ($r['codigo'] ?? ''));
        if ($codigo === '') return null;
        return rtrim((string) Config::get('sitio.url_publica', 'https://camcasoluciones.com.ar'), '/') .
            '/verificar/?c=' . $codigo;
    }

    private static function hostPublico(): string
    {
        $u = (string) Config::get('sitio.url_publica', 'https://camcasoluciones.com.ar');
        return (string) (parse_url($u, PHP_URL_HOST) ?? $u) . (parse_url($u, PHP_URL_PORT) ? ':' . parse_url($u, PHP_URL_PORT) : '');
    }

    private static function cantidad(mixed $n): string
    {
        return $n === null ? '—' : number_format((float) $n, 0, ',', '.');
    }

    /** "1 baño", no "1 baños": en un papel que ve el cliente, se nota. */
    private static function unidad(mixed $n, string $unidad): string
    {
        $singular = ['baños' => 'baño', 'unidades' => 'unidad', 'módulos' => 'módulo'];
        return ((int) $n === 1 && isset($singular[$unidad])) ? $singular[$unidad] : $unidad;
    }

    private static function nombreEvidencia(string $tipo): string
    {
        return ['foto' => 'Fotografía', 'firma' => 'Firma del receptor', 'nota' => 'Nota'][$tipo] ?? $tipo;
    }

    private static function cssExtra(): string
    {
        return <<<'CSS'
table.items { width: 100%; border-collapse: collapse; }
table.items th, table.items td { text-align: left; padding: .15cm .2cm .15cm 0; border-bottom: 1px solid #ddd8cd; }
table.items th { font-size: 9.5pt; color: #4a5852; font-weight: 600; }
table.items .num { text-align: right; font-variant-numeric: tabular-nums; }
.conformidad[data-resultado="rechazado"] h2 { color: #8c241c; border-bottom-color: #8c241c; }
.conformidad[data-resultado="rechazado"] table.campos tr:first-child td { color: #8c241c; font-weight: 700; }
.firma-caja { margin: .2cm 0 0 6.5cm; width: 7cm; border-bottom: 1px solid #17201b; }
.firma-caja svg.firma { display: block; width: 100%; height: auto; }
.firma-alerta { color: #8c241c; font-weight: 700; }
.certificacion p { margin: 0 0 .1cm; }
.cert-cuerpo { display: flex; gap: .8cm; align-items: flex-start; justify-content: space-between; }
.qr-caja { flex: 0 0 3.2cm; text-align: center; }
.qr-caja svg.qr { display: block; width: 3.2cm; height: 3.2cm; }
.qr-caja p { font-size: 8pt; color: #4a5852; margin: .1cm 0 0; line-height: 1.3; }
.certificacion[data-nivel="certificado"] h2 { color: #1f5c3a; border-bottom-color: #1f5c3a; }
.certificacion[data-nivel="alerta"] h2, .certificacion[data-nivel="alerta"] p { color: #8c241c; font-weight: 700; }
CSS;
    }
}
