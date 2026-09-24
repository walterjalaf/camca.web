<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Propuesta de facturación. Paso F3.3.
 *
 *   GET  /api/v1/facturacion[?cliente_id=]                    propuestas y lo que espera facturarse
 *   GET  /api/v1/facturacion/previa?cliente_id=&desde=&hasta=  lo que saldría, sin guardar
 *   GET  /api/v1/facturacion/{id}                             una propuesta con sus líneas
 *   POST /api/v1/facturacion                                  la arma {cliente_id, desde, hasta}
 *   POST /api/v1/facturacion/descartar                        descarta un borrador {id, motivo}
 *   POST /api/v1/facturacion/aprobar                          aprueba un borrador {id} (F3.4)
 *   POST /api/v1/facturacion/quitar                           saca una línea de un borrador {id, orden, motivo}
 *   GET  /api/v1/facturacion/exportar?desde=&hasta=&tipo=     CSV contable de lo aprobado (F3.5)
 *   POST /api/v1/facturacion/ajuste                           NC o ND sobre una aprobada {propuesta_id, tipo, motivo, lineas}
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$ultimo = rawurldecode((string) basename($ruta));

if ($metodo === 'GET') {
    if ($ultimo === 'previa') {
        $v = new Validar($_GET);
        $cliente = $v->entero('cliente_id', 1, PHP_INT_MAX);
        $desde = $v->texto('desde', 10, 10);
        $hasta = $v->texto('hasta', 10, 10);
        $v->fin();
        Http::ok(Facturacion::previsualizar($cliente, $desde, $hasta));
    }
    if ($ultimo === 'exportar') {
        $v = new Validar($_GET);
        $desde = $v->texto('desde', 10, 10);
        $hasta = $v->texto('hasta', 10, 10);
        $que = $v->texto('tipo', 1, 20, false) ?? 'comprobantes';
        $v->fin();
        if (!in_array($que, ['comprobantes', 'lineas'], true)) {
            throw new ErrorValidacion(['tipo' => 'Es «comprobantes» o «lineas».']);
        }
        if ($que === 'comprobantes') {
            $filas = Exportacion::comprobantes($desde, $hasta);
            $csv = Exportacion::csv(Exportacion::COMPROBANTES, $filas, ['neto', 'iva_alicuota', 'iva', 'total'], ['neto', 'iva', 'total'],
                static fn($f) => $f['tipo'] === 'NC' ? -1 : 1);
        } else {
            $filas = Exportacion::lineas($desde, $hasta);
            // La fila de control resta las líneas de las NC, igual que la de
            // comprobantes: sin esto, las dos planillas del mismo período
            // daban totales distintos (revisión de la Fase 3).
            $csv = Exportacion::csv(Exportacion::LINEAS, $filas, ['unitario', 'neto'], ['neto'],
                static fn($f) => str_starts_with((string) $f['numero'], 'NC-') ? -1 : 1);
        }
        // Qué se exportó, cuándo y quién, con la huella del archivo: si
        // después la contabilidad no cuadra, se sabe qué planilla recibió.
        Hash::auditar('exportacion', null, 'contable', [
            'tipo' => $que, 'desde' => $desde, 'hasta' => $hasta, 'filas' => count($filas), 'sha' => hash('sha256', $csv),
        ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Length: ' . strlen($csv));
        header('Content-Disposition: attachment; filename="camca-' . $que . '-' . $desde . '-a-' . $hasta . '.csv"');
        header('Cache-Control: no-store, private');
        echo $csv;
        exit;
    }
    if (ctype_digit($ultimo)) {
        Http::ok(Facturacion::leer((int) $ultimo));
    }
    $cliente = isset($_GET['cliente_id']) && ctype_digit((string) $_GET['cliente_id']) ? (int) $_GET['cliente_id'] : null;
    Http::ok([
        'propuestas'   => Facturacion::listar($cliente),
        'por_facturar' => Facturacion::porFacturar(),
        'iva_alicuota_pb' => Facturacion::alicuota(),
    ]);
}

$datos = Http::cuerpo();
if ($ultimo === 'descartar') {
    $v = new Validar($datos);
    $id = $v->entero('id', 1, PHP_INT_MAX);
    $motivo = $v->texto('motivo', 1, 255);
    $v->fin();
    Http::ok(Facturacion::descartar($id, $motivo, Policy::id()));
}

if ($ultimo === 'aprobar') {
    $v = new Validar($datos);
    $id = $v->entero('id', 1, PHP_INT_MAX);
    $v->fin();
    Http::ok(Facturacion::aprobar($id, Policy::id()));
}
if ($ultimo === 'quitar') {
    $v = new Validar($datos);
    $id = $v->entero('id', 1, PHP_INT_MAX);
    $orden = $v->entero('orden', 1, 100000);
    $motivo = $v->texto('motivo', 1, 255);
    $v->fin();
    Http::ok(Facturacion::quitarLinea($id, $orden, $motivo, Policy::id()));
}
if ($ultimo === 'ajuste') {
    $lineas = is_array($datos['lineas'] ?? null) ? $datos['lineas'] : [];
    unset($datos['lineas']);
    $v = new Validar($datos);
    $id = $v->entero('propuesta_id', 1, PHP_INT_MAX);
    $tipo = $v->texto('tipo', 1, 10);
    $motivo = $v->texto('motivo', 1, 255);
    $v->fin();
    Http::creado(Facturacion::ajustar($id, $tipo, $motivo, $lineas, Policy::id()));
}

$v = new Validar($datos);
$cliente = $v->entero('cliente_id', 1, PHP_INT_MAX);
$desde = $v->texto('desde', 10, 10);
$hasta = $v->texto('hasta', 10, 10);
$v->fin();
Http::creado(Facturacion::crear($cliente, $desde, $hasta, Policy::id()));
