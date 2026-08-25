<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Throwable;

/**
 * The one place that knows how events_archive monthly partitions are named
 * and bounded — used by the migration that creates the table and the
 * `events:partitions` command that rotates it, so the two cannot disagree.
 *
 * Layout: one RANGE partition per month of received_at (TO_DAYS), named
 * pYYYYMM, plus a pmax MAXVALUE catch-all. New months are split out of pmax
 * with REORGANIZE PARTITION (pmax is empty future, so the split is a
 * metadata operation); retention is DROP PARTITION, which removes a month of
 * events in milliseconds where DELETE would take hours.
 */
final class ArchivePartitions
{
    public const TABLE = 'events_archive';

    public const CATCH_ALL = 'pmax';

    public static function name(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Ym');
    }

    /** Exclusive upper bound: the first day of the following month. */
    public static function boundary(CarbonImmutable $month): string
    {
        return $month->startOfMonth()->addMonthNoOverflow()->format('Y-m-d');
    }

    public static function clause(CarbonImmutable $month): string
    {
        return sprintf(
            "PARTITION %s VALUES LESS THAN (TO_DAYS('%s'))",
            self::name($month),
            self::boundary($month),
        );
    }

    /**
     * Partition clauses for table creation: every month from $from through
     * $monthsAhead months after it, plus the catch-all.
     */
    public static function initialClauses(CarbonImmutable $from, int $monthsAhead): string
    {
        $clauses = [];
        $month = $from->startOfMonth();

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $clauses[] = self::clause($month);
            $month = $month->addMonthNoOverflow();
        }

        $clauses[] = sprintf('PARTITION %s VALUES LESS THAN MAXVALUE', self::CATCH_ALL);

        return implode(",\n    ", $clauses);
    }

    /**
     * @return list<string> existing partition names, pmax included
     */
    public static function existing(Connection $connection): array
    {
        /** @var list<object{PARTITION_NAME: string|null}> $rows */
        $rows = $connection->select(
            'SELECT PARTITION_NAME FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION',
            [self::TABLE],
        );

        return array_values(array_filter(array_map(
            static fn (object $row): ?string => $row->PARTITION_NAME,
            $rows,
        )));
    }

    /**
     * Make sure a partition exists for every month up to $monthsAhead from
     * now, splitting each missing one out of the catch-all.
     *
     * @return list<string> partitions created
     */
    public static function ensureFuture(Connection $connection, int $monthsAhead): array
    {
        $existing = self::existing($connection);
        $created = [];
        $month = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $name = self::name($month);

            if (! in_array($name, $existing, true)) {
                $connection->statement(sprintf(
                    'ALTER TABLE %s REORGANIZE PARTITION %s INTO (%s, PARTITION %s VALUES LESS THAN MAXVALUE)',
                    self::TABLE,
                    self::CATCH_ALL,
                    self::clause($month),
                    self::CATCH_ALL,
                ));

                $created[] = $name;
            }

            $month = $month->addMonthNoOverflow();
        }

        return $created;
    }

    /**
     * Drop monthly partitions whose entire range is older than the cutoff.
     *
     * @return list<string> partitions dropped
     */
    public static function dropOlderThan(Connection $connection, CarbonImmutable $cutoff): array
    {
        $dropped = [];

        foreach (self::existing($connection) as $name) {
            if ($name === self::CATCH_ALL) {
                continue;
            }

            try {
                $month = CarbonImmutable::createFromFormat('!Ym', substr($name, 1));
            } catch (Throwable) {
                $month = null;
            }

            // Not one of ours (someone hand-added a partition); leave it be.
            if ($month === null) {
                continue;
            }

            // The partition holds data up to the exclusive boundary; only
            // drop when even that boundary is past the cutoff.
            if ($month->startOfMonth()->addMonthNoOverflow()->lessThanOrEqualTo($cutoff)) {
                $connection->statement(sprintf('ALTER TABLE %s DROP PARTITION %s', self::TABLE, $name));
                $dropped[] = $name;
            }
        }

        return $dropped;
    }
}
