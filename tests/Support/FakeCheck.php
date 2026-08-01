<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Health\Check;
use RuntimeException;

final readonly class FakeCheck implements Check
{
    /**
     * @param  array<string, scalar>  $detail
     */
    public function __construct(
        private string $name,
        private array $detail = [],
        private ?string $throws = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, scalar>
     */
    public function probe(): array
    {
        if ($this->throws !== null) {
            throw new RuntimeException($this->throws);
        }

        return $this->detail;
    }
}
