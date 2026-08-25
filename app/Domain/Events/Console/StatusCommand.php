<?php

declare(strict_types=1);

namespace App\Domain\Events\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Projections\DailyActiveUsers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * One screen of pipeline state: buffer depth, per-group backlog, dead
 * letters, and today's DAU projections. The load-test and chaos runbooks
 * both watch this.
 */
final class StatusCommand extends Command
{
    protected $signature = 'events:status';

    protected $description = 'Show event pipeline depth, consumer group lag and projections';

    public function handle(EventBuffer $buffer, DailyActiveUsers $dau): int
    {
        try {
            $info = $buffer->info();
        } catch (Throwable $e) {
            $this->error("Stream unreachable: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line(sprintf(
            '<info>Stream</info> %s  depth=%d / maxlen=%d  dead-letter=%d',
            is_string($info['stream'] ?? null) ? $info['stream'] : '?',
            is_numeric($info['depth'] ?? null) ? (int) $info['depth'] : 0,
            is_numeric($info['maxlen'] ?? null) ? (int) $info['maxlen'] : 0,
            is_numeric($info['dead_letter'] ?? null) ? (int) $info['dead_letter'] : 0,
        ));

        /** @var list<array<string, mixed>> $groups */
        $groups = is_array($info['groups'] ?? null) ? array_values($info['groups']) : [];

        $this->table(
            ['group', 'consumers', 'pending', 'lag', 'last delivered'],
            array_map(static fn (array $group): array => [
                is_string($group['name'] ?? null) ? $group['name'] : '?',
                is_numeric($group['consumers'] ?? null) ? (string) $group['consumers'] : '?',
                is_numeric($group['pending'] ?? null) ? (string) $group['pending'] : '?',
                is_numeric($group['lag'] ?? null) ? (string) $group['lag'] : '?',
                is_string($group['last-delivered-id'] ?? null) ? $group['last-delivered-id'] : '?',
            ], $groups),
        );

        $today = CarbonImmutable::now()->utc();

        try {
            /** @var list<string> $slugs */
            $slugs = Product::query()->pluck('slug')->all();

            foreach ($slugs as $slug) {
                $this->line(sprintf(
                    '<info>DAU</info> %-12s today=%d  yesterday=%d',
                    $slug,
                    $dau->count($slug, $today),
                    $dau->count($slug, $today->subDay()),
                ));
            }

            $this->line(sprintf('<info>Active bitmap</info> today=%d', $dau->activeCount($today)));
        } catch (Throwable $e) {
            $this->warn("Projections unavailable: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }
}
