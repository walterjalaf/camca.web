<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Codificador QR mínimo. Obj. 2, paso F2.4.
 *
 * POR QUÉ PROPIO: por la misma razón que Pdf.php. Hostinger compartido no
 * tiene Composer, y lo único que hace falta es codificar UNA dirección corta
 * (la de /verificar) en un cuadrado que un teléfono lea. Eso es modo byte,
 * nivel de corrección M y versiones 1 a 10 de ISO/IEC 18004: unas 250 líneas,
 * sin dependencias, que se dibujan igual en SVG y en PDF.
 *
 * QUÉ NO HACE: otros modos (numérico, alfanumérico, kanji), otros niveles de
 * corrección ni versiones grandes. Una URL de más de 213 bytes no entra, y se
 * dice con una excepción en vez de generar un QR ilegible.
 *
 * La prueba NO confía en este código: decodifica lo que produce con un lector
 * independiente (jsQR) y exige que devuelva exactamente la dirección.
 */
final class Qr
{
    /** Palabras de corrección por bloque, nivel M, versiones 1..10. */
    private const ECC_POR_BLOQUE = [1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    /** Cantidad de bloques, nivel M. */
    private const BLOQUES = [1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    /** Centros de los patrones de alineación. */
    private const ALINEACION = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private int $tam;
    /** @var bool[][] [fila][columna] */
    private array $m = [];
    /** @var bool[][] */
    private array $funcion = [];

    /**
     * La matriz de módulos de un texto: filas de booleanos (true = oscuro),
     * sin la zona de silencio (se agrega al dibujar).
     */
    public static function matriz(string $texto): array
    {
        $datos = array_values(unpack('C*', $texto) ?: []);
        for ($v = 1; $v <= 10; $v++) {
            $capacidad = self::palabrasDatos($v) * 8;
            $bits = 4 + ($v < 10 ? 8 : 16) + 8 * count($datos);
            if ($bits <= $capacidad) return (new self($v))->construir($datos)->m;
        }
        throw new LengthException('El texto no entra en un QR versión 10 nivel M (' . strlen($texto) . ' bytes).');
    }

    private function __construct(private int $version)
    {
        $this->tam = $version * 4 + 17;
        $fila = array_fill(0, $this->tam, false);
        $this->m = array_fill(0, $this->tam, $fila);
        $this->funcion = array_fill(0, $this->tam, $fila);
    }

    private function construir(array $datos): self
    {
        $this->patronesFijos();
        $this->ubicar($this->intercalar($this->codificar($datos)));

        // Se prueba cada una de las 8 máscaras y se queda la de menor
        // penalidad, como pide la norma. Cualquiera se lee; la mejor se lee
        // mejor en un papel impreso con poca tinta.
        $mejor = null;
        $puntaje = PHP_INT_MAX;
        for ($k = 0; $k < 8; $k++) {
            $this->enmascarar($k);
            $this->formato($k);
            $p = $this->penalidad();
            if ($p < $puntaje) { $puntaje = $p; $mejor = $k; }
            $this->enmascarar($k);   // XOR otra vez: deshace
        }
        $this->enmascarar($mejor);
        $this->formato($mejor);
        return $this;
    }

    // ------------------------------------------------------------------
    // Datos
    // ------------------------------------------------------------------

    private static function modulosCrudos(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $n = intdiv($v, 7) + 2;
            $r -= (25 * $n - 10) * $n - 55;
            if ($v >= 7) $r -= 36;
        }
        return $r;
    }

    private static function palabrasDatos(int $v): int
    {
        return intdiv(self::modulosCrudos($v), 8) - self::ECC_POR_BLOQUE[$v] * self::BLOQUES[$v];
    }

    /** Modo byte: indicador, cuenta, datos, terminador y relleno. */
    private function codificar(array $datos): array
    {
        $bits = [];
        $poner = static function (int $valor, int $largo) use (&$bits): void {
            for ($i = $largo - 1; $i >= 0; $i--) $bits[] = ($valor >> $i) & 1;
        };
        $poner(0b0100, 4);
        $poner(count($datos), $this->version < 10 ? 8 : 16);
        foreach ($datos as $b) $poner($b, 8);

        $capacidad = self::palabrasDatos($this->version) * 8;
        $poner(0, min(4, $capacidad - count($bits)));
        $poner(0, (8 - count($bits) % 8) % 8);
        for ($relleno = 0xEC; count($bits) < $capacidad; $relleno ^= 0xEC ^ 0x11) $poner($relleno, 8);

        $palabras = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $palabras[] = array_reduce($byte, static fn($a, $b) => ($a << 1) | $b, 0);
        }
        return $palabras;
    }

