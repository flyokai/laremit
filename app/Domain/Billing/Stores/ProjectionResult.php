<?php

declare(strict_types=1);

namespace App\Domain\Billing\Stores;

use App\Domain\Billing\Models\Subscription;

/**
 * What projecting a snapshot did. Verdicts:
 *   applied            local state changed to match the store
 *   confirmed          already matched; the watermark advanced
 *   stale              older than what is applied; nothing written
 *   wrong_environment  Sandbox truth offered to a Production app (or vice versa)
 *   unknown_product    the store product id is not in our catalog
 *   product_mismatch   the store record names a different product than the row
 *   unknown_user       first sight of this purchase and no app account token we know
 */
final readonly class ProjectionResult
{
    public function __construct(
        public string $verdict,
        public ?Subscription $subscription = null,
    ) {}

    /** The snapshot was the kind of thing we can act on, whether or not it changed anything. */
    public function isApplicable(): bool
    {
        return in_array($this->verdict, ['applied', 'confirmed', 'stale'], true);
    }
}
