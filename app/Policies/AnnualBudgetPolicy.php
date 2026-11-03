<?php

namespace App\Policies;

use App\Models\AnnualBudget;
use App\Models\User;

class AnnualBudgetPolicy
{
    /**
     * AD-01 y DIR-01 listan presupuestos (DIR-01 solo los de sus carreras, se filtra en la consulta).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR]);
    }

    /**
     * AD-01 ve todos; DIR-01 solo los de las carreras que tiene asignadas.
     */
    public function view(User $user, AnnualBudget $budget): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $user->isAdmin() || $user->careers()->whereKey($budget->career_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function update(User $user, AnnualBudget $budget): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, AnnualBudget $budget): bool
    {
        return $this->create($user);
    }
}
