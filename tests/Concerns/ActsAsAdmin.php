<?php

namespace Tests\Concerns;

use App\Admin\GroupCheckFailed;
use App\Admin\GroupMembership;

trait ActsAsAdmin
{
    /** Emails the fake group check asked about. */
    public array $groupChecks = [];

    protected function fakeGroup(bool|GroupCheckFailed $answer): void
    {
        $test = $this;
        $this->app->instance(GroupMembership::class, new class($answer, $test) implements GroupMembership
        {
            public function __construct(private bool|GroupCheckFailed $answer, private object $test) {}

            public function isMember(string $email): bool
            {
                $this->test->groupChecks[] = $email;

                if ($this->answer instanceof GroupCheckFailed) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        });
    }

    protected function signedInAs(string $email = 'mika@lezwatchtv.com', ?int $checkedAt = null): static
    {
        return $this->withSession([
            'admin.email' => $email,
            'admin.checked_at' => $checkedAt ?? now()->getTimestamp(),
        ]);
    }

    protected function adminUrl(string $path = ''): string
    {
        return '/secret-door'.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
