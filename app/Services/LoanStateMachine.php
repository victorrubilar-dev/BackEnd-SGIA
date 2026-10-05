<?php

namespace App\Services;

/**
 * Máquina de estados de los préstamos de insumos (Loan, FU-04).
 *
 * Flujo lineal: pendiente -> en_proceso -> procesado, con salida a rechazado.
 *
 *  - pendiente:  solicitud remota creada por el docente (REQ-09).
 *  - en_proceso: aprobada por el pañol, stock descontado y notificado el
 *                solicitante, a la espera de que retire el material (REQ-10).
 *  - procesado:  préstamo entregado (entrega remota confirmada o préstamo
 *                presencial directo) (REQ-10).
 *  - rechazado:  solicitud denegada por el pañol con motivo (REQ-10).
 */
class LoanStateMachine
{
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_IN_PROGRESS = 'en_proceso';
    public const STATUS_PROCESSED = 'procesado';
    public const STATUS_REJECTED = 'rechazado';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PROCESSED,
        self::STATUS_REJECTED,
    ];

    /**
     * Etiquetas legibles para la interfaz (estilo visual por estado).
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_IN_PROGRESS => 'En proceso',
        self::STATUS_PROCESSED => 'Procesado',
        self::STATUS_REJECTED => 'Rechazado',
    ];

    /**
     * Transiciones permitidas desde cada estado.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_IN_PROGRESS, self::STATUS_REJECTED],
        self::STATUS_IN_PROGRESS => [self::STATUS_PROCESSED],
        self::STATUS_PROCESSED => [],
        self::STATUS_REJECTED => [],
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

    public static function labelFor(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
