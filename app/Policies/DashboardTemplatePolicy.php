<?php

namespace App\Policies;

use App\Models\DashboardTemplate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Plantillas de dashboard (REQ-15): DIR-01 y AD-01; cada uno solo ve las suyas (una ajena responde `404`).
 */
class DashboardTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, DashboardTemplate $template): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $template->user_id === $user->id ? Response::allow() : Response::denyAsNotFound('Recurso no encontrado.');
    }

    public function update(User $user, DashboardTemplate $template): Response
    {
        return $this->view($user, $template);
    }

    public function delete(User $user, DashboardTemplate $template): Response
    {
        return $this->view($user, $template);
    }
}
