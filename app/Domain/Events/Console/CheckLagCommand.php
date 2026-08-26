<?php

declare(strict_types=1);

namespace App\Domain\Events\Console;

use App\Domain\Events\Contracts\EventBuffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Turns consumer-group health from something `events:status` shows a human
 * who happens to look into something that pages. Meant to run on a schedule
 * (routes/console.php), not by hand.
 *
 * Two distinct failures, because XADD MAXLEN trims by aggregate stream
 * length only and has no idea whether any specific group has read an entry:
 *
 * - A group is MISSING entirely — its worker has never run (or `XGROUP
 *   CREATE` never happened), so it isn't in XINFO GROUPS at all. Its cursor
 *   isn't just behind, it doesn't exist; entries appended and later trimmed
 *   while it was absent are gone for it, forever, with no error anywhere
 *   else in this codebase.
 * - A group EXISTS but has fallen behind (`lag`) or is holding an unusually
 *   large PEL (`pending`) — a slow or stuck worker, not yet a total loss,
 *   but heading toward the same trim race if nobody intervenes.
 */
final class CheckLagCommand extends Command
{
    protected $signature = 'events:check-lag';

    protected $description = 'Alert when a consumer group is missing, lagging, or holding an oversized PEL';

    public function handle(EventBuffer $buffer): int
    {
        // The map is name => Consumer class (also WorkCommand's source of
        // truth); only the names matter here.
        $expected = array_keys((array) config('events.consumers.groups', []));
        $maxLag = (int) config('events.consumers.max_lag');
        $maxPending = (int) config('events.consumers.max_pending');

        $info = $buffer->info();

        /** @var list<mixed> $groups */
        $groups = is_array($info['groups'] ?? null) ? array_values($info['groups']) : [];

        /** @var array<string, array<string, mixed>> $byName */
        $byName = [];
        foreach ($groups as $group) {
            if (is_array($group) && is_string($group['name'] ?? null)) {
                $byName[$group['name']] = $group;
            }
        }

        $problems = [];

        foreach ($expected as $name) {
            if (! isset($byName[$name])) {
                $problems[] = sprintf(
                    'group [%s] does not exist on the stream — its worker has likely never run '
                    .'(events:work %s was never started, or XGROUP CREATE never happened)',
                    $name,
                    $name,
                );

                continue;
            }

            $group = $byName[$name];
            $pending = is_numeric($group['pending'] ?? null) ? (int) $group['pending'] : 0;
            $lag = is_numeric($group['lag'] ?? null) ? (int) $group['lag'] : null;

            if ($lag === null) {
                // Redis < 7.0 has no `lag` field; pending is the only signal left.
                if ($pending > $maxPending) {
                    $problems[] = sprintf('group [%s] has %d pending entries (no lag field — Redis < 7.0?)', $name, $pending);
                }

                continue;
            }

            if ($lag > $maxLag) {
                $problems[] = sprintf('group [%s] lag=%d exceeds threshold %d', $name, $lag, $maxLag);
            }

            if ($pending > $maxPending) {
                $problems[] = sprintf('group [%s] has %d pending (unacked) entries, threshold %d', $name, $pending, $maxPending);
            }
        }

        if ($problems === []) {
            $this->info('All consumer groups healthy.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        // Log::critical, not ::warning — every problem here is on the direct
        // path to the silent-loss scenario in the class docblock, not routine
        // noise. Wire this into whatever pages on-call once that exists;
        // nothing in this codebase does yet (see ADR-003's "one person"
        // operational budget).
        Log::critical('Event pipeline consumer group(s) unhealthy.', [
            'problems' => $problems,
            'depth' => $info['depth'] ?? null,
            'maxlen' => $info['maxlen'] ?? null,
        ]);

        return self::FAILURE;
    }
}
