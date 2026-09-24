<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Exportación contable. Obj. 3, paso F3.5.
 *
 * Lo aprobado (propuestas, notas de crédito y de débito) en planillas que el
 * sistema contable de CAMCA pueda importar. La emisión fiscal ante ARCA sigue
 * afuera (S6): esto es lo que el contador carga, no la factura.
 *
 * DECISIONES DE FORMATO, TODAS POR LA MISMA RAZÓN —QUE CUADRE AL CENTAVO AL
 * VOLVER A LEERLO—:
 *
 *  - CSV con «;» y coma decimal, sin separador de miles: es lo que abre bien
 *    un Excel en castellano de Argentina, y «12345,67» no se puede leer de
 *    dos maneras (con punto de miles, «12.345» es doce mil o doce coma tres
 *    según quién lo lea).
 *  - UTF-8 con BOM y fin de línea CRLF: sin BOM, Excel muestra «RazÃ³n».
 *  - Importes siempre positivos; el signo lo da el tipo (NC resta). Un
 *    importe negativo en una NC suele restarse dos veces.
 *  - Una celda que empieza con = + - @ se escapa con un apóstrofo: un
 *    cliente llamado «=HIPERVINCULO(…)» no puede ejecutar nada en la
 *    planilla del contador (inyección de fórmulas).
 *  - Última fila de control con las sumas, para que el que importa pueda
 *    comprobar que llegó todo.
 */
final class Exportacion
{
    public const COMPROBANTES = [
        'tipo', 'numero', 'fecha', 'comprobante', 'referencia', 'cliente', 'razon_social', 'cuit', 'condicion_iva',
        'periodo_desde', 'periodo_hasta', 'neto', 'iva_alicuota', 'iva', 'total', 'motivo', 'huella',
    ];
    public const LINEAS = [
        'numero', 'orden', 'fecha', 'detalle', 'cantidad', 'unitario', 'neto', 'remitos',
    ];

    /** Fecha de Argentina (UTC-3) de un instante UTC de la base. */
    private static function fechaAr(?string $utc): string
    {
        return $utc === null ? '' : gmdate('Y-m-d', strtotime($utc . ' UTC') - 3 * 3600);
    }

    /** 1234567 → «12345,67». Sin miles, con coma. */
    public static function importe(int $cent): string
    {
        $signo = $cent < 0 ? '-' : '';
        $cent = abs($cent);
        return $signo . intdiv($cent, 100) . ',' . str_pad((string) ($cent % 100), 2, '0', STR_PAD_LEFT);
    }

    /** «12345,67» → 1234567, sin float. null si no es un importe de este formato. */
    public static function leerImporte(string $s): ?int
    {
        if (!preg_match('/^(-?)(\d+),(\d{2})$/', trim($s), $m)) return null;
        $v = (int) $m[2] * 100 + (int) $m[3];
        return $m[1] === '-' ? -$v : $v;
    }

    /** Los rangos se filtran por la fecha de aprobación / emisión, en hora de Argentina. */
    private static function rangoUtc(string $desde, string $hasta): array
    {
        $desde = Tarifa::fechaValida($desde, 'desde');
        $hasta = Tarifa::fechaValida($hasta, 'hasta');
        if ($hasta < $desde) throw new ErrorValidacion(['hasta' => 'El período termina antes de empezar.']);
        return [gmdate('Y-m-d H:i:s', strtotime($desde . ' 03:00:00 UTC')),
                gmdate('Y-m-d H:i:s', strtotime($hasta . ' 03:00:00 UTC') + 86400)];
    }

