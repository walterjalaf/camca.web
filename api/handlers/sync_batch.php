<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * POST /api/v1/sync/lote
 *
 * El carril LIVIANO: los eventos JSON de la jornada. Se manda primero y solo,
 * sin adjuntos, porque es lo que hace que el supervisor vea la jornada completa
 * —paradas, horas, motivos— ANTES de que suba la primera foto. Con 20 segundos
 * de senial en un repecho de la bajada alcanza para vaciarlo.
 *
 * IDEMPOTENCIA DOBLE. El mismo lote reenviado tres veces produce UNA fila:
 *   - uuid: clave tecnica, UNIQUE.
 *   - (jornada, orden de parada, tipo, ronda): clave de negocio, por si el
 *     cliente perdio el uuid y regenero el evento. La ronda es cuantas veces
 *     se reabrio la parada (ver 0017): sin ella, cerrar una parada reabierta
 *     se descartaba como duplicado y nunca se aplicaba.
 * Se responde que paso con CADA uuid para que el cliente sepa exactamente que
 * puede sacar de la cola. Nunca se borra nada que el servidor no haya nombrado.
 *
 * Un evento que no se puede procesar se RECHAZA explicitamente y se dice por
 * que. Lo que no se nombra queda pendiente y se reintenta: el silencio nunca
 * significa exito.
 */

const MAX_EVENTOS = 200;

$datos = Http::cuerpo();
$eventos = $datos['eventos'] ?? null;
if (!is_array($eventos)) {
    throw new ErrorValidacion(['eventos' => 'Falta la lista de eventos.']);
}
if (count($eventos) > MAX_EVENTOS) {
    throw new ErrorValidacion(['eventos' => 'Maximo ' . MAX_EVENTOS . ' eventos por lote.']);
}

$u = Policy::usuario();
$dispositivoId = $u['dispositivo_id'] ?? null;
$ahora = time();

$aceptados = [];
$duplicados = [];
$rechazados = [];

foreach ($eventos as $ev) {
    $uuid = is_string($ev['uuid'] ?? null) ? strtolower($ev['uuid']) : null;
    if ($uuid === null || !preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
        $rechazados[] = ['uuid' => $ev['uuid'] ?? null, 'motivo' => 'uuid invalido'];
        continue;
    }

    $tipo = is_string($ev['tipo'] ?? null) ? $ev['tipo'] : null;
    if ($tipo === null || $tipo === '') {
        $rechazados[] = ['uuid' => $uuid, 'motivo' => 'falta el tipo'];
        continue;
    }

    $jornadaId = isset($ev['jornada_id']) ? (int) $ev['jornada_id'] : null;
    $orden     = isset($ev['parada_orden']) ? (int) $ev['parada_orden'] : null;
    $sitioId   = isset($ev['sitio_id']) ? (int) $ev['sitio_id'] : null;
    // Un telefono viejo no la manda: es la ronda 0, que es lo que siempre fue.
    $ronda     = isset($ev['ronda']) ? max(0, min(999, (int) $ev['ronda'])) : 0;

    // Hora del telefono y desfase respecto del servidor. Se guardan los dos:
    // un reloj corrido tiene que poder verse, no descubrirse en una auditoria.
    $ocurridoMs = isset($ev['ocurrido_ms']) ? (int) $ev['ocurrido_ms'] : $ahora * 1000;
    $delta = $ahora * 1000 - $ocurridoMs;
    $ocurridoUtc = gmdate('Y-m-d H:i:s', intdiv($ocurridoMs, 1000));

    // PERTENENCIA (revisión de la Fase 2). Sin esto, cualquier chofer con
    // sesión podía mandar eventos sobre la jornada de OTRO —los ids son
    // correlativos— y, desde F2.2, escribirle la conformidad del cliente: un
    // papel que el cliente ve y que después se certifica. Misma regla que
    // jornada.php: la jornada sin dueño se acepta (el cron la crea sin chofer),
    // la de otro no. Se rechaza con motivo y el teléfono lo deja en su cola.
    if ($jornadaId !== null) {
        $duenio = Db::col('SELECT chofer_id FROM jornada WHERE id = :j', [':j' => $jornadaId]);
        if ($duenio !== null && $duenio !== false && (int) $duenio !== (int) $u['id']) {
            Log::aviso('sync_jornada_ajena', ['jornada' => $jornadaId, 'quien' => (int) $u['id']]);
            $rechazados[] = ['uuid' => $uuid, 'motivo' => 'la jornada es de otro chofer'];
            continue;
        }
    }

    try {
        Db::tx(static function () use ($ev, $uuid, $tipo, $jornadaId, $orden, $ronda, $sitioId, $ocurridoUtc, $delta, $dispositivoId) {
            Db::q(
                'INSERT INTO evento_sync (uuid, jornada_id, sitio_id, parada_orden, ronda, dispositivo_id, tipo, payload, ocurrido_utc, recibido_utc, delta_reloj_ms)
                 VALUES (:u, :j, :s, :o, :r, :d, :t, :p, :oc, UTC_TIMESTAMP(), :dr)',
                [
                    ':u'  => $uuid,
                    ':j'  => $jornadaId,
                    ':s'  => $sitioId,
                    ':o'  => $orden,
                    ':r'  => $ronda,
                    ':d'  => $dispositivoId,
                    ':t'  => $tipo,
                    ':p'  => json_encode($ev['datos'] ?? [], JSON_UNESCAPED_UNICODE),
                    ':oc' => $ocurridoUtc,
                    ':dr' => $delta,
                ]
            );
            camca_aplicar_evento($tipo, $jornadaId, $orden, $ev['datos'] ?? [], $ocurridoUtc, [
                'uuid' => $uuid, 'ronda' => $ronda, 'dispositivo_id' => $dispositivoId,
            ]);
        });
        $aceptados[] = $uuid;
    } catch (PDOException $e) {
        if (Db::esDuplicado($e)) {
            // Ya estaba: el cliente puede sacarlo de la cola con tranquilidad.
            $duplicados[] = $uuid;
        } else {
            Log::error('sync_evento', ['uuid' => $uuid, 'sqlstate' => $e->getCode()]);
            $rechazados[] = ['uuid' => $uuid, 'motivo' => 'error al guardar'];
        }
    } catch (Throwable $e) {
        Log::error('sync_evento', ['uuid' => $uuid, 'detalle' => $e->getMessage()]);
        $rechazados[] = ['uuid' => $uuid, 'motivo' => 'error al aplicar'];
    }
}

