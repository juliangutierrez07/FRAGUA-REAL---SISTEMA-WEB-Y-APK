<?php

namespace App\Support\Push;

use RuntimeException;

/**
 * M11 — Avisos push del personal (28/10/2026, RF-M11-013): el protocolo Web Push sin librerías externas
 * (la de PHP más usada exige Guzzle 7 y Laravel 13 trae Guzzle 8). Solo usa `openssl` y `hash_hkdf`:
 *  - Llaves VAPID (RFC 8292): un par P-256; la pública va al navegador al suscribirse y la privada firma
 *    un JWT ES256 en cada envío, para que el servicio push (Google, Mozilla, Apple, Microsoft) sepa que el
 *    aviso viene de este servidor.
 *  - Cifrado del contenido (RFC 8291, «aes128gcm»): solo el navegador suscrito puede leer el aviso; el
 *    servicio push lo transporta sin verlo.
 * Las llaves y los valores binarios viajan en base64url sin relleno, como los entrega el navegador.
 */
final class WebPush
{
    /** Prefijo DER de una llave pública P-256 (SubjectPublicKeyInfo) antes de sus 65 bytes. */
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Tamaño de registro del cifrado aes128gcm: un aviso cabe en un solo registro. */
    private const REGISTRO = 4096;

    public static function b64(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }

    public static function desdeB64(string $texto): string
    {
        $binario = base64_decode(strtr($texto, '-_', '+/').str_repeat('=', (4 - strlen($texto) % 4) % 4), true);
        if ($binario === false) {
            throw new RuntimeException('Valor base64url inválido.');
        }

        return $binario;
    }

    /** Un par de llaves VAPID nuevo. @return array{publica: string, privada: string} en base64url */
    public static function generarLlaves(): array
    {
        [$privada, $publica] = self::nuevoPar();

        return ['publica' => self::b64($publica), 'privada' => self::b64($privada)];
    }

    /** ¿Es una llave pública P-256 sin comprimir (65 bytes que empiezan en 0x04)? */
    public static function esLlavePublica(string $binario): bool
    {
        return strlen($binario) === 65 && $binario[0] === "\x04";
    }

    /**
     * Cifra el aviso para un navegador (RFC 8291). $uaPublica y $auth son los valores `p256dh` y `auth` de
     * su suscripción, ya decodificados. $remitente y $sal solo se fijan en las pruebas (vector del RFC);
     * en un envío real son nuevos cada vez.
     *
     * @param  array{0: string, 1: string}|null  $remitente  [privada cruda de 32 bytes, pública de 65]
     */
    public static function cifrar(string $mensaje, string $uaPublica, string $auth, ?array $remitente = null, ?string $sal = null): string
    {
        if (! self::esLlavePublica($uaPublica) || strlen($auth) !== 16) {
            throw new RuntimeException('La suscripción push tiene llaves inválidas.');
        }
        [$asPrivada, $asPublica] = $remitente ?? self::nuevoPar();
        $sal ??= random_bytes(16);

        $secreto = openssl_pkey_derive(self::llavePublica($uaPublica), self::llavePrivada($asPrivada, $asPublica), 0);
        if ($secreto === false) {
            throw new RuntimeException('No se pudo acordar la llave del aviso push.');
        }
        $ikm = hash_hkdf('sha256', $secreto, 32, "WebPush: info\0".$uaPublica.$asPublica, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);

        $etiqueta = '';
        // Un solo registro: el contenido, el delimitador 0x02 de «último registro» y sin relleno.
        $cifrado = openssl_encrypt($mensaje."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiqueta, '', 16);
        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar el aviso push.');
        }

        return $sal.pack('N', self::REGISTRO).chr(strlen($asPublica)).$asPublica.$cifrado.$etiqueta;
    }

