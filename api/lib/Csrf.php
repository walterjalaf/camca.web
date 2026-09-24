<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Protección CSRF para las rutas que mutan estado.
 *
 * La API es same-origin, así que además del token se exige Sec-Fetch-Site.
 * Los navegadores modernos lo mandan y no se puede falsear desde JS, lo que
 * lo hace más fuerte que mirar Origin a secas.
 */
final class Csrf
{
    public static function verificar(): void
    {
        $sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($sitio !== null && !in_array($sitio, ['same-origin', 'none'], true)) {
            throw new ErrorProhibido('Petición de origen cruzado.');
        }

        $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origen !== '') {
            $permitidos = Config::get('origenes', []);
            if ($permitidos && !in_array($origen, $permitidos, true)) {
                throw new ErrorProhibido('Origen no permitido.');
            }
        }

        // El token sólo se exige a clientes con cookie de sesión. La app de
        // campo autentica con Bearer, que no viaja solo en un ataque CSRF.
        if (!empty($_COOKIE['camca_sid'])) {
            $enviado  = $_SERVER['HTTP_X_CAMCA_CSRF'] ?? '';
            $esperado = Sesion::tokenCsrf();
            if ($esperado === null || $enviado === '' || !hash_equals($esperado, $enviado)) {
                throw new ErrorProhibido('Token CSRF inválido.');
            }
        }
    }
}
