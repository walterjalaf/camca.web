<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Cifrado hibrido para los backups.
 *
 * LA IDEA CENTRAL: el servidor CIFRA pero NO PUEDE DESCIFRAR. Se usa la clave
 * PUBLICA de CAMCA; la privada se custodia fuera del servidor (dos copias en
 * manos distintas y una impresa). Asi, alguien que se lleve el hosting entero
 * —o el repositorio de backups— no se lleva los datos: adentro hay DNI y
 * firmas de personal de clientes mineros.
 *
 * Formato CAMCABK1:
 *   "CAMCABK1" | uint32 largo_clave | clave AES envuelta con RSA-OAEP
 *              | 16 bytes IV | ciphertext (AES-256-CTR) | 32 bytes HMAC-SHA256
 *
 * Encrypt-then-MAC: el HMAC cubre el IV y el ciphertext, asi se detecta
 * cualquier alteracion ANTES de intentar descifrar.
 */
final class Cripto
{
    private const MAGIA = 'CAMCABK1';
    private const TROZO = 1048576;   // 1 MB: no se carga el archivo en memoria

    /**
     * Cifra `$origen` en `$destino` con la clave publica PEM dada.
     * @return string sha256 del archivo cifrado
     */
    public static function cifrarArchivo(string $origen, string $destino, string $pemPublica): string
    {
        $publica = openssl_pkey_get_public($pemPublica);
        if ($publica === false) {
            throw new RuntimeException('La clave publica de backup no es valida.');
        }

        // Clave simetrica de un solo uso, y una clave de MAC derivada de ella.
        $claveAes = random_bytes(32);
        $claveMac = hash_hkdf('sha256', $claveAes, 32, 'camca-backup-mac');
        $iv = random_bytes(16);

        if (!openssl_public_encrypt($claveAes, $envuelta, $publica, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('No se pudo envolver la clave con RSA.');
        }

        $in = fopen($origen, 'rb');
        $out = fopen($destino, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('No se pudo abrir el archivo a cifrar.');
        }

        fwrite($out, self::MAGIA);
        fwrite($out, pack('N', strlen($envuelta)));
        fwrite($out, $envuelta);
        fwrite($out, $iv);

        $hmac = hash_init('sha256', HASH_HMAC, $claveMac);
        hash_update($hmac, $iv);

        // CTR permite cifrar en streaming manteniendo el contador entre trozos.
        $contador = $iv;
        $bloque = 0;
        while (!feof($in)) {
            $trozo = fread($in, self::TROZO);
            if ($trozo === false || $trozo === '') break;
            $contador = self::contador($iv, $bloque);
            $cifrado = openssl_encrypt($trozo, 'aes-256-ctr', $claveAes, OPENSSL_RAW_DATA, $contador);
            if ($cifrado === false) {
                throw new RuntimeException('Fallo el cifrado.');
            }
            fwrite($out, $cifrado);
            hash_update($hmac, $cifrado);
            $bloque += (int) ceil(strlen($trozo) / 16);
        }

        fwrite($out, hash_final($hmac, true));
        fclose($in);
        fclose($out);

        return (string) hash_file('sha256', $destino);
    }

    /** Contador CTR: IV + numero de bloque, big-endian sobre los ultimos 8 bytes. */
    private static function contador(string $iv, int $bloque): string
    {
        $alto = substr($iv, 0, 8);
        $bajo = unpack('J', substr($iv, 8, 8))[1] ?? 0;
        return $alto . pack('J', ($bajo + $bloque) & PHP_INT_MAX);
    }

    /**
     * Genera un par de claves. Se corre UNA vez, en la maquina de quien va a
     * custodiar la privada, NUNCA en el servidor: si la privada pasa por el
     * servidor, el cifrado no protege de nada.
     */
    public static function generarPar(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            // En Windows esto falla casi siempre por openssl.cnf: el mensaje
            // crudo de OpenSSL ("configuration file routines::no such file")
            // no lo dice, y se pierde media hora buscando en el lugar
            // equivocado. Se dice aca.
            $detalle = [];
            while ($e = openssl_error_string()) $detalle[] = $e;
            throw new RuntimeException(
                'No se pudo generar el par de claves. Si el error menciona "configuration file", '
                . 'falta openssl.cnf: exportá OPENSSL_CONF apuntando a él. Detalle: '
                . implode(' | ', $detalle)
            );
        }
        openssl_pkey_export($res, $privada);
        $detalles = openssl_pkey_get_details($res);
        return ['privada' => $privada, 'publica' => $detalles['key']];
    }
}
