<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/lib/Pin.php';

/**
 * Enrolamiento de dispositivos.
 *
 *   POST /api/v1/dispositivo/codigo    (supervisor) emite un codigo
 *   POST /api/v1/dispositivo/reclamar  (publico)    canjea el codigo
 *
 * El codigo usa alfabeto Crockford (sin I, L, O ni U) y va en grupos de 4,
 * porque tiene que poder DICTARSE POR TELEFONO O VHF. Reponer un celular roto
 * a las 6 de la maniana en Tamberias no puede depender de que dos personas
 * tengan senial y pantalla al mismo tiempo para pasarse un QR.
 *
 * Dura 30 dias, no 24 horas, tambien a proposito: el supervisor deja los
 * codigos pre-emitidos en un sobre cerrado en la base y la cuadrilla se
 * autoabastece sin llamar a nadie.
 */

const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';   // Crockford: sin I, L, O, U

function camca_generar_codigo(): string
{
    $n = strlen(ALFABETO);
    $cuerpo = '';
    for ($i = 0; $i < 7; $i++) {
        $cuerpo .= ALFABETO[random_int(0, $n - 1)];
    }
    // Digito verificador: evita que un codigo mal dictado llegue al servidor.
    $suma = 0;
    foreach (str_split($cuerpo) as $i => $c) {
        $suma += (strpos(ALFABETO, $c) ?: 0) * ($i + 1);
    }
    $codigo = $cuerpo . ALFABETO[$suma % $n];
    return substr($codigo, 0, 4) . '-' . substr($codigo, 4);
}

function camca_codigo_valido(string $codigo): bool
{
    $limpio = strtoupper(str_replace(['-', ' '], '', $codigo));
    // Confusiones tipicas al dictar.
    $limpio = strtr($limpio, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);
    if (strlen($limpio) !== 8) return false;
    $cuerpo = substr($limpio, 0, 7);
    $dv = $limpio[7];
    $n = strlen(ALFABETO);
    $suma = 0;
    foreach (str_split($cuerpo) as $i => $c) {
        $p = strpos(ALFABETO, $c);
        if ($p === false) return false;
        $suma += $p * ($i + 1);
    }
    return ALFABETO[$suma % $n] === $dv;
}

function camca_normalizar(string $codigo): string
{
    $l = strtoupper(str_replace(['-', ' '], '', $codigo));
    $l = strtr($l, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);
    return substr($l, 0, 4) . '-' . substr($l, 4);
}

// Sólo el camino, sin la query: con «?/dispositivo/codigo» un pedido anónimo
// entraba en la rama de emisión y el 404/401 decía qué ids eran choferes
// activos (revisión de la Fase 4).
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

// ------------------------------------------------------------------
// Emitir (supervisor)
// ------------------------------------------------------------------
if (str_ends_with($ruta, '/dispositivo/codigo')) {
    $datos = Http::cuerpo();
    $v = new Validar($datos);
    $usuarioId = $v->entero('usuario_id', 1, 4294967295);
    $dias      = $v->entero('dias', 1, 60, false) ?? 30;
    $v->fin();

    // Sólo el teléfono de un CHOFER se enrola (revisión de la Fase 3). El
    // canje crea una sesión con el rol del destinatario: si un supervisor
    // pudiera emitir un código para un admin, se lo canjeaba él mismo y
    // quedaba admin (aprobar facturas, notas de crédito, accesos del portal).
    $destino = Db::una("SELECT id, nombre, rol FROM usuario WHERE id = :id AND activo = 1 AND rol = 'chofer'", [':id' => $usuarioId]);
    if ($destino === null) throw new ErrorNoEncontrado('No existe ese chofer.');

    $codigo = camca_generar_codigo();
    Db::q(
        'INSERT INTO codigo_enrolamiento (codigo, usuario_id, emitido_por, emitido_utc, expira_utc)
         VALUES (:c, :u, :e, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :d DAY)',
        [':c' => $codigo, ':u' => $usuarioId, ':e' => Policy::id(), ':d' => $dias]
    );

    Hash::auditar('dispositivo', $usuarioId, 'codigo_emitido', ['por' => Policy::id(), 'dias' => $dias]);

    Http::creado([
        'codigo'  => $codigo,
        'para'    => $destino['nombre'],
        'expira'  => gmdate('c', time() + $dias * 86400),
        'dictado' => 'Se lee en voz alta por grupos: ' . implode(' ', str_split(str_replace('-', '', $codigo), 4)),
    ]);
}

