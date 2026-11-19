<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * Registro de estudiantes (REQ-20): lo gestiona el pañol (PAN-01). No se borran, se desactivan.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole(User::ROLE_WAREHOUSE);
    }

    public function view(User $user, Student $student): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Student $student): bool
    {
        return $this->viewAny($user);
    }
}
