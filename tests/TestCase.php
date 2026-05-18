<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Terminate any stale connections from previous tests that may hold locks.
        // Necessary because IngestReportAction uses a nested DB::transaction() inside
        // RefreshDatabase's outer transaction; the TCP connection from the previous
        // test class may not be fully closed by the time our setUp seeds the DB.
        DB::statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid()');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
