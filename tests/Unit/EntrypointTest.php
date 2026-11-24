<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * docker/php/entrypoint.sh, run directly with sh against a throwaway app dir.
 * Locally, compose.override.yaml mounts the host checkout over the image, so
 * a checkout without vendor/ must fail loudly instead of crash-looping.
 */
class EntrypointTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lwtv-entrypoint-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/vendor/autoload.php');
        @rmdir($this->dir.'/vendor');
        @rmdir($this->dir);
    }

    private function entrypoint(): Process
    {
        $process = new Process(
            ['sh', dirname(__DIR__, 2).'/docker/php/entrypoint.sh', 'echo', 'started'],
            env: ['APP_DIR' => $this->dir, 'APP_ENV' => 'local', 'RUN_MIGRATIONS' => 'false'],
        );
        $process->run();

        return $process;
    }

    public function test_missing_vendor_stops_with_instructions(): void
    {
        $process = $this->entrypoint();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringNotContainsString('started', $process->getOutput());
        $this->assertStringContainsString('vendor/autoload.php is missing', $process->getErrorOutput());
        $this->assertStringContainsString('npm run installer', $process->getErrorOutput());
    }

    public function test_present_vendor_starts_the_command(): void
    {
        mkdir($this->dir.'/vendor');
        touch($this->dir.'/vendor/autoload.php');

        $process = $this->entrypoint();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("started\n", $process->getOutput());
    }
}
