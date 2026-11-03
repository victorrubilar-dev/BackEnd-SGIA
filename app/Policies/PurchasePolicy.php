<?php

namespace App\Policies;

use App\Models\AnnualBudget;
use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    /**
     * Determine whether the user can view any purchases (AD-01, DIR-01, PAN-01).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
            User::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can view the purchase.
     */
    public function view(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create purchases (AD-01, DIR-01).
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Comprometer presupuesto: AD-01, o DIR-01 si la carrera del presupuesto es suya.
     */
    public function useBudget(User $user, AnnualBudget $budget): bool
    {
        return $this->create($user)
            && ($user->isAdmin() || $user->careers()->whereKey($budget->career_id)->exists());
    }

    /**
     * Cancelar una compra: AD-01, o DIR-01 de la carrera de su presupuesto.
     */
    public function cancel(User $user, Purchase $purchase): bool
    {
        if (! $this->create($user)) {
            return false;
        }

        if ($user->isAdmin() || $purchase->annual_budget_id === null) {
            return $user->isAdmin();
        }

        return $user->careers()->whereKey($purchase->loadMissing('annualBudget')->annualBudget->career_id)->exists();
    }

    /**
     * Determine whether the user can update the purchase status (AD-01, DIR-01, PAN-01).
     */
    public function updateStatus(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can scan the arrival guide/invoice (AD-01, DIR-01, PAN-01).
     */
    public function scanArrival(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }
}
