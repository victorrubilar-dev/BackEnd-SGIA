<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

/**
 * Cotizaciones del flujo antiguo: solo lectura (DIR-01).
 */
class QuotationPolicy
{
    /**
     * Determine whether the user can view any quotations (DIR-01).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_DIRECTOR,
        ]);
    }

    /**
     * Determine whether the user can view the quotation.
     */
    public function view(User $user, Quotation $quotation): bool
    {
        return $this->viewAny($user);
    }
}