// ------------------------------------------------------------------
// Canjear (publico: el telefono nuevo todavia no tiene sesion)
// ------------------------------------------------------------------
$datos = Http::cuerpo();
$v = new Validar($datos);
$codigo     = $v->texto('codigo', 6, 20);
$pin        = $v->texto('pin', 6, 6);
$etiqueta   = $v->texto('etiqueta', 0, 80, false);
$plataforma = $v->texto('plataforma', 0, 40, false);
$v->fin();

if (!camca_codigo_valido((string) $codigo)) {
    // El digito verificador atrapa el codigo mal dictado ANTES de gastar un
    // intento contra la base y antes de culpar al chofer.
    throw new ErrorValidacion(['codigo' => 'El codigo no es valido. Revisá que esté completo.']);
}
if (!Pin::valido((string) $pin)) {
    throw new ErrorValidacion(['pin' => 'El PIN tiene que ser de 6 dígitos y no puede ser una secuencia obvia.']);
}

$normalizado = camca_normalizar((string) $codigo);
Rate::consumir('enrolar|' . $normalizado, 6, 3600);

$fila = Db::una(
    'SELECT ce.id, ce.usuario_id, ce.usado_utc, ce.expira_utc, u.rol, u.nombre, u.legajo
       FROM codigo_enrolamiento ce
       JOIN usuario u ON u.id = ce.usuario_id
      WHERE ce.codigo = :c AND u.activo = 1 AND u.rol = :rol
      LIMIT 1',
    [':c' => $normalizado, ':rol' => Policy::CHOFER]
);
if ($fila === null)                                       throw new ErrorAuth('Código inexistente o vencido.', 'CODIGO_INVALIDO');
if ($fila['usado_utc'] !== null)                          throw new ErrorConflicto('Ese código ya se usó.', 'CODIGO_USADO');
if (strtotime((string) $fila['expira_utc']) < time())     throw new ErrorAuth('Código vencido.', 'CODIGO_VENCIDO');

$secreto = bin2hex(random_bytes(32));

$dispositivoId = Db::tx(static function () use ($fila, $secreto, $pin, $etiqueta, $plataforma): int {
    Db::q(
        'INSERT INTO dispositivo (usuario_id, etiqueta, secreto_hash, pin_hash, plataforma, enrolado_utc)
         VALUES (:u, :e, :s, :p, :pl, UTC_TIMESTAMP())',
        [
            ':u'  => $fila['usuario_id'],
            ':e'  => $etiqueta,
            ':s'  => hash('sha256', $secreto),
            ':p'  => Pin::hashear((string) $pin, $secreto),
            ':pl' => $plataforma,
        ]
    );
    $id = Db::insertarId();
    Db::q(
        'UPDATE codigo_enrolamiento SET usado_utc = UTC_TIMESTAMP(), dispositivo_id = :d WHERE id = :id',
        [':d' => $id, ':id' => $fila['id']]
    );
    return $id;
});

Hash::auditar('dispositivo', $dispositivoId, 'enrolado', [
    'usuario'    => $fila['usuario_id'],
    'plataforma' => $plataforma,
]);

$tok = Sesion::crear((int) $fila['usuario_id'], (string) $fila['rol'], $dispositivoId);

Http::creado([
    // El secreto se entrega UNA sola vez. El servidor guarda su hash.
    'dispositivo'  => $secreto,
    'token'        => $tok['token'],
    'expira_dias'  => $tok['expira_dias'],
    'usuario'      => [
        'id'     => (int) $fila['usuario_id'],
        'nombre' => $fila['nombre'],
        'legajo' => $fila['legajo'],
        'rol'    => $fila['rol'],
    ],
    'offline'      => ['sal' => substr(hash('sha256', $secreto), 0, 32), 'iteraciones' => 150000],
    'servidor_utc' => gmdate('c'),
]);
