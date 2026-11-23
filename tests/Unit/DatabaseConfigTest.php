<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reads config/database.php directly, so the developer's .env and phpunit.xml
 * can't mask the defaults the server falls back to.
 */
class DatabaseConfigTest extends TestCase
{
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = $_SERVER['DB_DATABASE'] ?? null;
        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);
    }

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            putenv("DB_DATABASE={$this->saved}");
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $this->saved;
        }
        parent::tearDown();
    }

    private function sqlite(): array
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $config = require __DIR__.'/../../config/database.php';

        return [$app, $config['connections']['sqlite']];
    }

    public function test_sqlite_defaults_to_the_shared_volume(): void
    {
        [$app, $sqlite] = $this->sqlite();

        $this->assertSame($app->databasePath('data/database.sqlite'), $sqlite['database']);
    }

    public function test_sqlite_uses_wal_and_waits_for_locks(): void
    {
        [, $sqlite] = $this->sqlite();

        $this->assertSame('wal', $sqlite['journal_mode']);
        $this->assertSame(5000, $sqlite['busy_timeout']);
    }
}
