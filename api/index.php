<?php
declare(strict_types=1);

/**
 * Front controller unico de la API.
 *
 * Todo /api/** entra por aca gracias al rewrite de api-src/.htaccess, que
 * NO lleva la condicion "!-f": asi ningun .php suelto (los de cli/, por
 * ejemplo) es alcanzable directo por HTTP.
 */

require_once __DIR__ . '/lib/bootstrap.php';

// Centinela: mientras el pipeline sube archivos, 503 honesto en vez de un
// 500 por codigo a medio subir. La app de campo trata el 503 como
// "reintentar", no como "rechazado", y no toca la cola.
if (camca_centinela_mantenimiento()) {
    header('Retry-After: 90');
    Http::error(503, 'MANTENIMIENTO', 'Actualizacion en curso. Reintenta en un minuto.');
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'OPTIONS') {
    // API same-origin: no hay preflight legitimo que responder.
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

// Path relativo al directorio del front controller, robusto ante subcarpetas.
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'), '/');
$ruta = ($base !== '' && str_starts_with($uri, $base)) ? substr($uri, strlen($base)) : $uri;
$ruta = '/' . trim($ruta, '/');

// Version de API en el primer segmento: /v1/...
if (!preg_match('#^/(v[1-9][0-9]?)(/.*)?$#', $ruta, $m)) {
    Http::error(404, 'VERSION_INVALIDA', 'Falta la version de la API (ej. /api/v1/health).');
}

$version = $m[1];
$resto   = $m[2] ?? '/';

if ($version !== 'v1') {
    Http::error(404, 'VERSION_DESCONOCIDA', 'Version de API no soportada.');
}

require_once __DIR__ . '/lib/Router.php';

$rutas = require __DIR__ . '/rutas.php';

(new Router($rutas))->despachar($metodo, $resto);
