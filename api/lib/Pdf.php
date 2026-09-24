<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Generador de PDF mínimo, sin dependencias. Obj. 1, paso F1.7 (supuesto S5).
 *
 * POR QUÉ ESTO Y NO UNA LIBRERÍA:
 *
 * Hostinger compartido no tiene Composer ni binarios como wkhtmltopdf, y el
 * preflight todavía no confirmó qué permite `disable_functions`. Vendorizar una
 * librería completa para armar un formulario de dos páginas con texto y líneas
 * trae un problema de licencia, uno de actualización y ~300 kB en el FTP de
 * cada deploy.
 *
 * QUÉ HACE Y QUÉ NO:
 *
 * Hace texto en Helvetica, negrita, líneas y tablas de dos columnas con salto
 * de página automático. NO hace imágenes, ni colores de fondo, ni tipografías
 * propias. Es deliberado: un R28 es un formulario, no un folleto, y lo que
 * tiene que hacer es imprimirse igual en cualquier lado y ser legible dentro de
 * diez años.
 *
 * El HTML sigue siendo la fuente única de la presentación (S5). Esto arma el
 * PDF desde los MISMOS datos, no desde otra plantilla: dos plantillas para el
 * mismo documento divergen, y la que diverge es siempre la que nadie mira.
 *
 * Las fuentes base de PDF usan WinAnsi (CP1252), así que el texto se convierte.
 * Lo que no entra en CP1252 —que en castellano es casi nada— se translitera en
 * vez de salir como un cuadradito.
 */
final class Pdf
{
    // A4 en puntos: 72 pt = 1 pulgada.
    private const ANCHO = 595.28;
    private const ALTO  = 841.89;
    private const MARGEN_X = 45.0;
    private const MARGEN_SUP = 50.0;
    private const MARGEN_INF = 55.0;

    private array $paginas = [];
    private string $actual = '';
    private float $y;

    public function __construct()
    {
        $this->nuevaPagina();
    }

    public function nuevaPagina(): void
    {
        if ($this->actual !== '') $this->paginas[] = $this->actual;
        $this->actual = '';
        $this->y = self::ALTO - self::MARGEN_SUP;
    }

    /** Espacio restante antes del pie. */
    public function restante(): float
    {
        return $this->y - self::MARGEN_INF;
    }

    private function asegurar(float $alto): void
    {
        if ($this->restante() < $alto) $this->nuevaPagina();
    }

    public function texto(string $s, float $tam = 10.0, bool $negrita = false, ?float $x = null, float $interlineado = 1.35): void
    {
        $this->asegurar($tam * $interlineado);
        $x ??= self::MARGEN_X;
        $this->y -= $tam * $interlineado;
        $this->actual .= sprintf(
            "BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $negrita ? 'F2' : 'F1', $tam, $x, $this->y, self::escapar($s)
        );
    }

    /** Texto alineado a la derecha del ancho útil. */
    public function textoDerecha(string $s, float $tam = 10.0, bool $negrita = false): void
    {
        $ancho = self::anchoTexto($s, $tam, $negrita);
        $this->texto($s, $tam, $negrita, self::ANCHO - self::MARGEN_X - $ancho);
    }

    /**
     * Fila de etiqueta y valor. El valor se parte solo si no entra.
     *
     * Es el 90 % de un formulario: sin esto habría que calcular posiciones a
     * mano en cada campo, que es justo donde un documento sale con el texto
     * pisado y nadie lo nota hasta que lo imprime el cliente.
     */
    public function fila(string $etiqueta, string $valor, float $tam = 10.0): void
    {
        $xEtiqueta = self::MARGEN_X;
        $xValor = self::MARGEN_X + 175.0;
        $anchoValor = self::ANCHO - self::MARGEN_X - $xValor;

        $lineas = self::partir($valor, $anchoValor, $tam, false);
        $this->asegurar($tam * 1.35 * max(1, count($lineas)));

        $yInicio = $this->y;
        $this->y -= $tam * 1.35;
        $this->actual .= sprintf(
            "BT /F1 %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $tam, $xEtiqueta, $this->y, self::escapar($etiqueta)
        );

        $this->y = $yInicio;
        foreach ($lineas as $l) {
            $this->y -= $tam * 1.35;
            $this->actual .= sprintf(
                "BT /F2 %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
                $tam, $xValor, $this->y, self::escapar($l)
            );
        }
    }

    /** @param string|null $rgb color del trazo como "r g b" de 0 a 1; negro si no se dice */
    public function linea(float $grosor = 0.6, ?string $rgb = null): void
    {
        $this->asegurar(6);
        $this->y -= 4;
        $this->actual .= ($rgb !== null ? $rgb . " RG\n" : '') . sprintf(
            "%.2f w %.2f %.2f m %.2f %.2f l S\n",
            $grosor, self::MARGEN_X, $this->y, self::ANCHO - self::MARGEN_X, $this->y
        ) . ($rgb !== null ? "0 G\n" : '');
        $this->y -= 4;
    }

    public function espacio(float $pt = 8.0): void
    {
        $this->y -= $pt;
    }

