<?php

namespace App\Policies;

use App\Models\EquipmentUnit;
use App\Models\User;

class EquipmentUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, EquipmentUnit $unit): bool
    {
        return $user->is_active;
    }

    /** Alta, edición y cambio de estado: AD-01, DIR-01, PAN-01. */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE]);
    }

    public function update(User $user, EquipmentUnit $unit): bool
    {
        return $this->create($user);
    }

    public function updateStatus(User $user, EquipmentUnit $unit): bool
    {
        return $this->create($user);
    }

    /** Borrado: AD-01, DIR-01. */
    public function delete(User $user, EquipmentUnit $unit): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR]);
    }
}
