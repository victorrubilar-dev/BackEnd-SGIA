<?php

namespace App\Policies;

use App\Models\Usuario;

class PurchasePolicy
{
    /**
     * Determine whether the user can view any purchases (AD-01, DIR-01, PAN-01).
     */
    public function viewAny(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can view the purchase.
     */
    public function view(Usuario $user, mixed $purchase): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create purchases (AD-01, DIR-01).
     */
    public function create(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can update the purchase status (AD-01, DIR-01, PAN-01).
     */
    public function updateStatus(Usuario $user, mixed $purchase): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can scan the arrival guide/invoice (AD-01, DIR-01, PAN-01).
     */
    public function scanArrival(Usuario $user, mixed $purchase): bool
    {
        return $this->viewAny($user);
    }
}
