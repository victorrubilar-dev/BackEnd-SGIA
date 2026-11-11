<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento crítico auditado (REQ-NF-01). Solo se inserta; no se edita ni se borra desde la API.
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
