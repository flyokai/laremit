<?php

declare(strict_types=1);

// Child-process entry for the interleaved-user rig — see InterleaveHarness
// for why this cannot run inside the phpunit process. Invoked by
// tests/Feature/Octane/InterleavedUsersTest.php:
//
//   php tests/Support/Octane/interleave-runner.php [--leak]
//
// Prints one JSON document on stdout; logs go to stderr (LOG_CHANNEL is
// forced there by the harness), so stdout stays parseable.

use Tests\Support\Octane\InterleaveHarness;

require __DIR__.'/../../../vendor/autoload.php';

$result = InterleaveHarness::run(
    in_array('--leak', array_slice($argv, 1), true),
);

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
