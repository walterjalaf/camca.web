<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Firma Ed25519 del servidor. Obj. 2, paso F2.3.
 *
 * QUÉ FIRMA Y QUÉ NO:
 *
 * Firma cada eslabón de la cadena de remitos (Certificacion.php) y la cabeza
 * diaria de las cadenas (el ancla, F2.5). No firma cada eslabón de auditoría:
 * esos se protegen encadenados y con la cabeza firmada una vez por día.
 *
 * DÓNDE VIVE LA CLAVE:
 *
 * La privada vive en camca_priv/, fuera de public_html, porque el servidor
 * tiene que firmar solo, a las 3 de la mañana, sin nadie al lado. Es lo
 * contrario de la clave del backup (que el servidor NO debe poder usar para
 * descifrar), y por eso la protección no es la custodia sino el ANCLA: la
 * cabeza firmada sale todos los días fuera del proveedor. Quien robe la clave
 * puede firmar remitos nuevos, pero no puede reescribir los que ya salieron
 * sin que el ancla lo delate.
 *
 * EN QUÉ CLAVES SE CONFÍA:
 *
 * En las de la CONFIGURACIÓN (la actual, derivada de la privada, y las
 * retiradas listadas en firma.claves_publicas), nunca en las de la base. La
 * tabla firma_clave es un registro informativo: si el verificador confiara en
 * ella, cualquiera con acceso a la base podría cargar su propia clave y
 * volver a firmar la historia entera.
 *
 * SIN SODIUM NO HAY FIRMA. PHP recién trae Ed25519 por OpenSSL desde la 8.4;
 * con una versión anterior sin sodium, alg() dice 'ninguna' y la
 * certificación se niega en vez de simular (supuesto S10).
 */
final class Firma
{
    public const ALG = 'ed25519';

    /** Prefijo de dominio: una firma de remito no sirve como firma de otra cosa. */
    public const DOMINIO_REMITO = 'CAMCA-REMITO-1|';
    public const DOMINIO_ANCLA  = 'CAMCA-ANCLA-1|';

    private static ?array $par = null;
    private static bool $cargado = false;

    public static function sodium(): bool
    {
        return function_exists('sodium_crypto_sign_detached');
    }

    /** ¿Hay con qué firmar? Sodium y una clave privada legible y válida. */
    public static function disponible(): bool
    {
        return self::par() !== null;
    }

    /** El algoritmo con el que se firma hoy, o 'ninguna'. */
    public static function alg(): string
    {
        return self::disponible() ? self::ALG : 'ninguna';
    }

    public static function claveId(): ?string
    {
        return self::par()['id'] ?? null;
    }

    public static function publicaB64(): ?string
    {
        $p = self::par();
        return $p === null ? null : base64_encode($p['publica']);
    }

    /** Identificador corto de una clave pública: los 16 primeros hex de su SHA-256. */
    public static function idDe(string $publicaBin): string
    {
        return substr(hash('sha256', $publicaBin), 0, 16);
    }

    /**
     * Firma un mensaje. Devuelve la firma en base64, el algoritmo y la clave,
     * o null si no hay con qué firmar: el que llama decide si eso lo frena.
     */
    public static function firmar(string $mensaje): ?array
    {
        $p = self::par();
        if ($p === null) return null;
        self::registrar($p);
        return [
            'firma'    => base64_encode(sodium_crypto_sign_detached($mensaje, $p['secreta'])),
            'alg'      => self::ALG,
            'clave_id' => $p['id'],
        ];
    }

    /**
     * Verifica una firma contra una clave de CONFIANZA (ver arriba).
     *
     * @return bool|null  null si no se puede verificar (sin sodium, o la
     *                    clave no es de confianza): no es lo mismo que falsa.
     */
    public static function verificar(string $mensaje, string $firmaB64, string $claveId): ?bool
    {
        if (!self::sodium()) return null;
        $pub = self::confiables()[$claveId] ?? null;
        if ($pub === null) return null;
        $firma = base64_decode($firmaB64, true);
        if ($firma === false || strlen($firma) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
        return sodium_crypto_sign_verify_detached($firma, $mensaje, $pub);
    }

    /**
     * Las claves públicas en las que se confía: la actual y las retiradas que
     * la configuración lista. id => clave binaria.
     */
    public static function confiables(): array
    {
        $salida = [];
        foreach ((array) Config::get('firma.claves_publicas', []) as $b64) {
            $bin = base64_decode((string) $b64, true);
            if ($bin !== false && strlen($bin) === 32) $salida[self::idDe($bin)] = $bin;
        }
        $p = self::par();
        if ($p !== null) $salida[$p['id']] = $p['publica'];
        return $salida;
    }

    /**
     * Genera un par nuevo. Lo usa tools/generar_clave_firma.php, y las
     * pruebas. La privada sale en base64 para guardarla en un archivo.
     */
    public static function generar(): array
    {
        if (!self::sodium()) {
            throw new RuntimeException('Falta la extensión sodium de PHP: sin ella no hay firma Ed25519.');
        }
        $par = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($par);
        return [
            'privada' => base64_encode(sodium_crypto_sign_secretkey($par)),
            'publica' => base64_encode($pub),
            'id'      => self::idDe($pub),
        ];
    }

    /** Para las pruebas: olvidar la clave cargada y volver a leer la configuración. */
    public static function reiniciar(): void
    {
        self::$par = null;
        self::$cargado = false;
    }

    // ------------------------------------------------------------------

    private static function par(): ?array
    {
        if (self::$cargado) return self::$par;
        self::$cargado = true;
        if (!self::sodium()) return self::$par = null;

        $ruta = (string) Config::get('firma.clave_privada', Config::priv() . '/firma_ed25519.key');
        if ($ruta === '' || !is_readable($ruta)) return self::$par = null;

        $secreta = base64_decode(trim((string) file_get_contents($ruta)), true);
        if ($secreta === false || strlen($secreta) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            Log::error('firma_clave_invalida', ['ruta' => basename($ruta)]);
            return self::$par = null;
        }
        $publica = sodium_crypto_sign_publickey_from_secretkey($secreta);
        return self::$par = ['secreta' => $secreta, 'publica' => $publica, 'id' => self::idDe($publica)];
    }

    /** Deja la clave pública anotada en la base, para que se pueda publicar. */
    private static function registrar(array $p): void
    {
        static $hecho = [];
        if (isset($hecho[$p['id']])) return;
        Db::q(
            'INSERT IGNORE INTO firma_clave (clave_id, algoritmo, publica, creada_utc)
             VALUES (:i, :a, :p, UTC_TIMESTAMP())',
            [':i' => $p['id'], ':a' => self::ALG, ':p' => base64_encode($p['publica'])]
        );
        $hecho[$p['id']] = true;
    }
}
