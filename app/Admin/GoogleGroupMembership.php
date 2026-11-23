<?php

namespace App\Admin;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks the Cloud Identity Groups API, as a service account that holds the
 * Groups Reader admin role. No domain-wide delegation.
 */
class GoogleGroupMembership implements GroupMembership
{
    private const API = 'https://cloudidentity.googleapis.com/v1';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-identity.groups.readonly';

    public function __construct(private string $group, private ?string $keyPath) {}

    public function isMember(string $email): bool
    {
        try {
            $client = Http::withToken($this->accessToken())->timeout(10)->acceptJson();

            $groupName = $client->get(self::API.'/groups:lookup', ['groupKey.id' => $this->group])
                ->throw()->json('name');

            if (! is_string($groupName) || ! str_starts_with($groupName, 'groups/')) {
                throw new GroupCheckFailed('Group lookup returned no group name.');
            }

            $quoted = "'".addcslashes($email, "'\\")."'";
            $hasMembership = $client->get(
                self::API."/{$groupName}/memberships:checkTransitiveMembership",
                ['query' => "member_key_id=={$quoted}"],
            )->throw()->json('hasMembership');
        } catch (GroupCheckFailed $e) {
            throw $e;
        } catch (RequestException|ConnectionException $e) {
            throw new GroupCheckFailed('Google group check failed: '.$e->getMessage(), previous: $e);
        }

        if (! is_bool($hasMembership)) {
            throw new GroupCheckFailed('Membership check returned no answer.');
        }

        return $hasMembership;
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
