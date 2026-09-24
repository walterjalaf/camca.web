<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/desvios — lo planificado contra lo ejecutado. Paso F1.6.
 *
 * Parámetros: desde, hasta (AAAA-MM-DD), ruta_id, cliente_id, tipo, severidad.
 *
 * El rango por defecto es la semana en curso y no el día: una ruta semanal se
 * mira por semana, y un desvío de un martes se entiende comparándolo con el
 * resto de la semana, no solo.
 */

$hoy = gmdate('Y-m-d', time() + Desvios::ART_SEGUNDOS);
$desde = $_GET['desde'] ?? gmdate('Y-m-d', strtotime($hoy . ' -6 days'));
$hasta = $_GET['hasta'] ?? $hoy;

$filtros = [];
if (isset($_GET['ruta_id']) && ctype_digit((string) $_GET['ruta_id'])) {
    $filtros['ruta_id'] = (int) $_GET['ruta_id'];
}
if (isset($_GET['cliente_id']) && ctype_digit((string) $_GET['cliente_id'])) {
    $filtros['cliente_id'] = (int) $_GET['cliente_id'];
}

// Un rango largo puede traer miles de filas y este endpoint lo consulta una
// persona mirando una pantalla: se acota y se dice que se acotó.
foreach ([$desde, $hasta] as $f) {
    if (!Desvios::fechaValida($f)) {
        throw new ErrorValidacion(['fecha' => 'Fecha inválida. Formato esperado AAAA-MM-DD.']);
    }
}
if (strtotime((string) $hasta) - strtotime((string) $desde) > 92 * 86400) {
    throw new ErrorValidacion(['hasta' => 'El rango no puede pasar de 92 días.']);
}

$r = Desvios::entre((string) $desde, (string) $hasta, $filtros);

// Filtros de presentación: se aplican DESPUÉS de resumir, para que el resumen
// siga diciendo cuántos hay en total y no cuántos quedaron tras filtrar.
$tipo = $_GET['tipo'] ?? null;
$sev  = $_GET['severidad'] ?? null;
if ($tipo !== null || $sev !== null) {
    $r['desvios'] = array_values(array_filter($r['desvios'], static function ($d) use ($tipo, $sev) {
        if ($tipo !== null && $d['tipo'] !== $tipo) return false;
        if ($sev !== null && $d['severidad'] !== $sev) return false;
        return true;
    }));
    $r['filtrado'] = ['tipo' => $tipo, 'severidad' => $sev];
}

$r['tipos'] = Desvios::TIPOS;

Http::ok($r);
