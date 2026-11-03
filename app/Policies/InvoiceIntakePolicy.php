<?php

namespace App\Policies;

use App\Models\AnnualBudget;
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
        return $this->operatesOn($user, $intake->loadMissing('annualBudget')->annualBudget);
    }

    /**
     * Subir una factura contra un presupuesto: AD-01, o DIR-01 si la carrera es suya.
     */
    public function create(User $user, AnnualBudget $budget): bool
    {
        return $this->operatesOn($user, $budget);
    }

    public function update(User $user, InvoiceIntake $intake): bool
    {
        return $this->operatesOn($user, $intake->loadMissing('annualBudget')->annualBudget);
    }

    private function operatesOn(User $user, AnnualBudget $budget): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $user->isAdmin() || $user->careers()->whereKey($budget->career_id)->exists();
    }
}
