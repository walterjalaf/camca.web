<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Portal del cliente. Paso F3.10.
 *
 *   GET /api/v1/portal                          sus remitos, cuáles están certificados, y sus desvíos
 *   GET /api/v1/portal/remito/{id}?formato=     un remito suyo, en HTML o PDF
 *
 * AISLAMIENTO. Todo se filtra por el cliente de la SESIÓN, nunca por un
 * parámetro del pedido. Un remito de otro cliente contesta exactamente lo
 * mismo que uno que no existe (404, mismo cuerpo): si contestara 403, el
 * cliente podría recorrer números y saber cuántos remitos emite CAMCA y
 * cuáles son de otros. Las rutas de la oficina también le dan 404 (Policy).
 *
 * Lo del cliente incluye lo de los clientes que se fusionaron en él (F3.1):
 * los remitos conservan el cliente con que se emitieron.
 */

$u = Policy::usuario();
$clienteId = (int) Db::col('SELECT cliente_id FROM usuario WHERE id = :u', [':u' => $u['id']]);
if ($clienteId === 0) throw new ErrorNoEncontrado('Ruta no encontrada.');
$ids = Facturacion::clientesDe($clienteId);
$marcas = implode(',', array_map('intval', $ids));

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (preg_match('#/portal/remito/(\d+)$#', $ruta, $m)) {
    $id = (int) $m[1];
    // La misma respuesta para «no existe» y «no es tuyo».
    $propio = Db::col("SELECT id FROM remito WHERE id = :i AND cliente_id IN ($marcas)", [':i' => $id]);
    if ($propio === null) throw new ErrorNoEncontrado('No encontrado.');
    $formato = (string) ($_GET['formato'] ?? 'html');
    if ($formato === 'pdf') {
        $r = Remito::leer($id);
        $pdf = Remito::pdf($id);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $r['numero']) . '.pdf"');
    } else {
        $pdf = Remito::html($id);
        header('Content-Type: text/html; charset=utf-8');
    }
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    Hash::auditar('portal', $id, 'remito_visto', ['usuario' => $u['id'], 'formato' => $formato]);
    echo $pdf;
    exit;
}

$cliente = Db::una('SELECT nombre, razon_social FROM cliente WHERE id = :c', [':c' => $clienteId]);
$remitos = array_map(static fn($r) => [
    'id' => (int) $r['id'], 'numero' => $r['numero'], 'fecha' => $r['fecha'], 'estado' => $r['estado'],
    'conformidad' => $r['conformidad'], 'sitio' => $r['sitio'],
    'certificado' => in_array($r['flujo'], ['certificado', 'facturable', 'facturado'], true) && $r['estado'] === 'emitido',
    'codigo' => $r['codigo_verificacion'],
], Db::todas("SELECT r.id, r.numero, r.fecha, r.estado, r.conformidad, r.codigo_verificacion, s.nombre AS sitio, pe.flujo
                FROM remito r JOIN sitio s ON s.id = r.sitio_id JOIN parada_ejecucion pe ON pe.id = r.parada_id
               WHERE r.cliente_id IN ($marcas) AND r.fecha >= CURDATE() - INTERVAL 180 DAY
               ORDER BY r.fecha DESC, r.numero_seq DESC LIMIT 500"));

// Los desvíos de sus sitios en los últimos 90 días, sólo los que dicen algo
// del servicio que recibió (S15). Los de jornada (km de la ruta entera) no
// son de un cliente; «fuera de orden» es la hoja de ruta de CAMCA, no su
// servicio; y «evidencia» es el control interno sobre el chofer, que la
// oficina revisa antes de concluir nada.
$visibles = ['omitida', 'fuera_de_horario', 'cantidad_distinta'];
$hasta = gmdate('Y-m-d', time() - 3 * 3600);
$desde = gmdate('Y-m-d', time() - 3 * 3600 - 89 * 86400);
$desvios = [];
foreach ($ids as $cid) {
    foreach (Desvios::entre($desde, $hasta, ['cliente_id' => $cid])['desvios'] as $d) {
        if (!in_array($d['tipo'], $visibles, true)) continue;
        $detalle = (string) ($d['detalle'] ?? '');
        if ($d['tipo'] === 'omitida') {
            $motivo = trim((string) ($d['valores']['motivo'] ?? ''));
            $detalle = in_array($motivo, Desvios::MOTIVOS_FRECUENTES, true)
                ? 'No se hizo: ' . $motivo . '.'
                : 'No se hizo. Por el motivo, consultá con CAMCA.';
        }
        $desvios[] = ['fecha' => $d['fecha'], 'tipo' => Desvios::TIPOS[$d['tipo']] ?? $d['tipo'], 'sitio' => $d['sitio'] ?? null,
                      'detalle' => $detalle];
    }
}
usort($desvios, static fn($a, $b) => strcmp($b['fecha'], $a['fecha']));

Http::ok([
    'cliente' => $cliente['razon_social'] ?: $cliente['nombre'],
    'remitos' => $remitos,
    'desvios' => $desvios,
]);
