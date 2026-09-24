<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * POST /api/v1/sync/adjunto
 *
 * El carril PESADO: fotos y firmas, subidas POR TROZOS y reanudables.
 *
 * Por que por trozos y no de una: max_execution_time en Hostinger compartido
 * son 25-30 segundos, y a las 20:00 pueden estar subiendo seis choferes a la
 * vez desde la bajada del cerro con 64 kbps. Una foto de 200 kB de un solo
 * golpe se corta y se reintenta entera; en trozos de 128 kB, cada intento
 * avanza y lo avanzado no se pierde.
 *
 * EL OFFSET LO DICE EL SERVIDOR, siempre, en TODA respuesta. Si lo decidiera
 * el cliente, un reintento a mitad de trozo duplica bytes y el sha256 final no
 * cierra: el archivo queda corrupto y nadie se entera hasta que alguien
 * intenta abrir la foto meses despues.
 *
 * Los binarios se escriben FUERA de public_html (camca_priv/evidencias/AAAA/MM):
 * el deploy FTP sincroniza y borraria lo que no viaja en dist/, y ademas
 * serian descargables por URL sin ninguna sesion.
 */

$metadatos = $_SERVER['HTTP_X_CAMCA_ADJUNTO'] ?? '';
if ($metadatos === '') {
    throw new ErrorValidacion(['adjunto' => 'Falta la cabecera X-Camca-Adjunto.']);
}
$meta = json_decode($metadatos, true);
if (!is_array($meta)) {
    throw new ErrorValidacion(['adjunto' => 'Cabecera X-Camca-Adjunto ilegible.']);
}

$v = new Validar($meta);
$uuid   = $v->uuid('uuid');
$tipo   = $v->enum('tipo', ['foto', 'firma', 'nota']);
$total  = $v->entero('bytes', 1, 12 * 1024 * 1024);
$offset = $v->entero('offset', 0, 12 * 1024 * 1024);
$sha    = $v->texto('sha256', 64, 64, false);
$jornadaId = $v->entero('jornada_id', 1, 4294967295, false);
$orden     = $v->entero('parada_orden', 1, 999, false);
$v->fin();

$u = Policy::usuario();

// Pertenencia (revisión de la Fase 2): una evidencia no se cuelga de la
// jornada de otro chofer. Sin esto, un chofer podía poner su firma en la
// parada ajena. 404 y no 403, como jornada.php: no se confirma que exista.
if ($jornadaId !== null) {
    $duenio = Db::col('SELECT chofer_id FROM jornada WHERE id = :j', [':j' => $jornadaId]);
    if ($duenio !== null && $duenio !== false && (int) $duenio !== (int) $u['id']) {
        Log::aviso('adjunto_jornada_ajena', ['jornada' => $jornadaId, 'quien' => (int) $u['id']]);
        throw new ErrorNoEncontrado('No existe esa jornada.');
    }
}

// Trozo crudo.
$trozo = file_get_contents('php://input');
if ($trozo === false) $trozo = '';
$largo = strlen($trozo);

$raiz = Config::get('rutas.evidencias', Config::priv() . '/evidencias');
$carpeta = $raiz . '/' . gmdate('Y/m');
if (!is_dir($carpeta) && !@mkdir($carpeta, 0700, true) && !is_dir($carpeta)) {
    Log::error('evidencia_carpeta', ['carpeta' => $carpeta]);
    throw new ErrorMantenimiento('SIN_ALMACENAMIENTO', 'No se pudo preparar el almacenamiento.');
}

$extension = match ($tipo) {
    'firma' => 'svg',
    'nota'  => 'txt',
    default => 'webp',
};
$relativa = gmdate('Y/m') . '/' . $uuid . '.' . $extension;
$absoluta = $raiz . '/' . $relativa;
$parcial  = $absoluta . '.part';

$fila = Db::una('SELECT id, offset_bytes, completa, bytes_esperados FROM evidencia WHERE uuid = :u', [':u' => $uuid]);