/**
 * Aplica el efecto del evento sobre la jornada.
 *
 * REGLA DE CONFLICTO (para el supervisor, en castellano):
 *   - Gana el TERRENO en hora de arribo, cantidad ejecutada y evidencias.
 *   - Gana la OFICINA en el estado, si la parada ya fue certificada.
 * En Fase 0 no hay certificacion, asi que el terreno gana siempre salvo que la
 * parada este bloqueada (destildada a mano).
 */
function camca_aplicar_evento(string $tipo, ?int $jornadaId, ?int $orden, array $datos, string $ocurridoUtc, array $evento = []): void
{
    if ($jornadaId === null || $orden === null) return;

    $p = Db::una(
        'SELECT id, estado, bloqueada, flujo FROM parada_ejecucion WHERE jornada_id = :j AND orden = :o',
        [':j' => $jornadaId, ':o' => $orden]
    );
    if ($p === null) return;

    // Gana la OFICINA una vez que verificó (revisión de la Fase 2). Lo que la
    // oficina miró es lo que va al remito: un cierre que llega después —un
    // reenvío, un teléfono tocado— no puede cambiar la cantidad ni la
    // conformidad de un trabajo ya verificado. El evento queda guardado en
    // evento_sync (se ve y se puede auditar) pero no se aplica. Si el chofer
    // tiene razón, la oficina devuelve el trabajo a «ejecutado» con motivo y
    // el siguiente cierre entra.
    if (in_array($p['flujo'], ['verificado', 'certificado', 'facturable', 'facturado', 'anulado'], true)) {
        Log::aviso('sync_tras_verificacion', ['parada' => (int) $p['id'], 'flujo' => $p['flujo'], 'tipo' => $tipo]);
        return;
    }

    // Un evento de una ronda VIEJA no pisa a uno nuevo (revisión de la Fase 2).
    // Si el primer cierre fue rechazado (un deadlock, por ejemplo) y quedó en
    // la cola, puede llegar DESPUÉS de la reapertura y del segundo cierre: sin
    // esto dejaría la cantidad vieja y la conformidad vieja. La ronda del
    // servidor es cuántas reaperturas ya recibió, sin contar este evento.
    $rondaServidor = (int) Db::col(
        "SELECT COUNT(*) FROM evento_sync
          WHERE jornada_id = :j AND parada_orden = :o AND tipo = 'parada_reabierta' AND uuid <> :u",
        [':j' => $jornadaId, ':o' => $orden, ':u' => (string) ($evento['uuid'] ?? '')]
    );
    if ((int) ($evento['ronda'] ?? 0) < $rondaServidor) {
        Log::aviso('sync_ronda_vieja', ['parada' => (int) $p['id'], 'ronda' => (int) ($evento['ronda'] ?? 0), 'actual' => $rondaServidor]);
        return;
    }

    switch ($tipo) {
        case 'parada_cerrada':
            // Una parada destildada a mano NO se vuelve a tildar sola: el
            // chofer ya dijo que no la hizo y el GPS no lo puede contradecir.
            if ((int) $p['bloqueada'] === 1 && ($datos['origen'] ?? 'manual') !== 'manual') return;

            Db::q(
                'UPDATE parada_ejecucion
                    SET estado = :e, cantidad_real = :c, motivo = NULL,
                        arribo_utc = COALESCE(:a, arribo_utc), cierre_utc = :ci,
                        origen = :o, precision_m = :pr, edad_fix_seg = :ed,
                        ambiguo = :am, bloqueada = 0, actualizado_utc = UTC_TIMESTAMP()
                  WHERE id = :id',
                [
                    ':e'  => 'ejecutada',
                    ':c'  => isset($datos['cantidad_real']) ? (int) $datos['cantidad_real'] : null,
                    ':a'  => isset($datos['arribo_ms']) ? gmdate('Y-m-d H:i:s', intdiv((int) $datos['arribo_ms'], 1000)) : null,
                    ':ci' => $ocurridoUtc,
                    // Con ?? también en la rama del medio: sin origen, leer
                    // $datos['origen'] era un aviso que el manejador de errores
                    // convierte en excepción, y el evento se rechazaba PARA
                    // SIEMPRE (lo encontró la revisión de la Fase 2).
                    ':o'  => in_array($datos['origen'] ?? 'manual', ['flota', 'telefono', 'manual'], true) ? ($datos['origen'] ?? 'manual') : 'manual',
                    ':pr' => isset($datos['precision_m']) ? (int) $datos['precision_m'] : null,
                    ':ed' => isset($datos['edad_fix_seg']) ? (int) $datos['edad_fix_seg'] : null,
                    ':am' => !empty($datos['ambiguo']) ? 1 : 0,
                    ':id' => $p['id'],
                ]
            );
            camca_registrar_conformidad((int) $p['id'], (int) $jornadaId, $datos, $ocurridoUtc, $evento);
            break;

        case 'parada_no_ejecutada':
            Db::q(
                'UPDATE parada_ejecucion
                    SET estado = :e, motivo = :m, cierre_utc = :ci, origen = :o,
                        bloqueada = 1, actualizado_utc = UTC_TIMESTAMP()
                  WHERE id = :id',
                [
                    ':e'  => 'no_ejecutada',
                    ':m'  => mb_substr((string) ($datos['motivo'] ?? ''), 0, 255) ?: null,
                    ':ci' => $ocurridoUtc,
                    ':o'  => 'manual',
                    ':id' => $p['id'],
                ]
            );
            // Si la parada estaba cerrada como hecha y con conformidad, ya no
            // lo está: esa conformidad era sobre un trabajo que no se hizo.
            Db::q('UPDATE conformidad SET vigente = 0 WHERE parada_id = :p AND vigente = 1', [':p' => $p['id']]);
            break;

        case 'parada_reabierta':
            // Destildado a mano: vuelve a planificada Y queda bloqueada, para
            // que el proximo mensaje del GPS no la vuelva a tildar hoy.
            Db::q(
                'UPDATE parada_ejecucion
                    SET estado = :e, cantidad_real = NULL, arribo_utc = NULL, cierre_utc = NULL,
                        origen = :o, bloqueada = 1, actualizado_utc = UTC_TIMESTAMP()
                  WHERE id = :id',
                [':e' => 'planificada', ':o' => 'ninguno', ':id' => $p['id']]
            );
            // La conformidad era sobre el cierre que se acaba de deshacer. Deja
            // de valer, pero queda: que un cliente haya firmado conforme sobre
            // una parada que después se reabrió es un hecho, no un error.
            Db::q('UPDATE conformidad SET vigente = 0 WHERE parada_id = :p AND vigente = 1', [':p' => $p['id']]);
            break;

        case 'arribo':
            Db::q(
                'UPDATE parada_ejecucion
                    SET arribo_utc = COALESCE(arribo_utc, :a), origen = CASE WHEN origen = :n THEN :o ELSE origen END,
                        actualizado_utc = UTC_TIMESTAMP()
                  WHERE id = :id',
                [
                    // El arribo es el INICIO de la permanencia, no el final:
                    // es la hora en que el camion llego, no en que se fue.
                    ':a'  => isset($datos['desde_ms']) ? gmdate('Y-m-d H:i:s', intdiv((int) $datos['desde_ms'], 1000)) : $ocurridoUtc,
                    ':n'  => 'ninguno',
                    ':o'  => 'telefono',
                    ':id' => $p['id'],
                ]
            );
            break;
    }
}

