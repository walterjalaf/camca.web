<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Ancla diaria de las cadenas. Obj. 2, paso F2.5.
 *
 * Una vez por día se toma la cabeza de la cadena de remitos y la de la
 * auditoría, se firma, y sale del servidor dos veces: dentro del backup
 * cifrado (que vive fuera del proveedor) y por mail a quien custodia. El
 * porqué completo está en la migración 0019; en una línea: la firma protege
 * contra quien NO tiene la clave, el ancla protege contra quien SÍ la tiene.
 *
 * Formato exportable (el que viaja en el backup y en el mail): un JSON con el
 * contenido canónico tal cual se firmó, la firma, el algoritmo, el id y la
 * clave pública. Con eso cualquiera lo verifica sin la base.
 */
final class Ancla
{
    /**
     * El ancla de una fecha. Si ya existe, la devuelve tal cual: el ancla de
     * un día es una sola, y generarla dos veces daría dos cabezas distintas
     * para el mismo día, que es justo la confusión que no puede haber.
     */
    public static function generar(?string $fecha = null): array
    {
        // El ancla lleva la fecha del día que CIERRA: corre de madrugada, así
        // que la del 22 se genera el 23 a las 02:20 (revisión de la Fase 2).
        // Una fecha distinta de ayer no se acepta: sellar las cabezas de hoy
        // con una fecha pasada sería mentir sobre qué se ancló cuándo.
        $ayer = gmdate('Y-m-d', time() - 3 * 3600 - 86400);
        $fecha ??= $ayer;
        if ($fecha !== $ayer && Db::col('SELECT id FROM ancla WHERE fecha = :f', [':f' => $fecha]) === null) {
            throw new ErrorValidacion(['fecha' => "Sólo se ancla el día que cierra ($ayer)."]);
        }
        $ya = Db::una('SELECT * FROM ancla WHERE fecha = :f', [':f' => $fecha]);
        if ($ya !== null) return self::exportar($ya);

        $cab = [];
        foreach (Db::todas('SELECT nombre, ultimo_hash, ultimo_id FROM cadena') as $c) {
            $cab[$c['nombre']] = ['hash' => $c['ultimo_hash'], 'id' => $c['ultimo_id'] === null ? null : (int) $c['ultimo_id']];
        }
        $contenido = Hash::canonical([
            'v'            => 1,
            'fecha'        => $fecha,
            'remito'       => $cab[Certificacion::CADENA] ?? ['hash' => null, 'id' => null],
            'auditoria'    => $cab['auditoria'] ?? ['hash' => null, 'id' => null],
            'generado_utc' => gmdate('Y-m-d H:i:s'),
        ]);
        $firma = Firma::firmar(Firma::DOMINIO_ANCLA . $contenido);

        $propia = false;
        try {
            Db::q(
                'INSERT INTO ancla (fecha, contenido, cabeza_remito, cabeza_auditoria, firma, firma_alg, clave_id, creado_utc)
                 VALUES (:f, :c, :hr, :ha, :fi, :a, :k, UTC_TIMESTAMP())',
                [
                    ':f'  => $fecha,
                    ':c'  => $contenido,
                    ':hr' => $cab[Certificacion::CADENA]['hash'] ?? null,
                    ':ha' => $cab['auditoria']['hash'] ?? null,
                    ':fi' => $firma['firma'] ?? null,
                    ':a'  => $firma['alg'] ?? 'ninguna',
                    ':k'  => $firma['clave_id'] ?? null,
                ]
            );
            $propia = true;
        } catch (PDOException $e) {
            // Dos crons a la vez: gana el primero y el segundo usa el suyo.
            if (!Db::esDuplicado($e)) throw $e;
        }
        $fila = Db::una('SELECT * FROM ancla WHERE fecha = :f', [':f' => $fecha]);
        // Se audita DESPUÉS de leer las cabezas: este eslabón ya queda fuera
        // del ancla de hoy, y entra en la de mañana.
        // Sólo audita quien la creó: si dos procesos la generan a la vez, el
        // que perdió el INSERT no deja un segundo «generada».
        if ($propia) Hash::auditar('ancla', (int) $fila['id'], 'generada', ['fecha' => $fecha]);
        return self::exportar($fila);
    }

    public static function exportar(array $fila): array
    {
        $pub = $fila['clave_id'] !== null ? (Firma::confiables()[$fila['clave_id']] ?? null) : null;
        return [
            'formato'       => 'camca-ancla-1',
            'contenido'     => (string) $fila['contenido'],
            'firma'         => $fila['firma'],
            'firma_alg'     => (string) $fila['firma_alg'],
            'clave_id'      => $fila['clave_id'],
            'clave_publica' => $pub === null ? null : base64_encode($pub),
            'mensaje'       => 'Se firma "' . Firma::DOMINIO_ANCLA . '" seguido del contenido, tal cual.',
        ];
    }

    /**
     * Verifica una base contra un ancla exportada: que el ancla sea auténtica
     * y que la historia que ancló siga siendo la de la base.
     *
     * @return array{valida: bool, problemas: array, fecha: ?string}
     */
    public static function verificar(array $ancla): array
    {
        $problemas = [];
        $c = json_decode((string) ($ancla['contenido'] ?? ''), true);
        if (!is_array($c) || ($c['v'] ?? null) !== 1) {
            return ['valida' => false, 'fecha' => null, 'problemas' => [
                ['remito' => null, 'eslabon' => null, 'codigo' => 'ancla_ilegible', 'detalle' => 'El ancla no se puede leer.'],
            ]];
        }

        // 1. El ancla misma, firmada con una clave de CONFIANZA de esta
        //    instalación. La clave pública que trae el archivo es para quien
        //    no tiene la base; acá no se le cree.
        if ($ancla['firma'] === null) {
            $problemas[] = ['remito' => null, 'eslabon' => null, 'codigo' => 'ancla_sin_firma',
                'detalle' => "El ancla del {$c['fecha']} no está firmada: sólo prueba que alguien la escribió."];
        } else {
            $v = Firma::verificar(Firma::DOMINIO_ANCLA . $ancla['contenido'], (string) $ancla['firma'], (string) $ancla['clave_id']);
            if ($v !== true) {
                $problemas[] = ['remito' => null, 'eslabon' => null, 'codigo' => 'ancla_firma_invalida',
                    'detalle' => $v === null
                        ? "El ancla del {$c['fecha']} está firmada con una clave que esta instalación no reconoce."
                        : "La firma del ancla del {$c['fecha']} no corresponde a su contenido."];
            }
        }

        // 2. La historia anclada sigue en la base.
        if (($c['remito']['hash'] ?? null) !== null) {
            $r = Certificacion::verificarCadena((string) $c['remito']['hash']);
            $problemas = array_merge($problemas, $r['problemas']);
        }
        if (($c['auditoria']['hash'] ?? null) !== null) {
            $a = Auditoria::verificarCadena((string) $c['auditoria']['hash']);
            $problemas = array_merge($problemas, $a['problemas']);
        }

        return ['valida' => $problemas === [], 'fecha' => $c['fecha'] ?? null, 'problemas' => $problemas];
    }

    /** Las anclas guardadas, de la más nueva a la más vieja. */
    public static function listar(int $cuantas = 30): array
    {
        return Db::todas(
            'SELECT id, fecha, cabeza_remito, cabeza_auditoria, firma_alg, clave_id, creado_utc, mail_enviado_utc, en_backup_utc
               FROM ancla ORDER BY fecha DESC LIMIT ' . max(1, min(366, $cuantas))
        );
    }
}
