<?php

namespace App\Services;

/**
 * Máquina de estados de la solicitud de compra (nuevo flujo de compras).
 *
 * borrador → aprobada → enviada → cotizada → con_oc → facturada; `cancelada` desde cualquier
 * estado no final. Transiciones de vuelta, solo internas: `cotizada → enviada` (se quitó una
 * cotización y quedan menos del mínimo) y `facturada → con_oc` (se anuló la factura).
 */
class PurchaseRequestStateMachine
{
    public const STATUS_DRAFT = 'borrador';

    public const STATUS_APPROVED = 'aprobada';

    public const STATUS_SENT = 'enviada';

    public const STATUS_QUOTED = 'cotizada';

    public const STATUS_ORDERED = 'con_oc';

    public const STATUS_INVOICED = 'facturada';

    public const STATUS_CANCELLED = 'cancelada';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_QUOTED,
        self::STATUS_ORDERED,
        self::STATUS_INVOICED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_APPROVED => 'Aprobada',
        self::STATUS_SENT => 'Enviada',
        self::STATUS_QUOTED => 'Cotizada',
        self::STATUS_ORDERED => 'Con OC',
        self::STATUS_INVOICED => 'Facturada',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_SENT, self::STATUS_CANCELLED],
        self::STATUS_SENT => [self::STATUS_QUOTED, self::STATUS_CANCELLED],
        self::STATUS_QUOTED => [self::STATUS_ORDERED, self::STATUS_SENT, self::STATUS_CANCELLED],
        self::STATUS_ORDERED => [self::STATUS_INVOICED, self::STATUS_CANCELLED],
        self::STATUS_INVOICED => [self::STATUS_ORDERED],
        self::STATUS_CANCELLED => [],
    ];

    /** Estados desde los que se puede cancelar. */
    public const CANCELLABLE = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_QUOTED,
        self::STATUS_ORDERED,
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

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
