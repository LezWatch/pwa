<?php

namespace App\Admin;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks the Admin SDK Directory API (members.hasMember), as a service account
 * that holds the Groups Reader admin role. No domain-wide delegation.
 *
 * hasMember follows nested groups within the domain and works on every
 * Workspace edition; Cloud Identity's checkTransitiveMembership needs an
 * Enterprise or Cloud Identity Premium edition.
 */
class GoogleGroupMembership implements GroupMembership
{
    private const API = 'https://admin.googleapis.com/admin/directory/v1';

    private const SCOPE = 'https://www.googleapis.com/auth/admin.directory.group.member.readonly';

    public function __construct(private string $group, private ?string $keyPath) {}

    public function isMember(string $email): bool
    {
        $url = sprintf('%s/groups/%s/hasMember/%s', self::API, rawurlencode($this->group), rawurlencode($email));

        try {
            $isMember = Http::withToken($this->accessToken())
                ->timeout(10)
                ->acceptJson()
                ->get($url)
                ->throw()
                ->json('isMember');
        } catch (RequestException|ConnectionException $e) {
            throw new GroupCheckFailed('Google group check failed: '.$e->getMessage(), previous: $e);
        }

        if (! is_bool($isMember)) {
            throw new GroupCheckFailed('Membership check returned no answer.');
        }

        return $isMember;
    }

    protected function accessToken(): string
    {
        if (! $this->keyPath || ! is_readable($this->keyPath)) {
            throw new GroupCheckFailed('Google service account key is missing or unreadable.');
        }

        try {
            $key = json_decode((string) file_get_contents($this->keyPath), true, flags: JSON_THROW_ON_ERROR);
            $token = (new ServiceAccountCredentials(self::SCOPE, $key))->fetchAuthToken();
        } catch (Throwable $e) {
            throw new GroupCheckFailed('Could not get a Google access token: '.$e->getMessage(), previous: $e);
        }

        if (empty($token['access_token'])) {
            throw new GroupCheckFailed('Google returned no access token.');
        }

        return $token['access_token'];
    }
}
