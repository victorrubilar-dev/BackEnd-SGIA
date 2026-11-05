<?php

namespace App\Policies;

use App\Models\IncidentReport;
use App\Models\User;

class IncidentReportPolicy
{
    /**
     * Determine whether the user can view the list of incident reports
     * of an equipment (AD-01, DIR-01, PAN-01).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole([
            User::ROLE_ADMIN,
            User::ROLE_DIRECTOR,
            User::ROLE_WAREHOUSE,
        ]);
    }

    /**
     * Determine whether the user can view a single incident report.
     */
    public function view(User $user, IncidentReport $incidentReport): bool
    {
        // Personal de atención, o el docente dueño del informe.
        return $this->viewAny($user)
            || ($user->is_active && $incidentReport->reported_by === $user->id);
    }

    /**
     * Cambiar el estado de atención de un informe (AD-01, DIR-01, PAN-01).
     */
    public function updateStatus(User $user, IncidentReport $incidentReport): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Solo los docentes (PRO-01) registran informes de novedades.
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(User::ROLE_TEACHER);
    }
}
