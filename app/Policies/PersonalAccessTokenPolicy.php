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
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $this->isActiveAdmin($user) && $token->isOwnedByUser();
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, PersonalAccessToken $token): bool
    {
        return $this->isActiveAdmin($user) && $token->isOwnedByUser();
    }

    public function rotate(User $user, PersonalAccessToken $token): bool
    {
        return $this->update($user, $token);
    }

    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $this->isActiveAdmin($user) && $token->isOwnedByUser();
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
