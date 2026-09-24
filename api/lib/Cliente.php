<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Maestro de clientes. Obj. 3, paso F3.1.
 *
 * Tres cosas y nada más:
 *   · validar el CUIT con su dígito verificador y que no se repita;
 *   · proponer fusiones de duplicados, con la decisión SIEMPRE humana;
 *   · fusionar de forma reversible, sin tocar documentos ya emitidos.
 *
 * Qué clientes son el mismo es una decisión de CAMCA (H8). El código sólo
 * sugiere, muestra qué se mueve antes de moverlo, y deja deshacerlo.
 */
final class Cliente
{
    // 50, 51 y 55: los CUIT genéricos de personas y entidades del exterior
    // (revisión de la Fase 3: se rechazaban clientes extranjeros válidos).
    private const PREFIJOS_CUIT = ['20', '23', '24', '27', '30', '33', '34', '50', '51', '55'];

    // ------------------------------------------------------------------
    // CUIT
    // ------------------------------------------------------------------

    /**
     * El CUIT normalizado (XX-XXXXXXXX-X) si es válido, o null.
     *
     * Módulo 11 con los pesos 5-4-3-2-7-6-5-4-3-2. Un resto que da 10 no es
     * un CUIT válido: ARCA nunca asigna esa combinación (por eso existen los
     * prefijos 23 y 33).
     */
    public static function cuit(?string $entrada): ?string
    {
        $d = preg_replace('/\D/', '', (string) $entrada) ?? '';
        if (strlen($d) !== 11 || !in_array(substr($d, 0, 2), self::PREFIJOS_CUIT, true)) return null;
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($pesos as $i => $p) $suma += (int) $d[$i] * $p;
        $v = 11 - $suma % 11;
        $v = $v === 11 ? 0 : $v;
        if ($v === 10 || $v !== (int) $d[10]) return null;
        return substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . $d[10];
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    public static function listar(): array
    {
        $accesos = self::accesos();
        return array_map(static fn(array $c) => self::publico($c) + ['accesos' => $accesos[(int) $c['id']] ?? []], Db::todas(
            'SELECT c.*, (SELECT COUNT(*) FROM sitio s WHERE s.cliente_id = c.id) AS sitios,
                    (SELECT COUNT(*) FROM remito r WHERE r.cliente_id = c.id) AS remitos,
                    f.nombre AS fusionado_en_nombre
               FROM cliente c LEFT JOIN cliente f ON f.id = c.fusionado_en
              ORDER BY c.activo DESC, c.nombre'
        ));
    }

    public static function uno(int $id): array
    {
        $c = Db::una('SELECT * FROM cliente WHERE id = :id', [':id' => $id]);
        if ($c === null) throw new ErrorNoEncontrado('No existe ese cliente.');
        return $c;
    }

    private static function publico(array $c): array
    {
        return [
            'id'                => (int) $c['id'],
            'nombre'            => $c['nombre'],
            'razon_social'      => $c['razon_social'],
            'tipo'              => $c['tipo'],
            'cuit'              => $c['cuit'],
            'condicion_iva'     => $c['condicion_iva'],
            'domicilio_fiscal'  => $c['domicilio_fiscal'],
            'email_facturacion' => $c['email_facturacion'],
            'provisorio'        => (int) $c['provisorio'] === 1,
            'activo'            => (int) $c['activo'] === 1,
            'fusionado_en'      => $c['fusionado_en'] === null ? null
                : ['id' => (int) $c['fusionado_en'], 'nombre' => $c['fusionado_en_nombre'] ?? null],
            'sitios'            => (int) ($c['sitios'] ?? 0),
            'remitos'           => (int) ($c['remitos'] ?? 0),
            // Lo que falta para poder facturarle. La pantalla lo muestra así,
            // con las palabras, y no como un tilde que no dice qué falta.
            'falta'             => self::falta($c),
        ];
    }

    /** Qué le falta a un cliente para poder facturarle. Vacío = listo. */
    public static function falta(array $c): array
    {
        $f = [];
        if (trim((string) $c['razon_social']) === '') $f[] = 'razón social';
        if ($c['cuit'] === null) $f[] = 'CUIT';
        if ($c['condicion_iva'] === null) $f[] = 'condición frente al IVA';
        return $f;
    }

    // ------------------------------------------------------------------
    // Edición
    // ------------------------------------------------------------------

    /**
     * Guarda los datos fiscales. Un cliente deja de ser provisorio cuando no
     * le falta nada para facturarle.
     */
    public static function actualizar(int $id, array $datos, ?int $usuarioId): array
    {
        $c = self::uno($id);
        if ((int) $c['activo'] !== 1) {
            throw new ErrorConflicto('Este cliente se fusionó en otro: se edita el que quedó.', 'CLIENTE_FUSIONADO');
        }

        $cambios = [];
        if (array_key_exists('cuit', $datos)) {
            $crudo = trim((string) $datos['cuit']);
            if ($crudo === '') {
                $cambios['cuit'] = null;
            } else {
                $cuit = self::cuit($crudo);
                if ($cuit === null) {
                    throw new ErrorValidacion(['cuit' => 'Ese CUIT no es válido: el dígito verificador no coincide.']);
                }
                $cambios['cuit'] = $cuit;
            }
        }
        foreach (['razon_social' => 160, 'domicilio_fiscal' => 255] as $k => $max) {
            if (array_key_exists($k, $datos)) {
                $v = trim((string) $datos[$k]);
                $cambios[$k] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        if (array_key_exists('email_facturacion', $datos)) {
            $v = trim((string) $datos['email_facturacion']);
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new ErrorValidacion(['email_facturacion' => 'Email inválido.']);
            }
            $cambios['email_facturacion'] = $v === '' ? null : $v;
        }
        if (array_key_exists('tipo', $datos)) {
            $v = $datos['tipo'];
            if ($v !== null && $v !== '' && !in_array($v, ['empresa', 'persona'], true)) {
                throw new ErrorValidacion(['tipo' => 'Empresa o persona.']);
            }
            $cambios['tipo'] = $v === '' ? null : $v;
        }
        if (array_key_exists('condicion_iva', $datos)) {
            $v = $datos['condicion_iva'];
            if ($v !== null && $v !== '' && !in_array($v, ['responsable_inscripto', 'monotributo', 'exento', 'consumidor_final'], true)) {
                throw new ErrorValidacion(['condicion_iva' => 'Condición frente al IVA desconocida.']);
            }
            $cambios['condicion_iva'] = $v === '' ? null : $v;
        }
        if ($cambios === []) return self::publico(Db::una('SELECT * FROM cliente WHERE id = :id', [':id' => $id]));

        $nuevo = array_merge($c, $cambios);
        $cambios['provisorio'] = self::falta($nuevo) === [] ? 0 : 1;
        $cambios['cuit_activo'] = $nuevo['cuit'];

        $sets = implode(', ', array_map(static fn($k) => "$k = :$k", array_keys($cambios)));
        $par = [':id' => $id];
        foreach ($cambios as $k => $v) $par[':' . $k] = $v;

        try {
            Db::q("UPDATE cliente SET $sets, actualizado_utc = UTC_TIMESTAMP() WHERE id = :id", $par);
        } catch (PDOException $e) {
            if (!Db::esDuplicado($e)) throw $e;
            $otro = Db::una('SELECT id, nombre FROM cliente WHERE cuit_activo = :c', [':c' => $cambios['cuit_activo']]);
            throw new ErrorConflicto(
                "Ese CUIT ya lo tiene «" . ($otro['nombre'] ?? '?') . "». Si son el mismo cliente, fusionalos.",
                'CUIT_DUPLICADO'
            );
        }

        Hash::auditar('cliente', $id, 'actualizado', ['cambios' => array_diff_key($cambios, ['cuit_activo' => 1])]);
        return self::publico(Db::una('SELECT * FROM cliente WHERE id = :id', [':id' => $id]));
    }

    // ------------------------------------------------------------------
    // Sugerencias de fusión
    // ------------------------------------------------------------------

    /**
     * Pares de clientes activos que PARECEN el mismo. Sólo sugiere: el que
     * decide es alguien de CAMCA que conoce a los clientes.
     *
     * Tres señales, en orden de fuerza: mismo CUIT; un nombre contenido en el
     * otro por palabras enteras («Terusi» en «Terusi Costanera»); nombres muy
     * parecidos (errores de tipeo: «Federico Fernadez»).
     */
    public static function sugerencias(): array
    {
        $cs = Db::todas('SELECT id, nombre, cuit FROM cliente WHERE activo = 1 ORDER BY nombre');
        $norm = static function (string $s): string {
            $s = mb_strtolower($s);
            $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
            return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
        };
        $salida = [];
        $n = count($cs);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$a, $b] = [$cs[$i], $cs[$j]];
                $na = $norm($a['nombre']);
                $nb = $norm($b['nombre']);
                $razon = null;
                if ($a['cuit'] !== null && $a['cuit'] === $b['cuit']) {
                    $razon = 'mismo CUIT';
                } elseif ($na !== '' && $nb !== '' && (
                    preg_match('/(^| )' . preg_quote($na, '/') . '( |$)/', $nb) ||
                    preg_match('/(^| )' . preg_quote($nb, '/') . '( |$)/', $na))) {
                    $razon = 'un nombre contiene al otro';
                } else {
                    $lmax = max(strlen($na), strlen($nb));
                    if ($lmax >= 6 && levenshtein($na, $nb) <= max(1, intdiv($lmax, 8))) $razon = 'nombres casi iguales';
                }
                if ($razon !== null) {
                    // Se propone fusionar en el de nombre más largo: suele ser
                    // el más específico («Terusi Costanera» antes que «Terusi»),
                    // y la persona que confirma lo puede dar vuelta.
                    [$origen, $destino] = mb_strlen($a['nombre']) <= mb_strlen($b['nombre']) ? [$a, $b] : [$b, $a];
                    $salida[] = [
                        'origen'  => ['id' => (int) $origen['id'], 'nombre' => $origen['nombre']],
                        'destino' => ['id' => (int) $destino['id'], 'nombre' => $destino['nombre']],
                        'razon'   => $razon,
                    ];
                }
            }
        }
        return $salida;
    }

    // ------------------------------------------------------------------
    // Fusión
    // ------------------------------------------------------------------

    /**
     * Qué pasaría si se fusiona $origen en $destino, sin hacer nada.
     *
     * Devuelve además una CLAVE DE CONFIRMACIÓN atada a este estado exacto:
     * si entre que alguien miró la previsualización y apretó «confirmar» se
     * agregó un sitio o cambió un dato, la clave deja de servir y hay que
     * volver a mirar. Confirmar algo distinto de lo que se vio no es confirmar.
     */
    public static function previsualizar(int $origenId, int $destinoId): array
    {
        [$o, $d] = self::validarPar($origenId, $destinoId);
        $sitios = array_map('intval', array_column(
            Db::todas('SELECT id FROM sitio WHERE cliente_id = :c ORDER BY id', [':c' => $origenId]), 'id'));
        $docs = (int) Db::col('SELECT COUNT(*) FROM registro WHERE cliente_id = :c', [':c' => $origenId])
              + (int) Db::col('SELECT COUNT(*) FROM remito WHERE cliente_id = :c', [':c' => $origenId]);

        $conflictoCuit = $o['cuit'] !== null && $d['cuit'] !== null && $o['cuit'] !== $d['cuit'];

        return [
            'origen'   => ['id' => $origenId, 'nombre' => $o['nombre'], 'cuit' => $o['cuit']],
            'destino'  => ['id' => $destinoId, 'nombre' => $d['nombre'], 'cuit' => $d['cuit']],
            'sitios'   => count($sitios),
            'nombres_sitios' => array_column(Db::todas(
                'SELECT nombre FROM sitio WHERE cliente_id = :c ORDER BY id LIMIT 20', [':c' => $origenId]), 'nombre'),
            'documentos_que_no_cambian' => $docs,
            'aviso_cuit' => $conflictoCuit
                ? "Tienen CUIT distintos ({$o['cuit']} y {$d['cuit']}): casi seguro NO son el mismo cliente."
                : null,
            'confirmacion' => self::clave($origenId, $destinoId, $sitios, $o, $d),
        ];
    }

    /**
     * Fusiona, si la clave corresponde a lo que se previsualizó.
     * Mueve los sitios, desactiva el origen y anota qué se movió.
     */
    public static function fusionar(int $origenId, int $destinoId, string $confirmacion, string $motivo, ?int $usuarioId): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') throw new ErrorValidacion(['motivo' => 'Hace falta decir por qué son el mismo cliente.']);

        return Db::txReintentable(static function () use ($origenId, $destinoId, $confirmacion, $motivo, $usuarioId): array {
            // Los dos clientes bloqueados: nadie los cambia mientras se fusionan.
            Db::todas('SELECT id FROM cliente WHERE id IN (:a, :b) FOR UPDATE', [':a' => $origenId, ':b' => $destinoId]);
            [$o, $d] = self::validarPar($origenId, $destinoId);
            $sitios = array_map('intval', array_column(
                Db::todas('SELECT id FROM sitio WHERE cliente_id = :c ORDER BY id FOR UPDATE', [':c' => $origenId]), 'id'));

            if (!hash_equals(self::clave($origenId, $destinoId, $sitios, $o, $d), $confirmacion)) {
                throw new ErrorConflicto(
                    'Algo cambió desde que se previsualizó la fusión (un sitio nuevo, un dato distinto). ' .
                    'Hay que volver a mirarla antes de confirmar.',
                    'FUSION_DESACTUALIZADA'
                );
            }
            if ($o['cuit'] !== null && $d['cuit'] !== null && $o['cuit'] !== $d['cuit']) {
                throw new ErrorConflicto('Tienen CUIT distintos: no son el mismo cliente.', 'CUIT_DISTINTO');
            }

            if ($sitios !== []) {
                Db::q('UPDATE sitio SET cliente_id = :d WHERE cliente_id = :o', [':d' => $destinoId, ':o' => $origenId]);
            }
            // El destino hereda los datos fiscales que le falten del origen.
            $hereda = [];
            foreach (['razon_social', 'cuit', 'tipo', 'condicion_iva', 'domicilio_fiscal', 'email_facturacion'] as $k) {
                if ($d[$k] === null && $o[$k] !== null) $hereda[$k] = $o[$k];
            }
            Db::q('UPDATE cliente SET activo = 0, fusionado_en = :d, cuit_activo = NULL, actualizado_utc = UTC_TIMESTAMP()
                    WHERE id = :o', [':d' => $destinoId, ':o' => $origenId]);
            if ($hereda !== []) {
                $nuevo = array_merge($d, $hereda);
                $hereda['cuit_activo'] = $nuevo['cuit'];
                $hereda['provisorio'] = self::falta($nuevo) === [] ? 0 : 1;
                $sets = implode(', ', array_map(static fn($k) => "$k = :$k", array_keys($hereda)));
                $par = [':id' => $destinoId];
                foreach ($hereda as $k => $v) $par[':' . $k] = $v;
                Db::q("UPDATE cliente SET $sets, actualizado_utc = UTC_TIMESTAMP() WHERE id = :id", $par);
            }

            Db::q(
                'INSERT INTO cliente_fusion (origen_id, destino_id, motivo, sitios_json, usuario_id, creado_utc)
                 VALUES (:o, :d, :m, :s, :u, UTC_TIMESTAMP())',
                [':o' => $origenId, ':d' => $destinoId, ':m' => mb_substr($motivo, 0, 255),
                 ':s' => json_encode(['sitios' => $sitios, 'heredado' => array_keys(array_diff_key($hereda, ['cuit_activo' => 1, 'provisorio' => 1]))]),
                 ':u' => $usuarioId]
            );
            $fid = Db::insertarId();
            Hash::auditar('cliente', $origenId, 'fusionado', [
                'en' => $destinoId, 'sitios' => $sitios, 'motivo' => $motivo, 'fusion' => $fid,
            ]);
            return ['fusion' => $fid, 'sitios_movidos' => count($sitios)];
        });
    }

    /**
     * Deshace una fusión: el origen vuelve a estar activo con EXACTAMENTE los
     * sitios que se le movieron, y el destino pierde lo que heredó.
     *
     * Sólo la última fusión que tocó al destino, y sólo si el destino sigue
     * activo: deshacer en otro orden dejaría sitios en un cliente que ya no
     * existe como tal.
     */
    public static function deshacer(int $fusionId, ?int $usuarioId): array
    {
        return Db::txReintentable(static function () use ($fusionId, $usuarioId): array {
            $f = Db::una('SELECT * FROM cliente_fusion WHERE id = :id FOR UPDATE', [':id' => $fusionId]);
            if ($f === null) throw new ErrorNoEncontrado('No existe esa fusión.');
            if ($f['deshecha_utc'] !== null) throw new ErrorConflicto('Esa fusión ya se deshizo.', 'FUSION_DESHECHA');

            $d = self::uno((int) $f['destino_id']);
            if ((int) $d['activo'] !== 1) {
                throw new ErrorConflicto(
                    "«{$d['nombre']}» se fusionó después en otro cliente. Primero hay que deshacer esa fusión.",
                    'FUSION_ENCADENADA'
                );
            }
            $posterior = Db::col(
                'SELECT id FROM cliente_fusion WHERE destino_id = :d AND id > :id AND deshecha_utc IS NULL LIMIT 1',
                [':d' => $f['destino_id'], ':id' => $fusionId]
            );
            if ($posterior !== null && $posterior !== false) {
                throw new ErrorConflicto('Hay una fusión posterior sobre el mismo cliente: se deshace primero esa.', 'FUSION_ENCADENADA');
            }

            $info = json_decode((string) $f['sitios_json'], true) ?: [];
            $sitios = array_map('intval', $info['sitios'] ?? []);
            if ($sitios !== []) {
                $marcas = implode(',', array_fill(0, count($sitios), '?'));
                Db::q("UPDATE sitio SET cliente_id = ? WHERE cliente_id = ? AND id IN ($marcas)",
                      array_merge([(int) $f['origen_id'], (int) $f['destino_id']], $sitios));
            }
            // Lo heredado vuelve a quedar vacío en el destino, pero sólo si
            // sigue siendo lo heredado. El origen conserva sus propios datos
            // (fusionado no se edita), así que un valor distinto del suyo es
            // una corrección que la oficina hizo después, y se respeta: borrarla
            // dejaba al cliente sin datos para facturarle (revisión de la Fase 3).
            $origen = self::uno((int) $f['origen_id']);
            $conservados = [];
            foreach ($info['heredado'] ?? [] as $k) {
                if (!in_array($k, ['razon_social', 'cuit', 'tipo', 'condicion_iva', 'domicilio_fiscal', 'email_facturacion'], true)) continue;
                if ($d[$k] !== $origen[$k]) { $conservados[] = $k; continue; }
                Db::q("UPDATE cliente SET $k = NULL WHERE id = :d", [':d' => $f['destino_id']]);
            }
            $destino = self::uno((int) $f['destino_id']);
            Db::q('UPDATE cliente SET cuit_activo = :c, provisorio = :p WHERE id = :d',
                  [':c' => $destino['cuit'], ':p' => self::falta($destino) === [] ? 0 : 1, ':d' => $f['destino_id']]);
            try {
                Db::q('UPDATE cliente SET activo = 1, fusionado_en = NULL, cuit_activo = :c, actualizado_utc = UTC_TIMESTAMP() WHERE id = :o',
                      [':c' => $origen['cuit'], ':o' => $f['origen_id']]);
            } catch (PDOException $e) {
                if (!Db::esDuplicado($e)) throw $e;
                $otro = Db::una('SELECT nombre FROM cliente WHERE cuit_activo = :c', [':c' => $origen['cuit']]);
                throw new ErrorConflicto("El CUIT {$origen['cuit']} de «{$origen['nombre']}» ahora lo tiene «" . ($otro['nombre'] ?? '?') .
                    '»: hay que resolver eso antes de deshacer la fusión.', 'CUIT_EN_USO');
            }
            Db::q('UPDATE cliente_fusion SET deshecha_utc = UTC_TIMESTAMP(), deshecha_por = :u WHERE id = :id',
                  [':u' => $usuarioId, ':id' => $fusionId]);

            Hash::auditar('cliente', (int) $f['origen_id'], 'fusion_deshecha', ['fusion' => $fusionId, 'sitios' => $sitios, 'conservados' => $conservados]);
            return ['fusion' => $fusionId, 'sitios_devueltos' => count($sitios), 'conservados' => $conservados];
        });
    }

    /**
     * Da acceso al portal (F3.10) a una persona del cliente. La contraseña se
     * genera acá y se devuelve UNA vez: no se guarda en claro en ningún lado,
     * así que si se pierde se genera otra (quitar el acceso y volver a darlo).
     *
     * Un acceso que se quitó y se vuelve a dar al mismo email del mismo
     * cliente reusa el usuario, con contraseña nueva y sin ninguna de sus
     * sesiones viejas.
     */
    public static function crearAcceso(int $clienteId, string $nombre, string $email, ?int $usuarioId): array
    {
        $c = self::uno($clienteId);
        if ((int) $c['activo'] !== 1) throw new ErrorConflicto('Ese cliente se fusionó en otro: el acceso se le da al que quedó.', 'CLIENTE_FUSIONADO');
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ErrorValidacion(['email' => 'Email inválido.']);
        // Sin letras que se confunden al dictarla por teléfono (0/O, 1/l/I).
        $alfabeto = 'abcdefghjkmnpqrstuvwxyz23456789';
        $grupo = static function () use ($alfabeto): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            return $s;
        };
        $clave = 'Camca-' . $grupo() . '-' . $grupo() . '-' . $grupo();
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $nombre = trim($nombre) !== '' ? mb_substr(trim($nombre), 0, 120) : $c['nombre'];

        $id = Db::txReintentable(static function () use ($clienteId, $email, $hash, $nombre): int {
            // Sin FOR UPDATE sobre el email: si no existe, eso bloquea un
            // HUECO del índice y dos altas simultáneas se traban al insertar.
            // La que existe se bloquea por su id; la que no, la cuida el UNIQUE.
            $previo = Db::una('SELECT id FROM usuario WHERE email = :e', [':e' => $email]);
            if ($previo !== null) {
                $previo = Db::una('SELECT id, rol, cliente_id, activo FROM usuario WHERE id = :i FOR UPDATE', [':i' => $previo['id']]);
                // Sólo se reactiva lo que ya era de ESTE cliente. Un email de
                // otro cliente, o de alguien de la oficina, no se toca.
                if ($previo['rol'] !== Policy::CLIENTE || (int) $previo['cliente_id'] !== $clienteId || (int) $previo['activo'] === 1) {
                    throw new ErrorConflicto('Ya hay un usuario con ese email.', 'EMAIL_DUPLICADO');
                }
                Db::q('UPDATE usuario SET pass_hash = :h, nombre = :n, activo = 1 WHERE id = :i',
                      [':h' => $hash, ':n' => $nombre, ':i' => $previo['id']]);
                // Un login que corría justo mientras se quitaba el acceso pudo
                // dejar una sesión sin revocar, muerta sólo por activo = 0.
                // Al reactivar no puede revivir: se empieza sin ninguna.
                Db::q('UPDATE sesion SET revocada_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND revocada_utc IS NULL',
                      [':i' => $previo['id']]);
                return (int) $previo['id'];
            }
            try {
                Db::q("INSERT INTO usuario (rol, nombre, email, pass_hash, cliente_id, activo, creado_utc)
                       VALUES ('cliente', :n, :e, :h, :c, 1, UTC_TIMESTAMP())",
                      [':n' => $nombre, ':e' => $email, ':h' => $hash, ':c' => $clienteId]);
            } catch (PDOException $e) {
                if (Db::esDuplicado($e)) throw new ErrorConflicto('Ya hay un usuario con ese email.', 'EMAIL_DUPLICADO');
                throw $e;
            }
            return Db::insertarId();
        });
        Hash::auditar('usuario', $id, 'acceso_portal', ['cliente' => $clienteId, 'email' => $email, 'por' => $usuarioId]);
        return ['usuario_id' => $id, 'email' => $email, 'clave' => $clave];
    }

    /**
     * Quita el acceso al portal. Corta en el acto las sesiones abiertas: la
     * persona que dejó la empresa del cliente no sigue viendo sus remitos
     * hasta que venza el token. Sólo alcanza a usuarios con rol cliente: por
     * acá no se da de baja a nadie de la oficina.
     */
    public static function quitarAcceso(int $usuarioCliente, ?int $usuarioId): array
    {
        $u = Db::tx(static function () use ($usuarioCliente): array {
            $u = Db::una("SELECT id, email, cliente_id, activo FROM usuario WHERE id = :i AND rol = 'cliente' FOR UPDATE",
                         [':i' => $usuarioCliente]);
            if ($u === null) throw new ErrorNoEncontrado('No existe ese acceso.');
            Db::q('UPDATE usuario SET activo = 0 WHERE id = :i', [':i' => $u['id']]);
            Db::q('UPDATE sesion SET revocada_utc = UTC_TIMESTAMP() WHERE usuario_id = :i AND revocada_utc IS NULL', [':i' => $u['id']]);
            return $u;
        });
        Hash::auditar('usuario', (int) $u['id'], 'acceso_portal_quitado', ['cliente' => (int) $u['cliente_id'], 'por' => $usuarioId]);
        return ['usuario_id' => (int) $u['id'], 'email' => $u['email']];
    }

    /** Los accesos vigentes al portal, por cliente. */
    private static function accesos(): array
    {
        $por = [];
        foreach (Db::todas("SELECT id, cliente_id, email, nombre FROM usuario WHERE rol = 'cliente' AND activo = 1 ORDER BY email") as $u) {
            $por[(int) $u['cliente_id']][] = ['usuario_id' => (int) $u['id'], 'email' => $u['email'], 'nombre' => $u['nombre']];
        }
        return $por;
    }

    public static function fusiones(): array
    {
        return Db::todas(
            'SELECT f.id, f.origen_id, o.nombre AS origen, f.destino_id, d.nombre AS destino, f.motivo,
                    f.creado_utc, f.deshecha_utc
               FROM cliente_fusion f JOIN cliente o ON o.id = f.origen_id JOIN cliente d ON d.id = f.destino_id
              ORDER BY f.id DESC LIMIT 100'
        );
    }

    private static function validarPar(int $origenId, int $destinoId): array
    {
        if ($origenId === $destinoId) throw new ErrorValidacion(['destino' => 'Un cliente no se fusiona consigo mismo.']);
        $o = self::uno($origenId);
        $d = self::uno($destinoId);
        if ((int) $o['activo'] !== 1) throw new ErrorConflicto("«{$o['nombre']}» ya está fusionado en otro cliente.", 'CLIENTE_FUSIONADO');
        if ((int) $d['activo'] !== 1) throw new ErrorConflicto("«{$d['nombre']}» ya está fusionado en otro cliente.", 'CLIENTE_FUSIONADO');
        return [$o, $d];
    }

    /** La huella del estado que se previsualizó. */
    private static function clave(int $o, int $d, array $sitios, array $co, array $cd): string
    {
        $estado = Hash::canonical([
            'o' => $o, 'd' => $d, 's' => $sitios,
            'co' => [$co['nombre'], $co['cuit'], $co['actualizado_utc']],
            'cd' => [$cd['nombre'], $cd['cuit'], $cd['actualizado_utc']],
        ]);
        return hash_hmac('sha256', $estado, (string) Config::get('pepper_pin', 'camca'));
    }
}
