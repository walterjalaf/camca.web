<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Remitos digitales. Paso F2.1.
 *
 *   POST /api/v1/remito                  emite el remito de una parada verificada
 *   POST /api/v1/remito?accion=anular    anula, con motivo obligatorio
 *   GET  /api/v1/remito/{id}             JSON, HTML imprimible (?formato=html) o PDF
 *
 * Como el R28, el remito se dibuja desde lo CONGELADO: impreso hoy o dentro de
 * dos años, es el mismo papel.
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'POST') {
    $datos = Http::cuerpo();

    if (($_GET['accion'] ?? '') === 'anular') {
        $v = new Validar($datos);
        $id = $v->entero('remito_id', 1, PHP_INT_MAX);
        $motivo = $v->texto('motivo', 3, 255);
        $v->fin();

        Remito::anular($id, $motivo, Policy::id());
        Http::ok(['anulado' => $id]);
    }

    $v = new Validar($datos);
    $paradaId = $v->entero('parada_id', 1, PHP_INT_MAX);
    $v->fin();

    Http::creado(Remito::emitir($paradaId, Policy::id()));
}

$partes = explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/'));
$id = (int) end($partes);
if ($id <= 0) throw new ErrorNoEncontrado('Falta el número de remito.');

$formato = (string) ($_GET['formato'] ?? 'json');

if ($formato === 'html') {
    $html = Remito::html($id);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    echo $html;
    exit;
}

if ($formato === 'pdf') {
    $r = Remito::leer($id);
    $pdf = Remito::pdf($id);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $r['numero']) . '.pdf"');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    echo $pdf;
    exit;
}

Http::ok(Remito::leer($id));
