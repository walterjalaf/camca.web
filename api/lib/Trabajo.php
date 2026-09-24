<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Máquina de estados del trabajo. Obj. 1, paso F1.3.
 *
 * UNA sola función mueve un trabajo: mover(). No hay ningún otro camino, y eso
 * es deliberado — un `UPDATE parada_ejecucion SET flujo = ...` suelto en un
 * handler es exactamente cómo un circuito auditable deja de serlo.
 *
 * Tres garantías, cada una con su motivo:
 *
 *  1. LA TRANSICIÓN TIENE QUE ESTAR EN LA TABLA. No se inventan caminos.
 *  2. EL UPDATE LLEVA EL ESTADO DE ORIGEN EN EL WHERE. Si dos personas mueven
 *     el mismo trabajo a la vez, una sola gana; la otra recibe un conflicto en
 *     vez de pisar el movimiento ajena sin que nadie se entere.
 *  3. TODO MOVIMIENTO DEJA FILA. El historial no se actualiza ni se borra: es
 *     lo que responde "por qué este trabajo está donde está", que es la
 *     pregunta que aparece cuando el cliente discute una factura.
 */
final class Trabajo
{
    /** Estados del circuito, en orden de avance. */
    public const ESTADOS = [
        'planificado', 'asignado', 'en_curso', 'ejecutado', 'verificado',
        'certificado', 'facturable', 'facturado', 'reprogramado', 'anulado',
    ];

    /**
     * Transiciones permitidas. Lo que no está acá, no existe.
     *
     * 'anulado' y 'facturado' son terminales: de un trabajo facturado no se
     * sale sin una nota de crédito, que es otro documento y otro paso (F3.4).
     */
    public const TRANSICIONES = [
        'planificado'  => ['asignado', 'en_curso', 'reprogramado', 'anulado'],
        'asignado'     => ['en_curso', 'planificado', 'reprogramado', 'anulado'],
        'en_curso'     => ['ejecutado', 'reprogramado', 'anulado'],
        // Se puede volver a en_curso: una parada que se dio por terminada y
        // despues se reabre en el telefono tiene que poder volver.
        'ejecutado'    => ['verificado', 'en_curso', 'reprogramado', 'anulado'],
        // Verificado -> ejecutado es el rechazo del supervisor: falta evidencia.
        'verificado'   => ['certificado', 'ejecutado', 'anulado'],
        'certificado'  => ['facturable', 'anulado'],
        // Facturable -> certificado: la propuesta que lo tomaba se descartó
        // (F3.3). Es un retroceso, así que lleva motivo.
        'facturable'   => ['facturado', 'certificado', 'verificado', 'anulado'],
        'facturado'    => [],
        'reprogramado' => ['planificado', 'anulado'],
        'anulado'      => [],
    ];

    /**
     * Movimientos que EXIGEN motivo escrito.
     *
     * Son los que sacan trabajo del circuito o lo mandan para atrás. Sin esto,
     * seis meses después nadie puede decir por qué una parada de la ruta del
     * lunes no se facturó nunca.
     */
    private const EXIGEN_MOTIVO = ['reprogramado', 'anulado'];

    /** Destinos a los que no se llega con un remito vivo (ver verificarInvariantes). */
    private const SIN_REMITO_VIVO = ['planificado', 'asignado', 'en_curso', 'ejecutado', 'reprogramado', 'anulado'];

    /**
     * Orden del circuito, para saber que es "ir para atras".
     *
     * Un retroceso es tan explicable como una anulacion: cuando el cliente
     * discute la factura, un trabajo que entro y salio de verificacion sin una
     * palabra no se puede defender. El docblock de EXIGEN_MOTIVO ya prometia
     * "o lo mandan para atras" y la constante no lo cumplia.
     */
    private const ORDEN = [
        'planificado' => 0, 'asignado' => 1, 'en_curso' => 2, 'ejecutado' => 3,
        'verificado' => 4, 'certificado' => 5, 'facturable' => 6, 'facturado' => 7,
    ];

    private static function esRetroceso(string $desde, string $hacia): bool
    {
        return isset(self::ORDEN[$desde], self::ORDEN[$hacia])
            && self::ORDEN[$hacia] < self::ORDEN[$desde];
    }

    public static function estado(int $paradaId): string
    {
        $f = Db::col('SELECT flujo FROM parada_ejecucion WHERE id = :id', [':id' => $paradaId]);
        if ($f === null || $f === false) throw new ErrorNoEncontrado('No existe esa parada.');
        return (string) $f;
    }

