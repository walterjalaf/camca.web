<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * GET /api/v1/indicadores?desde=&hasta=   Dashboards de trazabilidad (F3.9).
 */

$v = new Validar($_GET);
$desde = $v->texto('desde', 10, 10);
$hasta = $v->texto('hasta', 10, 10);
$v->fin();
Http::ok(Indicadores::calcular($desde, $hasta));
