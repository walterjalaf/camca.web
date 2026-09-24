<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Cliente de Wialon del lado del SERVIDOR.
 *
 * POR QUE ACA Y NO EN EL NAVEGADOR.
 * Los prototipos hablaban con Wialon desde el telefono y tenian que pelear con
 * CORS: uno cargaba el SDK oficial (que lo sortea con un iframe puente a
 * /post.html) y el otro mandaba todo por un proxy de Cloudflare. Desde PHP el
 * problema no existe: CORS es una politica que impone el NAVEGADOR, y curl no
 * es un navegador. Se llama directo a la API.
 *
 * Y sobre todo: el token de Wialon da acceso a TODA la flota. En los
 * prototipos vivia en sessionStorage, es decir al alcance de cualquiera con el
 * telefono en la mano o de cualquier script inyectado. Aca vive en
 * camca_priv/config.php, fuera de public_html, y el telefono nunca lo ve.
 *
 * El rastro de flota es la SEGUNDA FUENTE DE EVIDENCIA, independiente del
 * telefono: es lo que permite reconstruir una jornada si el celular se rompe,
 * y es la fuente autoritativa del arribo, porque no depende de que la pantalla
 * este encendida ni de cuanta bateria quede.
 */
final class WialonClient
{
    private const TIMEOUT = 25;

    private string $host;
    private ?string $sid = null;

    public function __construct()
    {
        $this->host = rtrim((string) Config::get('wialon.host', 'https://hst-api.wialon.us'), '/');
    }

