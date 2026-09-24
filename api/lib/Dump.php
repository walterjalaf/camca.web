<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Volcado de la base en PHP PURO, sin mysqldump.
 *
 * En Hostinger compartido `shell_exec` suele estar en `disable_functions` y el
 * binario `mysqldump` puede directamente no existir. Un backup que depende de
 * un binario que quizas no esta es un backup que se descubre roto el dia que
 * hace falta, que es el peor dia posible. Esto solo usa PDO.
 *
 * Se escribe directo a un archivo gzip, por lotes: una base con anios de
 * evidencias no entra en memory_limit si se arma el SQL en un string.
 */
final class Dump
{
    private const LOTE = 500;

    /** @return int bytes escritos */
    public static function generar(string $destinoGz): int
    {
        $gz = gzopen($destinoGz, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('No se pudo abrir el destino del dump.');
        }

        $pdo = Db::pdo();
        $base = (string) Config::get('db.nombre');

        gzwrite($gz, "-- CAMCA · volcado generado en PHP puro\n");
        gzwrite($gz, '-- base: ' . $base . "\n");
        gzwrite($gz, '-- fecha: ' . gmdate('c') . "\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tablas as $tabla) {
            $crear = $pdo->query('SHOW CREATE TABLE `' . $tabla . '`')->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "\n-- ---------- $tabla ----------\n");
            gzwrite($gz, 'DROP TABLE IF EXISTS `' . $tabla . "`;\n");
            gzwrite($gz, ($crear[1] ?? '') . ";\n\n");

            // Paginado por OFFSET: no se carga la tabla entera en memoria.
            $total = (int) $pdo->query('SELECT COUNT(*) FROM `' . $tabla . '`')->fetchColumn();
            for ($desde = 0; $desde < $total; $desde += self::LOTE) {
                $filas = $pdo->query('SELECT * FROM `' . $tabla . '` LIMIT ' . self::LOTE . ' OFFSET ' . $desde)
                             ->fetchAll(PDO::FETCH_ASSOC);
                if (!$filas) break;

                $columnas = '`' . implode('`,`', array_keys($filas[0])) . '`';
                $valores = [];
                foreach ($filas as $fila) {
                    $celdas = array_map(static function ($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        if (is_int($v) || is_float($v)) return (string) $v;
                        return $pdo->quote((string) $v);
                    }, array_values($fila));
                    $valores[] = '(' . implode(',', $celdas) . ')';
                }
                gzwrite($gz, 'INSERT INTO `' . $tabla . '` (' . $columnas . ") VALUES\n" . implode(",\n", $valores) . ";\n");
            }
        }

        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return (int) filesize($destinoGz);
    }
}
