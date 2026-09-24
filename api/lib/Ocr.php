<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Piloto de OCR de remitos en papel. Obj. 5, paso F4.4.
 *
 * Foto → la API de Claude la transcribe del lado del servidor → BORRADOR →
 * una persona la valida viendo la foto → registro. La clave de la API vive
 * en camca_priv/config.php (`ocr.anthropic_api_key`) y nunca sale del
 * servidor: el navegador sube la foto a /api/ y ve el borrador, nada más.
 *
 * Se habla con la API por HTTP (curl) y no con el SDK de PHP: el hosting
 * compartido no tiene Composer (S5), y es el mismo camino que ya usa Wialon.
 *
 * NADA SE AUTO-CONFIRMA. procesar() sólo deja «borrador» o «error»; la única
 * función que escribe en `remito_papel` es validar(), y la llama una persona
 * con sesión. Si el modelo no está configurado o falla, la persona transcribe
 * a mano sobre el mismo formulario: el piloto nunca frena la carga.
 */
final class Ocr
{
    /** Límite de la API para una imagen. Una foto de celular de un A5 entra holgada. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const CAMPOS = ['numero', 'fecha', 'cliente', 'sitio', 'servicio', 'cantidad', 'receptor', 'firmado', 'observaciones'];
    public const SERVICIOS = ['limpieza' => 'Limpieza y mantenimiento', 'entrega' => 'Entrega de unidades', 'retiro' => 'Retiro de unidades',
                              'desagote' => 'Desagote', 'otro' => 'Otro'];
    /**
     * USD por millón de tokens [entrada, salida]. Tabla de precios de la API
     * de Anthropic (junio de 2026). Se puede pisar con `ocr.precios`.
     */
    public const PRECIOS = [
        'claude-opus-5' => [5.00, 25.00], 'claude-opus-5-5' => [4.00, 20.00], 'claude-opus-4-8' => [5.00, 25.00],
        'claude-sonnet-5' => [2.00, 10.00], 'claude-haiku-4-5' => [1.00, 5.00], 'claude-fable-5-1' => [10.00, 50.00],
    ];
    private const FIRMAS = ["\xFF\xD8\xFF" => ['image/jpeg', 'jpg'], "\x89PNG\r\n\x1A\n" => ['image/png', 'png']];
    private const URL_API = 'https://api.anthropic.com/v1/messages';
    private const SISTEMA = <<<'TXT'
Transcribís remitos de servicio en papel de CAMCA, una empresa de baños químicos de San Juan (Argentina). Cada remito es un formulario preimpreso completado a mano.

Devolvé lo que está ESCRITO en el papel, no lo que te parece razonable:
- numero: el número del remito tal como está impreso o escrito (por ejemplo «0001-00004512»).
- fecha: la fecha del servicio en formato AAAA-MM-DD. En Argentina se escribe día/mes/año; un año de dos cifras es del 2000.
- cliente: el nombre o razón social del cliente.
- sitio: la dirección, obra o lugar del servicio.
- servicio: el tipo de servicio marcado o escrito (limpieza, entrega, retiro, desagote u otro).
- cantidad: cuántos baños o unidades, como número entero.
- receptor: la aclaración de quien recibió (el nombre escrito, no la firma).
- firmado: true si en el recuadro de firma hay una firma manuscrita, false si está vacío.
- observaciones: lo escrito en observaciones, o null si no hay nada.

Si un campo no está, está tachado o no se puede leer con seguridad, poné null y agregalo a «dudosos». Si lo leíste pero no estás seguro de una letra o un número, poné tu mejor lectura y agregalo a «dudosos». Nunca completes un dato que no está en el papel.
TXT;

    /** Tope de gasto en la API por día, en dólares (revisión de la Fase 4). */
    public const TOPE_DIARIO_USD = 5.0;
    /** Una foto «procesando» desde hace más que esto se da por trabada y se puede reintentar. */
    public const TRABADA_MIN = 10;

