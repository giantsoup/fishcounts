<?php

namespace App\Policies;

use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * Agent API tokens are managed by active admins only, and only tokens that belong to a user.
 */
class PersonalAccessTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveAdmin();
    }

    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $this->canManage($user, $token);
    }

    public function create(User $user): bool
    {
        return $user->isActiveAdmin();
    }

    public function update(User $user, PersonalAccessToken $token): bool
    {
        return $this->canManage($user, $token);
    }

    public function rotate(User $user, PersonalAccessToken $token): bool
    {
        return $this->canManage($user, $token);
    }

    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $this->canManage($user, $token);
    }

    private function canManage(User $user, PersonalAccessToken $token): bool
    {
        return $user->isActiveAdmin() && $token->isOwnedByUser();
    }
}