    public static function permitidos(string $desde): array
    {
        return self::TRANSICIONES[$desde] ?? [];
    }

    /**
     * Mueve un trabajo. Devuelve el estado nuevo.
     *
     * @param array $opts  motivo (obligatorio para retroceder, reprogramar o
     *                     anular), usuario_id y contexto.
     */
    public static function mover(int $paradaId, string $hacia, array $opts = []): array
    {
        if (!in_array($hacia, self::ESTADOS, true)) {
            throw new ErrorValidacion(['hacia' => "'$hacia' no es un estado del circuito."]);
        }

        $p = Db::una(
            'SELECT id, jornada_id, flujo, estado, motivo, conciliacion FROM parada_ejecucion WHERE id = :id',
            [':id' => $paradaId]
        );
        if ($p === null) throw new ErrorNoEncontrado('No existe esa parada.');

        $desde = (string) $p['flujo'];

        if ($desde === $hacia) {
            throw new ErrorConflicto(
                "El trabajo ya está en '$hacia'.",
                'TRANSICION_REDUNDANTE'
            );
        }
        if (!in_array($hacia, self::permitidos($desde), true)) {
            $posibles = self::permitidos($desde);
            throw new ErrorConflicto(
                "No se puede pasar de '$desde' a '$hacia'." .
                ($posibles === []
                    ? " '$desde' es un estado final."
                    : ' Desde ahí sólo se puede ir a: ' . implode(', ', $posibles) . '.'),
                'TRANSICION_INVALIDA'
            );
        }

        $motivo = trim((string) ($opts['motivo'] ?? ''));
        $retrocede = self::esRetroceso($desde, $hacia);
        if (($retrocede || in_array($hacia, self::EXIGEN_MOTIVO, true)) && $motivo === '') {
            throw new ErrorValidacion([
                'motivo' => $retrocede
                    ? "Volver de '$desde' a '$hacia' hace falta explicarlo. " .
                      'Sin eso, dentro de seis meses nadie puede decir por qué este trabajo ' .
                      'entró y salió de esa etapa.'
                    : "Para pasar a '$hacia' hace falta decir por qué. " .
                      'Sin eso, dentro de seis meses nadie puede explicar este trabajo.',
            ]);
        }

        self::verificarInvariantes($p, $hacia);

        $usuarioId = $opts['usuario_id'] ?? (Policy::$usuario['id'] ?? null);
        $contexto  = isset($opts['contexto']) ? Hash::canonical($opts['contexto']) : null;

        return Db::tx(static function () use ($paradaId, $p, $desde, $hacia, $motivo, $usuarioId, $contexto): array {
            // El estado de origen va en el WHERE: si otro lo movió mientras
            // tanto, este UPDATE no toca ninguna fila y se avisa, en vez de
            // pisar el movimiento ajeno sin que nadie se entere.
            $st = Db::q(
                'UPDATE parada_ejecucion SET flujo = :hacia, actualizado_utc = UTC_TIMESTAMP()
                  WHERE id = :id AND flujo = :desde',
                [':hacia' => $hacia, ':id' => $paradaId, ':desde' => $desde]
            );
            if ($st->rowCount() !== 1) {
                throw new ErrorConflicto(
                    'Alguien movió este trabajo mientras tanto. Volvé a mirarlo antes de insistir.',
                    'TRANSICION_CARRERA'
                );
            }

            // Con la fila de la parada ya bloqueada por el UPDATE, se vuelve a
            // mirar el remito con lectura bloqueante (la de verificarInvariantes
            // fue sin bloqueo). Emitir y anular un remito bloquean la misma
            // fila, así que esto ve lo último: nadie emitió un remito mientras
            // el trabajo retrocedía, ni lo anuló mientras se certificaba.
            if ($hacia === 'certificado' || in_array($hacia, self::SIN_REMITO_VIVO, true)) {
                $vivo = Db::col('SELECT numero FROM remito WHERE unico_activo = :v LOCK IN SHARE MODE', [':v' => 'REM-' . $paradaId]);
                if ($hacia === 'certificado' && ($vivo === null || $vivo === false)) {
                    throw new ErrorConflicto('El remito de este trabajo se anuló mientras se certificaba.', 'SIN_REMITO');
                }
                if ($hacia !== 'certificado' && $vivo !== null && $vivo !== false) {
                    throw new ErrorConflicto("Se emitió el remito $vivo mientras tanto: primero hay que anularlo.", 'REMITO_VIVO');
                }
            }

            $hash = Hash::auditar('trabajo', $paradaId, 'flujo:' . $hacia, [
                'desde'  => $desde,
                'hacia'  => $hacia,
                'motivo' => $motivo !== '' ? $motivo : null,
            ]);

            Db::q(
                'INSERT INTO trabajo_transicion
                    (parada_id, jornada_id, desde, hacia, motivo, usuario_id, contexto, auditoria_hash, creado_utc)
                 VALUES (:p, :j, :d, :h, :m, :u, :c, :ah, UTC_TIMESTAMP())',
                [
                    ':p'  => $paradaId,
                    ':j'  => (int) $p['jornada_id'],
                    ':d'  => $desde,
                    ':h'  => $hacia,
                    ':m'  => $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
                    ':u'  => $usuarioId,
                    ':c'  => $contexto,
                    ':ah' => $hash,
                ]
            );

            return ['parada_id' => $paradaId, 'desde' => $desde, 'hacia' => $hacia, 'motivo' => $motivo ?: null];
        });
    }

