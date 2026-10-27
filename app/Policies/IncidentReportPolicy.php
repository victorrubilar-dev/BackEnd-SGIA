<?php

namespace App\Policies;

use App\Models\Usuario;

class IncidentReportPolicy
{
    /**
     * Determine whether the user can view the list of incident reports
     * of an equipment (AD-01, DIR-01, PAN-01).
     */
    public function viewAny(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_ADMIN,
            Usuario::ROLE_DIRECTOR,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can view a single incident report.
     */
    public function view(Usuario $user, mixed $incidentReport): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can register an incident report for an
     * equipment (PRO-01, PAN-01).
     */
    public function create(Usuario $user): bool
    {
        return $user->activo && $user->hasRole([
            Usuario::ROLE_TEACHER,
            Usuario::ROLE_WAREHOUSE,
        ]);
    }
}
