<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    /**
     * Determine whether the user can view any suppliers (AD-01, DIR-01, PAN-01).
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
     * Determine whether the user can view the supplier.
     */
    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create suppliers (AD-01, DIR-01).
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can update the supplier (AD-01, DIR-01).
     */
    public function update(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can update the supplier status (AD-01, DIR-01).
     */
    public function updateStatus(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can delete a supplier (AD-01).
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
