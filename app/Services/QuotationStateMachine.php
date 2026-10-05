<?php

namespace App\Services;

/**
 * Máquina de estados de las cotizaciones (REQ-07), complementaria a la de las
 * órdenes de compra (`PurchaseStateMachine`, REQ-08).
 *
 * Flujo: pendiente (enviada a los proveedores) -> aceptada / rechazada
 *        -> convertida (al generarse la orden de compra).
 */
class QuotationStateMachine
{
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_ACCEPTED = 'aceptada';
    public const STATUS_REJECTED = 'rechazada';
    public const STATUS_CONVERTED = 'convertida';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_CONVERTED,
    ];

    /**
     * Transiciones permitidas desde cada estado.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
        self::STATUS_ACCEPTED => [self::STATUS_CONVERTED, self::STATUS_REJECTED],
        self::STATUS_REJECTED => [],
        self::STATUS_CONVERTED => [],
    ];

    /**
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
