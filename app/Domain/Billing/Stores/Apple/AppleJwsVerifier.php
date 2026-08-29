<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores\Apple;

use App\Support\Jws\Jws;
use App\Support\Jws\JwsException;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Verifies App Store Server Notification payloads (and the signed
 * transaction/renewal info nested inside them) against the pinned trust
 * anchor in config. Every byte Apple tells us about a subscription arrives
 * as a JWS; nothing is believed until this class has said so.
 *
 * Production delta (tech-debt #12): Apple signs with a leaf certificate
 * chained to the Apple Root CA G3 and ships the chain in the `x5c` header.
 * The real verifier walks that chain to the pinned root; this one pins the
 * signing key directly, which is the same trust decision with one fewer
 * hop.
 */
final readonly class AppleJwsVerifier
{
    public function __construct(private Config $config) {}

    public function isConfigured(): bool
    {
        return $this->publicKeyPem() !== null;
    }

    /**
     * @return array<string, mixed> the verified payload
     *
     * @throws JwsException
     */
    public function decode(string $jws): array
    {
        $pem = $this->publicKeyPem();

        if ($pem === null) {
            throw new JwsException('No App Store signing key is configured; refusing to verify.');
        }

        return Jws::decode($jws, $pem)['payload'];
    }

    private function publicKeyPem(): ?string
    {
        $encoded = $this->config->get('billing.stores.apple.public_key');

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $pem = base64_decode($encoded, true);

        return is_string($pem) && str_contains($pem, 'PUBLIC KEY') ? $pem : null;
    }
}
