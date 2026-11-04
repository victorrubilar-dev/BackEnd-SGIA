<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * Registro de estudiantes (REQ-20): lo gestiona el pañol (PAN-01) y AD-01; solo AD-01 borra.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_WAREHOUSE]);
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

    public function delete(User $user, Student $student): bool
    {
        return $user->is_active && $user->hasRole(User::ROLE_ADMIN);
    }
}