    /**
     * Invariantes que no son parte del grafo pero sí de la realidad.
     *
     * El grafo dice qué movimientos existen; esto dice cuáles tienen sentido
     * con lo que pasó en el campo. Sin esto se puede marcar como ejecutado un
     * trabajo que el chofer nunca tocó.
     */
    private static function verificarInvariantes(array $p, string $hacia): void
    {
        // Un remito vivo es un papel que el cliente ya tiene en la mano. Si el
        // trabajo vuelve atrás o sale del circuito, ese papel queda afirmando
        // algo que la oficina ya no sostiene. Primero se anula el remito (y si
        // está certificado, eso es una nota de crédito, F3.4).
        if (in_array($hacia, self::SIN_REMITO_VIVO, true)) {
            $vivo = Db::col('SELECT numero FROM remito WHERE unico_activo = :v', [':v' => 'REM-' . (int) $p['id']]);
            if ($vivo !== null && $vivo !== false) {
                throw new ErrorConflicto(
                    "Este trabajo tiene el remito $vivo, que el cliente ya puede tener en la mano. " .
                    "Para llevarlo a '$hacia' primero hay que anular ese remito, con su motivo.",
                    'REMITO_VIVO'
                );
            }
        }

        // 'ejecutado' exige que el chofer la haya dado por HECHA.
        //
        // Antes esto sólo rechazaba 'planificada', y una parada cerrada como
        // NO HECHA pasaba limpio: de ahí seguía a verificado, certificado,
        // facturable y facturado sin tocar una sola barrera. Se terminaba
        // facturando un baño que nunca se limpió, y el historial no mostraba
        // ninguna excepción. El camino de una no ejecutada es reprogramarla o
        // anularla, no darla por hecha.
        if ($hacia === 'ejecutado' && $p['estado'] !== 'ejecutada') {
            throw new ErrorConflicto(
                $p['estado'] === 'no_ejecutada'
                    ? 'Esta parada está cerrada como NO HECHA' .
                      ($p['motivo'] ? ' («' . mb_substr((string) $p['motivo'], 0, 80) . '»)' : '') .
                      '. Un trabajo que no se hizo no se da por ejecutado: se reprograma o se anula.'
                    : 'Esta parada todavía no tiene resultado en el campo: no está ni hecha ni con motivo. ' .
                      'Un trabajo no se puede dar por ejecutado antes de que el chofer lo cierre.',
                'SIN_RESULTADO_EN_CAMPO'
            );
        }

        if ($hacia === 'verificado') {
            $incompletas = (int) Db::col(
                'SELECT COUNT(*) FROM evidencia WHERE parada_id = :p AND completa = 0',
                [':p' => (int) $p['id']]
            );
            if ($incompletas > 0) {
                throw new ErrorConflicto(
                    "Quedan $incompletas evidencias sin terminar de subir. " .
                    'Verificar un trabajo cuya evidencia todavía vive adentro de un teléfono ' .
                    'es exactamente lo que después aparece como faltante.',
                    'EVIDENCIA_INCOMPLETA'
                );
            }

            // La firma de la conformidad viaja por el carril lento: el evento
            // de cierre puede estar en el servidor y la firma todavía en el
            // teléfono, SIN fila en `evidencia` (la fila nace con el primer
            // trozo). Contar las incompletas no la ve. Verificar ahí es emitir
            // después un remito «conforme» sin la firma que lo sostiene.
            $firmaPendiente = Db::col(
                "SELECT c.firma_uuid FROM conformidad c
                  WHERE c.parada_id = :p AND c.vigente = 1 AND c.firma_uuid IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM evidencia e WHERE e.uuid = c.firma_uuid AND e.completa = 1)
                  LIMIT 1",
                [':p' => (int) $p['id']]
            );
            if ($firmaPendiente !== null && $firmaPendiente !== false) {
                throw new ErrorConflicto(
                    'La firma del cliente todavía no terminó de llegar desde el teléfono. ' .
                    'Hay que esperar a que sincronice: verificar ahora dejaría la conformidad sin su firma.',
                    'FIRMA_PENDIENTE'
                );
            }

            // Y tiene que haber ALGO que respalde el trabajo.
            //
            // Contar sólo las evidencias incompletas daba cero cuando no hay
            // NINGUNA evidencia, así que una parada sin fotos, sin firma y sin
            // evidencia de posición se verificaba sola. Verificar es afirmar
            // que el trabajo se hizo: si ni el rastro de flota, ni el GPS del
            // celular, ni una foto lo respaldan, no hay nada que verificar.
            $completas = (int) Db::col(
                'SELECT COUNT(*) FROM evidencia WHERE parada_id = :p AND completa = 1',
                [':p' => (int) $p['id']]
            );
            $conPosicion = in_array($p['conciliacion'] ?? 'pendiente',
                ['doble', 'solo_flota', 'solo_telefono'], true);

            if ($completas === 0 && !$conPosicion) {
                throw new ErrorConflicto(
                    'Esta parada no tiene nada que la respalde: ni fotos, ni firma, ni evidencia ' .
                    'de posición de ninguna de las dos fuentes. Verificarla sería afirmar algo que ' .
                    'nadie puede sostener si el cliente lo discute.',
                    'SIN_RESPALDO'
                );
            }
        }

