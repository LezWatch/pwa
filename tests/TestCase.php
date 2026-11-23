<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before RefreshDatabase drops every table.
     *
     * Inside Docker, .env values are real environment variables and can beat
     * phpunit.xml. That once pointed the tests at the live SQLite file and
     * wiped the catalogue. Refuse to touch anything but an in-memory database.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests must use the in-memory SQLite database, got {$connection}:{$database}. Check phpunit.xml."
            );
        }

        return parent::setUpTraits();
    }
}
