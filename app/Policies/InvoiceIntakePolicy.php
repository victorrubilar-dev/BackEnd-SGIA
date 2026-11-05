<?php

namespace App\Policies;

use App\Models\InvoiceIntake;
use App\Models\User;

class InvoiceIntakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR]);
    }

    public function view(User $user, InvoiceIntake $intake): bool
    {
        return $this->operatesOn($user);
    }

    /**
     * Subir una factura: AD-01 y DIR-01.
     */
    public function create(User $user): bool
    {
        return $this->operatesOn($user);
    }

    public function update(User $user, InvoiceIntake $intake): bool
    {
        return $this->operatesOn($user);
    }

    private function operatesOn(User $user): bool
    {
        return $this->viewAny($user) && $user->managesBudgets();
    }
}
