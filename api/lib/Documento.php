<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Render imprimible de un R23 o R28. Obj. 1, paso F1.7.
 *
 * SE DIBUJA DESDE EL REGISTRO CONGELADO, NUNCA DESDE LAS TABLAS VIVAS.
 *
 * Toma la definición con la que se emitió —no la vigente— y los valores que
 * quedaron guardados. Por eso un documento impreso hoy y otro impreso dentro de
 * dos años salen idénticos aunque el formulario haya cambiado tres veces y
 * alguien haya corregido la jornada. Es la misma regla de F1.2 llevada hasta el
 * papel, que es donde termina importando.
 *
 * El HTML es la FUENTE ÚNICA (supuesto S5): el PDF se arma desde los mismos
 * datos, no desde otra plantilla. Dos plantillas para el mismo documento
 * divergen, y la que diverge siempre es la que nadie mira.
 */
final class Documento
{
    /** Marca de agua para lo que no es definitivo. */
    private const LEYENDA_BORRADOR = 'BORRADOR · SIN NÚMERO';
    private const LEYENDA_ANULADO  = 'ANULADO';

    /**
     * HTML completo y autocontenido: sin CSS externo, sin fuentes remotas, sin
     * JavaScript. Tiene que imprimirse igual desde una notebook sin internet.
     */
    public static function html(int $registroId): string
    {
        $r = Formulario::leer($registroId);
        $ev = self::evidencias($registroId);

        $titulo = ($r['esquema']['titulo'] ?? $r['tipo']) . ' ' . ($r['numero'] ?? '(borrador)');
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $marca = '';
        if ($r['estado'] === 'borrador') $marca = self::LEYENDA_BORRADOR;
        if ($r['estado'] === 'anulado')  $marca = self::LEYENDA_ANULADO;

        $emisor = $r['emisor'] ?? [];

        $filas = '';
        foreach ($r['esquema']['secciones'] ?? [] as $sec) {
            $campos = '';
            foreach ($sec['campos'] ?? [] as $c) {
                $clave = (string) ($c['clave'] ?? '');
                if ($clave === '') continue;

                // Un campo condicional que no aplica no se imprime vacío: una
                // fila "Motivo: —" en una parada que SÍ se hizo confunde.
                if (isset($c['solo_si'])) {
                    $ref = $r['valores'][$c['solo_si']['campo']] ?? null;
                    if ($ref !== ($c['solo_si']['igual'] ?? null)) continue;
                }

                $valor = $r['valores'][$clave] ?? null;
                // La nota explica el campo, no el valor. Impresa debajo de un
                // "No" se lee como si el caso aplicara: en la prueba salió
                // "Parada ambigua: No" seguido de "hay otra parada en la misma
                // coordenada", que dice exactamente lo contrario de la
                // respuesta. Sólo se imprime cuando hay algo que matizar.
                $conNota = isset($c['nota']) && $valor !== null && $valor !== '' && $valor !== false;
                $campos .= '<tr><th>' . $e($c['etiqueta'] ?? $clave) . '</th><td>' .
                    $e(self::mostrar($valor, (string) ($c['tipo'] ?? 'texto'), $c)) .
                    ($conNota ? '<small>' . $e($c['nota']) . '</small>' : '') .
                    '</td></tr>';
            }
            if ($campos === '') continue;
            $filas .= '<section class="bloque"><h2>' . $e($sec['titulo'] ?? '') . '</h2>' .
                      '<table class="campos">' . $campos . '</table></section>';
        }

        $evHtml = '';
        if ($ev !== []) {
            $items = '';
            foreach ($ev as $x) {
                $items .= '<li>' . $e(self::nombreEvidencia($x)) .
                    ' <code>' . $e(substr((string) ($x['sha256'] ?? ''), 0, 16)) . '</code></li>';
            }
            $evHtml = '<section class="bloque"><h2>Evidencia adjunta</h2><ul class="evidencias">' . $items .
                '</ul><p class="pie-nota">Se listan por huella SHA-256. Si un archivo se reemplaza, la huella deja de coincidir.</p></section>';
        }

        $anulacion = $r['anulado'] !== null
            ? '<section class="bloque anulacion"><h2>Anulación</h2><p>' .
              $e($r['motivo_anulacion'] ?? '') . '</p><p class="pie-nota">Anulado el ' .
              $e(self::fechaHora($r['anulado'])) . '. El número NO se reutiliza.</p></section>'
            : '';

        // Un R23 o un R28 NO se firman uno por uno: lo que se firma y se
        // certifica es el remito (F2.3). Antes esta leyenda miraba la
        // configuración y, con la firma activada, un R28 habría dicho
        // «firmado digitalmente» sin llevar firma alguna. El papel dice lo que
        // el papel es, no lo que la instalación puede hacer.
        $leyendaFirma = 'Registro interno de la plataforma. No lleva firma criptográfica: no es un certificado. ' .
            'Lo que se certifica es el remito.';

        $css = self::css();
        $logo = self::logo();

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($titulo)}</title>
<style>{$css}</style>
</head>
<body>
<article class="hoja" data-estado="{$e($r['estado'])}">
  <header class="cabeza">
    <div>
      {$logo}
      <p class="emisor">{$e($emisor['razon_social'] ?? '')}</p>
      <p class="emisor-dato">CUIT {$e($emisor['cuit'] ?? '')} · {$e($emisor['domicilio'] ?? '')}</p>
    </div>
    <div class="identificacion">
      <p class="tipo">{$e($r['esquema']['titulo'] ?? $r['tipo'])}</p>
      <p class="numero">{$e($r['numero'] ?? 'SIN NÚMERO')}</p>
      <p class="version">Formulario {$e($r['formulario']['tipo'])} v{$e($r['formulario']['version'])}</p>
    </div>
  </header>

