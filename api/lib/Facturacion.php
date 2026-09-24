<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Propuesta de facturación. Obj. 3, paso F3.3.
 *
 * Arma lo que CAMCA le propone facturarle a un cliente por un período, con
 * una sola fuente: el trabajo CERTIFICADO. Nada que el cliente no haya
 * conformado y que no esté firmado en la cadena llega a una línea.
 *
 * Las tres propiedades que el plan pide, y dónde vive cada una:
 *
 *  - NADA SE FACTURA DOS VECES: el UNIQUE de factura_linea_remito.remito_vivo
 *    (en la base, no sólo acá) y el paso del trabajo de «certificado» a
 *    «facturable» con el estado de origen en el WHERE (Trabajo::mover).
 *  - Σ LÍNEAS = TOTAL: el neto se suma en PHP con enteros y la base rechaza
 *    una cabecera cuyo total no sea neto + IVA (CHECK en 0022).
 *  - TODA LÍNEA TIENE REMITO CERTIFICADO: se inserta con su remito en la
 *    misma transacción; verificar() lo recorre sobre toda la base.
 */
final class Facturacion
{
    /** El servicio de una visita de ruta. Supuesto S12: hoy es el único que ejecuta la app de campo. */
    public const SERVICIO_VISITA = 'limpieza';

    /** Alícuota de IVA en centésimos de punto (2100 = 21 %). Supuesto S12. */
    public static function alicuota(): int
    {
        return (int) Config::get('facturacion.iva_pb', 2100);
    }

    /** IVA de un neto, redondeado al centavo una sola vez, mitad hacia arriba. */
    public static function iva(int $netoCent, int $pb): int
    {
        return intdiv($netoCent * $pb + 5000, 10000);
    }

    /**
     * El cliente y los que se fusionaron en él (F3.1), en cadena.
     *
     * Un remito emitido antes de una fusión conserva el cliente de entonces:
     * los documentos no se reescriben. Sin esto, la propuesta del cliente que
     * quedó no vería el trabajo del que se fusionó, y ese trabajo no se
     * facturaría nunca a nadie.
     *
     * @return int[]
     */
    public static function clientesDe(int $clienteId): array
    {
        $ids = [$clienteId];
        for ($i = 0; $i < count($ids) && $i < 50; $i++) {
            foreach (Db::todas('SELECT origen_id FROM cliente_fusion WHERE destino_id = :d AND deshecha_utc IS NULL',
                               [':d' => $ids[$i]]) as $f) {
                if (!in_array((int) $f['origen_id'], $ids, true)) $ids[] = (int) $f['origen_id'];
            }
        }
        return $ids;
    }

    /**
     * Los remitos certificados de un cliente en un período que todavía no
     * están en ninguna propuesta viva.
     */
    public static function pendientes(int $clienteId, string $desde, string $hasta, bool $bloquear = false): array
    {
        $ids = self::clientesDe($clienteId);
        $marcas = [];
        $par = [':d' => $desde, ':h' => $hasta];
        foreach ($ids as $k => $id) { $marcas[] = ':c' . $k; $par[':c' . $k] = $id; }
        return Db::todas(
            "SELECT r.id, r.numero, r.fecha, r.parada_id, r.datos_json, p.flujo
               FROM remito r
               JOIN parada_ejecucion p ON p.id = r.parada_id
              WHERE r.cliente_id IN (" . implode(',', $marcas) . ")
                AND r.fecha BETWEEN :d AND :h
                AND r.estado = 'emitido' AND r.unico_activo IS NOT NULL
                AND r.conformidad = 'conforme'
                AND p.flujo = 'certificado'
                AND EXISTS (SELECT 1 FROM remito_eslabon e
                             WHERE e.remito_id = r.id AND e.tipo = 'emision' AND e.firma IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM factura_linea_remito f WHERE f.remito_vivo = r.id)
              ORDER BY r.fecha, r.numero_seq" . ($bloquear ? ' FOR UPDATE' : ''),
            $par
        );
    }

    /**
     * Lo que saldría, sin guardar nada. Es lo que la oficina mira antes de
     * armar la propuesta, y lo mismo que crear() vuelve a calcular adentro de
     * su transacción.
     */
    public static function previsualizar(int $clienteId, string $desde, string $hasta, bool $bloquear = false): array
    {
        $desde = Tarifa::fechaValida($desde, 'desde');
        $hasta = Tarifa::fechaValida($hasta, 'hasta');
        if ($hasta < $desde) throw new ErrorValidacion(['hasta' => 'El período termina antes de empezar.']);

        $cliente = Db::una('SELECT * FROM cliente WHERE id = :c', [':c' => $clienteId]);
        if ($cliente === null) throw new ErrorNoEncontrado('No existe ese cliente.');
        if ((int) $cliente['activo'] !== 1) {
            throw new ErrorConflicto("«{$cliente['nombre']}» se fusionó en otro cliente: se factura al que quedó.", 'CLIENTE_FUSIONADO');
        }

        $servicio = Db::una('SELECT nombre, unidad, unidad_plural FROM servicio WHERE codigo = :s', [':s' => self::SERVICIO_VISITA]);
        $lineas = [];
        $omitidos = [];
        $neto = 0;
        foreach (self::pendientes($clienteId, $desde, $hasta, $bloquear) as $r) {
            $d = json_decode((string) $r['datos_json'], true) ?: [];
            $cant = $d['items'][0]['cantidad'] ?? null;
            if (!is_int($cant) && !(is_string($cant) && ctype_digit($cant))) {
                $omitidos[] = ['remito' => $r['numero'], 'fecha' => $r['fecha'], 'motivo' => 'El remito no dice cuántos baños se atendieron.'];
                continue;
            }
            $cant = (int) $cant;
            try {
                // Con la tarifa del cliente que se factura, no la del que se
                // fusionó en él: el precio es el del contrato vigente.
                $c = Tarifa::cotizar($clienteId, self::SERVICIO_VISITA, (string) $r['fecha'], $cant);
            } catch (ErrorConflicto $e) {
                $omitidos[] = ['remito' => $r['numero'], 'fecha' => $r['fecha'],
                               'motivo' => 'Sin tarifa de ' . mb_strtolower((string) $servicio['nombre']) . ' vigente ese día.'];
                continue;
            }
            $sitio = (string) ($d['sitio']['nombre'] ?? '');
            $lineas[] = [
                'remito_id'       => (int) $r['id'],
                'remito'          => $r['numero'],
                'parada_id'       => (int) $r['parada_id'],
                'fecha'           => $r['fecha'],
                'servicio_codigo' => self::SERVICIO_VISITA,
                'descripcion'     => mb_substr($servicio['nombre'] . ($sitio !== '' ? ' · ' . $sitio : '') . ' · ' . $r['numero'], 0, 255),
                'cantidad'        => $cant,
                'tarifa_id'       => $c['tarifa_id'],
                'unitario_cent'   => $c['unitario_cent'],
                'minimo_aplicado' => $c['minimo_aplicado'],
                'neto_cent'       => $c['subtotal_cent'],
            ];
            $neto += $c['subtotal_cent'];
        }

        $pb = self::alicuota();
        $iva = self::iva($neto, $pb);
        return [
            'cliente'          => self::datosCliente($cliente),
            'falta'            => Cliente::falta($cliente),
            'desde'            => $desde,
            'hasta'            => $hasta,
            'tipo_comprobante' => $cliente['condicion_iva'] === 'responsable_inscripto' ? 'A' : 'B',
            'iva_alicuota_pb'  => $pb,
            'lineas'           => $lineas,
            'omitidos'         => $omitidos,
            'neto_cent'        => $neto,
            'iva_cent'         => $iva,
            'total_cent'       => $neto + $iva,
        ];
    }

