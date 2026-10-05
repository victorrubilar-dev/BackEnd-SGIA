<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    /**
     * Determine whether the user can create a remote loan request (REQ-09).
     *
     * Solo el docente (PRO-01) solicita préstamos remotos; el pañol (PAN-01)
     * registra los préstamos presenciales directos (REQ-10).
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(User::ROLE_TEACHER);
    }

    /**
     * Determine whether the user can view their own loan requests (REQ-09).
     */
    public function viewOwnRequests(User $user, Loan $loan): bool
    {
        return $user->is_active
            && $user->hasRole(User::ROLE_TEACHER)
            && $loan->requested_by === $user->id;
    }
}
