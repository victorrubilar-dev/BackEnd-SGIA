<?php

namespace App\Policies;

use App\Models\MaintenanceDocument;
use App\Models\User;

/**
 * Documentos de mantenimiento (REQ-19): PAN-01 y AD-01 suben y borran; DIR-01 lee y descarga.
 */
class MaintenanceDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE]);
    }

    public function view(User $user, MaintenanceDocument $document): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_WAREHOUSE]);
    }

    public function delete(User $user, MaintenanceDocument $document): bool
    {
        return $this->create($user);
    }
}
