<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LwtvConfigTest extends TestCase
{
    private const KEYS = ['LWTV_API_URL', 'LWTV_API_VERIFY_TLS', 'LWTV_API_TIMEOUT'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }
        parent::tearDown();
    }

    public function test_defaults_point_at_production(): void
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }

        $config = $this->loadConfig();

        $this->assertSame('https://lezwatchtv.com', $config['api_url']);
        $this->assertTrue($config['verify_tls']);
        $this->assertSame(30, $config['timeout']);
    }

    public function test_local_overrides_are_read_and_trailing_slash_trimmed(): void
    {
        $this->setEnv('LWTV_API_URL', 'https://lwtv.local/');
        $this->setEnv('LWTV_API_VERIFY_TLS', 'false');
        $this->setEnv('LWTV_API_TIMEOUT', '5');

        $config = $this->loadConfig();

        $this->assertSame('https://lwtv.local', $config['api_url']);
        $this->assertFalse($config['verify_tls']);
        $this->assertSame(5, $config['timeout']);
    }

    private function loadConfig(): array
    {
        return require __DIR__.'/../../config/lwtv.php';
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
