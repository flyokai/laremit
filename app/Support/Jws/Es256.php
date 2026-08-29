<?php

declare(strict_types=1);

namespace App\Support\Jws;

use InvalidArgumentException;

/**
 * ECDSA P-256 / SHA-256 signatures in the JOSE encoding (raw R||S, 64
 * bytes). OpenSSL speaks DER; the two converters are the whole reason this
 * class exists, and the reason people reach for a library. Nothing here
 * that a reader of RFC 7518 §3.4 would not recognise.
 */
final class Es256
{
    private const COORDINATE_BYTES = 32;

    /** @return string the 64-byte raw signature */
    public static function sign(string $data, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new InvalidArgumentException('ES256: unusable private key.');
        }

        $der = '';

        if (! openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('ES256: signing failed.');
        }

        return self::derToRaw($der);
    }

    public static function verify(string $data, string $rawSignature, string $publicKeyPem): bool
    {
        if (strlen($rawSignature) !== 2 * self::COORDINATE_BYTES) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new InvalidArgumentException('ES256: unusable public key.');
        }

        return openssl_verify($data, self::rawToDer($rawSignature), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** DER SEQUENCE { INTEGER r, INTEGER s } -> fixed-width r || s. */
    private static function derToRaw(string $der): string
    {
        $offset = 0;

        if (ord($der[$offset++]) !== 0x30) {
            throw new InvalidArgumentException('ES256: DER signature is not a SEQUENCE.');
        }

        // Sequence length (one or two bytes for P-256 sizes).
        $offset += (ord($der[$offset]) & 0x80) !== 0 ? (ord($der[$offset]) & 0x7F) + 1 : 1;

        $r = self::readDerInteger($der, $offset);
        $s = self::readDerInteger($der, $offset);

        return $r.$s;
    }

    private static function readDerInteger(string $der, int &$offset): string
    {
        if (ord($der[$offset++]) !== 0x02) {
            throw new InvalidArgumentException('ES256: expected a DER INTEGER.');
        }

        $length = ord($der[$offset++]);
        $value = substr($der, $offset, $length);
        $offset += $length;

        // Strip the sign byte a positive INTEGER carries when its high bit is
        // set, then left-pad to the coordinate width.
        $value = ltrim($value, "\x00");

        return str_pad($value, self::COORDINATE_BYTES, "\x00", STR_PAD_LEFT);
    }

    private static function rawToDer(string $raw): string
    {
        $r = self::derInteger(substr($raw, 0, self::COORDINATE_BYTES));
        $s = self::derInteger(substr($raw, self::COORDINATE_BYTES));

        $body = $r.$s;

        return "\x30".chr(strlen($body)).$body;
    }

    private static function derInteger(string $coordinate): string
    {
        $value = ltrim($coordinate, "\x00");

        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".chr(strlen($value)).$value;
    }
}
