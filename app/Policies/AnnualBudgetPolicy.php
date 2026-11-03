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

    /**
     * Rol que puede gestionar presupuestos (AD-01 y DIR-01). La carrera se valida aparte.
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR]);
    }

    /**
     * Crear para una carrera concreta: AD-01 cualquiera; DIR-01 solo las suyas.
     */
    public function createFor(User $user, int $careerId): bool
    {
        return $this->create($user) && ($user->isAdmin() || $user->careers()->whereKey($careerId)->exists());
    }

    public function update(User $user, AnnualBudget $budget): bool
    {
        return $this->createFor($user, $budget->career_id);
    }

    public function delete(User $user, AnnualBudget $budget): bool
    {
        return $this->createFor($user, $budget->career_id);
    }
}