    /**
     * Arma la propuesta y pasa sus trabajos a «facturable». Todo o nada.
     */
    public static function crear(int $clienteId, string $desde, string $hasta, ?int $usuarioId): array
    {
        try {
            return Db::txReintentable(static function () use ($clienteId, $desde, $hasta, $usuarioId): array {
                $p = self::previsualizar($clienteId, $desde, $hasta, true);
                if ($p['falta'] !== []) {
                    throw new ErrorConflicto('Al cliente le falta ' . implode(', ', $p['falta']) .
                        ': sin eso no se le puede facturar. Se completa en Clientes.', 'CLIENTE_INCOMPLETO');
                }
                if ($p['lineas'] === []) {
                    throw new ErrorConflicto('No hay trabajo certificado sin facturar de ese cliente en ese período' .
                        ($p['omitidos'] !== [] ? ' con precio: ' . count($p['omitidos']) . ' quedaron afuera (ver el detalle).' : '.'),
                        'NADA_PARA_FACTURAR');
                }

                Db::q('INSERT INTO factura_propuesta (cliente_id, desde, hasta, estado, tipo_comprobante, cliente_json,
                                                      iva_alicuota_pb, neto_cent, iva_cent, total_cent, omitidos_json,
                                                      creado_utc, creado_por)
                       VALUES (:c, :d, :h, \'borrador\', :t, :cj, :pb, :n, :i, :tot, :o, UTC_TIMESTAMP(), :u)',
                      [':c' => $clienteId, ':d' => $p['desde'], ':h' => $p['hasta'], ':t' => $p['tipo_comprobante'],
                       ':cj' => json_encode($p['cliente'], JSON_UNESCAPED_UNICODE), ':pb' => $p['iva_alicuota_pb'],
                       ':n' => $p['neto_cent'], ':i' => $p['iva_cent'], ':tot' => $p['total_cent'],
                       ':o' => json_encode($p['omitidos'], JSON_UNESCAPED_UNICODE), ':u' => $usuarioId]);
                $id = Db::insertarId();

                foreach ($p['lineas'] as $k => $l) {
                    Db::q('INSERT INTO factura_linea (propuesta_id, orden, servicio_codigo, descripcion, fecha, cantidad,
                                                      tarifa_id, unitario_cent, minimo_aplicado, neto_cent)
                           VALUES (:p, :o, :s, :d, :f, :c, :t, :u, :m, :n)',
                          [':p' => $id, ':o' => $k + 1, ':s' => $l['servicio_codigo'], ':d' => $l['descripcion'],
                           ':f' => $l['fecha'], ':c' => $l['cantidad'], ':t' => $l['tarifa_id'],
                           ':u' => $l['unitario_cent'], ':m' => $l['minimo_aplicado'] ? 1 : 0, ':n' => $l['neto_cent']]);
                    $lineaId = Db::insertarId();
                    Db::q('INSERT INTO factura_linea_remito (linea_id, remito_id, propuesta_id, remito_vivo)
                           VALUES (:l, :r, :p, :r2)',
                          [':l' => $lineaId, ':r' => $l['remito_id'], ':p' => $id, ':r2' => $l['remito_id']]);
                    Trabajo::mover($l['parada_id'], 'facturable', [
                        'usuario_id' => $usuarioId,
                        'contexto'   => ['propuesta' => $id, 'remito' => $l['remito']],
                    ]);
                }

                Hash::auditar('factura_propuesta', $id, 'creada', [
                    'cliente' => $clienteId, 'desde' => $p['desde'], 'hasta' => $p['hasta'],
                    'lineas' => count($p['lineas']), 'neto' => $p['neto_cent'], 'iva' => $p['iva_cent'],
                    'total' => $p['total_cent'], 'remitos' => array_column($p['lineas'], 'remito'),
                ]);
                return self::leer($id);
            });
        } catch (PDOException $e) {
            // El UNIQUE de remito_vivo: otra propuesta se llevó uno de estos
            // remitos entre la lectura y la escritura. No se factura dos veces.
            if (Db::esDuplicado($e)) {
                throw new ErrorConflicto('Otra propuesta tomó alguno de estos remitos mientras se armaba esta. Volvé a mirar.', 'YA_FACTURADO');
            }
            throw $e;
        }
    }

    /**
     * Descarta un borrador: sus remitos vuelven a quedar disponibles y sus
     * trabajos vuelven a «certificado», con el motivo en el historial. Una
     * propuesta aprobada no se descarta: se corrige con nota de crédito (F3.4).
     */
    public static function descartar(int $id, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué se descarta.']);
        return self::modificar($id, 'descartar', $usuarioId, static function () use ($id, $motivo, $usuarioId): array {
            $p = Db::una('SELECT id, estado, numero FROM factura_propuesta WHERE id = :id FOR UPDATE', [':id' => $id]);
            if ($p === null) throw new ErrorNoEncontrado('No existe esa propuesta.');
            self::soloBorrador($p);
            Db::q("UPDATE factura_propuesta SET estado = 'descartada', descartada_utc = UTC_TIMESTAMP(),
                          descartada_por = :u, descartada_motivo = :m WHERE id = :id",
                  [':u' => $usuarioId, ':m' => mb_substr($motivo, 0, 255), ':id' => $id]);
            Db::q('UPDATE factura_linea_remito SET remito_vivo = NULL WHERE propuesta_id = :id', [':id' => $id]);
            foreach (Db::todas('SELECT r.parada_id FROM factura_linea_remito f JOIN remito r ON r.id = f.remito_id
                                 WHERE f.propuesta_id = :id', [':id' => $id]) as $x) {
                Trabajo::mover((int) $x['parada_id'], 'certificado', [
                    'usuario_id' => $usuarioId, 'motivo' => 'Propuesta de facturación descartada: ' . $motivo,
                    'contexto' => ['propuesta' => $id],
                ]);
            }
            Hash::auditar('factura_propuesta', $id, 'descartada', ['motivo' => $motivo]);
            return self::leer($id);
        });
    }


    // ------------------------------------------------------------------
    // F3.4: aprobación, cierre y ajustes
    // ------------------------------------------------------------------

    /**
     * Una aprobada no se toca; una descartada, tampoco. Todo lo que modifica
     * una propuesta pasa por acá antes de tocar nada.
     */
    private static function soloBorrador(array $p): void
    {
        if ($p['estado'] === 'aprobada') {
            throw new ErrorConflicto("La propuesta {$p['numero']} está aprobada y no se modifica. " .
                'Lo que haya que corregir va por nota de crédito o de débito.', 'PROPUESTA_APROBADA');
        }
        if ($p['estado'] === 'descartada') {
            throw new ErrorConflicto('Esa propuesta está descartada.', 'PROPUESTA_DESCARTADA');
        }
    }

    /**
     * Corre una modificación en su transacción. Si choca con una propuesta
     * aprobada, el intento queda en la auditoría: el rechazo se ve, no sólo
     * se evita. Se audita DESPUÉS del rollback, si no el eslabón se perdería
     * con la transacción que se deshizo.
     */
    private static function modificar(int $id, string $accion, ?int $usuarioId, callable $fn): array
    {
        try {
            return Db::txReintentable($fn);
        } catch (ErrorConflicto $e) {
            if ($e->codigo === 'PROPUESTA_APROBADA') {
                Hash::auditar('factura_propuesta', $id, 'modificacion_rechazada', ['accion' => $accion, 'usuario' => $usuarioId]);
                Log::aviso('factura_modificacion_rechazada', ['propuesta' => $id, 'accion' => $accion, 'usuario' => $usuarioId]);
            }
            throw $e;
        }
    }

    /**
     * Lo que se congela al aprobar, en una forma que no depende de nada vivo:
     * ni del nombre actual del cliente ni de la tarifa de hoy.
     */
    public static function contenido(int $id): array
    {
        $p = Db::una('SELECT * FROM factura_propuesta WHERE id = :id', [':id' => $id]);
        $lineas = Db::todas(
            'SELECT l.*, GROUP_CONCAT(r.numero ORDER BY r.numero_seq SEPARATOR \',\') AS remitos
               FROM factura_linea l
               LEFT JOIN factura_linea_remito f ON f.linea_id = l.id
               LEFT JOIN remito r ON r.id = f.remito_id
              WHERE l.propuesta_id = :id GROUP BY l.id ORDER BY l.orden', [':id' => $id]);
        return [
            'formato' => 'CAMCA-PF-1',
            'numero' => $p['numero'], 'cliente_id' => (int) $p['cliente_id'],
            'cliente' => json_decode((string) $p['cliente_json'], true),
            'desde' => $p['desde'], 'hasta' => $p['hasta'], 'tipo' => $p['tipo_comprobante'],
            'iva_pb' => (int) $p['iva_alicuota_pb'], 'neto' => (int) $p['neto_cent'],
            'iva' => (int) $p['iva_cent'], 'total' => (int) $p['total_cent'],
            'lineas' => array_map(static fn($l) => [
                'orden' => (int) $l['orden'], 'servicio' => $l['servicio_codigo'], 'descripcion' => $l['descripcion'],
                'fecha' => $l['fecha'], 'cantidad' => (int) $l['cantidad'], 'tarifa' => (int) $l['tarifa_id'],
                'unitario' => (int) $l['unitario_cent'], 'minimo' => (bool) $l['minimo_aplicado'],
                'neto' => (int) $l['neto_cent'],
                'remitos' => $l['remitos'] === null ? [] : explode(',', (string) $l['remitos']),
            ], $lineas),
        ];
    }

    /**
     * Aprueba un borrador: número propio, huella del contenido y los trabajos
     * a «facturado», que es un estado final.
     */
    public static function aprobar(int $id, ?int $usuarioId): array
    {
        $cab = Db::una('SELECT cliente_id FROM factura_propuesta WHERE id = :id', [':id' => $id]);
        if ($cab === null) throw new ErrorNoEncontrado('No existe esa propuesta.');
        $claves = [];
        foreach (Db::todas('SELECT DISTINCT servicio_codigo FROM factura_linea WHERE propuesta_id = :id', [':id' => $id]) as $s) {
            $claves[] = [(int) $cab['cliente_id'], (string) $s['servicio_codigo']];
            $claves[] = [null, (string) $s['servicio_codigo']];
        }
        // Las tarifas de este cliente (y la general) quietas mientras se
        // aprueba: la re-cotización de abajo no puede quedar vieja entre la
        // lectura y el commit.
        return Tarifa::bloqueadas($claves, static fn(): array => self::aprobarBloqueada($id, $usuarioId));
    }

    private static function aprobarBloqueada(int $id, ?int $usuarioId): array
    {
        return self::modificar($id, 'aprobar', $usuarioId, static function () use ($id, $usuarioId): array {
            $p = Db::una('SELECT * FROM factura_propuesta WHERE id = :id FOR UPDATE', [':id' => $id]);
            if ($p === null) throw new ErrorNoEncontrado('No existe esa propuesta.');
            self::soloBorrador($p);

            // Lo que se armó tiene que seguir valiendo para ESTE cliente, con
            // ESTOS datos fiscales y a ESTE precio (revisión de la Fase 3). Una
            // fusión deshecha después de armar el borrador le facturaba a uno
            // el trabajo del otro; una tarifa cerrada o cargada con fecha para
            // atrás dejaba la factura diciendo un precio y la tabla otro; y un
            // cambio de condición frente al IVA salía con el comprobante viejo.
            $cli = Db::una('SELECT * FROM cliente WHERE id = :c', [':c' => $p['cliente_id']]);
            $desactualizada = static fn(string $por, string $codigo) => new ErrorConflicto(
                $por . ' Descartá la propuesta y armala de nuevo: sale con lo de hoy.', $codigo);
            if ((int) $cli['activo'] !== 1) {
                throw $desactualizada("«{$cli['nombre']}» se fusionó en otro cliente después de armar esta propuesta: se le factura al que quedó.", 'PROPUESTA_DESACTUALIZADA');
            }
            $tipo = $cli['condicion_iva'] === 'responsable_inscripto' ? 'A' : 'B';
            if (self::datosCliente($cli) !== json_decode((string) $p['cliente_json'], true) || $tipo !== $p['tipo_comprobante']) {
                throw $desactualizada('Los datos fiscales del cliente cambiaron desde que se armó.', 'CLIENTE_CAMBIO');
            }
            foreach (Db::todas('SELECT orden, fecha, cantidad, servicio_codigo, tarifa_id, neto_cent FROM factura_linea WHERE propuesta_id = :id ORDER BY orden',
                               [':id' => $id]) as $l) {
                try {
                    $c = Tarifa::cotizar((int) $p['cliente_id'], (string) $l['servicio_codigo'], (string) $l['fecha'], (int) $l['cantidad']);
                } catch (ErrorConflicto) {
                    $c = null;
                }
                if ($c === null || (int) $c['tarifa_id'] !== (int) $l['tarifa_id'] || (int) $c['subtotal_cent'] !== (int) $l['neto_cent']) {
                    throw $desactualizada("El precio del {$l['fecha']} (línea {$l['orden']}) cambió desde que se armó.", 'TARIFA_CAMBIO');
                }
            }

            // Lo que se aprueba tiene que seguir siendo verdad AHORA: si un
            // remito se anuló o un trabajo se movió desde que se armó el
            // borrador, no se aprueba una factura sobre eso.
            $suyos = self::clientesDe((int) $p['cliente_id']);
            foreach (Db::todas("SELECT r.numero, r.estado, r.unico_activo, r.conformidad, r.cliente_id, pe.flujo, pe.id AS parada_id
                                  FROM factura_linea_remito f JOIN remito r ON r.id = f.remito_id
                                  JOIN parada_ejecucion pe ON pe.id = r.parada_id
                                 WHERE f.propuesta_id = :id FOR UPDATE", [':id' => $id]) as $r) {
                if ($r['estado'] !== 'emitido' || $r['unico_activo'] === null || $r['conformidad'] !== 'conforme' || $r['flujo'] !== 'facturable') {
                    throw new ErrorConflicto("El remito {$r['numero']} ya no está certificado y listo para facturar. " .
                        'Quitá esa línea o descartá la propuesta.', 'REMITO_NO_CERTIFICADO');
                }
                if (!in_array((int) $r['cliente_id'], $suyos, true)) {
                    throw new ErrorConflicto("El remito {$r['numero']} ya no es de este cliente: se deshizo la fusión que lo traía. " .
                        'Quitá esa línea o descartá la propuesta.', 'REMITO_DE_OTRO_CLIENTE');
                }
            }

            $n = Numerador::siguiente('PF', 'A');
            $numero = Numerador::formato('PF', 'A', $n);
            Db::q("UPDATE factura_propuesta SET estado = 'aprobada', serie = 'A', numero_seq = :n, numero = :num,
                          aprobada_utc = UTC_TIMESTAMP(), aprobada_por = :u WHERE id = :id",
                  [':n' => $n, ':num' => $numero, ':u' => $usuarioId, ':id' => $id]);
            $sha = hash('sha256', Hash::canonical(self::contenido($id)));
            Db::q('UPDATE factura_propuesta SET contenido_sha = :s WHERE id = :id', [':s' => $sha, ':id' => $id]);

            foreach (Db::todas('SELECT DISTINCT r.parada_id FROM factura_linea_remito f JOIN remito r ON r.id = f.remito_id
                                 WHERE f.propuesta_id = :id', [':id' => $id]) as $x) {
                Trabajo::mover((int) $x['parada_id'], 'facturado', [
                    'usuario_id' => $usuarioId, 'contexto' => ['propuesta' => $id, 'numero' => $numero],
                ]);
            }
            Hash::auditar('factura_propuesta', $id, 'aprobada', ['numero' => $numero, 'total' => (int) $p['total_cent'], 'sha' => $sha]);
            return self::leer($id);
        });
    }

    /**
     * Saca una línea de un borrador: su remito vuelve a quedar por facturar
     * y su trabajo a «certificado», con el motivo en el historial.
     */
    public static function quitarLinea(int $id, int $orden, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué se quita.']);
        return self::modificar($id, 'quitar_linea', $usuarioId, static function () use ($id, $orden, $motivo, $usuarioId): array {
            $p = Db::una('SELECT * FROM factura_propuesta WHERE id = :id FOR UPDATE', [':id' => $id]);
            if ($p === null) throw new ErrorNoEncontrado('No existe esa propuesta.');
            self::soloBorrador($p);
            $l = Db::una('SELECT * FROM factura_linea WHERE propuesta_id = :p AND orden = :o', [':p' => $id, ':o' => $orden]);
            if ($l === null) throw new ErrorNoEncontrado('Esa línea no está en la propuesta.');
            if ((int) Db::col('SELECT COUNT(*) FROM factura_linea WHERE propuesta_id = :p', [':p' => $id]) === 1) {
                throw new ErrorConflicto('Es la única línea: quitarla dejaría una propuesta vacía. Descartala.', 'PROPUESTA_VACIA');
            }
            $remitos = Db::todas('SELECT r.id, r.numero, r.fecha, r.parada_id FROM factura_linea_remito f JOIN remito r ON r.id = f.remito_id
                                   WHERE f.linea_id = :l', [':l' => $l['id']]);
            Db::q('DELETE FROM factura_linea_remito WHERE linea_id = :l', [':l' => $l['id']]);
            Db::q('DELETE FROM factura_linea WHERE id = :l', [':l' => $l['id']]);
            foreach ($remitos as $r) {
                Trabajo::mover((int) $r['parada_id'], 'certificado', [
                    'usuario_id' => $usuarioId, 'motivo' => 'Quitado de la propuesta: ' . $motivo, 'contexto' => ['propuesta' => $id],
                ]);
            }
            $neto = (int) Db::col('SELECT COALESCE(SUM(neto_cent), 0) FROM factura_linea WHERE propuesta_id = :p', [':p' => $id]);
            $iva = self::iva($neto, (int) $p['iva_alicuota_pb']);
            $omitidos = json_decode((string) $p['omitidos_json'], true) ?: [];
            foreach ($remitos as $r) $omitidos[] = ['remito' => $r['numero'], 'fecha' => $r['fecha'], 'motivo' => 'Quitado a mano: ' . $motivo];
            Db::q('UPDATE factura_propuesta SET neto_cent = :n, iva_cent = :i, total_cent = :t, omitidos_json = :o WHERE id = :id',
                  [':n' => $neto, ':i' => $iva, ':t' => $neto + $iva, ':o' => json_encode($omitidos, JSON_UNESCAPED_UNICODE), ':id' => $id]);
            Hash::auditar('factura_propuesta', $id, 'linea_quitada', [
                'orden' => $orden, 'remitos' => array_column($remitos, 'numero'), 'neto_linea' => (int) $l['neto_cent'], 'motivo' => $motivo,
            ]);
            return self::leer($id);
        });
    }

    /** Lo que queda por cobrar de una propuesta: su total, menos las NC, más las ND. */
    public static function saldo(int $id): int
    {
        return (int) Db::col(
            "SELECT p.total_cent
                    + COALESCE((SELECT SUM(total_cent) FROM factura_ajuste WHERE propuesta_id = p.id AND tipo = 'debito'), 0)
                    - COALESCE((SELECT SUM(total_cent) FROM factura_ajuste WHERE propuesta_id = p.id AND tipo = 'credito'), 0)
               FROM factura_propuesta p WHERE p.id = :id", [':id' => $id]);
    }

    private static function contenidoAjuste(array $a, string $propuestaNumero, array $lineas): array
    {
        return [
            'formato' => 'CAMCA-AJ-1', 'numero' => $a['numero'], 'tipo' => $a['tipo'], 'propuesta' => $propuestaNumero,
            'motivo' => $a['motivo'], 'iva_pb' => (int) $a['iva_alicuota_pb'], 'neto' => (int) $a['neto_cent'],
            'iva' => (int) $a['iva_cent'], 'total' => (int) $a['total_cent'],
            'lineas' => array_map(static fn($l) => [
                'orden' => (int) $l['orden'], 'descripcion' => $l['descripcion'], 'neto' => (int) $l['neto_cent'],
                'remito' => $l['remito'] ?? null,
            ], $lineas),
        ];
    }

    /**
     * Nota de crédito o de débito sobre una propuesta aprobada. Es la única
     * forma de corregirla. Una NC no puede llevar el saldo por debajo de cero.
     *
     * @param array $lineas [{descripcion, neto_cent, remito?}] con remito = número REM-A-…
     */
    public static function ajustar(int $propuestaId, string $tipo, string $motivo, array $lineas, ?int $usuarioId): array
    {
        if (!in_array($tipo, ['credito', 'debito'], true)) throw new ErrorValidacion(['tipo' => 'Es crédito o débito.']);
        $motivo = trim($motivo);
        if ($motivo === '') throw new ErrorValidacion(['motivo' => 'Una nota sin motivo no se puede explicar después.']);
        if ($lineas === [] || count($lineas) > 50) throw new ErrorValidacion(['lineas' => 'Entre 1 y 50 líneas.']);
        $limpias = [];
        foreach (array_values($lineas) as $k => $l) {
            $desc = trim((string) ($l['descripcion'] ?? ''));
            $neto = $l['neto_cent'] ?? null;
            if ($desc === '' || mb_strlen($desc) > 255) throw new ErrorValidacion(["lineas.$k.descripcion" => 'Cada línea necesita su descripción.']);
            if (!is_int($neto) || $neto <= 0 || $neto > 100_000_000_000) {
                throw new ErrorValidacion(["lineas.$k.neto_cent" => 'El importe es en centavos, entero y mayor que cero.']);
            }
            $rem = trim((string) ($l['remito'] ?? ''));
            $limpias[] = ['orden' => $k + 1, 'descripcion' => $desc, 'neto_cent' => $neto, 'remito' => $rem === '' ? null : $rem];
        }

        return Db::txReintentable(static function () use ($propuestaId, $tipo, $motivo, $limpias, $usuarioId): array {
            $p = Db::una('SELECT * FROM factura_propuesta WHERE id = :id FOR UPDATE', [':id' => $propuestaId]);
            if ($p === null) throw new ErrorNoEncontrado('No existe esa propuesta.');
            if ($p['estado'] !== 'aprobada') {
                throw new ErrorConflicto('Las notas de crédito y débito van sobre una propuesta aprobada. Un borrador se corrige directamente.',
                    'PROPUESTA_NO_APROBADA');
            }
            $propios = [];
            foreach (Db::todas('SELECT r.id, r.numero FROM factura_linea_remito f JOIN remito r ON r.id = f.remito_id
                                 WHERE f.propuesta_id = :p', [':p' => $propuestaId]) as $r) $propios[$r['numero']] = (int) $r['id'];
            foreach ($limpias as $k => $l) {
                if ($l['remito'] !== null && $l['remito'] !== '' && !isset($propios[$l['remito']])) {
                    throw new ErrorValidacion(["lineas.$k.remito" => "El remito {$l['remito']} no está en la propuesta {$p['numero']}."]);
                }
            }
            $neto = array_sum(array_column($limpias, 'neto_cent'));
            $pb = (int) $p['iva_alicuota_pb'];
            $iva = self::iva($neto, $pb);
            if ($tipo === 'credito') {
                $saldo = self::saldo($propuestaId);
                if ($neto + $iva > $saldo) {
                    throw new ErrorConflicto('La nota de crédito (' . Tarifa::pesos($neto + $iva) . ') supera lo que queda de la propuesta (' .
                        Tarifa::pesos($saldo) . ').', 'SALDO_INSUFICIENTE');
                }
            }
            $tn = $tipo === 'credito' ? 'NC' : 'ND';
            $n = Numerador::siguiente($tn, 'A');
            $a = ['numero' => Numerador::formato($tn, 'A', $n), 'tipo' => $tipo, 'motivo' => mb_substr($motivo, 0, 255),
                  'iva_alicuota_pb' => $pb, 'neto_cent' => $neto, 'iva_cent' => $iva, 'total_cent' => $neto + $iva];
            $sha = hash('sha256', Hash::canonical(self::contenidoAjuste($a, (string) $p['numero'], $limpias)));
            Db::q('INSERT INTO factura_ajuste (propuesta_id, tipo, serie, numero_seq, numero, motivo, iva_alicuota_pb,
                                               neto_cent, iva_cent, total_cent, contenido_sha, creado_utc, creado_por)
                   VALUES (:p, :t, \'A\', :n, :num, :m, :pb, :ne, :iv, :to, :s, UTC_TIMESTAMP(), :u)',
                  [':p' => $propuestaId, ':t' => $tipo, ':n' => $n, ':num' => $a['numero'], ':m' => $a['motivo'], ':pb' => $pb,
                   ':ne' => $neto, ':iv' => $iva, ':to' => $neto + $iva, ':s' => $sha, ':u' => $usuarioId]);
            $ajusteId = Db::insertarId();
            foreach ($limpias as $l) {
                Db::q('INSERT INTO factura_ajuste_linea (ajuste_id, orden, descripcion, neto_cent, remito_id) VALUES (:a, :o, :d, :n, :r)',
                      [':a' => $ajusteId, ':o' => $l['orden'], ':d' => $l['descripcion'], ':n' => $l['neto_cent'],
                       ':r' => $l['remito'] ? $propios[$l['remito']] : null]);
            }
            Hash::auditar('factura_ajuste', $ajusteId, 'emitida', [
                'numero' => $a['numero'], 'propuesta' => $p['numero'], 'total' => $neto + $iva, 'motivo' => $a['motivo'], 'sha' => $sha,
            ]);
            return self::leer($propuestaId);
        });
    }

    private static function ajustes(int $propuestaId): array
    {
        $salida = [];
        foreach (Db::todas('SELECT * FROM factura_ajuste WHERE propuesta_id = :p ORDER BY id', [':p' => $propuestaId]) as $a) {
            $lineas = Db::todas('SELECT l.orden, l.descripcion, l.neto_cent, r.numero AS remito FROM factura_ajuste_linea l
                                   LEFT JOIN remito r ON r.id = l.remito_id WHERE l.ajuste_id = :a ORDER BY l.orden', [':a' => $a['id']]);
            $salida[] = $a + ['lineas' => $lineas];
        }
        return $salida;
    }

    public static function leer(int $id): array
    {
        $p = Db::una('SELECT fp.*, c.nombre AS cliente FROM factura_propuesta fp JOIN cliente c ON c.id = fp.cliente_id
                       WHERE fp.id = :id', [':id' => $id]);
        if ($p === null) throw new ErrorNoEncontrado('No existe esa propuesta.');
        $lineas = Db::todas(
            'SELECT l.*, GROUP_CONCAT(r.numero ORDER BY r.numero_seq SEPARATOR \',\') AS remitos
               FROM factura_linea l
               JOIN factura_linea_remito f ON f.linea_id = l.id
               JOIN remito r ON r.id = f.remito_id
              WHERE l.propuesta_id = :id
              GROUP BY l.id ORDER BY l.orden',
            [':id' => $id]
        );
        return self::publica($p) + [
            'lineas' => array_map(static fn($l) => [
                'orden'           => (int) $l['orden'],
                'descripcion'     => $l['descripcion'],
                'fecha'           => $l['fecha'],
                'cantidad'        => (int) $l['cantidad'],
                'unitario_cent'   => (int) $l['unitario_cent'],
                'minimo_aplicado' => (bool) $l['minimo_aplicado'],
                'neto_cent'       => (int) $l['neto_cent'],
                'remitos'         => $l['remitos'] === null ? [] : explode(',', (string) $l['remitos']),
            ], $lineas),
            'omitidos' => json_decode((string) $p['omitidos_json'], true) ?: [],
            'cliente_datos' => json_decode((string) $p['cliente_json'], true) ?: [],
            'contenido_sha' => $p['contenido_sha'],
            'ajustes' => array_map(static fn($a) => [
                'numero' => $a['numero'], 'tipo' => $a['tipo'], 'motivo' => $a['motivo'],
                'neto_cent' => (int) $a['neto_cent'], 'iva_cent' => (int) $a['iva_cent'], 'total_cent' => (int) $a['total_cent'],
                'creado_utc' => $a['creado_utc'],
                'lineas' => array_map(static fn($l) => ['descripcion' => $l['descripcion'], 'neto_cent' => (int) $l['neto_cent'],
                                                       'remito' => $l['remito']], $a['lineas']),
            ], self::ajustes($id)),
            'saldo_cent' => self::saldo($id),
        ];
    }

    public static function listar(?int $clienteId = null): array
    {
        $sql = 'SELECT fp.*, c.nombre AS cliente,
                       (SELECT COUNT(*) FROM factura_linea l WHERE l.propuesta_id = fp.id) AS n_lineas
                  FROM factura_propuesta fp JOIN cliente c ON c.id = fp.cliente_id';
        $par = [];
        if ($clienteId !== null) { $sql .= ' WHERE fp.cliente_id = :c'; $par[':c'] = $clienteId; }
        $sql .= ' ORDER BY fp.id DESC LIMIT 200';
        return array_map(static fn($p) => self::publica($p) + ['lineas_n' => (int) $p['n_lineas']], Db::todas($sql, $par));
    }

    /**
     * Cuánto trabajo certificado espera propuesta, por cliente. Es la
     * pregunta con la que se abre la pantalla: a quién hay que facturarle.
     */
    public static function porFacturar(): array
    {
        $filas = Db::todas(
            "SELECT r.cliente_id, COUNT(*) AS remitos, MIN(r.fecha) AS desde, MAX(r.fecha) AS hasta
               FROM remito r JOIN parada_ejecucion p ON p.id = r.parada_id
              WHERE r.estado = 'emitido' AND r.unico_activo IS NOT NULL AND p.flujo = 'certificado'
                AND r.cliente_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM factura_linea_remito f WHERE f.remito_vivo = r.id)
              GROUP BY r.cliente_id"
        );
        // Lo de un cliente fusionado se cuenta en el que quedó.
        $destino = [];
        foreach (Db::todas('SELECT origen_id, destino_id FROM cliente_fusion WHERE deshecha_utc IS NULL ORDER BY id') as $f) {
            $destino[(int) $f['origen_id']] = (int) $f['destino_id'];
        }
        $acum = [];
        foreach ($filas as $f) {
            $c = (int) $f['cliente_id'];
            for ($i = 0; isset($destino[$c]) && $i < 50; $i++) $c = $destino[$c];
            $a = $acum[$c] ?? ['cliente_id' => $c, 'remitos' => 0, 'desde' => $f['desde'], 'hasta' => $f['hasta']];
            $a['remitos'] += (int) $f['remitos'];
            $a['desde'] = min($a['desde'], $f['desde']);
            $a['hasta'] = max($a['hasta'], $f['hasta']);
            $acum[$c] = $a;
        }
        $nombres = [];
        foreach (Db::todas('SELECT id, nombre FROM cliente') as $c) $nombres[(int) $c['id']] = $c['nombre'];
        $salida = array_values(array_map(static fn($a) => $a + ['cliente' => $nombres[$a['cliente_id']] ?? '?'], $acum));
        usort($salida, static fn($x, $y) => strcmp($x['cliente'], $y['cliente']));
        return $salida;
    }

    /**
     * Las tres propiedades, sobre TODA la base. Devuelve la lista de
     * problemas (vacía = todo en orden). Es lo que corre la prueba después de
     * cada paso, y lo que puede correr cualquiera después.
     */
    public static function verificar(): array
    {
        $prob = [];
        // 1. Nada dos veces.
        foreach (Db::todas("SELECT f.remito_id, COUNT(DISTINCT f.propuesta_id) AS n
                              FROM factura_linea_remito f JOIN factura_propuesta p ON p.id = f.propuesta_id
                             WHERE p.estado <> 'descartada' GROUP BY f.remito_id HAVING n > 1") as $x) {
            $prob[] = ['codigo' => 'DOBLE', 'detalle' => "El remito {$x['remito_id']} está en {$x['n']} propuestas vivas."];
        }
        foreach (Db::todas("SELECT f.remito_id FROM factura_linea_remito f JOIN factura_propuesta p ON p.id = f.propuesta_id
                             WHERE (p.estado = 'descartada') <> (f.remito_vivo IS NULL)") as $x) {
            $prob[] = ['codigo' => 'VIVO_INCONSISTENTE', 'detalle' => "La marca de vivo del remito {$x['remito_id']} no coincide con su propuesta."];
        }
        // 2. Las sumas.
        foreach (Db::todas('SELECT p.id, p.neto_cent, p.iva_cent, p.total_cent, p.iva_alicuota_pb,
                                   COALESCE(SUM(l.neto_cent), 0) AS suma, COUNT(l.id) AS n
                              FROM factura_propuesta p LEFT JOIN factura_linea l ON l.propuesta_id = p.id
                             GROUP BY p.id') as $x) {
            if ((int) $x['suma'] !== (int) $x['neto_cent']) {
                $prob[] = ['codigo' => 'SUMA', 'detalle' => "Propuesta {$x['id']}: las líneas suman {$x['suma']} y la cabecera dice {$x['neto_cent']}."];
            }
            if ((int) $x['iva_cent'] !== self::iva((int) $x['neto_cent'], (int) $x['iva_alicuota_pb'])
                || (int) $x['total_cent'] !== (int) $x['neto_cent'] + (int) $x['iva_cent']) {
                $prob[] = ['codigo' => 'IVA', 'detalle' => "Propuesta {$x['id']}: IVA o total no cierran."];
            }
            if ((int) $x['n'] === 0) $prob[] = ['codigo' => 'VACIA', 'detalle' => "Propuesta {$x['id']} sin líneas."];
        }
        // 3. Toda línea, con su remito certificado.
        foreach (Db::todas('SELECT l.id, l.propuesta_id FROM factura_linea l
                             WHERE NOT EXISTS (SELECT 1 FROM factura_linea_remito f WHERE f.linea_id = l.id)') as $x) {
            $prob[] = ['codigo' => 'SIN_REMITO', 'detalle' => "La línea {$x['id']} de la propuesta {$x['propuesta_id']} no tiene remito."];
        }
        foreach (Db::todas("SELECT r.numero, p.flujo, r.estado, r.conformidad,
                                   (SELECT COUNT(*) FROM remito_eslabon e WHERE e.remito_id = r.id AND e.tipo = 'emision' AND e.firma IS NOT NULL) AS firmas
                              FROM factura_linea_remito f
                              JOIN factura_propuesta fp ON fp.id = f.propuesta_id AND fp.estado <> 'descartada'
                              JOIN remito r ON r.id = f.remito_id
                              JOIN parada_ejecucion p ON p.id = r.parada_id") as $x) {
            if ($x['estado'] !== 'emitido' || $x['conformidad'] !== 'conforme' || (int) $x['firmas'] === 0
                || !in_array($x['flujo'], ['facturable', 'facturado'], true)) {
                $prob[] = ['codigo' => 'NO_CERTIFICADO', 'detalle' => "El remito {$x['numero']} está en una propuesta sin estar certificado ({$x['estado']}, {$x['conformidad']}, {$x['flujo']})."];
            }
        }
        // 4. Lo aprobado no cambió (F3.4).
        foreach (Db::todas("SELECT id, numero, contenido_sha FROM factura_propuesta WHERE estado = 'aprobada'") as $x) {
            if ($x['numero'] === null || $x['contenido_sha'] === null) {
                $prob[] = ['codigo' => 'SIN_NUMERO', 'detalle' => "La propuesta {$x['id']} figura aprobada sin número o sin huella."];
            } elseif (!hash_equals((string) $x['contenido_sha'], hash('sha256', Hash::canonical(self::contenido((int) $x['id']))))) {
                $prob[] = ['codigo' => 'ALTERADA', 'detalle' => "La propuesta {$x['numero']} cambió después de aprobada: su huella no coincide."];
            }
        }
        foreach (Db::todas('SELECT a.*, p.numero AS pnum, p.estado AS pestado FROM factura_ajuste a
                              JOIN factura_propuesta p ON p.id = a.propuesta_id') as $a) {
            $lineas = Db::todas('SELECT l.orden, l.descripcion, l.neto_cent, r.numero AS remito FROM factura_ajuste_linea l
                                   LEFT JOIN remito r ON r.id = l.remito_id WHERE l.ajuste_id = :a ORDER BY l.orden', [':a' => $a['id']]);
            if (!hash_equals((string) $a['contenido_sha'], hash('sha256', Hash::canonical(self::contenidoAjuste($a, (string) $a['pnum'], $lineas))))
                || array_sum(array_map('intval', array_column($lineas, 'neto_cent'))) !== (int) $a['neto_cent']) {
                $prob[] = ['codigo' => 'AJUSTE_ALTERADO', 'detalle' => "La nota {$a['numero']} cambió después de emitida."];
            }
            if ($a['pestado'] !== 'aprobada') {
                $prob[] = ['codigo' => 'AJUSTE_SIN_APROBADA', 'detalle' => "La nota {$a['numero']} está sobre una propuesta que no está aprobada."];
            }
        }
        foreach (Db::todas('SELECT DISTINCT propuesta_id FROM factura_ajuste') as $x) {
            if (self::saldo((int) $x['propuesta_id']) < 0) {
                $prob[] = ['codigo' => 'SALDO_NEGATIVO', 'detalle' => "La propuesta {$x['propuesta_id']} quedó con saldo negativo."];
            }
        }
        return $prob;
    }

    // ------------------------------------------------------------------

    private static function datosCliente(array $c): array
    {
        return [
            'nombre'            => $c['nombre'],
            'razon_social'      => $c['razon_social'],
            'cuit'              => $c['cuit'],
            'condicion_iva'     => $c['condicion_iva'],
            'domicilio_fiscal'  => $c['domicilio_fiscal'],
            'email_facturacion' => $c['email_facturacion'],
        ];
    }

    private static function publica(array $p): array
    {
        return [
            'id'               => (int) $p['id'],
            'numero'           => $p['numero'] ?? null,
            'cliente_id'       => (int) $p['cliente_id'],
            'cliente'          => $p['cliente'],
            'desde'            => $p['desde'],
            'hasta'            => $p['hasta'],
            'estado'           => $p['estado'],
            'tipo_comprobante' => $p['tipo_comprobante'],
            'iva_alicuota_pb'  => (int) $p['iva_alicuota_pb'],
            'neto_cent'        => (int) $p['neto_cent'],
            'iva_cent'         => (int) $p['iva_cent'],
            'total_cent'       => (int) $p['total_cent'],
            'creado_utc'       => $p['creado_utc'],
            'aprobada_utc'     => $p['aprobada_utc'],
            'descartada_utc'   => $p['descartada_utc'],
            'descartada_motivo' => $p['descartada_motivo'],
        ];
    }
}
