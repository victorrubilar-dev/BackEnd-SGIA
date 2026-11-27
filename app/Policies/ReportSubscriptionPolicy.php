<?php

namespace App\Policies;

use App\Models\ReportSubscription;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Cada usuario solo ve y cambia sus suscripciones (docs/suscripcion-reportes.md); el rol lo filtra la ruta y una
 * suscripción ajena responde `404`.
 */
class ReportSubscriptionPolicy
{
    public function update(User $user, ReportSubscription $subscription): Response
    {
        return $subscription->user_id === $user->id ? Response::allow() : Response::denyAsNotFound('Recurso no encontrado.');
    }

    public function delete(User $user, ReportSubscription $subscription): Response
    {
        return $this->update($user, $subscription);
    }
}
