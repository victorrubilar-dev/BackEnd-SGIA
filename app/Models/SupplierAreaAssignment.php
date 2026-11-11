<?php

namespace App\Models;

use App\Enums\SupplierArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de `supplier_areas`: un área (`SupplierArea`) asignada a un proveedor.
 *
 * @property int $supplier_id
 * @property SupplierArea $area
 */
class SupplierAreaAssignment extends Model
{
    protected $table = 'supplier_areas';

    protected $fillable = ['supplier_id', 'area'];

    protected function casts(): array
    {
        return ['area' => SupplierArea::class];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
