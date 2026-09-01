<?php

declare(strict_types=1);

// Phase 6 isolation demo — payment POST latency, measured twice: once at
// baseline, once while `queue:flood` holds a million jobs on the events
// lane. The deliverable is the two p99s being the same number (ADR-007).
//
//   php load/payments-p99.php --rate=25 --duration=60 --user-start=1000
//   php load/payments-p99.php --base=http://localhost:8100 --token=local-billing-token
//
// Constant arrival rate via curl_multi with keepalive, same harness family
// as the Phase 2 runs that predate k6 on this box (docs/load-tests.md).
//
// Every request is a REAL purchase: a fresh user (ids user-start.., seeded
// beforehand — see docs/load-tests.md), a unique Idempotency-Key, a 202
// with a payment_intent_id, a ChargeJob on the payments lane. rate*duration
// must not exceed the seeded user count, or later requests hit 409
// already_subscribed and measure the wrong code path.

$options = getopt('', ['base::', 'token::', 'rate::', 'duration::', 'user-start::', 'product::', 'plan::']);

$base = (string) ($options['base'] ?? 'http://localhost:8100');
$token = (string) ($options['token'] ?? 'local-billing-token');
$rate = max(1, (int) ($options['rate'] ?? 25));
$duration = max(1, (int) ($options['duration'] ?? 60));
$userStart = (int) ($options['user-start'] ?? 1000);
$product = (string) ($options['product'] ?? 'vpn');
$plan = (string) ($options['plan'] ?? 'monthly');

$total = $rate * $duration;
$interval = 1.0 / $rate;

fwrite(STDERR, "POST {$base}/v1/payments at {$rate} req/s for {$duration}s ({$total} requests, users {$userStart}..".($userStart + $total - 1).")\n");

$multi = curl_multi_init();
$latencies = [];
$failures = [];
$inFlight = [];
$sent = 0;
$runId = bin2hex(random_bytes(4));
$started = microtime(true);

$makeHandle = static function (int $i) use ($base, $token, $userStart, $product, $plan, $runId): CurlHandle {
    $handle = curl_init("{$base}/v1/payments");
    $key = sprintf('p99-%s-%06d', $runId, $i);

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'user_id' => $userStart + $i,
            'product' => $product,
            'plan' => $plan,
        ], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}",
            "Idempotency-Key: {$key}",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
    ]);

    return $handle;
};

while ($sent < $total || $inFlight !== []) {
    // Constant arrival: add every handle whose scheduled instant has passed.
    while ($sent < $total && microtime(true) - $started >= $sent * $interval) {
        $handle = $makeHandle($sent);
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
printf("latency ms: p50=%.1f p90=%.1f p95=%.1f p99=%.1f max=%.1f\n", $pct(50), $pct(90), $pct(95), $pct(99), $latencies === [] ? 0 : max($latencies));

foreach ($failures as $status => $count) {
    printf("  status %d: %d\n", $status, $count);
}

exit($failures === [] ? 0 : 1);
