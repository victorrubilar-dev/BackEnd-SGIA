<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceIntakeItem extends Model
{
    use HasFactory;

    protected $fillable = ['invoice_intake_id', 'product_id', 'detected_name', 'quantity', 'unit_price', 'subtotal'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(InvoiceIntake::class, 'invoice_intake_id');
    }

    /** Unidades de equipo creadas al confirmar este ítem (REQ-23). */
    public function units(): HasMany
    {
        return $this->hasMany(EquipmentUnit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
