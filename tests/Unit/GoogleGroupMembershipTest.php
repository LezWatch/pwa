<?php

namespace Tests\Unit;

use App\Admin\GoogleGroupMembership;
use App\Admin\GroupCheckFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleGroupMembershipTest extends TestCase
{
    private const LOOKUP = 'https://cloudidentity.googleapis.com/v1/groups:lookup*';

    private const CHECK = 'https://cloudidentity.googleapis.com/v1/groups/abc123/memberships:checkTransitiveMembership*';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['admin.group' => 'pwa-admins@lezwatchtv.com']);
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
        Http::fake([
            self::LOOKUP => Http::response(['name' => 'groups/abc123']),
            self::CHECK => Http::response(['hasMembership' => true]),
        ]);

        $this->assertTrue($this->membership()->isMember('mika@lezwatchtv.com'));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-token')
            && str_contains($r->url(), 'groupKey.id=pwa-admins%40lezwatchtv.com'));
    }

    public function test_non_member_returns_false(): void
    {
        Http::fake([
            self::LOOKUP => Http::response(['name' => 'groups/abc123']),
            self::CHECK => Http::response(['hasMembership' => false]),
        ]);

        $this->assertFalse($this->membership()->isMember('someone@lezwatchtv.com'));
    }

    public function test_lookup_error_throws(): void
    {
        Http::fake([self::LOOKUP => Http::response(['error' => []], 403)]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_membership_error_throws(): void
    {
        Http::fake([
            self::LOOKUP => Http::response(['name' => 'groups/abc123']),
            self::CHECK => Http::response([], 500),
        ]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_missing_has_membership_throws(): void
    {
        Http::fake([
            self::LOOKUP => Http::response(['name' => 'groups/abc123']),
            self::CHECK => Http::response(['unexpected' => 'shape']),
        ]);

        $this->expectException(GroupCheckFailed::class);
        $this->membership()->isMember('mika@lezwatchtv.com');
    }

    public function test_email_is_quoted_in_query(): void
    {
        Http::fake([
            self::LOOKUP => Http::response(['name' => 'groups/abc123']),
            self::CHECK => Http::response(['hasMembership' => true]),
        ]);

        $this->membership()->isMember("o'brien@lezwatchtv.com");

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'checkTransitiveMembership')
            && str_contains(urldecode($r->url()), "query=member_key_id=='o\\'brien@lezwatchtv.com'"));
    }

    public function test_missing_key_file_throws(): void
    {
        $this->expectException(GroupCheckFailed::class);
        (new GoogleGroupMembership('pwa-admins@lezwatchtv.com', '/nonexistent.json'))->isMember('mika@lezwatchtv.com');
    }
}