    public static function comprobantes(string $desde, string $hasta): array
    {
        [$a, $b] = self::rangoUtc($desde, $hasta);
        $filas = [];
        foreach (Db::todas("SELECT p.*, c.nombre FROM factura_propuesta p JOIN cliente c ON c.id = p.cliente_id
                             WHERE p.estado = 'aprobada' AND p.aprobada_utc >= :a AND p.aprobada_utc < :b", [':a' => $a, ':b' => $b]) as $p) {
            $cj = json_decode((string) $p['cliente_json'], true) ?: [];
            $filas[] = [
                'tipo' => 'PF', 'numero' => $p['numero'], 'fecha' => self::fechaAr($p['aprobada_utc']),
                'comprobante' => $p['tipo_comprobante'], 'referencia' => '',
                'cliente' => $p['nombre'], 'razon_social' => $cj['razon_social'] ?? '', 'cuit' => $cj['cuit'] ?? '',
                'condicion_iva' => $cj['condicion_iva'] ?? '', 'periodo_desde' => $p['desde'], 'periodo_hasta' => $p['hasta'],
                'neto' => (int) $p['neto_cent'], 'iva_alicuota' => (int) $p['iva_alicuota_pb'],
                'iva' => (int) $p['iva_cent'], 'total' => (int) $p['total_cent'], 'motivo' => '', 'huella' => $p['contenido_sha'],
                '_orden' => $p['aprobada_utc'] . '|' . $p['numero'],
            ];
        }
        foreach (Db::todas("SELECT a.*, p.numero AS pnum, p.tipo_comprobante, p.cliente_json, p.desde, p.hasta, c.nombre
                              FROM factura_ajuste a JOIN factura_propuesta p ON p.id = a.propuesta_id JOIN cliente c ON c.id = p.cliente_id
                             WHERE a.creado_utc >= :a AND a.creado_utc < :b", [':a' => $a, ':b' => $b]) as $x) {
            $cj = json_decode((string) $x['cliente_json'], true) ?: [];
            $filas[] = [
                'tipo' => $x['tipo'] === 'credito' ? 'NC' : 'ND', 'numero' => $x['numero'], 'fecha' => self::fechaAr($x['creado_utc']),
                'comprobante' => $x['tipo_comprobante'], 'referencia' => $x['pnum'],
                'cliente' => $x['nombre'], 'razon_social' => $cj['razon_social'] ?? '', 'cuit' => $cj['cuit'] ?? '',
                'condicion_iva' => $cj['condicion_iva'] ?? '', 'periodo_desde' => $x['desde'], 'periodo_hasta' => $x['hasta'],
                'neto' => (int) $x['neto_cent'], 'iva_alicuota' => (int) $x['iva_alicuota_pb'],
                'iva' => (int) $x['iva_cent'], 'total' => (int) $x['total_cent'], 'motivo' => $x['motivo'], 'huella' => $x['contenido_sha'],
                '_orden' => $x['creado_utc'] . '|' . $x['numero'],
            ];
        }
        usort($filas, static fn($x, $y) => strcmp($x['_orden'], $y['_orden']));
        return array_map(static function ($f) { unset($f['_orden']); return $f; }, $filas);
    }

    public static function lineas(string $desde, string $hasta): array
    {
        [$a, $b] = self::rangoUtc($desde, $hasta);
        $filas = [];
        foreach (Db::todas("SELECT p.numero, l.orden, l.fecha, l.descripcion, l.cantidad, l.unitario_cent, l.neto_cent,
                                   GROUP_CONCAT(r.numero ORDER BY r.numero_seq SEPARATOR ' ') AS remitos
                              FROM factura_propuesta p JOIN factura_linea l ON l.propuesta_id = p.id
                              LEFT JOIN factura_linea_remito f ON f.linea_id = l.id LEFT JOIN remito r ON r.id = f.remito_id
                             WHERE p.estado = 'aprobada' AND p.aprobada_utc >= :a AND p.aprobada_utc < :b
                             GROUP BY l.id ORDER BY p.aprobada_utc, p.numero, l.orden", [':a' => $a, ':b' => $b]) as $l) {
            $filas[] = ['numero' => $l['numero'], 'orden' => (int) $l['orden'], 'fecha' => $l['fecha'], 'detalle' => $l['descripcion'],
                        'cantidad' => (int) $l['cantidad'], 'unitario' => (int) $l['unitario_cent'], 'neto' => (int) $l['neto_cent'],
                        'remitos' => (string) $l['remitos']];
        }
        foreach (Db::todas("SELECT a.numero, l.orden, a.creado_utc, l.descripcion, l.neto_cent, r.numero AS remito
                              FROM factura_ajuste a JOIN factura_ajuste_linea l ON l.ajuste_id = a.id LEFT JOIN remito r ON r.id = l.remito_id
                             WHERE a.creado_utc >= :a AND a.creado_utc < :b ORDER BY a.creado_utc, a.numero, l.orden", [':a' => $a, ':b' => $b]) as $l) {
            $filas[] = ['numero' => $l['numero'], 'orden' => (int) $l['orden'], 'fecha' => self::fechaAr($l['creado_utc']),
                        'detalle' => $l['descripcion'], 'cantidad' => 1, 'unitario' => (int) $l['neto_cent'], 'neto' => (int) $l['neto_cent'],
                        'remitos' => (string) ($l['remito'] ?? '')];
        }
        return $filas;
    }

    /** Una celda segura para una planilla. */
    private static function celda(mixed $v): string
    {
        $s = (string) $v;
        // Inyección de fórmulas: lo que Excel o LibreOffice interpretarían
        // como fórmula al abrir el archivo.
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !preg_match('/^-?\d+,\d{2}$/', $s)) {
            $s = "'" . $s;
        }
        // Lo mismo DESPUÉS de una coma o un tabulador: una planilla
        // configurada en inglés separa por coma, y «ACME,=HIPERVINCULO(…)»
        // se abría como dos celdas, la segunda una fórmula. Las comillas no lo
        // evitan: para ese separador abren a mitad de campo y no cuentan
        // (revisión de la Fase 3; lo mostró la prueba del primer arreglo).
        $s = preg_replace('/([,\t])(\s*)([=+\-@])/u', "$1$2'$3", $s) ?? $s;
        if (preg_match('/[;"\r\n\t]/', $s)) $s = '"' . str_replace('"', '""', $s) . '"';
        return $s;
    }

    /**
     * El CSV entero. Las columnas de $importes van con coma decimal; las de
     * $sumables además se suman en la última fila, la de control, con el
     * signo que diga $signo (una NC resta del total del período).
     */
    public static function csv(array $columnas, array $filas, array $importes, array $sumables, ?callable $signo = null): string
    {
        $out = "\xEF\xBB\xBF" . implode(';', $columnas) . "\r\n";
        $sumas = array_fill_keys($sumables, 0);
        foreach ($filas as $f) {
            $celdas = [];
            $k = $signo ? $signo($f) : 1;
            foreach ($columnas as $c) {
                if (in_array($c, $importes, true)) {
                    $celdas[] = self::importe((int) $f[$c]);
                    if (isset($sumas[$c])) $sumas[$c] += $k * (int) $f[$c];
                } else {
                    $celdas[] = self::celda($f[$c] ?? '');
                }
            }
            $out .= implode(';', $celdas) . "\r\n";
        }
        $control = [];
        foreach ($columnas as $i => $c) {
            $control[] = $i === 0 ? 'TOTAL ' . count($filas) : (isset($sumas[$c]) ? self::importe($sumas[$c]) : '');
        }
        return $out . implode(';', $control) . "\r\n";
    }
}