    public static function hoy(): string { return gmdate('Y-m-d', time() - 3 * 3600); }
    public static function modelo(): string { return (string) Config::get('ocr.modelo', 'claude-opus-5'); }
    public static function configurado(): bool { return self::clave() !== ''; }

    private static function clave(): string
    {
        return trim((string) (Config::get('ocr.anthropic_api_key', '') ?: (getenv('ANTHROPIC_API_KEY') ?: '')));
    }

    /** El esquema que la API tiene que respetar (salida estructurada). */
    public static function esquema(): array
    {
        $nulable = static fn(array $t) => ['anyOf' => [$t, ['type' => 'null']]];
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [...self::CAMPOS, 'dudosos'],
            'properties' => [
                'numero' => $nulable(['type' => 'string']),
                'fecha' => $nulable(['type' => 'string', 'format' => 'date']),
                'cliente' => $nulable(['type' => 'string']),
                'sitio' => $nulable(['type' => 'string']),
                'servicio' => $nulable(['type' => 'string', 'enum' => array_keys(self::SERVICIOS)]),
                'cantidad' => $nulable(['type' => 'integer']),
                'receptor' => $nulable(['type' => 'string']),
                'firmado' => $nulable(['type' => 'boolean']),
                'observaciones' => $nulable(['type' => 'string']),
                'dudosos' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::CAMPOS]],
            ],
        ];
    }

    /** Costo en millonésimas de dólar, con el precio del modelo que respondió. */
    public static function costoMicros(string $modelo, int $entrada, int $salida, int $cacheEscrita = 0, int $cacheLeida = 0): int
    {
        $precios = (array) Config::get('ocr.precios', []) + self::PRECIOS;
        [$pin, $pout] = $precios[$modelo] ?? $precios[self::modelo()] ?? [5.00, 25.00];
        return (int) round($entrada * $pin + $salida * $pout + $cacheEscrita * $pin * 1.25 + $cacheLeida * $pin * 0.1);
    }

    /**
     * Le pide a la API la transcripción de una foto. No toca la base.
     *
     * @return array{campos: array, dudosos: list<string>, modelo: string, tokens_entrada: int, tokens_salida: int,
     *               costo_usd_micros: int, duracion_ms: int}
     */
    public static function extraer(string $bytes, string $mime): array
    {
        $clave = self::clave();
        if ($clave === '') throw new RuntimeException('OCR sin configurar: falta ocr.anthropic_api_key en camca_priv/config.php.');
        $modelo = self::modelo();
        $cuerpo = [
            'model' => $modelo,
            // Diez campos de JSON y el razonamiento adaptativo entran holgados;
            // el tope acota lo que puede costar una sola foto (unos 0,10 USD).
            'max_tokens' => 4000,
            'system' => self::SISTEMA,
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]],
                    ['type' => 'text', 'text' => 'Transcribí este remito.'],
                ],
            ]],
        ];
        $esfuerzo = Config::get('ocr.effort', null);
        if (is_string($esfuerzo) && $esfuerzo !== '') $cuerpo['output_config']['effort'] = $esfuerzo;
        $cabeceras = ['Content-Type: application/json', 'x-api-key: ' . $clave, 'anthropic-version: 2023-06-01'];
        // Si los clasificadores de seguridad rechazan una foto, la API la
        // reintenta sola en el modelo que corresponda en vez de devolver el
        // rechazo (fallbacks «default»). Se puede apagar con ocr.fallbacks.
        if (Config::get('ocr.fallbacks', true)) {
            $cuerpo['fallbacks'] = 'default';
            $cabeceras[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $inicio = microtime(true);
        $resp = null;
        // Dos intentos de 70 s como mucho: la pantalla espera 180 s. Con más,
        // el navegador se cansaba antes, la persona volvía a subir la foto y
        // se pagaba dos veces.
        for ($intento = 0; $intento < 2; $intento++) {
            if ($intento > 0) usleep((int) (1000000 * 2 ** $intento * (0.5 + mt_rand() / mt_getrandmax())));
            $ch = curl_init((string) Config::get('ocr.url', self::URL_API));
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $cabeceras,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 70, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'CAMCA-Plataforma/1.0',
            ]);
            $texto = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($texto === false) { $resp = ['http' => 0, 'error' => 'sin conexión con la API: ' . $err]; continue; }
            $resp = ['http' => $http, 'datos' => json_decode((string) $texto, true)];
            // 429, 5xx y 529 (sobrecarga) se reintentan; el resto es la respuesta.
            if ($http !== 429 && $http < 500) break;
        }
        $ms = (int) round((microtime(true) - $inicio) * 1000);
        $d = $resp['datos'] ?? null;
        if (($resp['http'] ?? 0) !== 200 || !is_array($d)) {
            $msg = is_array($d) ? (string) ($d['error']['message'] ?? 'respuesta inesperada') : (string) ($resp['error'] ?? 'respuesta inesperada');
            throw new RuntimeException('La API respondió ' . ($resp['http'] ?? 0) . ': ' . mb_substr($msg, 0, 300));
        }
        $servido = (string) ($d['model'] ?? $modelo);
        $u = (array) ($d['usage'] ?? []);
        $base = [
            'modelo' => $servido, 'tokens_entrada' => (int) ($u['input_tokens'] ?? 0), 'tokens_salida' => (int) ($u['output_tokens'] ?? 0),
            'costo_usd_micros' => self::costoMicros($servido, (int) ($u['input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0),
                                                    (int) ($u['cache_creation_input_tokens'] ?? 0), (int) ($u['cache_read_input_tokens'] ?? 0)),
            'duracion_ms' => $ms,
        ];
        $stop = (string) ($d['stop_reason'] ?? '');
        if ($stop === 'refusal') throw new ErrorOcr('El modelo no procesó la foto (rechazo de seguridad).', $base);
        if ($stop === 'max_tokens') throw new ErrorOcr('La respuesta del modelo quedó cortada.', $base);
        $salida = null;
        foreach ((array) ($d['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') { $salida = json_decode((string) $b['text'], true); break; }
        }
        if (!is_array($salida)) throw new ErrorOcr('El modelo no devolvió la transcripción en el formato pedido.', $base);
        $campos = [];
        foreach (self::CAMPOS as $c) $campos[$c] = $salida[$c] ?? null;
        $dudosos = array_values(array_intersect(self::CAMPOS, (array) ($salida['dudosos'] ?? [])));
        return ['campos' => $campos, 'dudosos' => $dudosos] + $base;
    }

    // ------------------------------------------------------------------
    // Circuito
    // ------------------------------------------------------------------

    /** Guarda la foto y la procesa. Devuelve la ficha con el borrador (o el error). */
    public static function subir(string $bytes, string $nombre, ?int $usuarioId): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ErrorValidacion(['archivo' => 'La foto tiene que pesar entre 1 byte y 5 MB.']);
        }
        $tipo = null;
        foreach (self::FIRMAS as $firma => $t) if (str_starts_with($bytes, $firma)) { $tipo = $t; break; }
        if ($tipo === null) throw new ErrorValidacion(['archivo' => 'Tiene que ser una foto JPG o PNG.']);
        $sha = hash('sha256', $bytes);
        // La misma foto otra vez no se vuelve a leer (ni a pagar): se devuelve
        // la que ya estaba, salvo que se haya descartado.
        $previa = Db::col("SELECT id FROM ocr_remito WHERE archivo_sha256 = :s AND estado <> 'descartado' ORDER BY id DESC LIMIT 1", [':s' => $sha]);
        if ($previa !== null && $previa !== false) return self::detalle((int) $previa) + ['repetida' => true];
        $ruta = self::rutaImagen($sha, $tipo[1]);
        if (!is_file($ruta)) {
            @mkdir(dirname($ruta), 0750, true);
            $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $ruta)) {
                @unlink($tmp);
                throw new RuntimeException('No se pudo guardar la foto.');
            }
        }
        $nombre = mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', $nombre) ?? '') ?: 'remito', -160);
        $id = Db::tx(static function () use ($sha, $tipo, $bytes, $nombre, $usuarioId): int {
            Db::q("INSERT INTO ocr_remito (archivo_sha256, archivo_mime, archivo_bytes, archivo_nombre, estado, subido_por, subido_utc)
                   VALUES (:s, :m, :b, :n, 'pendiente', :u, UTC_TIMESTAMP())",
                  [':s' => $sha, ':m' => $tipo[0], ':b' => strlen($bytes), ':n' => $nombre, ':u' => $usuarioId]);
            $id = Db::insertarId();
            Hash::auditar('ocr_remito', $id, 'subida', ['sha256' => $sha, 'bytes' => strlen($bytes), 'por' => $usuarioId]);
            return $id;
        });
        return self::procesar($id);
    }

    /**
     * Manda la foto al modelo y deja el borrador. Fuera de toda transacción:
     * una llamada de varios segundos no puede tener filas bloqueadas.
     */
    public static function procesar(int $id): array
    {
        $r = Db::una('SELECT id, estado, archivo_sha256, archivo_mime FROM ocr_remito WHERE id = :i', [':i' => $id]);
        if ($r === null) throw new ErrorNoEncontrado('No existe esa foto.');
        // Se RESERVA en un solo UPDATE antes de llamar a la API: de dos
        // reprocesos a la vez, uno solo llama (y paga). La reserva de un
        // proceso que murió vence a los TRABADA_MIN minutos.
        $reservada = Db::q("UPDATE ocr_remito SET estado = 'procesando', procesando_desde = UTC_TIMESTAMP(), intentos = intentos + 1
                             WHERE id = :i AND (estado IN ('pendiente','error')
                                   OR (estado = 'procesando' AND procesando_desde < UTC_TIMESTAMP() - INTERVAL " . self::TRABADA_MIN . " MINUTE))",
                           [':i' => $id])->rowCount() === 1;
        if (!$reservada) {
            throw new ErrorConflicto($r['estado'] === 'procesando' ? 'Esa foto ya se está leyendo.' : 'Esa foto ya tiene borrador o está cerrada.', 'OCR_YA_PROCESADO');
        }
        $bytes = (string) file_get_contents(self::rutaImagen($r['archivo_sha256'], $r['archivo_mime'] === 'image/png' ? 'png' : 'jpg'));
        try {
            $gastado = (int) Db::col('SELECT COALESCE(SUM(costo_usd_micros), 0) FROM ocr_remito WHERE subido_utc > UTC_TIMESTAMP() - INTERVAL 1 DAY');
            $tope = (float) Config::get('ocr.tope_diario_usd', self::TOPE_DIARIO_USD);
            if ($gastado >= $tope * 1e6) {
                throw new RuntimeException('Se llegó al tope de gasto del día (' . $tope . ' USD) para leer fotos: transcribila a mano o esperá a mañana.');
            }
            $x = self::extraer($bytes, $r['archivo_mime']);
            // Sólo si nadie la cerró mientras tanto: la validación manual de
            // una persona gana siempre sobre un borrador que llega tarde.
            $escrito = Db::q("UPDATE ocr_remito SET estado = 'borrador', modelo = :m, tokens_entrada = :ti, tokens_salida = :to, costo_usd_micros = :c,
                          duracion_ms = :d, extraido_json = :j, error_texto = NULL
                    WHERE id = :i AND estado = 'procesando'",
                  [':m' => $x['modelo'], ':ti' => $x['tokens_entrada'], ':to' => $x['tokens_salida'], ':c' => $x['costo_usd_micros'],
                   ':d' => $x['duracion_ms'], ':j' => json_encode(['campos' => $x['campos'], 'dudosos' => $x['dudosos']], JSON_UNESCAPED_UNICODE),
                   ':i' => $id])->rowCount() === 1;
            // Sólo si el borrador quedó: si una persona la validó a mano
            // mientras el modelo leía, gana la persona y no hay borrador que auditar.
            if ($escrito) {
                Hash::auditar('ocr_remito', $id, 'borrador', ['modelo' => $x['modelo'], 'costo_usd_micros' => $x['costo_usd_micros'],
                                                              'dudosos' => $x['dudosos']]);
            }
        } catch (ErrorOcr $e) {
            Db::q("UPDATE ocr_remito SET estado = 'error', error_texto = :e, modelo = :m, tokens_entrada = :ti, tokens_salida = :to,
                          costo_usd_micros = :c, duracion_ms = :d WHERE id = :i AND estado = 'procesando'",
                  [':e' => mb_substr($e->getMessage(), 0, 500), ':m' => $e->uso['modelo'], ':ti' => $e->uso['tokens_entrada'],
                   ':to' => $e->uso['tokens_salida'], ':c' => $e->uso['costo_usd_micros'], ':d' => $e->uso['duracion_ms'], ':i' => $id]);
            Hash::auditar('ocr_remito', $id, 'error', ['motivo' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            Db::q("UPDATE ocr_remito SET estado = 'error', error_texto = :e WHERE id = :i AND estado = 'procesando'",
                  [':e' => mb_substr($e->getMessage(), 0, 500), ':i' => $id]);
            Log::aviso('ocr_error', ['id' => $id, 'motivo' => mb_substr($e->getMessage(), 0, 200)]);
            Hash::auditar('ocr_remito', $id, 'error', ['motivo' => mb_substr($e->getMessage(), 0, 200)]);
        }
        return self::detalle($id);
    }

    /**
     * La validación humana: la ÚNICA puerta al registro. La persona confirma
     * o corrige cada campo mirando la foto; se guarda qué corrigió.
     */
    public static function validar(int $id, array $d, int $usuarioId): array
    {
        $v = new Validar($d);
        $numero = $v->texto('numero', 1, 30, false);
        $fecha = Tarifa::fechaValida((string) $v->texto('fecha', 10, 10), 'fecha');
        $cliente = trim((string) $v->texto('cliente', 2, 160));
        $sitio = $v->texto('sitio', 2, 200, false);
        $servicio = (string) $v->enum('servicio', array_keys(self::SERVICIOS));
        $cantidad = (int) $v->entero('cantidad', 1, 500);
        $receptor = $v->texto('receptor', 2, 120, false);
        $obs = $v->texto('observaciones', 1, 500, false);
        $v->fin();
        if (!is_bool($d['firmado'] ?? null)) throw new ErrorValidacion(['firmado' => 'Decí si el papel está firmado.']);
        if ($fecha > self::hoy()) throw new ErrorValidacion(['fecha' => 'La fecha del remito no puede ser futura.']);
        $valores = ['numero' => $numero !== null ? trim($numero) : null, 'fecha' => $fecha, 'cliente' => $cliente,
                    'sitio' => $sitio !== null ? trim($sitio) : null, 'servicio' => $servicio, 'cantidad' => $cantidad,
                    'receptor' => $receptor !== null ? trim($receptor) : null, 'firmado' => $d['firmado'],
                    'observaciones' => $obs !== null ? trim($obs) : null];

        return Db::txReintentable(static function () use ($id, $valores, $usuarioId): array {
            $r = Db::una('SELECT * FROM ocr_remito WHERE id = :i FOR UPDATE', [':i' => $id]);
            if ($r === null) throw new ErrorNoEncontrado('No existe esa foto.');
            // Una persona puede transcribir a mano también mientras el modelo
            // lee o si nunca terminó de leer: gana siempre la persona.
            if (!in_array($r['estado'], ['borrador', 'error', 'pendiente', 'procesando'], true)) {
                throw new ErrorConflicto('Esa foto ya está ' . $r['estado'] . '.', 'OCR_NO_VALIDABLE');
            }
            // El número se compara normalizado («0001-00004512» = «1-4512»), y
            // lo sostiene una clave única de la base (0030) aun con dos
            // validaciones a la vez.
            $numeroNorm = self::normalizar('numero', $valores['numero']);
            $yaCargado = static fn() => new ErrorConflicto('El remito ' . $valores['numero'] . ' del ' . $valores['fecha'] . ' ya está cargado.', 'REMITO_YA_CARGADO');
            if ($numeroNorm !== null && Db::col('SELECT id FROM remito_papel WHERE numero_norm = :n AND fecha = :f',
                                                [':n' => $numeroNorm, ':f' => $valores['fecha']]) !== null) {
                throw $yaCargado();
            }
            $extraido = json_decode((string) ($r['extraido_json'] ?? ''), true)['campos'] ?? null;
            $corregidos = $extraido === null ? self::CAMPOS
                : array_keys(array_filter(self::comparar($valores, $extraido), static fn($igual) => !$igual));
            // El cliente del registro, si el nombre coincide con uno del
            // maestro (siguiendo las fusiones de F3.1). Si no, queda el texto.
            $cli = Db::una('SELECT id, fusionado_en FROM cliente WHERE LOWER(nombre) = LOWER(:n)', [':n' => $valores['cliente']]);
            $cliId = $cli === null ? null : (int) ($cli['fusionado_en'] ?? $cli['id']);
            try {
                Db::q('INSERT INTO remito_papel (ocr_id, numero_papel, numero_norm, fecha, cliente, cliente_id, sitio, servicio, cantidad, receptor, firmado,
                                                 observaciones, validado_por, creado_utc)
                       VALUES (:o, :n, :nn, :f, :c, :ci, :s, :sv, :q, :r, :fi, :ob, :u, UTC_TIMESTAMP())',
                      [':o' => $id, ':n' => $valores['numero'], ':nn' => $numeroNorm, ':f' => $valores['fecha'], ':c' => $valores['cliente'],
                       ':ci' => $cliId, ':s' => $valores['sitio'], ':sv' => $valores['servicio'], ':q' => $valores['cantidad'],
                       ':r' => $valores['receptor'], ':fi' => $valores['firmado'] ? 1 : 0, ':ob' => $valores['observaciones'], ':u' => $usuarioId]);
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) throw $yaCargado();
                throw $e;
            }
            $registro = Db::insertarId();
            Db::q("UPDATE ocr_remito SET estado = 'validado', validado_por = :u, validado_utc = UTC_TIMESTAMP(), corregidos_json = :c WHERE id = :i",
                  [':u' => $usuarioId, ':c' => json_encode($corregidos), ':i' => $id]);
            Hash::auditar('ocr_remito', $id, 'validado', ['registro' => $registro, 'corregidos' => $corregidos, 'valores' => $valores,
                                                          'por' => $usuarioId]);
            return ['id' => $id, 'registro_id' => $registro, 'corregidos' => $corregidos];
        });
    }

    public static function descartar(int $id, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) throw new ErrorValidacion(['motivo' => 'El motivo es obligatorio.']);
        return Db::txReintentable(static function () use ($id, $motivo, $usuarioId): array {
            $r = Db::una('SELECT estado FROM ocr_remito WHERE id = :i FOR UPDATE', [':i' => $id]);
            if ($r === null) throw new ErrorNoEncontrado('No existe esa foto.');
            if ($r['estado'] === 'validado' || $r['estado'] === 'descartado') {
                throw new ErrorConflicto('Ya está ' . $r['estado'] . '.', 'OCR_NO_VALIDABLE');
            }
            Db::q("UPDATE ocr_remito SET estado = 'descartado', descartado_motivo = :m WHERE id = :i", [':m' => mb_substr($motivo, 0, 255), ':i' => $id]);
            Hash::auditar('ocr_remito', $id, 'descartado', ['motivo' => $motivo, 'por' => $usuarioId]);
            return ['id' => $id, 'estado' => 'descartado'];
        });
    }

    // ------------------------------------------------------------------
    // Medición
    // ------------------------------------------------------------------

    /**
     * Campo por campo, ¿dicen lo mismo? Con la normalización que haría una
     * persona: mayúsculas, acentos, espacios y puntuación no cuentan; en el
     * número de remito sólo cuentan las cifras.
     *
     * @return array<string,bool>
     */
    public static function comparar(array $a, array $b): array
    {
        $salida = [];
        foreach (self::CAMPOS as $c) $salida[$c] = self::normalizar($c, $a[$c] ?? null) === self::normalizar($c, $b[$c] ?? null);
        return $salida;
    }

    public static function normalizar(string $campo, mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        return match ($campo) {
            'numero' => implode('-', array_map(static fn($p) => ltrim($p, '0') ?: '0',
                                               preg_split('/\D+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY) ?: [])) ?: null,
            'cantidad' => (string) (int) $v,
            'firmado' => $v ? 'si' : 'no',
            'fecha', 'servicio' => strtolower(trim((string) $v)),
            default => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ',
                           strtolower(strtr((string) $v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                                                            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n']))))) ?: null,
        };
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    public static function listar(): array
    {
        $filas = Db::todas('SELECT r.id, r.estado, r.archivo_nombre, r.subido_utc, r.modelo, r.costo_usd_micros, r.duracion_ms, r.corregidos_json,
                                   r.error_texto, u.nombre AS subio, p.numero_papel, p.cliente, p.fecha
                              FROM ocr_remito r LEFT JOIN usuario u ON u.id = r.subido_por LEFT JOIN remito_papel p ON p.ocr_id = r.id
                             ORDER BY r.id DESC LIMIT 200');
        // Lo que el piloto mide en operación: de lo que una persona validó,
        // cuántas veces tuvo que corregir cada campo.
        $validados = Db::todas("SELECT corregidos_json, extraido_json FROM ocr_remito WHERE estado = 'validado' AND extraido_json IS NOT NULL");
        $porCampo = array_fill_keys(self::CAMPOS, 0);
        foreach ($validados as $x) foreach ((array) json_decode((string) $x['corregidos_json'], true) as $c) if (isset($porCampo[$c])) $porCampo[$c]++;
        $n = count($validados);
        $costos = Db::una("SELECT COUNT(*) AS n, COALESCE(SUM(costo_usd_micros), 0) AS total FROM ocr_remito WHERE costo_usd_micros IS NOT NULL");
        return [
            'configurado' => self::configurado(), 'modelo' => self::modelo(), 'servicios' => self::SERVICIOS,
            'fotos' => array_map(static fn($x) => [
                'id' => (int) $x['id'], 'estado' => $x['estado'], 'nombre' => $x['archivo_nombre'], 'subido_utc' => $x['subido_utc'],
                'subio' => $x['subio'], 'modelo' => $x['modelo'], 'costo_usd_micros' => $x['costo_usd_micros'] === null ? null : (int) $x['costo_usd_micros'],
                'duracion_ms' => $x['duracion_ms'] === null ? null : (int) $x['duracion_ms'], 'error' => $x['error_texto'],
                'corregidos' => $x['corregidos_json'] === null ? null : json_decode((string) $x['corregidos_json'], true),
                'registro' => $x['cliente'] === null ? null : ['numero' => $x['numero_papel'], 'cliente' => $x['cliente'], 'fecha' => $x['fecha']],
            ], $filas),
            'medicion' => [
                'validados_con_borrador' => $n,
                'precision_por_campo' => $n === 0 ? null : array_map(static fn($c) => round(1 - $c / $n, 4), $porCampo),
                'procesadas' => (int) $costos['n'], 'costo_total_usd_micros' => (int) $costos['total'],
                'costo_promedio_usd_micros' => (int) $costos['n'] === 0 ? null : (int) round((int) $costos['total'] / (int) $costos['n']),
            ],
        ];
    }

    public static function detalle(int $id): array
    {
        $r = Db::una('SELECT r.*, u.nombre AS subio, v.nombre AS valido FROM ocr_remito r LEFT JOIN usuario u ON u.id = r.subido_por
                        LEFT JOIN usuario v ON v.id = r.validado_por WHERE r.id = :i', [':i' => $id]);
        if ($r === null) throw new ErrorNoEncontrado('No existe esa foto.');
        $ext = json_decode((string) ($r['extraido_json'] ?? ''), true);
        $reg = Db::una('SELECT * FROM remito_papel WHERE ocr_id = :i', [':i' => $id]);
        return [
            'id' => (int) $r['id'], 'estado' => $r['estado'], 'nombre' => $r['archivo_nombre'], 'subido_utc' => $r['subido_utc'], 'subio' => $r['subio'],
            'intentos' => (int) $r['intentos'], 'modelo' => $r['modelo'], 'tokens_entrada' => $r['tokens_entrada'] === null ? null : (int) $r['tokens_entrada'],
            'tokens_salida' => $r['tokens_salida'] === null ? null : (int) $r['tokens_salida'],
            'costo_usd_micros' => $r['costo_usd_micros'] === null ? null : (int) $r['costo_usd_micros'],
            'duracion_ms' => $r['duracion_ms'] === null ? null : (int) $r['duracion_ms'], 'error' => $r['error_texto'],
            'borrador' => is_array($ext) ? $ext['campos'] : null, 'dudosos' => is_array($ext) ? $ext['dudosos'] : [],
            'corregidos' => $r['corregidos_json'] === null ? null : json_decode((string) $r['corregidos_json'], true),
            'validado_por' => $r['valido'], 'validado_utc' => $r['validado_utc'], 'descartado_motivo' => $r['descartado_motivo'],
            'registro' => $reg === null ? null : [
                'id' => (int) $reg['id'], 'numero' => $reg['numero_papel'], 'fecha' => $reg['fecha'], 'cliente' => $reg['cliente'],
                'cliente_id' => $reg['cliente_id'] === null ? null : (int) $reg['cliente_id'], 'sitio' => $reg['sitio'], 'servicio' => $reg['servicio'],
                'cantidad' => (int) $reg['cantidad'], 'receptor' => $reg['receptor'], 'firmado' => (int) $reg['firmado'] === 1,
                'observaciones' => $reg['observaciones'],
            ],
            'servicios' => self::SERVICIOS, 'configurado' => self::configurado(),
        ];
    }

    public static function imagen(int $id): array
    {
        $r = Db::una('SELECT archivo_sha256, archivo_mime FROM ocr_remito WHERE id = :i', [':i' => $id]);
        if ($r === null) throw new ErrorNoEncontrado('No existe esa foto.');
        $ruta = self::rutaImagen($r['archivo_sha256'], $r['archivo_mime'] === 'image/png' ? 'png' : 'jpg');
        if (!is_file($ruta)) throw new RuntimeException('Falta en disco la foto ' . $r['archivo_sha256'] . '.');
        return ['ruta' => $ruta, 'mime' => $r['archivo_mime']];
    }

    public static function rutaImagen(string $sha, string $ext): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha) || !in_array($ext, ['jpg', 'png'], true)) throw new LogicException('Huella inválida.');
        $raiz = (string) Config::get('rutas.documentos', Config::priv() . '/documentos');
        return $raiz . '/ocr/' . substr($sha, 0, 2) . '/' . $sha . '.' . $ext;
    }
}

/** La API respondió, pero no con una transcripción: se guarda lo que costó igual. */
final class ErrorOcr extends RuntimeException
{
    public function __construct(string $mensaje, public readonly array $uso)
    {
        parent::__construct($mensaje);
    }
}
