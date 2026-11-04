<?php

namespace App\Policies;

use App\Models\MaintenanceRecord;
use App\Models\User;

/**
 * Mantenciones (REQ-18): PAN-01 y AD-01 gestionan; DIR-01 solo lee.
 */
class MaintenanceRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE]);
    }

    public function view(User $user, MaintenanceRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_WAREHOUSE]);
    }

    /** Edición del checklist, finalización y anulación. */
    public function update(User $user, MaintenanceRecord $record): bool
    {
        return $this->create($user);
    }
}
