<?php
declare(strict_types=1);

/**
 * Arranque común de la API y de los scripts de cron.
 *
 * Orden deliberado:
 *   1. Constante de guardia  — para que ningún archivo de lib/ sea ejecutable suelto.
 *   2. Handlers de error     — ANTES de tocar Config, porque si config.php falta
 *                              queremos un 503 JSON y no un fatal con la ruta del
 *                              servidor impresa en pantalla.
 *   3. Ramificación por SAPI — en CLI el tiempo es ilimitado. Ponerle 25 s al cron
 *                              mataba el backup nocturno todas las noches.
 *   4. Centinela de mantenimiento — 503 con Retry-After mientras sube un deploy,
 *                              en vez de un 500 a medio subir.
 */

define('CAMCA_BOOT', true);
const CAMCA_ES_CLI = PHP_SAPI === 'cli';

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');   // se persiste en UTC; se muestra en ART

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (CAMCA_ES_CLI) {
    // Los crons hacen dump, cifrado y subida: no pueden tener techo de tiempo.
    set_time_limit(0);
    ini_set('max_execution_time', '0');
    ini_set('memory_limit', '256M');
} else {
    ini_set('max_execution_time', '25');   // por debajo del límite del plan
    ini_set('memory_limit', '128M');
}

require_once __DIR__ . '/Errores.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Rate.php';
require_once __DIR__ . '/Validar.php';
require_once __DIR__ . '/Sesion.php';
require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Hash.php';
// Obj. 1: circuito del trabajo y documentos R23/R28.
require_once __DIR__ . '/Trabajo.php';
require_once __DIR__ . '/Numerador.php';
require_once __DIR__ . '/Formulario.php';
require_once __DIR__ . '/Conciliador.php';
require_once __DIR__ . '/Desvios.php';
require_once __DIR__ . '/Pdf.php';
require_once __DIR__ . '/Qr.php';
require_once __DIR__ . '/Documento.php';
require_once __DIR__ . '/Firma.php';
require_once __DIR__ . '/Certificacion.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Ancla.php';
require_once __DIR__ . '/Cliente.php';
require_once __DIR__ . '/Tarifa.php';
require_once __DIR__ . '/Facturacion.php';
require_once __DIR__ . '/Exportacion.php';
require_once __DIR__ . '/Recursos.php';
require_once __DIR__ . '/Asignacion.php';
require_once __DIR__ . '/Indicadores.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Remito.php';
require_once __DIR__ . '/SgaDocumento.php';
require_once __DIR__ . '/Ambiental.php';
require_once __DIR__ . '/NoConformidad.php';
require_once __DIR__ . '/Ocr.php';
require_once __DIR__ . '/Usuarios.php';
require_once __DIR__ . '/Migrador.php';

// Versión desplegada: la escribe el postbuild. El healthcheck del pipeline
// compara este valor contra el sha del commit para saber si el deploy entró.
Http::$build = @trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'dev';

if (!CAMCA_ES_CLI) {
    set_exception_handler(static function (Throwable $e): void {
        if ($e instanceof ErrorLimite) {
            header('Retry-After: ' . $e->reintentarEn);
        }
        if ($e instanceof ErrorMantenimiento) {
            header('Retry-After: 60');
        }
        if ($e instanceof ErrorApi) {
            Log::aviso('error_api', ['codigo' => $e->codigo, 'http' => $e->http()]);
            Http::error($e->http(), $e->codigo, $e->getMessage(), $e->campos);
        }
        // No tipado: se registra entero y sale un 500 pelado con el request id.
        Log::error('excepcion', [
            'clase'   => $e::class,
            'detalle' => $e->getMessage(),
            'archivo' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        Http::error(500, 'ERROR_INTERNO', 'Error interno. Referencia: ' . Http::requestId());
    });

    set_error_handler(static function (int $nivel, string $msg, string $archivo, int $linea): bool {
        if (!(error_reporting() & $nivel)) return false;
        throw new ErrorException($msg, 0, $nivel, $archivo, $linea);
    });

    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            Log::error('fatal', ['msg' => $e['message'], 'archivo' => basename($e['file']) . ':' . $e['line']]);
            if (!headers_sent()) {
                Http::error(500, 'ERROR_INTERNO', 'Error interno. Referencia: ' . Http::requestId());
            }
        }
    });
}

/**
 * Centinela de mantenimiento: lo crea el pipeline antes del sync FTP y lo
 * borra después. Mientras existe, la API contesta 503 con Retry-After en vez
 * de 500 por archivos a medio subir. Vale también para los crons: un cron que
 * corre en medio de un deploy puede leer un .sql truncado.
 */
function camca_centinela_mantenimiento(): bool
{
    return is_file(dirname(__DIR__) . '/MANTENIMIENTO');
}