if ($fila === null) {
    Db::q(
        'INSERT INTO evidencia (uuid, parada_id, jornada_id, tipo, ruta_relativa, bytes_esperados, sha256, mime, offset_bytes, completa, creado_utc)
         VALUES (:u, NULL, :j, :t, :r, :be, :s, :m, 0, 0, UTC_TIMESTAMP())',
        [
            ':u'  => $uuid,
            ':j'  => $jornadaId,
            ':t'  => $tipo,
            ':r'  => $relativa,
            ':be' => $total,
            ':s'  => $sha,
            ':m'  => $tipo === 'firma' ? 'image/svg+xml' : ($tipo === 'nota' ? 'text/plain' : 'image/webp'),
        ]
    );
    $offsetServidor = 0;
} else {
    if ((int) $fila['completa'] === 1) {
        // Ya estaba completa: el cliente puede sacarla de la cola.
        Http::ok(['uuid' => $uuid, 'offset' => (int) $fila['bytes_esperados'], 'completa' => true, 'duplicado' => true]);
    }
    $offsetServidor = (int) $fila['offset_bytes'];
}

// Si el cliente manda desde un offset que no es el del servidor, NO se
// escribe: se le devuelve el offset autoritativo y que reintente desde ahi.
// Esta es la regla que evita los archivos con bytes duplicados.
if ($offset !== $offsetServidor) {
    Http::ok([
        'uuid'      => $uuid,
        'offset'    => $offsetServidor,
        'completa'  => false,
        'reubicar'  => true,
        'mensaje'   => 'Continuar desde el offset indicado.',
    ]);
}

if ($largo > 0) {
    $fh = @fopen($parcial, $offsetServidor === 0 ? 'wb' : 'cb');
    if ($fh === false) {
        throw new ErrorMantenimiento('SIN_ALMACENAMIENTO', 'No se pudo escribir la evidencia.');
    }
    // Escritura POSICIONAL: nunca append. Si dos peticiones se cruzan, el
    // append duplicaria bytes y el append no es reintentable.
    fseek($fh, $offsetServidor);
    $escritos = fwrite($fh, $trozo);
    fflush($fh);
    fclose($fh);

    if ($escritos === false || $escritos !== $largo) {
        throw new ErrorMantenimiento('ESCRITURA_PARCIAL', 'No se pudo guardar el trozo completo.');
    }
    $offsetServidor += $escritos;
    Db::q('UPDATE evidencia SET offset_bytes = :o WHERE uuid = :u', [':o' => $offsetServidor, ':u' => $uuid]);
}

$completa = $offsetServidor >= $total;

if ($completa) {
    // Verificacion de integridad antes de dar por buena la evidencia.
    $hashReal = hash_file('sha256', $parcial);
    if ($sha !== null && !hash_equals($sha, (string) $hashReal)) {
        // El archivo llego mal. Se descarta y se pide de nuevo desde cero:
        // una evidencia corrupta es peor que una evidencia ausente, porque
        // se descubre tarde y ya nadie puede volver a sacar esa foto.
        @unlink($parcial);
        Db::q('UPDATE evidencia SET offset_bytes = 0 WHERE uuid = :u', [':u' => $uuid]);
        Log::aviso('evidencia_hash', ['uuid' => $uuid]);
        Http::ok(['uuid' => $uuid, 'offset' => 0, 'completa' => false, 'reenviar' => true,
                  'mensaje' => 'La evidencia llegó dañada. Se vuelve a pedir.']);
    }

    @rename($parcial, $absoluta);

    // Se enlaza con la parada, si vino identificada.
    $paradaId = null;
    if ($jornadaId !== null && $orden !== null) {
        $paradaId = Db::col(
            'SELECT id FROM parada_ejecucion WHERE jornada_id = :j AND orden = :o',
            [':j' => $jornadaId, ':o' => $orden]
        );
    }

    Db::q(
        'UPDATE evidencia SET completa = 1, bytes = :b, sha256 = :s, parada_id = :p WHERE uuid = :u',
        [':b' => $offsetServidor, ':s' => $hashReal, ':p' => $paradaId, ':u' => $uuid]
    );

    Hash::auditar('evidencia', (int) Db::col('SELECT id FROM evidencia WHERE uuid = :u', [':u' => $uuid]), 'recibida', [
        'uuid' => $uuid, 'tipo' => $tipo, 'bytes' => $offsetServidor, 'sha256' => $hashReal,
    ]);
}

Http::ok([
    'uuid'     => $uuid,
    'offset'   => $offsetServidor,
    'completa' => $completa,
]);
