<?php

namespace App\Services;

use App\Models\MaintenanceRecord;

/**
 * Máquina de estados de las mantenciones (REQ-18).
 *
 *  - en_curso:   la unidad está en taller (`en_reparacion`).
 *  - finalizada: checklist cerrado; la unidad vuelve a `disponible` o queda `dado_de_baja`.
 *  - anulada:    la unidad y el informe asociado vuelven a su estado previo.
 */
class MaintenanceRecordStateMachine
{
    /** @var array<int, string> */
    public const STATUSES = MaintenanceRecord::STATUSES;

    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        MaintenanceRecord::STATUS_IN_PROGRESS => [MaintenanceRecord::STATUS_FINISHED, MaintenanceRecord::STATUS_CANCELLED],
        MaintenanceRecord::STATUS_FINISHED => [],
        MaintenanceRecord::STATUS_CANCELLED => [],
    ];

    /** @return array<int, string> */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /** @return array<int, string> */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }
}
