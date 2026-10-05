<?php

namespace App\Policies;

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
