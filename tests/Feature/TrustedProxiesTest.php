<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TLS ends at the host's nginx, and requests reach PHP from Docker's bridge
 * gateway. Laravel should believe forwarded headers from there, and only
 * from there.
 */
class TrustedProxiesTest extends TestCase
{
    private const GATEWAY = '172.18.0.1';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_proxy-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);
    }

    /** @param array<string, string> $headers */
    private function probe(string $remote, array $headers): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders($headers)
            ->getJson('/_proxy-probe')
            ->assertOk()
            ->json();
    }

    public function test_it_believes_the_docker_gateway(): void
    {
        $seen = $this->probe(self::GATEWAY, [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
        ]);

        $this->assertSame('203.0.113.7', $seen['ip']);
        $this->assertTrue($seen['secure']);
    }

    public function test_it_ignores_forwarded_headers_from_a_public_address(): void
    {
        $seen = $this->probe('198.51.100.20', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
        ]);

        $this->assertSame('198.51.100.20', $seen['ip']);
        $this->assertFalse($seen['secure']);
    }

    public function test_a_client_cannot_fake_its_ip_through_the_proxy(): void
    {
        // The host nginx appends the real address to whatever the client sent.
        $seen = $this->probe(self::GATEWAY, [
            'X-Forwarded-For' => '1.2.3.4, 203.0.113.7',
        ]);

        $this->assertSame('203.0.113.7', $seen['ip']);
    }

    public function test_it_never_takes_the_host_from_a_forwarded_header(): void
    {
        $seen = $this->probe(self::GATEWAY, ['X-Forwarded-Host' => 'evil.example']);

        $this->assertNotSame('evil.example', $seen['host']);
    }

    public function test_the_list_can_be_narrowed(): void
    {
        config(['trustedproxy.proxies' => ['10.9.9.9']]);

        $seen = $this->probe(self::GATEWAY, ['X-Forwarded-For' => '203.0.113.7']);

        $this->assertSame(self::GATEWAY, $seen['ip']);
    }
}
