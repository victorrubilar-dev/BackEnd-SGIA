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
        return $this->viewAny($user);
    }
}