        // R10 de la casa: mientras la firma sea 'ninguna' NO existe la
        // certificación, y no se le llama certificado a algo que no lo es.
        //
        // Desde F2.3 la puerta se abre, pero sólo si es verdad las tres
        // veces: hay un remito vivo, el cliente estuvo CONFORME, y ese
        // remito verifica entero en la cadena con una firma de confianza.
        // Certificar es afirmarle al cliente algo que él aceptó; sin su
        // conformidad no hay nada que certificar.
        if ($hacia === 'certificado') {
            if (Firma::alg() === 'ninguna') {
                throw new ErrorConflicto(
                    'Todavía no hay firma criptográfica configurada, así que no hay certificación. ' .
                    'Mientras firma_alg sea "ninguna", llamarle certificado a esto sería mentir.',
                    'SIN_FIRMA'
                );
            }
            $rem = Remito::deParada((int) $p['id']);
            if ($rem === null) {
                throw new ErrorConflicto('Este trabajo no tiene remito. Se certifica el remito que el cliente conformó: primero hay que emitirlo.', 'SIN_REMITO');
            }
            if ($rem['conformidad'] !== 'conforme') {
                throw new ErrorConflicto(
                    "El remito {$rem['numero']} " . ($rem['conformidad'] === 'rechazado'
                        ? 'dice que el cliente NO estuvo conforme'
                        : 'no tiene conformidad del cliente') .
                    '. No hay nada que certificar: la certificación afirma algo que el cliente aceptó.',
                    'SIN_CONFORMIDAD'
                );
            }
            // Conforme SIN firma del cliente no se certifica: el servidor ya no
            // lo acepta del teléfono, pero un remito de antes podría tenerlo.
            if (($rem['datos']['conformidad']['firma'] ?? null) === null) {
                throw new ErrorConflicto(
                    "El remito {$rem['numero']} dice conforme, pero sin la firma de quien recibió. " .
                    'Una conformidad sin firma no se certifica.',
                    'SIN_FIRMA_CLIENTE'
                );
            }
            $v = Certificacion::verificarRemito($rem['id']);
            if (!$v['valido']) {
                throw new ErrorConflicto(
                    "El remito {$rem['numero']} no verifica: " . implode(' ', $v['problemas']),
                    'REMITO_NO_VERIFICA'
                );
            }
            if (!$v['firmado']) {
                throw new ErrorConflicto(
                    "El remito {$rem['numero']} se emitió sin firma digital (antes de que hubiera clave). " .
                    'Para certificarlo hay que anularlo y emitirlo de nuevo, ya firmado.',
                    'REMITO_SIN_FIRMA'
                );
            }
        }
    }

    /**
     * Lleva el circuito hasta 'ejecutado' cuando el campo ya lo dio por hecho.
     *
     * El chofer cierra la parada desde el teléfono y eso escribe `estado`, no
     * `flujo` (ver 0012: son dos columnas a propósito). Hasta que alguien de la
     * oficina la toma, el circuito sigue en 'planificado'. Esto hace los pasos
     * que faltan —en_curso, ejecutado— por mover(), así que cada uno queda en
     * el historial con su eslabón de auditoría y el contexto dice de dónde
     * salió: del cierre en el campo, con su hora.
     *
     * No inventa nada: si el chofer no la cerró como hecha y el circuito
     * todavía no llegó a 'ejecutado', se rechaza con la razón de verdad («no
     * se hizo») en vez de dejar que mover() conteste «transición inválida»,
     * que es cierto pero no le dice a la oficina qué pasa.
     *
     * @return array los movimientos hechos, en orden (puede ser vacío)
     */
    public static function alinearConCampo(int $paradaId, ?int $usuarioId = null): array
    {
        $p = Db::una('SELECT id, flujo, estado, motivo, cierre_utc FROM parada_ejecucion WHERE id = :id', [':id' => $paradaId]);
        if ($p === null) throw new ErrorNoEncontrado('No existe esa parada.');

        if ($p['estado'] !== 'ejecutada' && in_array($p['flujo'], ['planificado', 'asignado', 'en_curso'], true)) {
            self::verificarInvariantes($p, 'ejecutado');   // lanza SIN_RESULTADO_EN_CAMPO
        }
        if ($p['estado'] !== 'ejecutada') return [];

        $camino = match ((string) $p['flujo']) {
            'planificado', 'asignado' => ['en_curso', 'ejecutado'],
            'en_curso'                => ['ejecutado'],
            default                   => [],
        };

        $hechos = [];
        foreach ($camino as $hacia) {
            $hechos[] = self::mover($paradaId, $hacia, [
                'usuario_id' => $usuarioId,
                'contexto'   => ['origen' => 'cierre_en_campo', 'cierre_utc' => $p['cierre_utc']],
            ]);
        }
        return $hechos;
    }

    /**
     * Historial completo de un trabajo, en orden.
     *
     * Devuelve además el estado reconstruido paso a paso, para poder comprobar
     * que la columna `flujo` y el historial no se separaron nunca.
     */
    public static function historial(int $paradaId): array
    {
        $filas = Db::todas(
            'SELECT id, desde, hacia, motivo, usuario_id, contexto, auditoria_hash, creado_utc
               FROM trabajo_transicion WHERE parada_id = :p ORDER BY id',
            [':p' => $paradaId]
        );

        $reconstruido = 'planificado';
        $coherente = true;
        foreach ($filas as $f) {
            if ($f['desde'] !== $reconstruido) $coherente = false;
            $reconstruido = (string) $f['hacia'];
        }

        $actual = self::estado($paradaId);

        return [
            'parada_id'     => $paradaId,
            'estado'        => $actual,
            'movimientos'   => $filas,
            // Si esto viene en false, alguien movió el flujo sin pasar por
            // mover(). Se dice, no se disimula.
            'coherente'     => $coherente && $reconstruido === $actual,
            'reconstruido'  => $reconstruido,
        ];
    }

    /** Cuántos trabajos hay en cada estado, para el panel de oficina. */
    public static function resumen(?int $jornadaId = null): array
    {
        $sql = 'SELECT flujo, COUNT(*) AS n FROM parada_ejecucion';
        $par = [];
        if ($jornadaId !== null) { $sql .= ' WHERE jornada_id = :j'; $par[':j'] = $jornadaId; }
        $sql .= ' GROUP BY flujo';

        $salida = array_fill_keys(self::ESTADOS, 0);
        foreach (Db::todas($sql, $par) as $f) $salida[$f['flujo']] = (int) $f['n'];
        return $salida;
    }
}
