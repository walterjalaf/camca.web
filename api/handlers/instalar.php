<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Instalador de la primera puesta en marcha (cierre, F4.6).
 *
 *   GET  /api/v1/instalar   requisitos del hosting (sólo mientras no está instalado)
 *   POST /api/v1/instalar   token + datos de la base + primer administrador
 *
 * Reemplaza, para la primera vez, los pasos a mano del runbook que necesitan
 * consola (crear_admin.php) o subir archivos con secretos (config.php): en
 * Hostinger compartido no siempre hay SSH, y un config.php armado a mano es
 * donde se cuelan los errores.
 *
 * Por qué es seguro dejarlo desplegado:
 *  - Hace falta un TOKEN de un solo uso: el build sólo lleva su sha256.
 *  - Se apaga en cuanto CUALQUIER camca_priv que la plataforma pueda usar
 *    (los mismos lugares que recorre Config::priv(), también el que dice el
 *    runbook a mano) tiene config.php: contesta 404 para siempre. Con un solo
 *    lugar, una instalación hecha a mano en otro nivel lo dejaba vivo, y
 *    quien tuviera el token ponía una carpeta más cercana y se quedaba con la
 *    plataforma (revisión de la Fase 4).
 *  - La base tiene que estar en el mismo servidor y sin usuarios.
 *  - Un solo instalador a la vez (lock de archivo), y config.php se escribe
 *    al FINAL: si algo se corta a mitad de camino no queda la plataforma a
 *    medias con el instalador apagado.
 */

$instalada = false;
foreach (Config::candidatos() as $c) {
    if (is_file($c . '/config.php') || is_file($c . '/INSTALADO')) { $instalada = true; break; }
}
if ($instalada) Http::error(404, 'NO_ENCONTRADO', 'Ruta no encontrada.');
$hashEsperado = trim((string) (getenv('CAMCA_INSTALAR_SHA256') ?: @file_get_contents(dirname(__DIR__) . '/instalar.sha256')));
if (!preg_match('/^[0-9a-f]{64}$/', $hashEsperado)) Http::error(404, 'NO_ENCONTRADO', 'Ruta no encontrada.');

// Dónde va camca_priv: donde Config::priv() la va a buscar. Si ya hay una
// carpeta (vacía) en alguno de esos lugares, esa; si no, al lado de
// public_html, fuera del sitio.
$destino = null;
foreach (Config::candidatos() as $c) if (is_dir($c)) { $destino = $c; break; }
$destino = rtrim(str_replace('\\', '/', $destino ?? dirname(__DIR__, 3) . '/camca_priv'), '/');
$escribible = is_dir($destino) ? is_writable($destino) : is_writable(dirname($destino));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    // Sin token no se muestra la ruta del servidor (lleva el usuario de la
    // cuenta de Hostinger): sólo si se puede escribir.
    $ext = [];
    foreach (['pdo_mysql', 'sodium', 'openssl', 'curl', 'mbstring', 'fileinfo'] as $e) $ext[$e] = extension_loaded($e);
    Http::ok(['instalado' => false, 'php' => PHP_VERSION, 'php_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
              'extensiones' => $ext, 'destino_escribible' => $escribible]);
}

// ---------------------------------------------------------------- POST
$d = Http::cuerpo();
$token = is_string($d['token'] ?? null) ? trim($d['token']) : '';
if ($token === '' || !hash_equals($hashEsperado, hash('sha256', $token))) {
    // El token tiene 150 bits: no hay nada que adivinar, y un sleep sólo
    // regalaba workers de PHP a quien mandara pedidos en paralelo.
    Http::error(403, 'TOKEN_INVALIDO', 'El token de instalación no es ese.');
}

