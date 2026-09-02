<?php

declare(strict_types=1);

// Ingest latency harness — the PHP twin of load/k6-ingest.js for boxes
// without k6 (docs/load-tests.md records which harness produced each run).
// Same wire shape, same defaults: 50 req/s of 100-event batches = 5,000
// events/sec, the Phase 2 sustained target, re-measured per rung of the
// Phase 8 optimization ladder.
//
//   php load/ingest-p99.php --rate=50 --batch=100 --duration=60
//   php load/ingest-p99.php --base=http://localhost:8100 --token=local-dev-token
//
// Constant arrival rate via curl_multi with keepalive, unique event_ids per
// run — the dedup layer must never be what makes a re-run look fast.

$options = getopt('', ['base::', 'token::', 'rate::', 'duration::', 'batch::']);

$base = (string) ($options['base'] ?? 'http://localhost:8100');
$token = (string) ($options['token'] ?? 'local-dev-token');
$rate = max(1, (int) ($options['rate'] ?? 50));
$duration = max(1, (int) ($options['duration'] ?? 60));
$batch = max(1, (int) ($options['batch'] ?? 100));

$total = $rate * $duration;
$interval = 1.0 / $rate;

fwrite(STDERR, "POST {$base}/v1/events at {$rate} req/s x {$batch}-event batches for {$duration}s (".($rate * $batch)." events/s)\n");

$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};

$products = ['edtech', 'vpn', 'ai-tutor'];

$makeHandle = static function () use ($base, $token, $batch, $uuid, $products): CurlHandle {
    $occurredAt = date('c');
    $events = [];

    for ($i = 0; $i < $batch; $i++) {
        $events[] = [
            'event_id' => $uuid(),
            'type' => 'video.watched',
            'schema_version' => 2,
            'occurred_at' => $occurredAt,
            'user_id' => random_int(1, 100_000),
            'product' => $products[array_rand($products)],
            'priority' => 'analytics',
            'payload' => ['video_id' => 'v-load', 'position_ms' => random_int(0, 600_000)],
        ];
    }

    $handle = curl_init("{$base}/v1/events");

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['events' => $events], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
    ]);

    return $handle;
};

$multi = curl_multi_init();
$latencies = [];
$failures = [];
$inFlight = [];
$accepted = 0;
$shed = 0;
$sent = 0;
$started = microtime(true);

while ($sent < $total || $inFlight !== []) {
    // Constant arrival: add every handle whose scheduled instant has passed.
    while ($sent < $total && microtime(true) - $started >= $sent * $interval) {
        $handle = $makeHandle();
        curl_multi_add_handle($multi, $handle);
        $inFlight[spl_object_id($handle)] = true;
        $sent++;
    }

    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.005);

    while (($done = curl_multi_info_read($multi)) !== false) {
        $handle = $done['handle'];
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $ms = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;

        if ($status === 202) {
            $latencies[] = $ms;
            $body = json_decode((string) curl_multi_getcontent($handle), true);

            if (is_array($body)) {
                $accepted += (int) ($body['accepted'] ?? 0);
                $shed += (int) ($body['shed'] ?? 0);
            }
        } else {
            $failures[$status] = ($failures[$status] ?? 0) + 1;
        }

        unset($inFlight[spl_object_id($handle)]);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
}

$elapsed = microtime(true) - $started;
sort($latencies);

$pct = static function (float $p) use ($latencies): float {
    return $latencies === [] ? 0.0 : $latencies[(int) min(count($latencies) - 1, ceil($p / 100 * count($latencies)) - 1)];
};

printf("requests: %d ok (202) / %d failed, %.1fs wall, %.1f req/s achieved\n", count($latencies), array_sum($failures), $elapsed, $total / $elapsed);
printf("events: %d accepted, %d shed (%.0f events/s)\n", $accepted, $shed, $accepted / $elapsed);
printf("latency ms: p50=%.1f p90=%.1f p95=%.1f p99=%.1f max=%.1f\n", $pct(50), $pct(90), $pct(95), $pct(99), $latencies === [] ? 0 : max($latencies));

foreach ($failures as $status => $count) {
    printf("  status %d: %d\n", $status, $count);
}

exit($failures === [] ? 0 : 1);
