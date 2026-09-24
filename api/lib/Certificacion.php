<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Cadena de remitos y su verificación. Obj. 2, paso F2.3.
 *
 * Un eslabón por cada hecho sobre un remito (emisión, anulación), encadenado
 * con SHA-256 y firmado con Ed25519. El detalle de por qué así está en la
 * migración 0018; acá está el cómo.
 *
 * LA REGLA DEL VERIFICADOR: dice EXACTAMENTE dónde está el problema. No
 * «la cadena está rota», sino «el remito REM-A-000042: su contenido no
 * coincide con la huella con que se emitió». Una auditoría que sólo sabe que
 * algo anda mal no le sirve a nadie para explicarle nada a un cliente.
 */
final class Certificacion
{
    public const CADENA = 'remito';

    // ------------------------------------------------------------------
    // Qué se encadena
    // ------------------------------------------------------------------

    /**
     * Lo que el eslabón de emisión afirma de un remito. Todo lo que importa
     * entra por huella: el contenido entero, el emisor, el código del QR.
     */
    public static function contenidoEmision(array $fila): array
    {
        return [
            'v'           => 1,
            'tipo'        => 'emision',
            'remito'      => (string) $fila['numero'],
            'serie'       => (string) $fila['serie'],
            'seq'         => (int) $fila['numero_seq'],
            'fecha'       => (string) $fila['fecha'],
            'codigo'      => $fila['codigo_verificacion'] === null ? null : (string) $fila['codigo_verificacion'],
            'conformidad' => (string) $fila['conformidad'],
            'datos_sha'   => hash('sha256', (string) $fila['datos_json']),
            'emisor_sha'  => hash('sha256', (string) $fila['emisor_json']),
            'emitido_utc' => (string) $fila['emitido_utc'],
        ];
    }

    public static function contenidoAnulacion(array $fila): array
    {
        return [
            'v'           => 1,
            'tipo'        => 'anulacion',
            'remito'      => (string) $fila['numero'],
            'motivo_sha'  => hash('sha256', (string) $fila['anulado_motivo']),
            'anulado_utc' => (string) $fila['anulado_utc'],
        ];
    }

    /**
     * Agrega un eslabón. SIEMPRE adentro de la transacción que produce el
     * hecho: un remito emitido sin su eslabón, o un eslabón de un remito que
     * no llegó a guardarse, son exactamente lo que la cadena existe para
     * impedir.
     */
    public static function eslabonar(int $remitoId, string $tipo, array $contenido): array
    {
        if (!Db::pdo()->inTransaction()) {
            throw new LogicException('Certificacion::eslabonar() fuera de una transacción.');
        }

        $previo = Hash::cabeza(self::CADENA);
        $canonico = Hash::canonical($contenido);
        $hash = hash('sha256', ($previo ?? str_repeat('0', 64)) . '|' . $canonico);
        $firma = Firma::firmar(Firma::DOMINIO_REMITO . $hash);

        Db::q(
            'INSERT INTO remito_eslabon (remito_id, tipo, contenido, hash_prev, hash, firma, firma_alg, clave_id, creado_utc)
             VALUES (:r, :t, :c, :hp, :h, :f, :a, :k, UTC_TIMESTAMP())',
            [
                ':r'  => $remitoId,
                ':t'  => $tipo,
                ':c'  => $canonico,
                ':hp' => $previo,
                ':h'  => $hash,
                ':f'  => $firma['firma'] ?? null,
                ':a'  => $firma['alg'] ?? 'ninguna',
                ':k'  => $firma['clave_id'] ?? null,
            ]
        );
        $id = Db::insertarId();
        Hash::avanzar(self::CADENA, $hash, $id);

        return ['id' => $id, 'hash' => $hash, 'firma_alg' => $firma['alg'] ?? 'ninguna', 'clave_id' => $firma['clave_id'] ?? null];
    }

    // ------------------------------------------------------------------
    // Verificación de la cadena entera
    // ------------------------------------------------------------------

