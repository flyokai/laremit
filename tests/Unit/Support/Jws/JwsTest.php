<?php

declare(strict_types=1);

use App\Support\Jws\Jws;
use App\Support\Jws\JwsException;

function mockPrivatePem(): string
{
    return (string) base64_decode((string) config('mockstores.apple.signing_key'), true);
}

function mockPublicPem(): string
{
    return (string) base64_decode((string) config('billing.stores.apple.public_key'), true);
}

it('round-trips an ES256 token', function (): void {
    $token = Jws::encode(['kid' => 'k1'], ['sub' => 'x', 'n' => 42], mockPrivatePem());

    $decoded = Jws::decode($token, mockPublicPem());

    expect($decoded['header'])->toBe(['kid' => 'k1', 'alg' => 'ES256'])
        ->and($decoded['payload'])->toBe(['sub' => 'x', 'n' => 42])
        ->and(Jws::peekPayload($token))->toBe(['sub' => 'x', 'n' => 42]);
});

it('signs differently every time yet always verifies (ECDSA is randomized)', function (): void {
    $a = Jws::encode([], ['x' => 1], mockPrivatePem());
    $b = Jws::encode([], ['x' => 1], mockPrivatePem());

    expect($a)->not->toBe($b)
        ->and(Jws::decode($a, mockPublicPem())['payload'])->toBe(['x' => 1])
        ->and(Jws::decode($b, mockPublicPem())['payload'])->toBe(['x' => 1]);
});

it('rejects a tampered payload', function (): void {
    $token = Jws::encode([], ['amount' => 1], mockPrivatePem());
    [$h, , $s] = explode('.', $token);
    $tampered = $h.'.'.Jws::base64UrlEncode('{"amount":1000000}').'.'.$s;

    expect(fn () => Jws::decode($tampered, mockPublicPem()))->toThrow(JwsException::class, 'does not verify');
});

it('rejects any algorithm other than ES256 before looking at the signature', function (): void {
    $none = Jws::base64UrlEncode('{"alg":"none"}').'.'.Jws::base64UrlEncode('{"admin":true}').'.';
    $hmac = Jws::base64UrlEncode('{"alg":"HS256"}').'.'.Jws::base64UrlEncode('{"admin":true}').'.'.Jws::base64UrlEncode('sig');

    expect(fn () => Jws::decode($none, mockPublicPem()))->toThrow(JwsException::class, 'not ES256')
        ->and(fn () => Jws::decode($hmac, mockPublicPem()))->toThrow(JwsException::class, 'not ES256');
});

it('rejects a token signed by a different key', function (): void {
    $stranger = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($stranger, $pem);

    $token = Jws::encode([], ['x' => 1], (string) $pem);

    expect(fn () => Jws::decode($token, mockPublicPem()))->toThrow(JwsException::class);
});

it('rejects malformed tokens', function (): void {
    expect(fn () => Jws::decode('a.b', mockPublicPem()))->toThrow(JwsException::class, 'three')
        ->and(fn () => Jws::decode('!!.!!.!!', mockPublicPem()))->toThrow(JwsException::class)
        ->and(Jws::peekPayload('nonsense'))->toBe([]);
});
