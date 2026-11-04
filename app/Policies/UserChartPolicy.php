<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserChart;
use Illuminate\Auth\Access\Response;

/**
 * Dashboard personalizado (REQ-15): DIR-01 y AD-01 arman su panel; cada uno solo ve el suyo.
 * Un gráfico ajeno responde `404` para no revelar que el id existe.
 */
class UserChartPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, UserChart $chart): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $chart->user_id === $user->id ? Response::allow() : Response::denyAsNotFound('Recurso no encontrado.');
    }

    public function update(User $user, UserChart $chart): Response
    {
        return $this->view($user, $chart);
    }

    public function delete(User $user, UserChart $chart): Response
    {
        return $this->view($user, $chart);
    }
}