  {$filas}
  {$evHtml}
  {$anulacion}

  <footer class="pie">
    <p>{$leyendaFirma}</p>
    <p class="pie-nota">Huella del contenido: <code>{$e(substr($r['sha'], 0, 32))}</code>
       · Emitido {$e(self::fechaHora($r['emitido']))}</p>
  </footer>
</article>
HTML
        . ($marca !== '' ? '<div class="marca">' . $e($marca) . '</div>' : '')
        . "\n</body>\n</html>\n";
    }

    /**
     * El mismo documento en PDF, armado desde los MISMOS datos congelados.
     *
     * No se convierte el HTML: se recorre el registro otra vez. Es lo que
     * evita depender de un motor de render que en Hostinger compartido no
     * existe, y lo que hace que el PDF no pueda "quedarse atrás" del HTML,
     * porque los dos salen de la misma fuente.
     */
    public static function pdf(int $registroId): string
    {
        $r = Formulario::leer($registroId);
        $ev = self::evidencias($registroId);
        $emisor = $r['emisor'] ?? [];

        $pdf = new Pdf();

        $pdf->grafico(26, 26, self::cuboPdf(26));
        $pdf->texto((string) ($emisor['razon_social'] ?? ''), 15, true);
        $pdf->texto('CUIT ' . ($emisor['cuit'] ?? '') . '  ' . ($emisor['domicilio'] ?? ''), 9);
        $pdf->textoDerecha((string) ($r['esquema']['titulo'] ?? $r['tipo']), 10);
        $pdf->textoDerecha((string) ($r['numero'] ?? 'SIN NUMERO'), 17, true);
        $pdf->textoDerecha('Formulario ' . $r['formulario']['tipo'] . ' v' . $r['formulario']['version'], 9);
        $pdf->linea(1.2, self::VERDE_PDF);

        if ($r['estado'] !== 'emitido') {
            $pdf->espacio(4);
            $pdf->texto($r['estado'] === 'anulado' ? self::LEYENDA_ANULADO : self::LEYENDA_BORRADOR, 14, true);
            $pdf->espacio(4);
        }

        foreach ($r['esquema']['secciones'] ?? [] as $sec) {
            $campos = [];
            foreach ($sec['campos'] ?? [] as $c) {
                $clave = (string) ($c['clave'] ?? '');
                if ($clave === '') continue;
                if (isset($c['solo_si'])) {
                    $ref = $r['valores'][$c['solo_si']['campo']] ?? null;
                    if ($ref !== ($c['solo_si']['igual'] ?? null)) continue;
                }
                $campos[] = [
                    (string) ($c['etiqueta'] ?? $clave),
                    self::mostrar($r['valores'][$clave] ?? null, (string) ($c['tipo'] ?? 'texto'), $c),
                ];
            }
            if ($campos === []) continue;

            $pdf->espacio(6);
            $pdf->texto(mb_strtoupper((string) ($sec['titulo'] ?? ''), 'UTF-8'), 9, true);
            $pdf->linea(0.4);
            foreach ($campos as [$et, $val]) $pdf->fila($et, $val);
        }

        if ($ev !== []) {
            $pdf->espacio(6);
            $pdf->texto('EVIDENCIA ADJUNTA', 9, true);
            $pdf->linea(0.4);
            foreach ($ev as $x) {
                $pdf->fila(self::nombreEvidencia($x), substr((string) ($x['sha256'] ?? ''), 0, 16), 9);
            }
            $pdf->texto('Se listan por huella SHA-256. Si un archivo se reemplaza, la huella deja de coincidir.', 8);
        }

        if ($r['anulado'] !== null) {
            $pdf->espacio(6);
            $pdf->texto('ANULACION', 9, true);
            $pdf->linea(0.4);
            $pdf->fila('Motivo', (string) ($r['motivo_anulacion'] ?? ''));
            $pdf->fila('Fecha', self::fechaHora($r['anulado']));
            $pdf->texto('El numero NO se reutiliza.', 8);
        }

        $pdf->espacio(10);
        $pdf->linea(0.4);
        $pdf->texto('Registro interno de la plataforma. No lleva firma criptografica: no es un certificado. ' .
                    'Lo que se certifica es el remito.', 9);
        $pdf->texto('Huella del contenido: ' . substr($r['sha'], 0, 32) .
                    '   Emitido ' . self::fechaHora($r['emitido']), 8);

        return $pdf->salida();
    }

    /** Un valor listo para leer. El formato es de lectura, no de máquina. */
    private static function mostrar(mixed $v, string $tipo, array $campo): string
    {
        if ($v === null || $v === '') return '—';
        return match ($tipo) {
            'booleano' => $v ? 'Sí' : 'No',
            'entero', 'decimal' => number_format((float) $v, $tipo === 'entero' ? 0 : 1, ',', '.') .
                                   (isset($campo['unidad']) ? ' ' . $campo['unidad'] : ''),
            'fecha' => self::fecha((string) $v),
            default => (string) $v,
        };
    }

    public static function fecha(string $iso): string
    {
        $t = strtotime($iso . ' 12:00:00 UTC');
        if ($t === false) return $iso;
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return gmdate('j', $t) . ' de ' . $meses[(int) gmdate('n', $t) - 1] . ' de ' . gmdate('Y', $t);
    }

    public static function fechaHora(?string $utc): string
    {
        if ($utc === null) return '—';
        $t = strtotime($utc . ' UTC');
        return $t === false ? '—' : gmdate('d/m/Y H:i', $t - 3 * 3600) . ' (hora de Argentina)';
    }

    private static function evidencias(int $registroId): array
    {
        return Db::todas(
            'SELECT re.sha256, e.tipo, e.creado_utc, e.bytes
               FROM registro_evidencia re
               JOIN evidencia e ON e.id = re.evidencia_id
              WHERE re.registro_id = :r
              ORDER BY e.tipo, e.id',
            [':r' => $registroId]
        );
    }

    private static function nombreEvidencia(array $x): string
    {
        $n = ['foto' => 'Fotografía', 'firma' => 'Firma del receptor', 'nota' => 'Nota'][$x['tipo']] ?? $x['tipo'];
        return $n . ' · ' . self::fechaHora($x['creado_utc']);
    }

    /**
     * CSS de impresión.
     *
     * En centímetros y no en píxeles: esto va a una hoja A4 y no a una
     * pantalla. Sin colores de fondo en los bloques, que la mitad de las
     * impresoras de oficina no imprimen y la otra mitad sacan en gris sucio.
     */
    /**
     * La marca CAMCA en SVG, dentro del documento: el cubo de tres caras y el
     * logotipo. Sin archivos ni fuentes externas, porque el documento se abre
     * como blob (no hay rutas relativas) y tiene que verse igual impreso,
     * guardado o reenviado por mail dentro de cinco años.
     */
    /** El verde bosque de la marca (#0B3B24) como color de trazo de PDF. */
    public const VERDE_PDF = '0.043 0.231 0.141';

    public static function logo(): string
    {
        return '<svg class="logo" viewBox="0 0 210 64" width="118" height="36" role="img" aria-label="CAMCA">'
            . '<polygon points="32,2 60,16 32,30 4,16" fill="#9C8D7C"/>'
            . '<polygon points="4,16 32,30 32,60 4,46" fill="#FFFFFF" stroke="#DDD8CD" stroke-width="1"/>'
            . '<polygon points="60,16 32,30 32,60 60,46" fill="#2E7D53"/>'
            . '<text x="76" y="42" font-family="Georgia, \'Times New Roman\', serif" font-size="34" letter-spacing="3" fill="#0B3B24">CAMCA</text>'
            . '</svg>';
    }

    /**
     * El mismo cubo, en operadores de PDF (origen abajo a la izquierda). El
     * logotipo va como texto al lado: el PDF no incrusta fuentes.
     */
    public static function cuboPdf(float $lado): string
    {
        $k = $lado / 60.0;
        $p = static fn(array $pts): string => implode(' ', array_map(
            static fn($i, $xy) => sprintf('%.2f %.2f %s', $xy[0] * $k, (62 - $xy[1]) * $k, $i === 0 ? 'm' : 'l'),
            array_keys($pts), $pts)) . ' h f';
        return "0.612 0.553 0.486 rg " . $p([[32, 2], [60, 16], [32, 30], [4, 16]]) . "
"
             . "0.867 0.847 0.804 rg " . $p([[4, 16], [32, 30], [32, 60], [4, 46]]) . "
"
             . "0.180 0.490 0.325 rg " . $p([[60, 16], [32, 30], [32, 60], [60, 46]]) . "
"
             . "0 g";
    }

    public static function css(): string
    {
        return <<<'CSS'
* { box-sizing: border-box; }
body {
  margin: 0; background: #f7f5f0; color: #17201b;
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
  font-family: "Helvetica Neue", Arial, sans-serif; font-size: 11pt; line-height: 1.45;
}
.hoja {
  position: relative; background: #fff; width: 21cm; min-height: 29.7cm;
  margin: 1cm auto; padding: 1.6cm 1.8cm; border: 1px solid #ddd8cd;
}
/* La cabeza cierra con la regla verde de la marca y, arriba de ella, el filete
   terracota de la cordillera: el mismo remate que la barra de la app. */
.cabeza { display: flex; justify-content: space-between; gap: 1cm; align-items: flex-start;
          border-bottom: 2px solid #0B3B24; padding-bottom: .5cm; margin-bottom: .7cm;
          background: linear-gradient(#C97D4A, #C97D4A) left bottom 3px / 100% 1px no-repeat; }
.logo { display: block; margin: 0 0 .25cm; }
.emisor { font-size: 14pt; font-weight: 700; margin: 0; color: #0B3B24; }
.emisor-dato { font-size: 9.5pt; color: #4a5852; margin: .1cm 0 0; }
.identificacion { text-align: right; }
.tipo { font-size: 10.5pt; font-weight: 600; color: #2E7D53; margin: 0; }
.numero { font-size: 18pt; font-weight: 700; margin: .1cm 0; font-variant-numeric: tabular-nums; color: #0B3B24; }
.version { font-size: 9pt; color: #4a5852; margin: 0; }

.bloque { margin-bottom: .6cm; break-inside: avoid; }
.bloque h2 { font-size: 11pt; font-weight: 700; color: #0B3B24;
             margin: 0 0 .2cm; border-bottom: 1px solid #ddd8cd; padding-bottom: .1cm; }
table.campos { width: 100%; border-collapse: collapse; }
table.campos th { width: 6.5cm; text-align: left; font-weight: 600; color: #4a5852;
                  padding: .13cm .3cm .13cm 0; vertical-align: top; font-size: 10pt; }
table.campos td { padding: .13cm 0; vertical-align: top; }
table.campos small { display: block; color: #6b7770; font-size: 8.5pt; margin-top: .05cm; }

.evidencias { margin: 0; padding-left: .6cm; }
.evidencias li { margin-bottom: .1cm; }
code { font-family: "Courier New", monospace; font-size: 9pt; color: #4a5852; }

.anulacion h2 { color: #8c241c; border-bottom-color: #8c241c; }

.pie { border-top: 1px solid #ddd8cd; padding-top: .35cm; margin-top: .8cm; }
.pie p { margin: 0 0 .1cm; }
.pie-nota { font-size: 9pt; color: #4a5852; }

/* La marca de borrador o anulado atraviesa la hoja: tiene que ser imposible
   confundir una copia de trabajo con el documento bueno. */
.marca {
  position: fixed; top: 45%; left: 0; right: 0; text-align: center;
  font-size: 48pt; font-weight: 700; letter-spacing: .1em;
  color: rgba(140, 36, 28, .16); transform: rotate(-22deg);
  pointer-events: none; z-index: 10;
}

@page { size: A4; margin: 1.4cm; }
@media print {
  body { background: #fff; }
  .hoja { width: auto; min-height: 0; margin: 0; padding: 0; border: 0; }
  .marca { position: fixed; }
}
CSS;
    }
}
