<?php

namespace App\Policies;

use App\Models\AnnualBudget;
use App\Models\User;

/**
 * AD-01 y DIR-01 (director del Área de Informática) gestionan los presupuestos de todas las carreras.
 */
class AnnualBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesBudgets();
    }

    public function view(User $user, AnnualBudget $budget): bool
    {
        return $user->managesBudgets();
    }

    public function create(User $user): bool
    {
        return $user->managesBudgets();
    }

    public function update(User $user, AnnualBudget $budget): bool
    {
        return $user->managesBudgets();
    }

    public function delete(User $user, AnnualBudget $budget): bool
    {
        return $user->managesBudgets();
    }
}