$v = new Validar($d);
$dbHost = $v->texto('db_host', 1, 60, false) ?? 'localhost';
$dbNombre = (string) $v->texto('db_nombre', 1, 64);
$dbUsuario = (string) $v->texto('db_usuario', 1, 64);
$dbClave = (string) ($v->texto('db_clave', 0, 200, false) ?? '');
$adminNombre = trim((string) $v->texto('admin_nombre', 3, 120));
$adminEmail = mb_strtolower((string) $v->email('admin_email'));
$adminClave = (string) $v->texto('admin_clave', Usuarios::CLAVE_MINIMA, 200);
$url = rtrim((string) ($v->texto('url_publica', 8, 200, false) ?? 'https://camcasoluciones.com.ar'), '/');
$v->fin();
if (!in_array($dbHost, ['localhost', '127.0.0.1'], true)) {
    throw new ErrorValidacion(['db_host' => 'La base tiene que estar en este mismo servidor (localhost).']);
}
foreach (['db_nombre' => $dbNombre, 'db_usuario' => $dbUsuario] as $campo => $valor) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $valor)) throw new ErrorValidacion([$campo => 'Sólo letras, números y guion bajo.']);
}
$host = (string) parse_url($url, PHP_URL_HOST);
$local = in_array($host, ['localhost', '127.0.0.1'], true);
if (!filter_var($url, FILTER_VALIDATE_URL) || (parse_url($url, PHP_URL_SCHEME) !== 'https' && !$local)) {
    throw new ErrorValidacion(['url_publica' => 'La dirección pública tiene que ser https://.']);
}

// Un solo instalador a la vez: dos pestañas, o un reintento después de un
// corte del proxy, se pisaban la configuración.
$lock = @fopen(sys_get_temp_dir() . '/camca_instalar_' . md5($destino) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new ErrorConflicto('Ya hay una instalación en curso. Esperá a que termine.', 'INSTALACION_EN_CURSO');
}
@set_time_limit(600);
ignore_user_abort(true);

// La base: que se pueda entrar y que no tenga usuarios (no se pisa nada).
try {
    $pdo = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbNombre . ';charset=utf8mb4', $dbUsuario, $dbClave,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
} catch (PDOException) {
    throw new ErrorValidacion(['db_clave' => 'No se pudo entrar a la base con esos datos. Revisá nombre, usuario y contraseña en hPanel.']);
}
if ($pdo->query("SHOW TABLES LIKE 'usuario'")->fetchColumn() !== false
    && (int) $pdo->query('SELECT COUNT(*) FROM usuario')->fetchColumn() > 0) {
    throw new ErrorConflicto('Esa base ya tiene usuarios: el instalador no pisa una instalación existente. Usá una base vacía.', 'BASE_CON_DATOS');
}
$pdo = null;

// camca_priv, fuera de public_html. Todavía SIN config.php.
if (!is_dir($destino) && !@mkdir($destino, 0750, true)) {
    throw new ErrorConflicto('No se puede escribir en ' . $destino . ' (¿open_basedir?). Creá esa carpeta desde el administrador de archivos de hPanel y volvé a intentar.', 'SIN_ESCRITURA');
}
foreach (['logs', 'estado', 'evidencias', 'documentos', 'backups'] as $sub) @mkdir($destino . '/' . $sub, 0750, true);
@file_put_contents($destino . '/.htaccess', "Require all denied\n");

$publicaFirma = null;
if (function_exists('sodium_crypto_sign_keypair')) {
    // Si ya hay una clave de un intento anterior, se usa esa.
    if (!is_file($destino . '/firma_ed25519.key')) {
        $par = sodium_crypto_sign_keypair();
        file_put_contents($destino . '/firma_ed25519.key', base64_encode(sodium_crypto_sign_secretkey($par)) . "\n");
        @chmod($destino . '/firma_ed25519.key', 0600);
        file_put_contents($destino . '/firma_ed25519.pub', base64_encode(sodium_crypto_sign_publickey($par)) . "\n");
    }
    $publicaFirma = trim((string) @file_get_contents($destino . '/firma_ed25519.pub')) ?: null;
}
$healthToken = bin2hex(random_bytes(16));
// El sitio con y sin www: si sólo quedaba el que se usó para instalar, todo
// POST desde el otro daba 403 «Origen no permitido», el login incluido.
$desnudo = preg_replace('/^www\./', '', $host);
$origenes = $local ? [$url] : ['https://' . $desnudo, 'https://www.' . $desnudo];
$config = [
    'entorno' => 'produccion',
    'db' => ['host' => $dbHost, 'nombre' => $dbNombre, 'usuario' => $dbUsuario, 'clave' => $dbClave],
    'pepper_pin' => bin2hex(random_bytes(32)),
    'health_token' => $healthToken,
    'rutas' => ['evidencias' => $destino . '/evidencias', 'logs' => $destino . '/logs', 'backups' => $destino . '/backups',
                'estado' => $destino . '/estado', 'documentos' => $destino . '/documentos'],
    'sitio' => ['url_publica' => $url],
    'origenes' => $origenes,
    'firma' => ['clave_privada' => $destino . '/firma_ed25519.key', 'claves_publicas' => []],
    // Se completan después, a mano, cuando estén (PENDIENTES-HUMANOS): sin
    // ellos la plataforma funciona y lo dice.
    'wialon' => ['host' => 'https://hst-api.wialon.us', 'token' => ''],
    'ancla' => ['destinatarios' => [$adminEmail]],
    'ocr' => ['anthropic_api_key' => ''],
];
Config::precargar($config);

