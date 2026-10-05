<?php

namespace App\Services;

/**
 * Máquina de estados compartida para las órdenes de compra (Purchase).
 *
 * La misma definición se reutilizará para las cotizaciones (Quotation) cuando
 * se implemente REQ-07 (TASKS/Cotizaciones.md).
 *
 * Flujo lineal: pendiente -> en_camino -> completa
 */
class PurchaseStateMachine
{
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_IN_TRANSIT = 'en_camino';
    public const STATUS_COMPLETED = 'completa';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_TRANSIT,
        self::STATUS_COMPLETED,
    ];

    /**
     * Transiciones permitidas desde cada estado.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_IN_TRANSIT],
        self::STATUS_IN_TRANSIT => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
    ];

    /**
     * Estados válidos de la máquina.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /**
     * Estados alcanzables desde el estado indicado.
     *
     * @return array<int, string>
     */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }
}
