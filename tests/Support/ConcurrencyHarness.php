<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use DateTimeInterface;
use GuzzleHttp\Client;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The real-parallelism rig for the concurrency suite (Module 9): a throwaway
 * MySQL database, the app served by PHP's built-in server with a pool of
 * worker processes, and a Guzzle client that fires genuinely simultaneous
 * requests at it. In-process tests can only interleave; proving "20 parallel
 * requests, exactly one charge" needs 20 requests that actually race on
 * MySQL's locks, which is what this buys.
 *
 * Booted once per pest run (the migrate + serve cost), reused by every test
 * in the suite; the server dies with the run via a shutdown hook. Skips
 * cleanly when MySQL is not reachable — same posture as the Redis
 * integration tests: the compose stack (or the CI service) provides it.
 */
final class ConcurrencyHarness
{
    private static ?self $instance = null;

    private static bool $probed = false;

    private ?Process $server = null;

    private ?PDO $pdo = null;

    private int $port = 0;

    private function __construct(
        private readonly string $mysqlHost,
        private readonly int $mysqlPort,
        private readonly string $mysqlUsername,
        private readonly string $mysqlPassword,
        private readonly string $database,
    ) {}

    /** Null means "no MySQL here" — the caller should skip, not fail. */
    public static function boot(): ?self
    {
        if (self::$probed) {
            return self::$instance;
        }

        self::$probed = true;

        $harness = new self(
            self::env('TEST_MYSQL_HOST', '127.0.0.1'),
            (int) self::env('TEST_MYSQL_PORT', '33100'),
            self::env('TEST_MYSQL_USERNAME', 'root'),
            self::env('TEST_MYSQL_PASSWORD', 'root'),
            self::env('TEST_MYSQL_DATABASE', 'laremit_concurrency'),
        );

        if (! $harness->reachable()) {
            return null;
        }

        $harness->recreateDatabase();
        $harness->migrate();
        $harness->serve();

        register_shutdown_function(static function () use ($harness): void {
            $harness->shutdown();
        });

        return self::$instance = $harness;
    }

    public function shutdown(): void
    {
        $this->server?->stop(2);

        // The built-in server's worker pool can outlive a killed master —
        // and an orphaned worker holds this process's output pipe open,
        // which is how a green run hangs its own CI step. Sweep by the
        // unique listen address, best effort.
        if ($this->port !== 0) {
            (new Process(['pkill', '-f', '--', '127.0.0.1:'.$this->port]))->run();
        }
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $this->mysqlHost, $this->mysqlPort, $this->database),
                $this->mysqlUsername,
                $this->mysqlPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return $this->pdo;
    }

    public function client(): Client
    {
        return new Client([
            'base_uri' => sprintf('http://127.0.0.1:%d', $this->port),
            'http_errors' => false,
            'timeout' => 60,
        ]);
    }

    /**
     * Insert one row built from a factory's raw attributes, so the schema
     * knowledge stays in the factories.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function insert(string $table, array $attributes): int
    {
        $row = [];

        foreach ($attributes as $column => $value) {
            $row[$column] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                is_bool($value) => (int) $value,
                default => $value,
            };
        }

        $columns = implode(', ', array_map(fn (string $c): string => "`{$c}`", array_keys($row)));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));

        $statement = $this->pdo()->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})");
        $statement->execute(array_values($row));

        return (int) $this->pdo()->lastInsertId();
    }

    public function scalar(string $sql): int
    {
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            throw new RuntimeException("Query failed: {$sql}");
        }

        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function reachable(): bool
    {
        $socket = @fsockopen($this->mysqlHost, $this->mysqlPort, $errorCode, $errorMessage, 1.0);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function recreateDatabase(): void
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d', $this->mysqlHost, $this->mysqlPort),
            $this->mysqlUsername,
            $this->mysqlPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        $pdo->exec("CREATE DATABASE `{$this->database}`");
    }

    private function migrate(): void
    {
        $migrate = new Process(
            [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
            $this->projectRoot(),
            $this->appEnv(),
            timeout: 120,
        );
        $migrate->run();

        if (! $migrate->isSuccessful()) {
            throw new RuntimeException('Concurrency harness migrate failed: '.$migrate->getErrorOutput().$migrate->getOutput());
        }
    }

    private function serve(): void
    {
        $this->port = $this->freePort();

        // php -S is run directly, not through `artisan serve`: the wrapper
        // adds a process between us and the pool, and killing the wrapper
        // strands the pool (see shutdown()). public/index.php as the router
        // is all a JSON API needs. One worker per parallel request plus
        // headroom — a pool smaller than the burst would serialize at the
        // server and fake the race away.
        $this->server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, 'public/index.php'],
            $this->projectRoot(),
            $this->appEnv() + ['PHP_CLI_SERVER_WORKERS' => '25'],
            timeout: null,
        );
        $this->server->start();

        $client = $this->client();
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            try {
                // Any HTTP answer proves the app boots; 401 is the expected one.
                if ($client->get('/v1/entitlements', ['timeout' => 1])->getStatusCode() < 500) {
                    return;
                }
            } catch (Throwable) {
                usleep(200_000);
            }
        }

        throw new RuntimeException(
            'Concurrency harness server never became ready: '.$this->server->getErrorOutput()
        );
    }

    /**
     * The child processes inherit phpunit's env (putenv), so everything that
     * must differ from the in-process suite is overridden explicitly here.
     *
     * @return array<string, string>
     */
    private function appEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->mysqlHost,
            'DB_PORT' => (string) $this->mysqlPort,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->mysqlUsername,
            'DB_PASSWORD' => $this->mysqlPassword,
            'DB_URL' => '',
            // Charges settle synchronously inside the request; webhook
            // deliveries are dropped outright so the server never has to
            // call back into itself mid-burst.
            'QUEUE_CONNECTION' => 'sync',
            'QUEUE_LANES_DRIVER' => 'sync',
            'BILLING_PSP_DRIVER' => 'loopback',
            'MOCKPSP_WEBHOOK_DROP_RATE' => '1',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'stderr',
        ];
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException("Could not probe for a free port: {$errorMessage}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw new RuntimeException('Could not read the probed socket name.');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }
}
