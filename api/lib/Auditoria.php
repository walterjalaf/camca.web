<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * La cadena de auditoría, leída. Obj. 2, pasos F2.3 y F2.5.
 *
 * Hash::auditar() la escribe; esto la verifica y la deja consultar. Cada
 * eslabón es sha256(previo | contenido canónico), así que alterar una fila
 * vieja deja de dar su huella, y taparlo recalculando corta la cadena en la
 * fila siguiente.
 */
final class Auditoria
{
    /**
     * Recorre la cadena entera. Dice en qué eslabón está cada problema.
     *
     * @param string|null $hasta  hash anclado (F2.5): se verifica hasta ahí.
     */
    public static function verificarCadena(?string $hasta = null): array
    {
        // Una sola foto de la base: cada tanda de 2000 filas y la cabeza tienen
        // que verse al mismo tiempo, o una acción de la oficina en el medio da
        // un falso «cabeza desfasada» (revisión de la Fase 2).
        return Db::tx(static fn(): array => self::recorrer($hasta));
    }

    private static function recorrer(?string $hasta): array
    {
        $problemas = [];
        $previo = null;
        $n = 0;
        $llego = $hasta === null;

        // De a tandas: en un año son cientos de miles de filas y el plan
        // compartido no da memoria para todas juntas.
        $desde = 0;
        do {
            $filas = Db::todas(
                'SELECT id, entidad, entidad_id, accion, contenido, hash_prev, hash
                   FROM auditoria WHERE id > :d ORDER BY id LIMIT 2000',
                [':d' => $desde]
            );
            foreach ($filas as $f) {
                $n++;
                $desde = (int) $f['id'];
                $que = "eslabón de auditoría {$f['id']} ({$f['entidad']} {$f['entidad_id']}: {$f['accion']})";
                if ($f['hash_prev'] !== $previo) {
                    $problemas[] = ['remito' => null, 'eslabon' => (int) $f['id'], 'codigo' => 'auditoria_cortada',
                        'detalle' => "$que no apunta al anterior: falta o sobra un eslabón."];
                }
                $esperado = hash('sha256', ($f['hash_prev'] ?? str_repeat('0', 64)) . '|' . $f['contenido']);
                if (!hash_equals($esperado, (string) $f['hash'])) {
                    $problemas[] = ['remito' => null, 'eslabon' => (int) $f['id'], 'codigo' => 'auditoria_alterada',
                        'detalle' => "$que: su contenido no da la huella guardada. Alguien editó la auditoría."];
                }
                $previo = (string) $f['hash'];
                if ($hasta !== null && hash_equals($hasta, $previo)) { $llego = true; break 2; }
            }
        } while (count($filas) === 2000);

        if (!$llego) {
            $problemas[] = ['remito' => null, 'eslabon' => null, 'codigo' => 'ancla_ausente_auditoria',
                'detalle' => 'La huella anclada de la auditoría no aparece: la historia anclada ya no es la de la base.'];
        }
        if ($hasta === null) {
            $cabeza = Db::col("SELECT ultimo_hash FROM cadena WHERE nombre = 'auditoria'");
            if (($cabeza ?: null) !== $previo) {
                $problemas[] = ['remito' => null, 'eslabon' => null, 'codigo' => 'auditoria_cabeza',
                    'detalle' => 'La cabeza de la auditoría no es su último eslabón.'];
            }
        }

        return ['eslabones' => $n, 'problemas' => $problemas, 'cabeza' => $previo];
    }

    /**
     * La auditoría, consultable (F2.5): qué pasó, cuándo, quién y sobre qué.
     *
     * Paginada hacia atrás por id (lo más nuevo primero) y no por OFFSET: con
     * cientos de miles de filas, un OFFSET grande recorre todo lo anterior.
     *
     * @param array $f entidad, entidad_id, desde, hasta (AAAA-MM-DD, hora de
     *                 Argentina), antes_de (id), limite
     */
    public static function consultar(array $f): array
    {
        $donde = [];
        $par = [];
        if (!empty($f['entidad'])) { $donde[] = 'a.entidad = :e'; $par[':e'] = (string) $f['entidad']; }
        if (!empty($f['entidad_id'])) { $donde[] = 'a.entidad_id = :ei'; $par[':ei'] = (int) $f['entidad_id']; }
        // Las fechas son de Argentina y la base guarda UTC: el día 21 en ART
        // va de las 03:00 del 21 a las 03:00 del 22 en UTC.
        if (!empty($f['desde'])) { $donde[] = 'a.creado_utc >= :d'; $par[':d'] = $f['desde'] . ' 03:00:00'; }
        if (!empty($f['hasta'])) {
            $donde[] = 'a.creado_utc < :h';
            $par[':h'] = gmdate('Y-m-d', strtotime($f['hasta'] . ' 12:00:00 UTC') + 86400) . ' 03:00:00';
        }
        if (!empty($f['antes_de'])) { $donde[] = 'a.id < :ad'; $par[':ad'] = (int) $f['antes_de']; }
        $limite = max(1, min(200, (int) ($f['limite'] ?? 50)));

        $filas = Db::todas(
            'SELECT a.id, a.entidad, a.entidad_id, a.accion, a.contenido, a.hash, a.creado_utc,
                    u.nombre AS usuario
               FROM auditoria a LEFT JOIN usuario u ON u.id = a.usuario_id' .
            ($donde ? ' WHERE ' . implode(' AND ', $donde) : '') .
            " ORDER BY a.id DESC LIMIT $limite",
            $par
        );

        return array_map(static function (array $x): array {
            $c = json_decode((string) $x['contenido'], true) ?: [];
            return [
                'id'         => (int) $x['id'],
                'cuando_utc' => $x['creado_utc'],
                'entidad'    => $x['entidad'],
                'entidad_id' => $x['entidad_id'] === null ? null : (int) $x['entidad_id'],
                'accion'     => $x['accion'],
                'usuario'    => $x['usuario'],
                'datos'      => $c['datos'] ?? null,
                'hash'       => $x['hash'],
            ];
        }, $filas);
    }

    /** Las entidades que aparecen en la auditoría, para el filtro de la pantalla. */
    public static function entidades(): array
    {
        return array_column(Db::todas('SELECT DISTINCT entidad FROM auditoria ORDER BY entidad'), 'entidad');
    }
}
