<?php

namespace App\Services;

use App\Models\EquipmentUnit;

/**
 * Máquina de estados de las unidades de equipo (EquipmentUnit).
 *
 *  - disponible:    en su cajón, lista para prestar.
 *  - prestado:      entregada en un préstamo; solo se cambia por préstamos/devoluciones.
 *  - en_reparacion: en taller o mantención.
 *  - dado_de_baja:  estado final.
 */
class EquipmentUnitStateMachine
{
    /** @var array<int, string> */
    public const STATUSES = EquipmentUnit::STATUSES;

    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        EquipmentUnit::STATUS_AVAILABLE => [EquipmentUnit::STATUS_ON_LOAN, EquipmentUnit::STATUS_IN_REPAIR, EquipmentUnit::STATUS_WRITTEN_OFF],
        EquipmentUnit::STATUS_ON_LOAN => [EquipmentUnit::STATUS_AVAILABLE, EquipmentUnit::STATUS_IN_REPAIR, EquipmentUnit::STATUS_WRITTEN_OFF],
        EquipmentUnit::STATUS_IN_REPAIR => [EquipmentUnit::STATUS_AVAILABLE, EquipmentUnit::STATUS_WRITTEN_OFF],
        EquipmentUnit::STATUS_WRITTEN_OFF => [],
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
