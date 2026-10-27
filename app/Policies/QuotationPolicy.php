<?php

namespace App\Policies;

use App\Models\Usuario;

class QuotationPolicy
{
    /**
     * Determine whether the user can view any quotations (AD-01, DIR-01).
     */
    public function viewAny(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can view the quotation.
     */
    public function view(Usuario $user, mixed $quotation): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create quotations (AD-01, DIR-01).
     */
    public function create(Usuario $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the quotation status.
     */
    public function updateStatus(Usuario $user, mixed $quotation): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can record supplier responses.
     */
    public function recordResponse(Usuario $user, mixed $quotation): bool
    {
        return $this->viewAny($user);
    }
}
