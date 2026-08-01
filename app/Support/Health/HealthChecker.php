<?php

declare(strict_types=1);

namespace App\Support\Health;

use Throwable;

final readonly class HealthChecker
{
    /**
     * @param  list<Check>  $checks
     */
    public function __construct(private array $checks) {}

    /**
     * Run every check.
     *
     * One failing dependency must not hide the state of the others, so a throw
     * is recorded against its own check and the rest still run. Readiness is a
     * diagnostic: "which dependency is broken" is the answer we actually want
     * at 3am, not just "something is".
     */
    public function run(): HealthReport
    {
        $results = [];

        foreach ($this->checks as $check) {
            $startedAt = hrtime(true);

            try {
                $detail = $check->probe();
                $results[] = CheckResult::healthy($check->name(), $this->elapsedMs($startedAt), $detail);
            } catch (Throwable $e) {
                $results[] = CheckResult::failed($check->name(), $this->elapsedMs($startedAt), $e->getMessage());
            }
        }

        return new HealthReport($results);
    }

    private function elapsedMs(int|float $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
