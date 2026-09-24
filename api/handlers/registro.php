<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Registros R23 y R28. Paso F1.7.
 *
 *   POST /api/v1/registro            emite (o deja en borrador)
 *   POST /api/v1/registro/anular     anula, con motivo obligatorio
 *   GET  /api/v1/registro/{id}       JSON, HTML imprimible o PDF
 *
 * El HTML y el PDF salen del registro CONGELADO, no de las tablas vivas: un
 * documento impreso hoy y otro impreso dentro de dos años tienen que ser
 * idénticos aunque el formulario haya cambiado tres veces.
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ------------------------------------------------------------------
// Emitir / anular
// ------------------------------------------------------------------
if ($metodo === 'POST') {
    $datos = Http::cuerpo();

    if (($_GET['accion'] ?? '') === 'anular' || isset($datos['anular'])) {
        $v = new Validar($datos);
        $id = $v->entero('registro_id', 1, 4294967295);
        $motivo = $v->texto('motivo', 3, 255);
        $v->fin();

        Formulario::anular($id, $motivo, Policy::id());
        Http::ok(['anulado' => $id]);
    }

    $v = new Validar($datos);
    $tipo = $v->enum('tipo', ['R23', 'R28']);
    // PHP_INT_MAX y no 2^64-1: ese literal no entra en un int, PHP lo parsea
    // como FLOAT, y con strict_types pasarselo a Validar::entero(int $max) es
    // un TypeError. O sea que este endpoint moria con 500 en CADA emision,
    // antes de validar nada. No lo vio ninguna prueba porque todas llamaban a
    // Formulario::emitir() directo, sin pasar por el handler.
    $paradaId = $v->entero('parada_id', 1, PHP_INT_MAX);
    $v->fin();

    $estado = ($datos['borrador'] ?? false) ? 'borrador' : 'emitido';
    $manual = is_array($datos['valores'] ?? null) ? $datos['valores'] : [];

    $r = Formulario::emitir($tipo, $paradaId, $manual, Policy::id(), $estado);
    Http::creado($r);
}

// ------------------------------------------------------------------
// Leer
// ------------------------------------------------------------------
// Mismo idioma que evidencia.php: el id sale del último segmento de la ruta.
$partes = explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'));
$id = (int) end($partes);
if ($id <= 0) throw new ErrorNoEncontrado('Falta el número de registro.');

$formato = (string) ($_GET['formato'] ?? 'json');

if ($formato === 'html') {
    $html = Documento::html($id);
    header('Content-Type: text/html; charset=utf-8');
    // Se sirve para imprimir, no para indexar ni cachear: un documento en
    // caché es un documento que alguien imprime desatualizado.
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    echo $html;
    exit;
}

if ($formato === 'pdf') {
    $pdf = Documento::pdf($id);
    $r = Formulario::leer($id);
    $nombre = ($r['numero'] ?? ($r['tipo'] . '-borrador-' . $id)) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $nombre) . '"');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    echo $pdf;
    exit;
}

Http::ok(Formulario::leer($id));