    /**
     * Un bloque gráfico: operadores de PDF en coordenadas LOCALES del bloque
     * (origen abajo a la izquierda, en puntos), ubicado en el flujo del texto.
     *
     * Es lo mínimo para dibujar la firma del receptor (trazos) y el QR de
     * verificación (cuadrados) sin meter imágenes en el PDF, que es lo que
     * esta clase se prometió no hacer.
     *
     * @param float|null $x  borde izquierdo; por defecto el margen
     */
    public function grafico(float $ancho, float $alto, string $ops, ?float $x = null): void
    {
        $this->asegurar($alto + 4);
        $this->y -= $alto;
        $x ??= self::MARGEN_X;
        $this->actual .= sprintf("q 1 0 0 1 %.2f %.2f cm\n%s\nQ\n", $x, $this->y, $ops);
    }

    /** Ancho útil de la página, para ubicar gráficos a la derecha. */
    public static function anchoUtil(): float
    {
        return self::ANCHO - 2 * self::MARGEN_X;
    }

    public static function margen(): float
    {
        return self::MARGEN_X;
    }

    /** Devuelve el archivo PDF completo. */
    public function salida(): string
    {
        if ($this->actual !== '') { $this->paginas[] = $this->actual; $this->actual = ''; }
        if ($this->paginas === []) $this->paginas[] = '';

        $n = count($this->paginas);
        $objetos = [];

        // 1 catálogo, 2 páginas, 3..(2+n) páginas, luego contenidos, luego fuentes.
        $idsPagina = [];
        for ($i = 0; $i < $n; $i++) $idsPagina[] = 3 + $i;
        $idsContenido = [];
        for ($i = 0; $i < $n; $i++) $idsContenido[] = 3 + $n + $i;
        $idF1 = 3 + 2 * $n;
        $idF2 = $idF1 + 1;

        $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objetos[2] = "<< /Type /Pages /Kids [" .
            implode(' ', array_map(static fn($id) => "$id 0 R", $idsPagina)) .
            "] /Count $n >>";

        for ($i = 0; $i < $n; $i++) {
            $objetos[$idsPagina[$i]] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] " .
                "/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>",
                self::ANCHO, self::ALTO, $idF1, $idF2, $idsContenido[$i]
            );
            $flujo = $this->paginas[$i];
            $objetos[$idsContenido[$i]] = "<< /Length " . strlen($flujo) . " >>\nstream\n" . $flujo . "endstream";
        }

        $objetos[$idF1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objetos[$idF2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        ksort($objetos);

        $pdf = "%PDF-1.4\n";
        // El comentario binario le dice a los transportes que el archivo NO es
        // texto. Sin esto, un FTP en modo ASCII lo corrompe en silencio.
        $pdf .= "%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objetos as $id => $cuerpo) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$cuerpo\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $total = count($objetos) + 1;
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($id = 1; $id < $total; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$inicioXref\n%%EOF\n";

        return $pdf;
    }

    // ------------------------------------------------------------------

    /** UTF-8 a WinAnsi, y después los tres caracteres que PDF reserva. */
    private static function escapar(string $s): string
    {
        $s = self::aWinAnsi($s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private static function aWinAnsi(string $s): string
    {
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        if ($r === false) {
            // Sin iconv utilizable: se translitera a mano lo que aparece en
            // castellano y el resto se descarta, que es preferible a emitir
            // bytes inválidos adentro del PDF.
            $r = strtr($s, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U',
                '·' => '-', '—' => '-', '–' => '-', '«' => '"', '»' => '"', '’' => "'", '…' => '...',
            ]);
            $r = preg_replace('/[^\x20-\x7E]/', '', $r) ?? '';
        }
        return $r;
    }

    /**
     * Ancho aproximado de Helvetica, en puntos.
     *
     * Son las métricas reales de las clases de ancho de Helvetica, agrupadas.
     * No es exacto al punto, pero alcanza para decidir dónde cortar una línea,
     * que es lo único para lo que se usa.
     */
    private static function anchoTexto(string $s, float $tam, bool $negrita): float
    {
        $s = self::aWinAnsi($s);
        $u = 0.0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === ' ') { $u += 278; continue; }
            if (strpos('ijltfI.,:;|!\'', $c) !== false) { $u += 250; continue; }
            if (strpos('MW%@', $c) !== false) { $u += 889; continue; }
            if (ctype_upper($c)) { $u += 667; continue; }
            $u += 556;
        }
        if ($negrita) $u *= 1.06;
        return $u * $tam / 1000.0;
    }

    /** Parte en líneas que entren en $ancho, cortando por palabras. */
    private static function partir(string $s, float $ancho, float $tam, bool $negrita): array
    {
        $palabras = preg_split('/\s+/', trim($s)) ?: [];
        if ($palabras === [] || $palabras === ['']) return [''];

        $lineas = [];
        $linea = '';
        foreach ($palabras as $p) {
            $prueba = $linea === '' ? $p : $linea . ' ' . $p;
            if (self::anchoTexto($prueba, $tam, $negrita) <= $ancho || $linea === '') {
                $linea = $prueba;
            } else {
                $lineas[] = $linea;
                $linea = $p;
            }
        }
        if ($linea !== '') $lineas[] = $linea;
        return $lineas;
    }
}
