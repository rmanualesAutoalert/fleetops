<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

trait RefreshInMemoryDatabase
{
    use RefreshDatabase;

    /** Build the disposable schema without dropping any tables. */
    protected function migrateDatabases(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Test migrations are allowed only on SQLite :memory:.');
        }

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }
}
