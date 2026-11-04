<?php

namespace App\Policies;

use App\Models\Storeroom;
use App\Models\User;

/**
 * Pañoles: lectura para todos los autenticados; alta, edición y borrado solo AD-01.
 */
class StoreroomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Storeroom $storeroom): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(User::ROLE_ADMIN);
    }

    public function update(User $user, Storeroom $storeroom): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Storeroom $storeroom): bool
    {
        return $this->create($user);
    }
}