    /**
     * La cabecera Authorization del envío (RFC 8292): «vapid t=<JWT ES256>, k=<llave pública>». El JWT vale
     * para el origen del servicio push y vence en $vence (máximo 24 horas).
     */
    public static function cabeceraVapid(string $endpoint, string $publica, string $privada, string $asunto, int $vence): string
    {
        $partes = parse_url($endpoint);
        if (! isset($partes['scheme'], $partes['host'])) {
            throw new RuntimeException('Dirección de suscripción push inválida.');
        }
        $audiencia = $partes['scheme'].'://'.$partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');
        $cabecera = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $carga = self::b64(json_encode(['aud' => $audiencia, 'exp' => $vence, 'sub' => $asunto], JSON_UNESCAPED_SLASHES));

        $publicaCruda = self::desdeB64($publica);
        if (! openssl_sign("{$cabecera}.{$carga}", $der, self::llavePrivada(self::desdeB64($privada), $publicaCruda), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el aviso push.');
        }

        return "vapid t={$cabecera}.{$carga}.".self::b64(self::firmaCruda($der)).", k={$publica}";
    }

    /** ¿La firma del JWT corresponde a la llave pública? (lo usan las pruebas y la revisión de llaves) */
    public static function verificarJwt(string $jwt, string $publica): bool
    {
        [$cabecera, $carga, $firma] = array_pad(explode('.', $jwt), 3, '');
        $cruda = self::desdeB64($firma);
        if (strlen($cruda) !== 64) {
            return false;
        }
        $entero = fn (string $v) => (($v = ltrim($v, "\0")) !== '' && ord($v[0]) > 0x7f ? "\0".$v : ($v === '' ? "\0" : $v));
        [$r, $s] = [$entero(substr($cruda, 0, 32)), $entero(substr($cruda, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;

        return openssl_verify("{$cabecera}.{$carga}", $der, self::llavePublica(self::desdeB64($publica)), OPENSSL_ALGO_SHA256) === 1;
    }

    // ------------------------------------------------------------------ llaves P-256

    /** @return array{0: string, 1: string} [privada cruda de 32 bytes, pública sin comprimir de 65] */
    private static function nuevoPar(): array
    {
        $opciones = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        // En Windows (Laragon) PHP no siempre encuentra openssl.cnf; con uno mínimo basta para crear llaves.
        $llave = @openssl_pkey_new($opciones) ?: openssl_pkey_new($opciones + ['config' => self::configMinima()]);
        while (openssl_error_string() !== false) {
            // Se vacía la cola de errores del primer intento.
        }
        $ec = $llave ? (openssl_pkey_get_details($llave)['ec'] ?? null) : null;
        if (! $ec) {
            throw new RuntimeException('No se pudo crear un par de llaves P-256.');
        }
        $a32 = fn (string $v) => str_pad($v, 32, "\0", STR_PAD_LEFT);

        return [$a32($ec['d']), "\x04".$a32($ec['x']).$a32($ec['y'])];
    }

    private static function configMinima(): string
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fragua-real-openssl.cnf';
        // default_bits: PHP lo exige aunque la llave sea de curva elíptica (no se usa).
        $contenido = "[ req ]\ndefault_bits = 2048\ndistinguished_name = dn\n[ dn ]\n";
        if (! is_file($ruta) || file_get_contents($ruta) !== $contenido) {
            file_put_contents($ruta, $contenido);
        }

        return $ruta;
    }

    private static function llavePublica(string $publica): \OpenSSLAsymmetricKey
    {
        if (! self::esLlavePublica($publica)) {
            throw new RuntimeException('Llave pública P-256 inválida.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_P256).$publica), 64, "\n")."-----END PUBLIC KEY-----\n";
        $llave = openssl_pkey_get_public($pem);
        if ($llave === false) {
            throw new RuntimeException('Llave pública P-256 inválida.');
        }

        return $llave;
    }

    /** La privada en formato SEC1 (ECPrivateKey) a partir de sus 32 bytes y su pública. */
    private static function llavePrivada(string $privada, string $publica): \OpenSSLAsymmetricKey
    {
        if (strlen($privada) !== 32 || ! self::esLlavePublica($publica)) {
            throw new RuntimeException('Llave privada P-256 inválida.');
        }
        $der = hex2bin('30770201010420').$privada.hex2bin('a00a06082a8648ce3d030107a144034200').$publica;
        $llave = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        if ($llave === false) {
            throw new RuntimeException('Llave privada P-256 inválida.');
        }

        return $llave;
    }

    /** La firma ECDSA que da openssl (DER: SEQUENCE de dos INTEGER) como r‖s de 32 bytes cada uno (JWS). */
    private static function firmaCruda(string $der): string
    {
        $pos = 2;
        $entero = function () use ($der, &$pos): string {
            if (($der[$pos] ?? '') !== "\x02") {
                throw new RuntimeException('Firma ECDSA inválida.');
            }
            $largo = ord($der[$pos + 1]);
            $valor = substr($der, $pos + 2, $largo);
            $pos += 2 + $largo;

            return str_pad(ltrim($valor, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $entero().$entero();
    }
}
