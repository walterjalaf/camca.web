<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Documentos controlados del SGA. Paso F4.1.
 *
 *   GET  /api/v1/sga/documentos                    lista, estado de revisión y mis pendientes de leer
 *   GET  /api/v1/sga/documento/{id}                historia completa de versiones y distribución
 *   POST /api/v1/sga/documento                     alta (con su v1 en borrador)
 *   POST /api/v1/sga/documento/{id}/version        abre una versión nueva en borrador
 *   POST /api/v1/sga/documento/{id}/destinatarios  fija la lista de distribución
 *   POST /api/v1/sga/documento/{id}/confirmar      revisión periódica sin cambios (admin)
 *   POST /api/v1/sga/documento/{id}/retirar        retira el documento entero (admin)
 *   POST /api/v1/sga/version/{id}/archivo          el PDF, como cuerpo crudo (sólo en borrador)
 *   GET  /api/v1/sga/version/{id}/archivo          lo baja, de cualquier versión: la historia se consulta
 *   POST /api/v1/sga/version/{id}/{accion}         enviar · devolver · aprobar · descartar · conocimiento
 */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$yo = Policy::id();
$id = isset($params['id']) ? (int) $params['id'] : 0;

if ($metodo === 'GET' && str_ends_with($ruta, '/sga/documentos')) {
    Http::ok(SgaDocumento::listar((int) $yo));
}

if ($metodo === 'GET' && preg_match('#/sga/version/\d+/archivo$#', $ruta)) {
    $a = SgaDocumento::archivo($id);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($a['ruta']));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $a['nombre']) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($a['ruta']);
    exit;
}

if ($metodo === 'GET') {
    Http::ok(SgaDocumento::detalle($id));
}

if (preg_match('#/sga/version/\d+/archivo$#', $ruta)) {
    // Cuerpo crudo: sin multipart ni base64 que inflen un 33 % lo que sube.
    $bytes = (string) file_get_contents('php://input', false, null, 0, SgaDocumento::MAX_BYTES + 1);
    $nombre = rawurldecode((string) ($_SERVER['HTTP_X_CAMCA_NOMBRE'] ?? 'documento.pdf'));
    Http::ok(SgaDocumento::subirArchivo($id, $bytes, $nombre, $yo));
}

$datos = Http::cuerpo();

if (str_ends_with($ruta, '/sga/documento')) {
    Http::creado(SgaDocumento::alta($datos, $yo));
}

if (preg_match('#/sga/documento/\d+/(version|destinatarios|confirmar|retirar)$#', $ruta, $m)) {
    $v = new Validar($datos);
    switch ($m[1]) {
        case 'version':
            $cambios = $v->texto('cambios', 3, 1000);
            $v->fin();
            Http::creado(SgaDocumento::nuevaVersion($id, $cambios, $yo));
        case 'destinatarios':
            $v->fin();
            $usuarios = $datos['usuarios'] ?? null;
            if (!is_array($usuarios) || count($usuarios) > 200 || array_filter($usuarios, static fn($x) => !is_int($x)) !== []) {
                throw new ErrorValidacion(['usuarios' => 'Lista de ids de usuario.']);
            }
            Http::ok(SgaDocumento::destinatarios($id, $usuarios, $yo));
        case 'confirmar':
            $v->fin();
            Http::ok(SgaDocumento::confirmarVigencia($id, $yo));
        case 'retirar':
            $motivo = $v->texto('motivo', 3, 255);
            $v->fin();
            Http::ok(SgaDocumento::retirar($id, $motivo, $yo));
    }
}

if (preg_match('#/sga/version/\d+/(enviar|devolver|aprobar|descartar|conocimiento)$#', $ruta, $m)) {
    $v = new Validar($datos);
    switch ($m[1]) {
        case 'enviar':
            $v->fin();
            Http::ok(SgaDocumento::enviar($id, $yo));
        case 'devolver':
        case 'descartar':
            $motivo = $v->texto('motivo', 3, 255);
            $v->fin();
            Http::ok($m[1] === 'devolver' ? SgaDocumento::devolver($id, $motivo, $yo) : SgaDocumento::descartar($id, $motivo, $yo));
        case 'aprobar':
            $v->fin();
            // Rol admin: lo controla la ruta. Acá sólo la regla de las dos personas.
            Http::ok(SgaDocumento::aprobar($id, $yo));
        case 'conocimiento':
            // Sin usuario_id: la persona misma. Con otro: la oficina registra
            // una entrega en mano (queda quién la registró).
            $para = $v->entero('usuario_id', 1, PHP_INT_MAX, false) ?? (int) $yo;
            $v->fin();
            Http::ok(SgaDocumento::tomarConocimiento($id, $para, $yo));
    }
}

throw new ErrorNoEncontrado('Ruta no encontrada.');
