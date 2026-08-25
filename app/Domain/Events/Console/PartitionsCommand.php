<?php

declare(strict_types=1);

namespace App\Domain\Events\Console;

use App\Domain\Events\Support\ArchivePartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Monthly partition rotation for events_archive: split future months out of
 * the pmax catch-all ahead of need, drop months past retention. Scheduled
 * daily; both operations are idempotent metadata changes.
 */
final class PartitionsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'events:partitions
        {--ahead=2 : Months of future partitions to keep pre-created}
        {--retain= : Months to retain (default events.archive.retention_months)}
        {--force : Run the destructive drop step without confirmation in production}';

    protected $description = 'Create upcoming and drop expired events_archive partitions';

    public function handle(): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            $this->warn('events_archive is only partitioned on MySQL; nothing to do on this driver.');

            return self::SUCCESS;
        }

        $created = ArchivePartitions::ensureFuture($connection, max(0, (int) $this->option('ahead')));

        foreach ($created as $name) {
            $this->info("Created partition {$name}.");
        }

        $retainOption = $this->option('retain');
        $retain = is_numeric($retainOption)
            ? (int) $retainOption
            : (int) config('events.archive.retention_months');

        if (! $this->confirmToProceed('Dropping partitions permanently deletes archived events')) {
            return self::FAILURE;
        }

        $dropped = ArchivePartitions::dropOlderThan(
            $connection,
            CarbonImmutable::now()->startOfMonth()->subMonths($retain),
        );

        foreach ($dropped as $name) {
            $this->info("Dropped partition {$name}.");
        }

        if ($created === [] && $dropped === []) {
            $this->info('Partitions already in shape; nothing changed.');
        }

        return self::SUCCESS;
    }
}
