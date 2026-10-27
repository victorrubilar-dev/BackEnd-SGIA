<?php

namespace App\Policies;

use App\Models\Producto;
use App\Models\Usuario;

class ProductPolicy
{
    /**
     * Determine whether the user can view any products.
     */
    public function viewAny(Usuario $user): bool
    {
        return (bool) $user->activo;
    }

    /**
     * Determine whether the user can view the product.
     */
    public function view(Usuario $user, mixed $product = null): bool
    {
        return (bool) $user->activo;
    }

    /**
     * Determine whether the user can create products (AD-01, DIR-01, PAN-01).
     */
    public function create(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can update the product.
     */
    public function update(Usuario $user, mixed $product = null): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can delete the product (AD-01, DIR-01).
     */
    public function delete(Usuario $user, mixed $product = null): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can update the status of the product.
     */
    public function updateStatus(Usuario $user, mixed $product = null): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can scan invoices (AD-01, DIR-01).
     */
    public function scanInvoice(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
        ]);
    }
}
