<?php

declare(strict_types=1);

// Entitlement-read latency harness, and the live half of the ADR-008
// interleaved-user leak check. GET /v1/entitlements is the cheapest
// endpoint in the API, which makes it two things at once:
//
//  - the request where per-request framework bootstrap is the largest
//    share of latency, so the rung of the Phase 8 ladder where Octane
//    worker mode shows its real delta (docs/load-tests.md);
//  - the request whose response echoes the user it answered for, so
//    EVERY response is asserted against the user that was asked about.
//    Alternating users (--users=A,B) turns this into the interleaved-user
//    test from Module 10: run it against a worker with
//    OCTANE_DEMO_CROSS_REQUEST_LEAK=true and the violations counter is
//    the leak, measured.
//
//   php load/entitlements-p99.php --users=40006,40007 --rate=100 --duration=60
//   php load/entitlements-p99.php --base=http://localhost:8100 --token=local-billing-token

$options = getopt('', ['base::', 'token::', 'rate::', 'duration::', 'users::', 'product::']);

$base = (string) ($options['base'] ?? 'http://localhost:8100');
$token = (string) ($options['token'] ?? 'local-billing-token');
$rate = max(1, (int) ($options['rate'] ?? 100));
$duration = max(1, (int) ($options['duration'] ?? 60));
$product = (string) ($options['product'] ?? 'vpn');
$users = array_values(array_filter(array_map('intval', explode(',', (string) ($options['users'] ?? '1')))));

if ($users === []) {
    fwrite(STDERR, "--users must name at least one user id\n");
    exit(2);
}

$total = $rate * $duration;
$interval = 1.0 / $rate;

fwrite(STDERR, "GET {$base}/v1/entitlements at {$rate} req/s for {$duration}s, users [".implode(',', $users)."] interleaved\n");

$makeHandle = static function (int $i) use ($base, $token, $users, $product): CurlHandle {
    $userId = $users[$i % count($users)];
    $handle = curl_init("{$base}/v1/entitlements?user_id={$userId}&product={$product}");

    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
        CURLOPT_PRIVATE => (string) $userId,
    ]);

    return $handle;
};

$multi = curl_multi_init();
$latencies = [];
$failures = [];
$violations = 0;
$firstViolation = null;
$inFlight = [];
$sent = 0;
$started = microtime(true);

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

        if ($status === 200) {
            $latencies[] = $ms;

            // The leak canary: the response must be about the user that was
            // asked about. Anything else is cross-request state.
            $requested = (int) curl_getinfo($handle, CURLINFO_PRIVATE);
            $body = json_decode((string) curl_multi_getcontent($handle), true);
            $returned = is_array($body) ? (int) ($body['user_id'] ?? 0) : 0;

            if ($returned !== $requested) {
                $violations++;
                $firstViolation ??= "asked about user {$requested}, answered about user {$returned}";
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

printf("requests: %d ok (200) / %d failed, %.1fs wall, %.1f req/s achieved\n", count($latencies), array_sum($failures), $elapsed, $total / $elapsed);
printf("latency ms: p50=%.1f p90=%.1f p95=%.1f p99=%.1f max=%.1f\n", $pct(50), $pct(90), $pct(95), $pct(99), $latencies === [] ? 0 : max($latencies));

foreach ($failures as $status => $count) {
    printf("  status %d: %d\n", $status, $count);
}

if ($violations > 0) {
    printf("CROSS-REQUEST LEAK: %d of %d responses answered about the wrong user (first: %s)\n", $violations, count($latencies), $firstViolation);
} else {
    printf("identity check: every response matched the requested user\n");
}

exit($failures === [] && $violations === 0 ? 0 : 1);