    /** Parte en bloques, agrega Reed-Solomon a cada uno e intercala. */
    private function intercalar(array $datos): array
    {
        $nBloques = self::BLOQUES[$this->version];
        $ecc = self::ECC_POR_BLOQUE[$this->version];
        $crudas = intdiv(self::modulosCrudos($this->version), 8);
        $cortos = $nBloques - $crudas % $nBloques;
        $largoCorto = intdiv($crudas, $nBloques);
        $divisor = self::generador($ecc);

        $bloques = [];
        $k = 0;
        for ($i = 0; $i < $nBloques; $i++) {
            $n = $largoCorto - $ecc + ($i < $cortos ? 0 : 1);
            $d = array_slice($datos, $k, $n);
            $k += $n;
            $r = self::resto($d, $divisor);
            if ($i < $cortos) $d[] = 0;   // hueco para alinear con los largos
            $bloques[] = array_merge($d, $r);
        }

        $salida = [];
        for ($i = 0, $L = count($bloques[0]); $i < $L; $i++) {
            foreach ($bloques as $j => $b) {
                // El hueco de los bloques cortos no se emite.
                if ($i !== $largoCorto - $ecc || $j >= $cortos) $salida[] = $b[$i];
            }
        }
        return $salida;
    }

    private static function generador(int $grado): array
    {
        $r = array_fill(0, $grado, 0);
        $r[$grado - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grado; $i++) {
            for ($j = 0; $j < $grado; $j++) {
                $r[$j] = self::mult($r[$j], $raiz);
                if ($j + 1 < $grado) $r[$j] ^= $r[$j + 1];
            }
            $raiz = self::mult($raiz, 0x02);
        }
        return $r;
    }

    private static function resto(array $datos, array $divisor): array
    {
        $r = array_fill(0, count($divisor), 0);
        foreach ($datos as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($divisor as $i => $c) $r[$i] ^= self::mult($c, $factor);
        }
        return $r;
    }

