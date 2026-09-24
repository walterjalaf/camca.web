<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Router de tabla plana. Sin regex compilada ni magia: la tabla de rutas
 * es legible de una pasada y es el único lugar donde se declara, por ruta,
 * qué roles la pueden llamar y qué límite de tasa tiene.
 *
 * Aplicar la política ACÁ y no dentro del handler es lo que hace que un
 * handler no se pueda olvidar de chequear permisos.
 */
final class Router
{
    /** @param array<string,array> $rutas */
    public function __construct(private readonly array $rutas) {}

    public function despachar(string $metodo, string $ruta): never
    {
        $ruta = '/' . trim($ruta, '/');
        $metodosDelPath = [];

        // Ninguna ruta de la API lleva caracteres codificados: ids, uuid y
        // códigos son alfanuméricos. Un «%» en el camino sólo sirve para que el
        // router y el handler lean dos rutas distintas: /facturacion/%65xportar
        // no casaba con la ruta de admin, entraba por /facturacion/{id} (de
        // supervisor) y el handler, que decodifica, servía la exportación
        // contable (revisión de la Fase 3). Se contesta como a una ruta que no
        // existe, igual para todos.
        //
        // La única excepción es el espacio en /verificar/{codigo}: ese código
        // lo tipea una persona mirando el papel («DF7SX 41JQG»), la ruta es
        // pública (no hay permisos que confundir) y el handler borra los
        // espacios. Cualquier otro «%», también ahí, sigue siendo 404.
        if (str_contains($ruta, '%') && !preg_match('#^/verificar/[0-9A-Za-z-]*(%20[0-9A-Za-z-]*)+$#', $ruta)) {
            Http::error(404, 'NO_ENCONTRADO', 'Ruta no encontrada.');
        }

        foreach ($this->rutas as $patron => $def) {
            [$m, $p] = explode(' ', $patron, 2);
            $params = $this->coincide($p, $ruta);
            if ($params === null) continue;

            $metodosDelPath[] = $m;
            if ($m !== $metodo) continue;

            $this->aplicarPolitica($def, $metodo);

            $archivo = dirname(__DIR__) . '/handlers/' . $def['handler'] . '.php';
            if (!is_file($archivo)) {
                Log::error('handler_ausente', ['handler' => $def['handler']]);
                throw new ErrorMantenimiento('HANDLER_AUSENTE', 'Servicio no disponible.');
            }
            require $archivo;
            // Un handler siempre termina en Http::ok()/Http::error(). Si vuelve, es un bug.
            throw new LogicException('El handler ' . $def['handler'] . ' no emitió respuesta.');
        }

        // Con sesión de cliente, ni siquiera el 405 se contesta: confirmaría
        // que la ruta existe (F3.10). Para él es un 404 como cualquier otro.
        if ($metodosDelPath && (Sesion::actual()['rol'] ?? null) !== Policy::CLIENTE) {
            header('Allow: ' . implode(', ', array_unique($metodosDelPath)));
            Http::error(405, 'METODO_NO_PERMITIDO', 'Método no permitido para esta ruta.');
        }
        Http::error(404, 'NO_ENCONTRADO', 'Ruta no encontrada.');
    }

    /** Devuelve los params si el patrón casa, o null. Soporta {nombre}. */
    private function coincide(string $patron, string $ruta): ?array
    {
        $pp = explode('/', trim($patron, '/'));
        $rr = explode('/', trim($ruta, '/'));
        if (count($pp) !== count($rr)) return null;

        $params = [];
        foreach ($pp as $i => $seg) {
            if (str_starts_with($seg, '{') && str_ends_with($seg, '}')) {
                if ($rr[$i] === '') return null;
                $params[trim($seg, '{}')] = rawurldecode($rr[$i]);
            } elseif ($seg !== $rr[$i]) {
                return null;
            }
        }
        return $params;
    }

    private function aplicarPolitica(array $def, string $metodo): void
    {
        Policy::exigir($def['roles'] ?? [Policy::ADMIN]);

        if (!in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true) && ($def['csrf'] ?? true)) {
            Csrf::verificar();
        }

        // R4: el bucket se arma con la identidad cuando la hay; la IP es
        // sólo backstop con umbral alto, porque con CGNAT toda la cuadrilla
        // comparte una sola IP.
        foreach (($def['limite'] ?? []) as $ambito => [$maximo, $ventana]) {
            $sufijo = match ($ambito) {
                'usuario'     => 'u:' . (Policy::$usuario['id'] ?? 'anon'),
                'dispositivo' => 'd:' . (Policy::$usuario['dispositivo_id'] ?? 'none'),
                default       => 'ip:' . Http::ipHash(),
            };
            Rate::consumir($def['handler'] . '|' . $ambito . '|' . $sufijo, $maximo, $ventana);
        }
    }
}
