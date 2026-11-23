<?php

namespace Tests\Unit;

use App\Admin\GoogleGroupMembership;
use App\Admin\GroupCheckFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleGroupMembershipTest extends TestCase
{
    private const HAS_MEMBER = 'https://admin.googleapis.com/admin/directory/v1/groups/*/hasMember/*';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function membership(): GoogleGroupMembership
    {
        return new class('pwa-admins@lezwatchtv.com', '/nonexistent.json') extends GoogleGroupMembership
        {
            protected function accessToken(): string
            {
                return 'test-token';
            }
        };
    }

    public function test_member_returns_true(): void
    {
        Http::fake([self::HAS_MEMBER => Http::response(['isMember' => true])]);

        $this->assertTrue($this->membership()->isMember('mika@lezwatchtv.com'));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-token')
            && $r->url() === 'https://admin.googleapis.com/admin/directory/v1/groups/pwa-admins%40lezwatchtv.com/hasMember/mika%40lezwatchtv.com');
    }

    public function test_non_member_returns_false(): void
    {
        Http::fake([self::HAS_MEMBER => Http::response(['isMember' => false])]);

        $this->assertFalse($this->membership()->isMember('someone@lezwatchtv.com'));
    }

    public function test_permission_error_throws(): void
    {
        Http::fake([self::HAS_MEMBER => Http::response(['error' => ['code' => 403]], 403)]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_server_error_throws(): void
    {
        Http::fake([self::HAS_MEMBER => Http::response([], 500)]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_timeout_throws(): void
    {
        Http::fake([self::HAS_MEMBER => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_missing_is_member_throws(): void
    {
        Http::fake([self::HAS_MEMBER => Http::response(['unexpected' => 'shape'])]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_path_characters_in_email_are_encoded(): void
    {
        Http::fake([self::HAS_MEMBER => Http::response(['isMember' => false])]);

        $this->membership()->isMember('a/../b@lezwatchtv.com');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hasMember/a%2F..%2Fb%40lezwatchtv.com'));
    }

    public function test_missing_key_file_throws(): void
    {
        $this->expectException(GroupCheckFailed::class);
        (new GoogleGroupMembership('pwa-admins@lezwatchtv.com', '/nonexistent.json'))->isMember('mika@lezwatchtv.com');
    }
}
