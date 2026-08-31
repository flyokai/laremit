<?php

declare(strict_types=1);

namespace App\Domain\Events;

use App\Domain\Events\Console\CheckLagCommand;
use App\Domain\Events\Console\PartitionsCommand;
use App\Domain\Events\Console\ReplayDeadLettersCommand;
use App\Domain\Events\Console\StatusCommand;
use App\Domain\Events\Console\WorkCommand;
use App\Domain\Events\Consumers\ProjectionConsumer;
use App\Domain\Events\Consumers\ReactionConsumer;
use App\Domain\Events\Contracts\EventBuffer;
use App\Domain\Events\Ingestion\Ingestor;
use App\Domain\Events\Projections\DailyActiveUsers;
use App\Domain\Events\Stream\RedisEventStream;
use App\Domain\Events\Support\EnvelopeValidator;
use App\Domain\Events\Support\SchemaRegistry;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the Events module. Everything the module needs from config is
 * read here, once, so the classes themselves stay constructor-explicit.
 */
final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SchemaRegistry::class, function (Application $app): SchemaRegistry {
            /** @var list<int> $live */
            $live = (array) $app->make(Repository::class)->get('events.schema.live_versions');

            $registry = new SchemaRegistry($live);

            // v1 reported playback position in whole seconds; v2 in ms. The
            // upcaster stays registered as long as v1 events can still be
            // replayed from the archive — which is forever, not merely while
            // v1 is live at ingest.
            $registry->registerUpcaster('video.watched', 1, static function (array $payload): array {
                $seconds = $payload['position_seconds'] ?? 0;
                unset($payload['position_seconds']);
                $payload['position_ms'] = (int) round((is_numeric($seconds) ? (float) $seconds : 0.0) * 1000);

                return $payload;
            });

            return $registry;
        });

        $this->app->singleton(EventBuffer::class, function (Application $app): RedisEventStream {
            $config = $app->make(Repository::class);

            return new RedisEventStream(
                $app->make(Factory::class),
                (string) $config->get('events.stream.connection'),
                (string) $config->get('events.stream.key'),
                (string) $config->get('events.dedup.prefix'),
                (int) $config->get('events.dedup.ttl'),
                (int) $config->get('events.stream.maxlen'),
                (string) $config->get('events.consumers.dead_letter_key'),
            );
        });

        $this->app->singleton(EnvelopeValidator::class, fn (Application $app): EnvelopeValidator => new EnvelopeValidator(
            $app->make(SchemaRegistry::class),
        ));

        $this->app->singleton(Ingestor::class, function (Application $app): Ingestor {
            $config = $app->make(Repository::class);

            $shedAbove = (int) $config->get('events.backpressure.shed_analytics_above');
            $rejectAbove = (int) $config->get('events.backpressure.reject_all_above');

            // The maxlen half of the ADR-003 invariant is checked here, where
            // both numbers are in hand; misconfiguration fails the boot, not
            // the 3am incident.
            Ingestor::assertSaneThresholds($shedAbove, $rejectAbove, (int) $config->get('events.stream.maxlen'));

            return new Ingestor(
                $app->make(EventBuffer::class),
                $app->make(EnvelopeValidator::class),
                $shedAbove,
                $rejectAbove,
                (int) $config->get('events.backpressure.retry_after_seconds'),
            );
        });

        $this->app->singleton(ProjectionConsumer::class, function (Application $app): ProjectionConsumer {
            $config = $app->make(Repository::class);

            return new ProjectionConsumer(
                $app->make(Factory::class),
                (string) $config->get('events.projections.connection'),
                (int) $config->get('events.projections.retention_days'),
            );
        });

        $this->app->singleton(ReactionConsumer::class, function (Application $app): ReactionConsumer {
            $config = $app->make(Repository::class);

            return new ReactionConsumer(
                $app->make(Dispatcher::class),
                $app->make(Factory::class),
                $config,
                $app->make(SchemaRegistry::class),
                (string) $config->get('events.stream.connection'),
                (string) $config->get('events.reactions.marker_prefix'),
                (int) $config->get('events.reactions.marker_ttl'),
            );
        });

        $this->app->singleton(DailyActiveUsers::class, fn (Application $app): DailyActiveUsers => new DailyActiveUsers(
            $app->make(Factory::class),
            (string) $app->make(Repository::class)->get('events.projections.connection'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkCommand::class,
                PartitionsCommand::class,
                StatusCommand::class,
                CheckLagCommand::class,
                ReplayDeadLettersCommand::class,
            ]);
        }
    }
}
