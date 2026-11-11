<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;

/**
 * Solicitudes de compra: lectura AD-01, DIR-01 y PAN-01; toda escritura (crear, aprobar, enviar,
 * cotizaciones, OC, cancelar) solo DIR-01, sin control cruzado (decisión de la reunión, queda en auditoría).
 */
class PurchaseRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE]);
    }

    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isDirector();
    }

    public function manage(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->create($user);
    }
}
