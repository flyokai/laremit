<?php

declare(strict_types=1);

namespace App\MockStores\Apple;

use App\MockStores\MockStoresSettings;
use App\Support\Jws\Jws;
use RuntimeException;

/** Signs as the pretend App Store: ES256 with the mock's private key. */
final readonly class AppleSigner
{
    public function __construct(private MockStoresSettings $settings) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $pem = $this->settings->appleSigningKeyPem();

        if ($pem === null) {
            throw new RuntimeException('MOCK_APPLE_SIGNING_KEY is not configured; the mock App Store cannot sign.');
        }

        return Jws::encode(['kid' => 'mock-app-store', 'typ' => 'JWT'], $payload, $pem);
    }
}
