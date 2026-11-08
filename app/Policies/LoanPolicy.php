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

    /**
     * Determine whether the user can list the loan history (REQ-11).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_WAREHOUSE,
            User::ROLE_DIRECTOR,
            User::ROLE_ADMIN,
        ]);
    }

    /**
     * Determine whether the user can view the loan: el director y el administrador ven
     * todos; el pañolero, los de su pañol; el docente, solo los suyos.
     */
    public function view(User $user, Loan $loan): bool
    {
        if ($this->viewAny($user)) {
            return $this->reaches($user, $loan);
        }

        return $user->is_active
            && $user->hasRole(User::ROLE_TEACHER)
            && $loan->requested_by === $user->id;
    }

    /**
     * Operaciones del pañol sobre los préstamos (REQ-10): ver pendientes,
     * aprobar, rechazar, confirmar entrega y registrar préstamos presenciales. El pañolero
     * solo opera los préstamos de su pañol.
     */
    public function pending(User $user): bool
    {
        return $this->operates($user);
    }

    public function approve(User $user, Loan $loan): bool
    {
        return $this->operates($user) && $this->reaches($user, $loan);
    }

    public function reject(User $user, Loan $loan): bool
    {
        return $this->operates($user) && $this->reaches($user, $loan);
    }

    public function deliver(User $user, Loan $loan): bool
    {
        return $this->operates($user) && $this->reaches($user, $loan);
    }

    /**
     * Registrar la devolución de un préstamo entregado (pañol y administrador).
     */
    public function checkin(User $user): bool
    {
        return $this->operates($user);
    }

    /**
     * Registrar la devolución de este préstamo en particular.
     */
    public function checkinLoan(User $user, Loan $loan): bool
    {
        return $this->operates($user) && $this->reaches($user, $loan);
    }

    /**
     * El docente solo cancela solicitudes propias que siguen pendientes de revisión.
     */
    public function cancel(User $user, Loan $loan): bool
    {
        return $this->viewOwnRequests($user, $loan);
    }

    /**
     * El pañolero solo registra préstamos presenciales en su pañol. Un `storeroom_id` no numérico
     * se deja pasar para que la validación responda `422`.
     */
    public function checkout(User $user, mixed $storeroomId = null): bool
    {
        if (! $this->operates($user)) {
            return false;
        }

        $own = $user->loanStoreroomId();

        return $own === null || ! is_numeric($storeroomId) || (int) $storeroomId === $own;
    }

    /**
     * El préstamo está al alcance del usuario: el pañolero solo llega a los de su pañol.
     */
    private function reaches(User $user, Loan $loan): bool
    {
        $storeroomId = $user->loanStoreroomId();

        return $storeroomId === null || (int) $loan->storeroom_id === $storeroomId;
    }

    private function operates(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_WAREHOUSE,
            User::ROLE_ADMIN,
        ]);
    }
}
