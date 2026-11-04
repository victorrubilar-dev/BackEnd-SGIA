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
     * Subir una factura contra un presupuesto: AD-01 y DIR-01 (cualquier carrera del área).
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
        return $this->viewAny($user) && $user->managesBudgets();
    }
}