    /**
     * Recorre la cadena entera y la contrasta con los remitos.
     *
     * @param string|null $hasta  hash de un eslabón (el del ancla, F2.5): se
     *                            verifica hasta ahí y se exige que exista.
     * @return array{eslabones:int, remitos:int, problemas:array, cabeza:?string, firmados:int}
     */
    public static function verificarCadena(?string $hasta = null): array
    {
        // Todo en UNA transacción de lectura: en REPEATABLE READ, las lecturas
        // de remitos, eslabones y cabeza ven la misma foto. Sin esto, un remito
        // emitido mientras se verificaba daba un falso «cabeza desfasada» o
        // «remito ausente» (revisión de la Fase 2).
        return Db::tx(static fn(): array => self::recorrer($hasta));
    }

    private static function recorrer(?string $hasta): array
    {
        $problemas = [];
        $firmados = 0;
        $n = 0;
        $previo = null;
        $vistos = [];      // remito_id => [tipos]
        $llegoAlAncla = $hasta === null;

        // Los remitos se leen enteros una vez: para cruzar cada eslabón con lo
        // que la base dice HOY de su remito.
        $remitos = [];
        foreach (Db::todas('SELECT id, numero, serie, numero_seq, fecha, codigo_verificacion, conformidad, estado,
                                   datos_json, datos_sha, emisor_json, emitido_utc, anulado_utc, anulado_motivo
                              FROM remito') as $r) {
            $remitos[(int) $r['id']] = $r;
        }

        foreach (Db::todas('SELECT * FROM remito_eslabon ORDER BY id') as $e) {
            $n++;
            $rem = $remitos[(int) $e['remito_id']] ?? null;
            $cual = $rem['numero'] ?? ('remito #' . $e['remito_id']);
            $donde = "eslabón {$e['id']} ($cual, {$e['tipo']})";

            // 1. El eslabón apunta al anterior.
            if ($e['hash_prev'] !== $previo) {
                $problemas[] = self::problema($cual, (int) $e['id'], 'cadena_cortada',
                    "$donde no apunta al eslabón anterior: falta un eslabón, sobra uno, o el anterior se reescribió.");
            }

            // 2. Su huella es la de su contenido.
            $recalculado = hash('sha256', ($e['hash_prev'] ?? str_repeat('0', 64)) . '|' . $e['contenido']);
            if (!hash_equals($recalculado, (string) $e['hash'])) {
                $problemas[] = self::problema($cual, (int) $e['id'], 'eslabon_alterado',
                    "$donde: su contenido no da la huella que tiene guardada. Alguien editó el eslabón.");
            }

            // 3. La firma, contra una clave de CONFIANZA.
            if ($e['firma'] !== null) {
                $v = Firma::verificar(Firma::DOMINIO_REMITO . $e['hash'], (string) $e['firma'], (string) $e['clave_id']);
                if ($v === true) {
                    $firmados++;
                } elseif ($v === false) {
                    $problemas[] = self::problema($cual, (int) $e['id'], 'firma_invalida',
                        "$donde: la firma no corresponde a su huella. El eslabón se cambió después de firmado.");
                } else {
                    $problemas[] = self::problema($cual, (int) $e['id'], 'clave_desconocida',
                        "$donde está firmado con la clave {$e['clave_id']}, que no es una clave de confianza de esta instalación" .
                        (Firma::sodium() ? '.' : ' (o falta sodium para verificar).'));
                }
            }

            // 4. Lo que el eslabón afirma coincide con el remito de hoy.
            if ($rem === null) {
                $problemas[] = self::problema($cual, (int) $e['id'], 'remito_ausente',
                    "$donde: el remito al que pertenece ya no está en la base.");
            } else {
                $afirmado = json_decode((string) $e['contenido'], true) ?: [];
                $actual = $e['tipo'] === 'emision' ? self::contenidoEmision($rem)
                        : ($rem['anulado_utc'] === null ? null : self::contenidoAnulacion($rem));
                if ($actual === null) {
                    $problemas[] = self::problema($cual, (int) $e['id'], 'anulacion_deshecha',
                        "El remito $cual tiene un eslabón de anulación, pero en la base figura como NO anulado.");
                } else {
                    foreach ($afirmado as $campo => $valor) {
                        if (($actual[$campo] ?? null) !== $valor) {
                            $problemas[] = self::problema($cual, (int) $e['id'], 'remito_alterado',
                                "El remito $cual: " . self::nombreCampo($campo) . ' no coincide con lo que se ' .
                                ($e['tipo'] === 'emision' ? 'emitió' : 'anuló') . '.');
                        }
                    }
                }
                $vistos[(int) $e['remito_id']][] = $e['tipo'];
            }

            if ($hasta !== null && hash_equals($hasta, (string) $e['hash'])) {
                $llegoAlAncla = true;
                $previo = (string) $e['hash'];
                break;
            }
            $previo = (string) $e['hash'];
        }

        if (!$llegoAlAncla) {
            $problemas[] = self::problema(null, null, 'ancla_ausente',
                'La huella anclada no aparece en la cadena: la historia que se ancló ya no es la que está en la base.');
        }

        // 5. Remitos sin su eslabón (sólo si se recorrió la cadena entera).
        if ($hasta === null) {
            foreach ($remitos as $id => $r) {
                $tipos = $vistos[$id] ?? [];
                if (!in_array('emision', $tipos, true)) {
                    $problemas[] = self::problema($r['numero'], null, 'sin_eslabon',
                        "El remito {$r['numero']} no tiene eslabón de emisión: no está en la cadena.");
                }
                if ($r['estado'] === 'anulado' && !in_array('anulacion', $tipos, true)) {
                    $problemas[] = self::problema($r['numero'], null, 'anulacion_sin_eslabon',
                        "El remito {$r['numero']} figura anulado, pero su anulación no está en la cadena.");
                }
            }

            $cabeza = Db::col("SELECT ultimo_hash FROM cadena WHERE nombre = :n", [':n' => self::CADENA]);
            if (($cabeza ?: null) !== $previo) {
                $problemas[] = self::problema(null, null, 'cabeza_desfasada',
                    'La cabeza de la cadena no es el último eslabón: se agregó o se quitó algo por fuera.');
            }
        }

        return [
            'eslabones' => $n,
            'remitos'   => count($remitos),
            'firmados'  => $firmados,
            'problemas' => $problemas,
            'cabeza'    => $previo,
        ];
    }

    private static function problema(?string $remito, ?int $eslabon, string $codigo, string $detalle): array
    {
        return ['remito' => $remito, 'eslabon' => $eslabon, 'codigo' => $codigo, 'detalle' => $detalle];
    }

    private static function nombreCampo(string $c): string
    {
        return [
            'datos_sha'   => 'su contenido',
            'emisor_sha'  => 'la razón social del emisor',
            'codigo'      => 'el código de verificación',
            'conformidad' => 'la conformidad',
            'remito'      => 'el número',
            'seq'         => 'el número',
            'serie'       => 'la serie',
            'fecha'       => 'la fecha',
            'emitido_utc' => 'la hora de emisión',
            'motivo_sha'  => 'el motivo de la anulación',
            'anulado_utc' => 'la hora de anulación',
        ][$c] ?? $c;
    }

    // ------------------------------------------------------------------
    // Verificación de un remito
    // ------------------------------------------------------------------

    /**
     * Lo que se puede afirmar de UN remito: que su contenido es el que se
     * emitió, que su eslabón está entero y encadenado, y que la firma es
     * válida. Es lo que usan la certificación y la verificación pública.
     *
     * No recorre la cadena entera (eso es verificarCadena, que corre de
     * noche): mira su eslabón, el anterior y el siguiente.
     */
    public static function verificarRemito(int $remitoId): array
    {
        $r = Db::una('SELECT * FROM remito WHERE id = :id', [':id' => $remitoId]);
        if ($r === null) throw new ErrorNoEncontrado('No existe ese remito.');

        $problemas = [];
        $e = Db::una("SELECT * FROM remito_eslabon WHERE remito_id = :r AND tipo = 'emision'", [':r' => $remitoId]);
        if ($e === null) {
            return [
                'valido' => false, 'firmado' => false, 'firma_alg' => 'ninguna', 'clave_id' => null,
                'hash' => null, 'posicion' => null, 'total' => (int) Db::col('SELECT COUNT(*) FROM remito_eslabon'),
                'anulado' => $r['estado'] === 'anulado',
                'problemas' => ['Este remito no tiene eslabón de emisión: no está en la cadena.'],
            ];
        }

        if (!hash_equals((string) $r['datos_sha'], hash('sha256', (string) $r['datos_json']))) {
            $problemas[] = 'Su contenido no coincide con su propia huella.';
        }
        $afirmado = json_decode((string) $e['contenido'], true) ?: [];
        foreach (self::contenidoEmision($r) as $campo => $valor) {
            if (($afirmado[$campo] ?? null) !== $valor) {
                $problemas[] = ucfirst(self::nombreCampo($campo)) . ' no coincide con lo que se emitió.';
            }
        }
        $recalculado = hash('sha256', ($e['hash_prev'] ?? str_repeat('0', 64)) . '|' . $e['contenido']);
        if (!hash_equals($recalculado, (string) $e['hash'])) {
            $problemas[] = 'Su eslabón fue editado después de emitido.';
        }
        // Los vecinos: el anterior tiene que ser el previo, y el siguiente
        // tiene que apuntarle.
        $anterior = Db::col('SELECT hash FROM remito_eslabon WHERE id < :id ORDER BY id DESC LIMIT 1', [':id' => $e['id']]);
        if (($anterior ?: null) !== $e['hash_prev']) $problemas[] = 'La cadena está cortada justo antes de este remito.';
        $siguiente = Db::col('SELECT hash_prev FROM remito_eslabon WHERE id > :id ORDER BY id LIMIT 1', [':id' => $e['id']]);
        if ($siguiente !== null && $siguiente !== false && $siguiente !== $e['hash']) {
            $problemas[] = 'La cadena está cortada justo después de este remito.';
        }

        $firmaValida = null;
        if ($e['firma'] !== null) {
            $firmaValida = Firma::verificar(Firma::DOMINIO_REMITO . $e['hash'], (string) $e['firma'], (string) $e['clave_id']);
            if ($firmaValida === false) $problemas[] = 'La firma digital no corresponde: el eslabón cambió después de firmado.';
            if ($firmaValida === null) $problemas[] = 'Está firmado con una clave que esta instalación no reconoce.';
        }

        $anulado = $r['estado'] === 'anulado';
        $tieneAnulacion = (int) Db::col("SELECT COUNT(*) FROM remito_eslabon WHERE remito_id = :r AND tipo = 'anulacion'", [':r' => $remitoId]) > 0;
        if ($anulado !== $tieneAnulacion) {
            $problemas[] = $anulado
                ? 'Figura anulado, pero su anulación no está en la cadena.'
                : 'Su anulación está en la cadena, pero en la base figura como vigente.';
        }

        return [
            'valido'    => $problemas === [],
            'firmado'   => $firmaValida === true,
            'firma_alg' => (string) $e['firma_alg'],
            'clave_id'  => $e['clave_id'],
            'hash'      => (string) $e['hash'],
            'hash_prev' => $e['hash_prev'],
            'firma'     => $e['firma'],
            'contenido' => (string) $e['contenido'],
            'posicion'  => (int) Db::col('SELECT COUNT(*) FROM remito_eslabon WHERE id <= :id', [':id' => $e['id']]),
            'total'     => (int) Db::col('SELECT COUNT(*) FROM remito_eslabon'),
            'anulado'   => $anulado || $tieneAnulacion,
            'problemas' => $problemas,
        ];
    }

    /** Código de verificación: 10 caracteres Crockford, sin I, L, O ni U. */
    public static function nuevoCodigo(): string
    {
        $alfabeto = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $s = '';
        foreach (str_split(random_bytes(10)) as $b) $s .= $alfabeto[ord($b) % 32];
        return $s;
    }

    /** Como se escribe para una persona: 5-5, y así se dicta por teléfono. */
    public static function codigoLegible(?string $c): ?string
    {
        return $c === null ? null : substr($c, 0, 5) . '-' . substr($c, 5);
    }
}
