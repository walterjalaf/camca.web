<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Canonicalización y cadena de auditoría.
 *
 * canonical() produce la MISMA cadena de bytes para el mismo contenido
 * lógico, independientemente del orden en que llegaron las claves. Sin eso
 * la cadena de hashes no es verificable por un tercero, que es todo el punto.
 *
 * H2 — En Fase 0 la firma queda en 'ninguna' a propósito: hay cadena de
 * hashes y ancla diaria, pero NO firma asimétrica. Mientras firma_alg sea
 * 'ninguna', ni la documentación ni el presupuesto le llaman "certificado".
 * La firma verificable por terceros es Fase 1 / Objetivo 2.
 */
final class Hash
{
    /** JSON determinista: claves ordenadas en profundidad, sin escapes de barra. */
    public static function canonical(mixed $valor): string
    {
        return json_encode(
            self::ordenar($valor),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '';
    }

    private static function ordenar(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        $esLista = array_is_list($v);
        $salida = [];
        foreach ($v as $k => $item) $salida[$k] = self::ordenar($item);
        if (!$esLista) ksort($salida, SORT_STRING);
        return $salida;
    }

    public static function sha256(string $s): string { return hash('sha256', $s); }

    /** Eslabón: hash(hash_anterior || contenido_canónico). */
    public static function eslabon(?string $hashPrevio, mixed $contenido): string
    {
        return self::sha256(($hashPrevio ?? str_repeat('0', 64)) . '|' . self::canonical($contenido));
    }

    /**
     * Agrega una entrada a la cadena de auditoría y devuelve su hash.
     *
     * La cabeza se toma de la fila 'auditoria' de `cadena`, con FOR UPDATE por
     * clave primaria: serializa a los escritores concurrentes —sin eso dos
     * peticiones simultáneas producen dos eslabones con el mismo previo y la
     * cadena deja de ser una cadena— bloqueando UNA fila exacta.
     *
     * Antes se hacía con `ORDER BY id DESC LIMIT 1 FOR UPDATE` sobre la propia
     * tabla, y eso toma el hueco del final del índice: dos escritores se
     * trababan entre sí (ver 0016). Y como esto no se reintentaba, el
     * documento quedaba emitido y el que lo emitió recibía un error.
     *
     * Se reintenta si InnoDB lo elige como víctima y NO hay una transacción
     * de afuera; si la hay, la reintenta el que la abrió.
     */
    public static function auditar(string $entidad, ?int $entidadId, string $accion, array $datos): string
    {
        return Db::txReintentable(static function () use ($entidad, $entidadId, $accion, $datos): string {
            $previo = self::cabeza('auditoria');
            $contenido = [
                'entidad'    => $entidad,
                'entidad_id' => $entidadId,
                'accion'     => $accion,
                'datos'      => $datos,
                'ts'         => gmdate('c'),
                'usuario'    => Policy::$usuario['id'] ?? null,
            ];
            $hash = self::eslabon($previo !== null ? (string) $previo : null, $contenido);
            Db::q(
                'INSERT INTO auditoria (entidad, entidad_id, accion, usuario_id, contenido, hash_prev, hash, firma_alg, creado_utc)
                 VALUES (:e, :ei, :a, :u, :c, :hp, :h, :fa, UTC_TIMESTAMP())',
                [
                    ':e'  => $entidad,
                    ':ei' => $entidadId,
                    ':a'  => $accion,
                    ':u'  => Policy::$usuario['id'] ?? null,
                    ':c'  => self::canonical($contenido),
                    ':hp' => $previo,
                    ':h'  => $hash,
                    ':fa' => 'ninguna',   // cada eslabón no se firma: se firma la cabeza diaria (F2.5)
                ]
            );
            self::avanzar('auditoria', $hash, Db::insertarId());
            return $hash;
        });
    }

    /**
     * La cabeza de una cadena, bloqueada hasta el fin de la transacción.
     * Se llama SIEMPRE adentro de una.
     */
    public static function cabeza(string $cadena): ?string
    {
        $fila = Db::una('SELECT ultimo_hash FROM cadena WHERE nombre = :n FOR UPDATE', [':n' => $cadena]);
        if ($fila === null) {
            // Sin la fila no hay a quién bloquear, y dos escritores harían dos
            // eslabones con el mismo previo. Mejor no escribir que romper la
            // cadena: esto sólo pasa si falta la migración 0016.
            throw new ErrorMantenimiento('CADENA_SIN_CABEZA', "Falta la cabeza de la cadena '$cadena'.");
        }
        return $fila['ultimo_hash'] === null ? null : (string) $fila['ultimo_hash'];
    }

    /** Mueve la cabeza al eslabón recién escrito. En la misma transacción que cabeza(). */
    public static function avanzar(string $cadena, string $hash, int $id): void
    {
        Db::q(
            'UPDATE cadena SET ultimo_hash = :h, ultimo_id = :i, actualizado_utc = UTC_TIMESTAMP() WHERE nombre = :n',
            [':h' => $hash, ':i' => $id, ':n' => $cadena]
        );
    }
}
