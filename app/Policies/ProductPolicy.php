<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can view any products.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * Determine whether the user can view the product.
     */
    public function view(User $user, Product $product): bool
    {
        return $user->is_active;
    }

    /**
     * Determine whether the user can create products (AD-01, DIR-01, PAN-01).
     */
    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
            User::ROLE_WAREHOUSE,
        ], true);
    }

    /**
     * Determine whether the user can update the product.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->is_active && in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
            User::ROLE_WAREHOUSE,
        ], true);
    }

    /**
     * Determine whether the user can delete the product (AD-01, DIR-01).
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->is_active && in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
        ], true);
    }

    /**
     * Determine whether the user can update the status of the product.
     */
    public function updateStatus(User $user, Product $product): bool
    {
        return $user->is_active && in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
            User::ROLE_WAREHOUSE,
        ], true);
    }

    /**
     * Determine whether the user can scan invoices (AD-01, DIR-01).
     */
    public function scanInvoice(User $user): bool
    {
        return $user->is_active && in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
        ], true);
    }
}
