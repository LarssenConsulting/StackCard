<?php

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\User;

/** A client's token reads the whole fleet: admins only. */
class ApiClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ApiClient $client): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ApiClient $client): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ApiClient $client): bool
    {
        return $user->isAdmin();
    }
}