$aplicadas = [];
try {
    $aplicadas = Migrador::aplicar(static fn(string $m) => null) ?? throw new ErrorMigracion('No viajó la carpeta de migraciones.');
    Db::tx(static function () use ($adminNombre, $adminEmail, $adminClave, $aplicadas, $publicaFirma, $config, $destino): void {
        $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        Db::q("INSERT INTO usuario (rol, nombre, email, pass_hash, activo, creado_utc) VALUES ('admin', :n, :e, :h, 1, UTC_TIMESTAMP())",
              [':n' => $adminNombre, ':e' => $adminEmail, ':h' => password_hash($adminClave, $algoritmo)]);
        $adminId = Db::insertarId();
        Hash::auditar('instalacion', $adminId, 'instalada', ['admin' => $adminEmail, 'migraciones' => count($aplicadas), 'firma' => $publicaFirma !== null]);
        // config.php se escribe ANTES del commit: si el proceso muere entre
        // las dos cosas, queda config.php sin administrador y se arregla
        // borrando ese archivo (el instalador vuelve a andar). Al revés, quedaba
        // un administrador sin config.php y la base ya no era «vacía».
        $tmp = $destino . '/config.php.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "<?php\n// Generado por el instalador el " . gmdate('Y-m-d H:i') . " UTC. Ver docs/tecnica/REFERENCIA.md.\nreturn "
            . var_export($config, true) . ";\n");
        @chmod($tmp, 0600);
        if (!rename($tmp, $destino . '/config.php')) throw new RuntimeException('No se pudo escribir config.php.');
    });
} catch (Throwable $e) {
    @unlink($destino . '/config.php');
    Log::error('instalacion_fallida', ['motivo' => mb_substr($e->getMessage(), 0, 500), 'clase' => $e::class]);
    // Al cliente, lo que sirve para decidir; el detalle (SQL, rutas) queda en el log.
    throw new ErrorConflicto($e instanceof ErrorMigracion
        ? 'Una migración no se pudo aplicar. Quedó anotada en camca_priv/MIGRACION_FALLIDA y en el log: hay que revisarla a mano antes de reintentar.'
        : 'La instalación no terminó y no quedó nada a medias: el detalle está en el log del servidor (camca_priv/logs). Podés reintentar.', 'INSTALACION_FALLIDA');
}
file_put_contents($destino . '/INSTALADO', json_encode(['fecha_utc' => gmdate('c'), 'admin' => $adminEmail]) . "\n");
flock($lock, LOCK_UN);

$cli = str_replace('\\', '/', dirname(__DIR__)) . '/cli';
Http::creado([
    'instalado' => true,
    'destino' => $destino,
    'admin' => $adminEmail,
    'migraciones' => count($aplicadas),
    'health_token' => $healthToken,
    'firma_publica' => $publicaFirma,
    'crons' => [
        '*/5 * * * *  /usr/bin/php ' . $cli . '/migrar.php',
        '30 3 * * *   /usr/bin/php ' . $cli . '/planificar_dia.php',
        '*/2 * * * *  /usr/bin/php ' . $cli . '/gps_poll.php',
        '*/15 * * * * /usr/bin/php ' . $cli . '/reintentar_mail.php',
        '20 5 * * *   /usr/bin/php ' . $cli . '/anclar.php',
        '30 5 * * *   /usr/bin/php ' . $cli . '/backup.php',
        '0 6 * * *    /usr/bin/php ' . $cli . '/verificar_cadena.php',
    ],
]);
