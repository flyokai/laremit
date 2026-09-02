<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may silently talk to the network. Everything external is
        // in-process here (loopback PSP/store drivers), so an unmocked HTTP
        // call is always a bug — except the concurrency suite, which really
        // does talk to a server it booted itself and opts back out.
        Http::preventStrayRequests();
    }
}
