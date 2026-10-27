<?php

namespace App\Policies;

use App\Models\Usuario;

class LoanPolicy
{
    /**
     * Determine whether the user can create a remote loan request (REQ-09).
     *
     * Solo el docente (PRO-01) solicita préstamos remotos; el pañol (PAN-01)
     * registra los préstamos presenciales directos (REQ-10).
     */
    public function create(Usuario $user): bool
    {
        return $user->activo && $user->hasRole(Usuario::ROLE_TEACHER);
    }

    /**
     * Determine whether the user can view their own loan requests (REQ-09).
     */
    public function viewOwnRequests(Usuario $user, mixed $loan): bool
    {
        $requestedBy = is_object($loan) ? ($loan->requested_by ?? $loan->profesor_id ?? null) : null;

        return $user->activo
            && $user->hasRole(Usuario::ROLE_TEACHER)
            && $requestedBy === $user->id;
    }

    /**
     * Determine whether the user can list the loan history (REQ-11).
     */
    public function viewAny(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_WAREHOUSE,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_ADMIN,
        ]);
    }

    /**
     * Determine whether the user can view the loan: el pañol, el director y
     * el administrador ven el historial completo; el docente solo lo suyo.
     */
    public function view(Usuario $user, mixed $loan): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        $requestedBy = is_object($loan) ? ($loan->requested_by ?? $loan->profesor_id ?? null) : null;

        return $user->activo
            && $user->hasRole(Usuario::ROLE_TEACHER)
            && $requestedBy === $user->id;
    }

    /**
     * Operaciones del pañol sobre los préstamos (REQ-10): ver pendientes,
     * aprobar, rechazar, confirmar entrega y registrar préstamos presenciales.
     */
    public function pending(Usuario $user): bool
    {
        return $this->operates($user);
    }

    public function approve(Usuario $user, mixed $loan): bool
    {
        return $this->operates($user);
    }

    public function reject(Usuario $user, mixed $loan): bool
    {
        return $this->operates($user);
    }

    public function deliver(Usuario $user, mixed $loan): bool
    {
        return $this->operates($user);
    }

    public function checkout(Usuario $user): bool
    {
        return $this->operates($user);
    }

    private function operates(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_WAREHOUSE,
            Usuario::ROLE_ADMIN,
        ]);
    }
}
