<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/lib/Dump.php';
require_once dirname(__DIR__) . '/lib/Cripto.php';

/**
 * Backup nocturno cifrado que SALE del proveedor (H1).
 *
 * POR QUE NO ALCANZA EL BACKUP DE HOSTINGER: vive en el mismo disco y en la
 * misma cuenta que los datos. Si la cuenta se suspende por impago, si alguien
 * borra public_html, o si el proveedor tiene un mal dia, se va todo junto.
 * Un respaldo que solo existe adentro del proveedor no es un respaldo: es una
 * copia.
 *
 * Y LAS EVIDENCIAS VIAJAN, no solo el manifiesto. Un backup con la base
 * completa y un indice de 7.200 lineas que dice "aca habia una foto" no sirve
 * para nada: el sistema se vende como evidencia auditable ante el ANR y ante
 * clientes mineros, y la evidencia son las fotos y las firmas. Se empaquetan
 * las del dia (unos 30 MB con seis choferes: una subida trivial).
 *
 * El servidor CIFRA con la clave publica de CAMCA y NO PUEDE DESCIFRAR. Quien
 * se lleve el hosting entero no se lleva los datos.
 *
 * NUNCA se manda un dump por mail. Adentro hay documentos y firmas de personal
 * de clientes mineros: mandar eso por correo sin cifrar es peor que el
 * problema que resuelve.
 */

Cli::arrancar('backup');

$priv = Config::priv();
$dirBackups = Config::get('rutas.backups', $priv . '/backups');
if (!is_dir($dirBackups)) @mkdir($dirBackups, 0700, true);

$fecha = gmdate('Y-m-d');
$tmp = $dirBackups . '/tmp-' . $fecha;
@mkdir($tmp, 0700, true);

$pemRuta = (string) Config::get('backup.clave_publica_pem', '');
if ($pemRuta === '' || !is_readable($pemRuta)) {
    Cli::fallar('Falta la clave publica de backup. Sin ella el backup no sale: un dump sin cifrar con documentos y firmas adentro no se sube a ningun lado.');
}
$pem = (string) file_get_contents($pemRuta);

// ------------------------------------------------------------------
// 0. El ancla del día (F2.5), ANTES del volcado: así la base respaldada
//    también la tiene, y viaja además como archivo aparte, legible sin
//    restaurar nada. Si el cron de anclar ya la hizo, se usa la misma.
// ------------------------------------------------------------------
$ancla = Ancla::generar();
$anclas = array_map(
    static fn($f) => Ancla::exportar($f),
    Db::todas('SELECT * FROM ancla ORDER BY fecha DESC LIMIT 400')
);
file_put_contents($tmp . '/ancla.json', json_encode(
    ['ultima' => $ancla, 'todas' => $anclas],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
));
Cli::decir('Ancla: ' . (json_decode($ancla['contenido'], true)['fecha'] ?? '?') . ' (' . count($anclas) . ' en el paquete)');

// ------------------------------------------------------------------
// 1. Volcado de la base, en PHP puro (mysqldump puede no existir).
// ------------------------------------------------------------------
$sqlGz = $tmp . '/base.sql.gz';
$bytesSql = Dump::generar($sqlGz);
Cli::decir('Volcado: ' . round($bytesSql / 1024) . ' kB comprimidos');

// La conexion se cierra antes de las operaciones largas de disco: mantenerla
// abierta durante el empaquetado consume el cupo de conexiones del plan.
$evidencias = Db::todas(
    "SELECT uuid, ruta_relativa, sha256, bytes FROM evidencia
      WHERE completa = 1 AND externo_confirmado_utc IS NULL
      ORDER BY id LIMIT 5000"
);
Db::cerrar();

// ------------------------------------------------------------------
// 2. Evidencias todavia no respaldadas.
// ------------------------------------------------------------------
$raizEvidencias = Config::get('rutas.evidencias', $priv . '/evidencias');
$tarRuta = $tmp . '/evidencias.tar';
$subidas = [];

