<?php

namespace App\Admin;

interface GroupMembership
{
    /**
     * Whether the email belongs to the admin group, directly or through a nested group.
     *
     * @throws GroupCheckFailed when the answer can't be had; callers must treat that as "no"
     */
    public function isMember(string $email): bool;
}