    /** Llamada cruda a la API. */
    private function llamar(string $svc, array $params, bool $conSid = true): array
    {
        $qs = ['svc' => $svc, 'params' => json_encode($params, JSON_UNESCAPED_UNICODE)];
        if ($conSid && $this->sid !== null) $qs['sid'] = $this->sid;

        $ch = curl_init($this->host . '/wialon/ajax.html?' . http_build_query($qs));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'CAMCA-Plataforma/1.0',
        ]);
        $cuerpo = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false) {
            throw new RuntimeException('Wialon no respondio: ' . $err);
        }
        $datos = json_decode((string) $cuerpo, true);
        if (!is_array($datos)) {
            throw new RuntimeException('Wialon devolvio algo que no es JSON.');
        }
        // Wialon devuelve {"error":N} con HTTP 200. Hay que mirar el cuerpo.
        if (isset($datos['error']) && (int) $datos['error'] !== 0) {
            throw new ErrorWialon((int) $datos['error']);
        }
        return $datos;
    }

    /**
     * Inicia sesion. Reusa el sid guardado mientras siga vivo: Wialon limita
     * la cantidad de logins por hora, y con un cron cada 2 minutos hacer login
     * en cada corrida agota la cuota y termina bloqueando la cuenta.
     */
    public function conectar(): void
    {
        // ultimo_error entra en el SELECT para que el mensaje de espera diga la
        // CAUSA ("token vencido") y no un inutil "en espera por un error anterior".
        $estado = Db::una('SELECT sid, sid_utc, backoff_hasta, ultimo_error FROM gps_estado WHERE id = 1');

        if ($estado !== null && $estado['backoff_hasta'] !== null
            && strtotime((string) $estado['backoff_hasta']) > time()) {
            throw new EsperaWialon((string) ($estado['ultimo_error'] ?? 'sin detalle'));
        }

        // El sid de Wialon caduca por inactividad (~5 min). Se reusa si es fresco.
        if ($estado !== null && $estado['sid'] !== null && $estado['sid_utc'] !== null
            && strtotime((string) $estado['sid_utc']) > time() - 240) {
            $this->sid = (string) $estado['sid'];
            try {
                $this->llamar('core/get_account_data', ['type' => 1]);
                return;
            } catch (Throwable) {
                $this->sid = null;   // caducado: se vuelve a loguear
            }
        }

        $token = (string) Config::get('wialon.token');
        if ($token === '') {
            throw new RuntimeException('No hay token de Wialon configurado.');
        }

        $res = $this->llamar('token/login', ['token' => $token, 'operateAs' => ''], false);
        $this->sid = $res['eid'] ?? null;
        if ($this->sid === null) {
            throw new RuntimeException('Wialon no devolvio sid.');
        }

        Db::q(
            'INSERT INTO gps_estado (id, sid, sid_utc, ultimo_ok_utc, ultimo_error, backoff_hasta)
             VALUES (1, :s, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, NULL)
             ON DUPLICATE KEY UPDATE sid = VALUES(sid), sid_utc = VALUES(sid_utc),
                                     ultimo_ok_utc = VALUES(ultimo_ok_utc),
                                     ultimo_error = NULL, backoff_hasta = NULL',
            [':s' => $this->sid]
        );
    }

    public function desconectar(): void
    {
        if ($this->sid === null) return;
        try { $this->llamar('core/logout', []); } catch (Throwable) {}
        $this->sid = null;
    }

    /**
     * Unidades de la cuenta.
     *
     * TODAS las unidades de la cuenta son de CAMCA: figuran bajo el nombre
     * FRAM, que es la otra razon social de la misma empresa. No hace falta
     * filtrar por cuenta ni por cliente.
     *
     * El unico filtro es el de los gemelos de camara ("AF190AB  VIDEO",
     * "AG387MF - VIDEO"): el prototipo ya habia descubierto que cada patente
     * tiene un duplicado con nombre irregular, y que hay que filtrar por
     * PALABRA y no por separador.
     */
    public function unidades(): array
    {
        $flags = 1 | 1024 | 4096 | 8192;   // base | ultimo mensaje | sensores | contadores
        $res = $this->llamar('core/search_items', [
            'spec' => [
                'itemsType'     => 'avl_unit',
                'propName'      => 'sys_name',
                'propValueMask' => '*',
                'sortType'      => 'sys_name',
            ],
            'force' => 1, 'flags' => $flags, 'from' => 0, 'to' => 0,
        ]);

        $items = $res['items'] ?? [];
        return array_values(array_filter($items, static function ($u) {
            return preg_match('/\bvideo\b/i', (string) ($u['nm'] ?? '')) !== 1;
        }));
    }

    /**
     * Mensajes de un intervalo. Un dia son ~1900 mensajes por unidad, asi que
     * esto NO se llama en cada corrida del poll: solo una vez por noche para
     * calcular los km y el rastro del dia.
     */
    public function mensajes(int $unidadId, int $desde, int $hasta): array
    {
        $res = $this->llamar('messages/load_interval', [
            'itemId'    => $unidadId,
            'timeFrom'  => $desde,
            'timeTo'    => $hasta,
            'flags'     => 0,
            'flagsMask' => 0,
            'loadCount' => 4294967295,
        ]);
        return $res['messages'] ?? [];
    }

    public function liberarMensajes(): void
    {
        try { $this->llamar('messages/unload', []); } catch (Throwable) {}
    }

    /** Anota el fallo y, si el token esta vencido, entra en espera larga. */
    public static function registrarFallo(Throwable $e): void
    {
        // Estar en espera no es un fallo nuevo: si se registrara, pisaria la
        // causa original ("token vencido") con "en espera", y al dia siguiente
        // nadie sabria por que dejo de andar el rastreo.
        if ($e instanceof EsperaWialon) return;

        // 7 = acceso denegado, 8 = token invalido o vencido. Reintentar cada 2
        // minutos contra un token vencido solo llena el log: se espera 6 horas
        // y se avisa. La app de campo no se entera ni le importa, porque corre
        // con el GPS del propio telefono.
        $esToken = $e instanceof ErrorWialon && in_array($e->codigo, [7, 8], true);
        $espera = $esToken ? 6 * 3600 : 900;

        Db::q(
            'INSERT INTO gps_estado (id, ultimo_error, backoff_hasta)
             VALUES (1, :e, UTC_TIMESTAMP() + INTERVAL :s SECOND)
             ON DUPLICATE KEY UPDATE ultimo_error = VALUES(ultimo_error),
                                     backoff_hasta = VALUES(backoff_hasta)',
            [':e' => mb_substr($e->getMessage(), 0, 255), ':s' => $espera]
        );
    }
}

/** El enlace esta en espera por un fallo anterior. No es un fallo nuevo. */
final class EsperaWialon extends RuntimeException
{
    public function __construct(public readonly string $causa)
    {
        parent::__construct('En espera por un error anterior: ' . $causa);
    }
}

/** Error devuelto por Wialon en el cuerpo, con HTTP 200. */
final class ErrorWialon extends RuntimeException
{
    private const TEXTOS = [
        1 => 'sesion invalida',
        4 => 'parametros invalidos',
        7 => 'acceso denegado',
        8 => 'token invalido o vencido',
        1003 => 'solo un login por usuario permitido',
    ];

    public function __construct(public readonly int $codigo)
    {
        parent::__construct('Wialon error ' . $codigo . ': ' . (self::TEXTOS[$codigo] ?? 'desconocido'));
    }
}
