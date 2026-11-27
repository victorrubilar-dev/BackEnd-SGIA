<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento crítico auditado (REQ-NF-01). Solo se inserta; no se edita ni se borra desde la API.
 *
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Tipos auditables => clase del modelo. */
    public const TYPES = [
        'user' => User::class,
        'equipment_unit' => EquipmentUnit::class,
        'product' => Product::class,
        'purchase' => Purchase::class,
        'invoice_intake' => InvoiceIntake::class,
        'purchase_request' => PurchaseRequest::class,
    ];

    /** Exportación de un reporte con datos personales (docs/reportes-excel.md): no apunta a un registro (`auditable_id` 0). */
    public const REPORT_TYPE = 'report';

    public const REPORT_EXPORT_ACTION = 'report.export';

    /** Envío de un reporte suscrito (docs/suscripcion-reportes.md). */
    public const REPORT_EMAIL_ACTION = 'report.email';

    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
