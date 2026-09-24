<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Cliente SMTP minimo, sin Composer y sin dependencias vendorizadas.
 *
 * Se escribe a mano en vez de traer PHPMailer por tres razones concretas:
 * el proyecto entero no tiene Composer, vendorizar a mano obliga a vigilar
 * hashes en CI, y de todo PHPMailer aca se usaria menos del 5%.
 *
 * Nunca decide si el mensaje se guarda: para cuando esto corre, la consulta
 * YA esta en la base. Si el envio falla, se reintenta; no se pierde.
 */
final class Mailer
{
    private const TIMEOUT = 15;

    /**
     * @param string $responderA Reply-To. Va el email del consultante para que
     *        "Responder" en Gmail le escriba a el y no a la casilla del sitio.
     *        El From SIEMPRE es del dominio propio: poner el del consultante
     *        rompe SPF y manda el mensaje a spam.
     */
    public static function enviar(string $asunto, string $cuerpo, ?string $responderA = null, ?string $nombreQuienResponde = null, ?string $para = null): void
    {
        $cfg = Config::get('mail', []);
        $desde   = $cfg['desde']   ?? 'no-reply@camcasoluciones.com.ar';
        // $para: otro destinatario que el de las consultas del sitio (el ancla
        // diaria va a quien custodia, no a la casilla comercial).
        $destino = $para ?? ($cfg['destino'] ?? 'camcadistribuciones@gmail.com');
        // Va a las cabeceras y al RCPT TO: un salto de línea ahí inyecta
        // cabeceras. Hoy sale de la configuración; mañana podría no.
        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Destinatario de mail inválido.');
        }

        $cabeceras = [
            'Date: ' . date('r'),
            'From: CAMCA Servicios Integrales <' . $desde . '>',
            'To: <' . $destino . '>',
            'Subject: ' . self::codificarAsunto($asunto),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@camcasoluciones.com.ar>',
        ];
        if ($responderA !== null && filter_var($responderA, FILTER_VALIDATE_EMAIL)) {
            $nombre = self::limpiarCabecera((string) $nombreQuienResponde);
            $cabeceras[] = 'Reply-To: ' . ($nombre !== '' ? self::codificarAsunto($nombre) . ' ' : '') . '<' . $responderA . '>';
        }

        $mensaje = implode("\r\n", $cabeceras) . "\r\n\r\n" . self::normalizarSaltos($cuerpo);

        // Modo archivo: el mensaje queda en camca_priv/correo/ en vez de salir.
        // Es para desarrollo y para las pruebas, que tienen que poder leer lo
        // que se habría mandado sin depender de un servidor de correo.
        if (($cfg['modo'] ?? '') === 'archivo') {
            $dir = Config::priv() . '/correo';
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            file_put_contents($dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml', $mensaje);
            return;
        }

        if (!empty($cfg['host']) && !empty($cfg['usuario'])) {
            self::porSmtp($cfg, $desde, $destino, $mensaje);
            return;
        }
        self::porMailNativo($destino, $asunto, $cuerpo, $cabeceras);
    }

    /** RFC 2047: los acentos en el asunto llegan rotos si no se codifican. */
    private static function codificarAsunto(string $s): string
    {
        $s = self::limpiarCabecera($s);
        return preg_match('/[^\x20-\x7E]/', $s) === 1
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }

    /** Anti header-injection: un salto de linea en una cabecera inyecta cabeceras. */
    private static function limpiarCabecera(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    private static function normalizarSaltos(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $s);
    }

    private static function porMailNativo(string $destino, string $asunto, string $cuerpo, array $cabeceras): void
    {
        $extra = array_values(array_filter($cabeceras, static fn($c) => !str_starts_with($c, 'To:') && !str_starts_with($c, 'Subject:')));
        if (!@mail($destino, self::codificarAsunto($asunto), self::normalizarSaltos($cuerpo), implode("\r\n", $extra))) {
            throw new RuntimeException('mail() nativo devolvio false.');
        }
    }

    private static function porSmtp(array $cfg, string $desde, string $destino, string $mensaje): void
    {
        $host   = (string) $cfg['host'];
        $puerto = (int) ($cfg['puerto'] ?? 465);
        $seguro = (string) ($cfg['seguro'] ?? 'ssl');
        $dsn    = ($seguro === 'ssl' ? 'ssl://' : '') . $host . ':' . $puerto;

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client($dsn, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new RuntimeException("No se pudo conectar a $dsn: $errstr ($errno)");
        }
        stream_set_timeout($fp, self::TIMEOUT);

        try {
            self::esperar($fp, 220);
            self::cmd($fp, 'EHLO camcasoluciones.com.ar', 250);

            if ($seguro === 'tls') {
                self::cmd($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS fallo.');
                }
                self::cmd($fp, 'EHLO camcasoluciones.com.ar', 250);
            }

            self::cmd($fp, 'AUTH LOGIN', 334);
            self::cmd($fp, base64_encode((string) $cfg['usuario']), 334);
            self::cmd($fp, base64_encode((string) ($cfg['clave'] ?? '')), 235);
            self::cmd($fp, 'MAIL FROM:<' . $desde . '>', 250);
            self::cmd($fp, 'RCPT TO:<' . $destino . '>', 250);
            self::cmd($fp, 'DATA', 354);

            // Dot-stuffing: una linea que empieza con "." cortaria el mensaje.
            $cuerpo = preg_replace('/^\./m', '..', $mensaje);
            fwrite($fp, $cuerpo . "\r\n.\r\n");
            self::esperar($fp, 250);

            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }

    private static function cmd($fp, string $linea, int $esperado): void
    {
        fwrite($fp, $linea . "\r\n");
        self::esperar($fp, $esperado);
    }

    private static function esperar($fp, int $esperado): string
    {
        $respuesta = '';
        while (($linea = fgets($fp, 515)) !== false) {
            $respuesta .= $linea;
            // Las respuestas multilinea usan "250-"; la ultima usa "250 ".
            if (strlen($linea) < 4 || $linea[3] !== '-') break;
        }
        $codigo = (int) substr($respuesta, 0, 3);
        if ($codigo !== $esperado) {
            // La respuesta puede traer parte de la credencial: no va al log crudo.
            throw new RuntimeException('SMTP esperaba ' . $esperado . ' y recibio ' . $codigo);
        }
        return $respuesta;
    }
}