/**
 * La conformidad del cliente que vino con el cierre de la parada (F2.2).
 *
 * Se guarda lo que llegó, sin inventar: un «conforme» sin firma o un rechazo
 * sin motivo se guardan así y el remito los imprime así. El teléfono ya exige
 * las dos cosas; si alguna falta, que se vea en el papel es mejor que
 * descartar lo que el cliente sí dijo.
 */
function camca_registrar_conformidad(int $paradaId, int $jornadaId, array $datos, string $ocurridoUtc, array $evento): void
{
    $c = $datos['conformidad'] ?? null;
    if (!is_array($c) || !in_array($c['resultado'] ?? null, ['conforme', 'rechazado'], true)) return;
    if (!isset($evento['uuid'])) return;

    // Un «conforme» sin firma o sin nombre no es una conformidad: es un
    // casillero tildado. El teléfono ya lo exige; el servidor también, porque
    // lo que manda el teléfono se puede fabricar (revisión de la Fase 2). Se
    // registra el aviso y el cierre queda sin conformidad, que es la verdad.
    if ($c['resultado'] === 'conforme' && (!is_string($c['firma_uuid'] ?? null) || trim((string) ($c['receptor_nombre'] ?? '')) === '')) {
        Log::aviso('conformidad_incompleta', ['parada' => $paradaId]);
        Db::q('UPDATE conformidad SET vigente = 0 WHERE parada_id = :p AND vigente = 1', [':p' => $paradaId]);
        return;
    }

    $texto = static function (mixed $v, int $max): ?string {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    };
    $firma = is_string($c['firma_uuid'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', strtolower($c['firma_uuid']))
        ? strtolower($c['firma_uuid']) : null;

    // Un cierre nuevo reemplaza a la conformidad del anterior.
    Db::q('UPDATE conformidad SET vigente = 0 WHERE parada_id = :p AND vigente = 1', [':p' => $paradaId]);
    Db::q(
        'INSERT INTO conformidad
            (parada_id, jornada_id, evento_uuid, ronda, resultado, receptor_nombre, receptor_documento,
             observaciones, motivo_rechazo, firma_uuid, vigente, ocurrido_utc, recibido_utc, dispositivo_id)
         VALUES (:p, :j, :u, :r, :res, :n, :d, :o, :m, :f, 1, :oc, UTC_TIMESTAMP(), :disp)',
        [
            ':p'    => $paradaId,
            ':j'    => $jornadaId,
            ':u'    => $evento['uuid'],
            ':r'    => (int) ($evento['ronda'] ?? 0),
            ':res'  => $c['resultado'],
            ':n'    => $texto($c['receptor_nombre'] ?? null, 120),
            ':d'    => $texto($c['receptor_documento'] ?? null, 30),
            ':o'    => $texto($c['observaciones'] ?? null, 500),
            ':m'    => $c['resultado'] === 'rechazado' ? $texto($c['motivo_rechazo'] ?? null, 255) : null,
            ':f'    => $firma,
            ':oc'   => $ocurridoUtc,
            ':disp' => $evento['dispositivo_id'] ?? null,
        ]
    );
}

// Si la jornada quedo con todas sus paradas resueltas, se marca en curso o
// cerrada segun corresponda. No se toca una jornada ya cerrada y confirmada.
if ($aceptados !== []) {
    $jornadas = array_values(array_unique(array_filter(array_map(
        static fn($e) => isset($e['jornada_id']) ? (int) $e['jornada_id'] : null,
        $eventos
    ))));
    foreach ($jornadas as $jid) {
        Db::q(
            "UPDATE jornada SET estado = 'en_curso', abierta_utc = COALESCE(abierta_utc, UTC_TIMESTAMP())
              WHERE id = :j AND estado = 'planificada'",
            [':j' => $jid]
        );
    }
}

Http::ok([
    'aceptados'   => $aceptados,
    'duplicados'  => $duplicados,
    'rechazados'  => $rechazados,
    // El cliente solo saca de la cola lo que aparece en aceptados o duplicados.
    'confirmados' => array_merge($aceptados, $duplicados),
    'servidor_utc' => gmdate('c'),
]);