if ($evidencias !== []) {
    $tar = fopen($tarRuta, 'wb');
    foreach ($evidencias as $e) {
        $ruta = $raizEvidencias . '/' . $e['ruta_relativa'];
        if (!is_readable($ruta)) continue;
        $contenido = (string) file_get_contents($ruta);
        // Cabecera tar POSIX minima: 512 bytes, nombre y tamanio en octal.
        $nombre = substr((string) $e['ruta_relativa'], 0, 99);
        $cab = str_pad($nombre, 100, "\0");
        $cab .= str_pad('0000600', 8, "\0");
        $cab .= str_pad('0000000', 8, "\0");
        $cab .= str_pad('0000000', 8, "\0");
        $cab .= str_pad(decoct(strlen($contenido)), 11, '0', STR_PAD_LEFT) . "\0";
        $cab .= str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0";
        $cab .= str_repeat(' ', 8);          // checksum provisorio
        $cab .= '0';
        $cab = str_pad($cab, 512, "\0");
        $suma = 0;
        for ($i = 0; $i < 512; $i++) $suma += ord($cab[$i]);
        $cab = substr_replace($cab, str_pad(decoct($suma), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);

        fwrite($tar, $cab);
        fwrite($tar, $contenido);
        $relleno = 512 - (strlen($contenido) % 512);
        if ($relleno < 512) fwrite($tar, str_repeat("\0", $relleno));
        $subidas[] = $e['uuid'];
    }
    fwrite($tar, str_repeat("\0", 1024));   // fin de archivo tar
    fclose($tar);
    Cli::decir('Evidencias empaquetadas: ' . count($subidas) . ' (' . round(@filesize($tarRuta) / 1048576, 1) . ' MB)');
}

// ------------------------------------------------------------------
// 3. Manifiesto: el indice, no el backup.
// ------------------------------------------------------------------
$manifiesto = [
    'fecha'        => $fecha,
    'generado_utc' => gmdate('c'),
    'base_bytes'   => $bytesSql,
    // La cabeza anclada, a la vista en el índice; la firmada va en ancla.json.
    'ancla'        => json_decode($ancla['contenido'], true),
    'evidencias'   => array_map(static fn($e) => [
        'uuid' => $e['uuid'], 'ruta' => $e['ruta_relativa'], 'sha256' => $e['sha256'], 'bytes' => (int) $e['bytes'],
    ], $evidencias),
];
file_put_contents($tmp . '/manifiesto.json', json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ------------------------------------------------------------------
// 4. Un solo paquete, cifrado.
// ------------------------------------------------------------------
$paquete = $dirBackups . '/camca-' . $fecha . '.tar';
$p = fopen($paquete, 'wb');
foreach (['base.sql.gz', 'evidencias.tar', 'manifiesto.json', 'ancla.json'] as $nombre) {
    $ruta = $tmp . '/' . $nombre;
    if (!is_file($ruta)) continue;
    $contenido = (string) file_get_contents($ruta);
    $cab = str_pad($nombre, 100, "\0") . str_pad('0000600', 8, "\0") . str_pad('0000000', 8, "\0")
         . str_pad('0000000', 8, "\0") . str_pad(decoct(strlen($contenido)), 11, '0', STR_PAD_LEFT) . "\0"
         . str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0" . str_repeat(' ', 8) . '0';
    $cab = str_pad($cab, 512, "\0");
    $suma = 0;
    for ($i = 0; $i < 512; $i++) $suma += ord($cab[$i]);
    $cab = substr_replace($cab, str_pad(decoct($suma), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
    fwrite($p, $cab);
    fwrite($p, $contenido);
    $relleno = 512 - (strlen($contenido) % 512);
    if ($relleno < 512) fwrite($p, str_repeat("\0", $relleno));
}
fwrite($p, str_repeat("\0", 1024));
fclose($p);

$cifrado = $dirBackups . '/camca-' . $fecha . '.enc';
$sha = Cripto::cifrarArchivo($paquete, $cifrado, $pem);
@unlink($paquete);
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

$tamanio = (int) filesize($cifrado);
Cli::decir('Paquete cifrado: ' . round($tamanio / 1048576, 1) . ' MB · sha256 ' . substr($sha, 0, 16));

// ------------------------------------------------------------------
// 5. Fuera del proveedor.
// ------------------------------------------------------------------
$repo  = (string) Config::get('backup.repo', '');
$token = (string) Config::get('backup.token_github', '');
$subido = false;

if ($repo !== '' && $token !== '' && $token !== 'CAMBIAR') {
    // La API de contenidos de GitHub acepta hasta ~100 MB por archivo y pide
    // el contenido en base64, lo que infla un tercio. Por encima de 60 MB se
    // avisa en vez de intentarlo y fallar en silencio.
    if ($tamanio > 60 * 1048576) {
        Cli::decir('AVISO: el paquete supera los 60 MB. Hay que archivar evidencias viejas o partirlo.');
    } else {
        $url = 'https://api.github.com/repos/' . $repo . '/contents/' . $fecha . '/camca-' . $fecha . '.enc';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: CAMCA-Backup/1.0',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'message' => 'Backup ' . $fecha,
                'content' => base64_encode((string) file_get_contents($cifrado)),
            ]),
        ]);
        $res = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigo === 201 || $codigo === 200) {
            $subido = true;
            Cli::decir('Subido al repositorio externo.');
        } else {
            Cli::decir('AVISO: no se pudo subir (HTTP ' . $codigo . '). El paquete cifrado queda en ' . $cifrado);
        }
    }
} else {
    Cli::decir('AVISO: no hay repositorio externo configurado. El backup NO salio del proveedor.');
}

// ------------------------------------------------------------------
// 6. Solo se marcan como respaldadas las evidencias que EFECTIVAMENTE salieron.
// ------------------------------------------------------------------
if ($subido) {
    // El ancla salió del proveedor: queda anotado.
    Db::q('UPDATE ancla SET en_backup_utc = UTC_TIMESTAMP() WHERE fecha = :f',
          [':f' => json_decode($ancla['contenido'], true)['fecha'] ?? '']);
}
if ($subido && $subidas !== []) {
    $marcas = implode(',', array_fill(0, count($subidas), '?'));
    Db::q(
        "UPDATE evidencia SET externo_confirmado_utc = UTC_TIMESTAMP() WHERE uuid IN ($marcas)",
        $subidas
    );
    Cli::decir(count($subidas) . ' evidencias marcadas como respaldadas.');
}

// Retencion local: 14 dias de paquetes cifrados. Los que ya salieron viven
// afuera; estos son la red de seguridad de corto plazo.
foreach (glob($dirBackups . '/camca-*.enc') ?: [] as $viejo) {
    if (filemtime($viejo) < time() - 14 * 86400) @unlink($viejo);
}

Cli::latir('backup', ($subido ? 'subido' : 'local') . ' ' . round($tamanio / 1048576, 1) . ' MB');
