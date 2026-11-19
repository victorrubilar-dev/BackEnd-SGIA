<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    /**
     * Determine whether the user can view any suppliers (DIR-01, PAN-01).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([
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
     * Determine whether the user can create suppliers (DIR-01).
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can update the supplier (DIR-01).
     */
    public function update(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can update the supplier status (DIR-01).
     */
    public function updateStatus(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can delete a supplier (DIR-01).
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->is_active && $user->isDirector();
    }
}
