<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/lib/Mailer.php';

/**
 * POST /api/v1/contacto
 *
 * Reemplaza el POST a "/" que venia de la convencion de Netlify Forms.
 * Ese POST devolvia 200 en Hostinger (servia el index.html estatico) y, como
 * fetch solo rechaza ante error de red, el visitante SIEMPRE veia el mensaje
 * de exito. Ninguna consulta llego nunca a CAMCA.
 *
 * REGLA CENTRAL: la consulta se GUARDA SIEMPRE antes de intentar el mail.
 * Si el SMTP esta caido, la consulta ya esta en la base y un cron la reintenta.
 * Nunca mas se pierde una consulta por un problema de correo.
 *
 * El anti-spam DEGRADA a estado 'spam' en vez de rechazar: un falso positivo
 * no puede hacer desaparecer a un cliente real sin dejar rastro.
 */

$datos = Http::cuerpo();
$v = new Validar($datos);

$nombre   = $v->texto('nombre', 2, 120);
$email    = $v->email('email', false);
$telefono = $v->telefono('telefono', false);
$empresa  = $v->texto('empresa', 0, 160, false);
// El formulario del sitio llama al campo "motivo"; se acepta cualquiera de
// los dos nombres para no acoplar la API a la etiqueta de la pantalla.
$servicio = $v->texto(isset($datos['servicio']) ? 'servicio' : 'motivo', 0, 120, false);
$mensaje  = $v->texto('mensaje', 10, 5000);
$v->fin();

if ($email === null && $telefono === null) {
    throw new ErrorValidacion(
        ['email' => 'Dejanos un email o un telefono para poder responderte.'],
        'Hace falta al menos una forma de contacto.'
    );
}

// --- Anti-spam: dos senales, ninguna bloqueante ---
$sospechoso = false;

// 1. Honeypot. Un campo invisible que solo completa un bot.
if (trim((string) ($datos['bot-field'] ?? $datos['website'] ?? '')) !== '') {
    $sospechoso = true;
}

// 2. Trampa de tiempo. El formulario manda _ts con el momento en que se
//    dibujo; completarlo en menos de 3 segundos no lo hace una persona.
$ts = isset($datos['_ts']) ? (int) $datos['_ts'] : 0;
if ($ts > 0) {
    $segundos = (int) round((microtime(true) * 1000 - $ts) / 1000);
    if ($segundos >= 0 && $segundos < 3) $sospechoso = true;
}

$estado = $sospechoso ? 'spam' : 'pendiente';

Db::q(
    'INSERT INTO consulta_web (nombre, email, telefono, empresa, servicio, mensaje, estado, ip_hash, user_agent, creado_utc)
     VALUES (:n, :e, :t, :em, :s, :m, :est, :ip, :ua, UTC_TIMESTAMP())',
    [
        ':n'   => $nombre,
        ':e'   => $email,
        ':t'   => $telefono,
        ':em'  => $empresa,
        ':s'   => $servicio,
        ':m'   => $mensaje,
        ':est' => $estado,
        ':ip'  => Http::ipHash(),
        ':ua'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]
);
$id = Db::insertarId();

// Al sospechoso se le responde 201 igual: si es una persona real mal
// clasificada, no se entera y la consulta quedo guardada para revisarla.
if ($sospechoso) {
    Log::aviso('contacto_spam', ['id' => $id]);
    Http::creado(['id' => $id, 'guardado' => true]);
}

$cuerpo = "Nueva consulta desde camcasoluciones.com.ar\n"
    . str_repeat('-', 46) . "\n\n"
    . "Nombre:   {$nombre}\n"
    . 'Email:    ' . ($email ?? '(no dejo)') . "\n"
    . 'Telefono: ' . ($telefono ?? '(no dejo)') . "\n"
    . 'Empresa:  ' . ($empresa ?: '(no indico)') . "\n"
    . 'Servicio: ' . ($servicio ?: '(no indico)') . "\n\n"
    . "Mensaje:\n{$mensaje}\n\n"
    . str_repeat('-', 46) . "\n"
    . "Consulta #{$id} · " . gmdate('d/m/Y H:i') . " UTC\n";

try {
    Mailer::enviar('Consulta web: ' . $nombre, $cuerpo, $email, $nombre);
    Db::q(
        'UPDATE consulta_web SET estado = :e, enviado_utc = UTC_TIMESTAMP(), intentos = intentos + 1 WHERE id = :id',
        [':e' => 'enviado', ':id' => $id]
    );
    Log::info('contacto_enviado', ['id' => $id]);
} catch (Throwable $e) {
    // El mail fallo pero la consulta ESTA GUARDADA. Se reintenta por cron.
    Db::q(
        'UPDATE consulta_web SET estado = :e, intentos = intentos + 1, ultimo_error = :err WHERE id = :id',
        [':e' => 'fallido', ':err' => mb_substr($e->getMessage(), 0, 255), ':id' => $id]
    );
    Log::error('contacto_mail_fallo', ['id' => $id, 'detalle' => $e->getMessage()]);
    // Al visitante NO se le miente, pero tampoco se le pide que reenvie:
    // su consulta quedo registrada y alguien la va a leer.
}

Http::creado(['id' => $id, 'guardado' => true]);