    /** Producto en GF(2^8) módulo x^8 + x^4 + x^3 + x^2 + 1. */
    private static function mult(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    // ------------------------------------------------------------------
    // Dibujo de la matriz
    // ------------------------------------------------------------------

    private function poner(int $x, int $y, bool $oscuro): void
    {
        $this->m[$y][$x] = $oscuro;
        $this->funcion[$y][$x] = true;
    }

    private function patronesFijos(): void
    {
        for ($i = 0; $i < $this->tam; $i++) {
            $this->poner(6, $i, $i % 2 === 0);
            $this->poner($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->tam - 4, 3], [3, $this->tam - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx; $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $this->tam || $y >= $this->tam) continue;
                    $d = max(abs($dx), abs($dy));
                    $this->poner($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
        $pos = self::ALINEACION[$this->version];
        $n = count($pos);
        foreach ($pos as $i => $cy) {
            foreach ($pos as $j => $cx) {
                // Las tres esquinas de los buscadores no llevan alineación.
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->poner($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->formato(0);   // reserva el lugar; el definitivo va después de la máscara
        if ($this->version >= 7) {
            $r = $this->version;
            for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
            $bits = ($this->version << 12) | $r;
            for ($i = 0; $i < 18; $i++) {
                $b = (($bits >> $i) & 1) === 1;
                $a = $this->tam - 11 + $i % 3;
                $c = intdiv($i, 3);
                $this->poner($a, $c, $b);
                $this->poner($c, $a, $b);
            }
        }
    }

    /** Información de formato: nivel M (00) y la máscara, con su BCH. */
    private function formato(int $mascara): void
    {
        $datos = (0b00 << 3) | $mascara;
        $r = $datos;
        for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $bits = (($datos << 10) | $r) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) $this->poner(8, $i, $bit($i));
        $this->poner(8, 7, $bit(6));
        $this->poner(8, 8, $bit(7));
        $this->poner(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->poner(14 - $i, 8, $bit($i));

        for ($i = 0; $i < 8; $i++) $this->poner($this->tam - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->poner(8, $this->tam - 15 + $i, $bit($i));
        $this->poner(8, $this->tam - 8, true);   // el módulo oscuro fijo
    }

    /** Los datos en zigzag, de a dos columnas, de derecha a izquierda. */
    private function ubicar(array $palabras): void
    {
        $total = count($palabras) * 8;
        $i = 0;
        for ($der = $this->tam - 1; $der >= 1; $der -= 2) {
            if ($der === 6) $der = 5;   // la columna de sincronismo no se usa
            for ($v = 0; $v < $this->tam; $v++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $der - $j;
                    $sube = (($der + 1) & 2) === 0;
                    $y = $sube ? $this->tam - 1 - $v : $v;
                    if (!$this->funcion[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($palabras[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function enmascarar(int $k): void
    {
        for ($y = 0; $y < $this->tam; $y++) {
            for ($x = 0; $x < $this->tam; $x++) {
                if ($this->funcion[$y][$x]) continue;
                $invertir = match ($k) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invertir) $this->m[$y][$x] = !$this->m[$y][$x];
            }
        }
    }

    /** Penalidad de ISO 18004 §7.8.3: rachas, bloques, falsos buscadores, balance. */
    private function penalidad(): int
    {
        $p = 0;
        $t = $this->tam;
        $lineas = [];
        for ($i = 0; $i < $t; $i++) {
            $lineas[] = $this->m[$i];
            $lineas[] = array_column($this->m, $i);
        }
        foreach ($lineas as $l) {
            $racha = 1;
            for ($i = 1; $i <= $t; $i++) {
                if ($i < $t && $l[$i] === $l[$i - 1]) { $racha++; continue; }
                if ($racha >= 5) $p += 3 + ($racha - 5);
                $racha = 1;
            }
            $s = implode('', array_map(static fn($b) => $b ? '1' : '0', $l));
            $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        for ($y = 0; $y < $t - 1; $y++) {
            for ($x = 0; $x < $t - 1; $x++) {
                $c = $this->m[$y][$x];
                if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) $p += 3;
            }
        }
        $oscuros = 0;
        foreach ($this->m as $fila) foreach ($fila as $c) if ($c) $oscuros++;
        $p += 10 * intdiv(abs($oscuros * 20 - $t * $t * 10), $t * $t);
        return $p;
    }

    // ------------------------------------------------------------------
    // Salidas
    // ------------------------------------------------------------------

    /** SVG en línea, un solo camino. Zona de silencio de 4 módulos, como pide la norma. */
    public static function svg(array $m, string $etiqueta = 'Código QR'): string
    {
        $n = count($m);
        $lado = $n + 8;
        $d = '';
        foreach ($m as $y => $fila) {
            foreach ($fila as $x => $c) {
                if ($c) $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
            }
        }
        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '" ' .
            'shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($etiqueta, ENT_QUOTES) . '">' .
            '<rect width="' . $lado . '" height="' . $lado . '" fill="#fff"/>' .
            '<path d="' . $d . '" fill="#000"/></svg>';
    }

    /**
     * Operadores de PDF: un rectángulo por módulo oscuro, en un cuadrado de
     * $ladoPt puntos con la zona de silencio incluida.
     */
    public static function pdf(array $m, float $ladoPt): string
    {
        $n = count($m);
        $mod = $ladoPt / ($n + 8);
        $ops = "0 0 0 rg\n";
        foreach ($m as $y => $fila) {
            foreach ($fila as $x => $c) {
                if (!$c) continue;
                // PDF crece hacia arriba: la fila 0 va arriba de todo.
                $ops .= sprintf("%.3f %.3f %.3f %.3f re\n", ($x + 4) * $mod, $ladoPt - ($y + 5) * $mod, $mod, $mod);
            }
        }
        return $ops . "f\n";
    }
}
