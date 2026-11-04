<?php

namespace App\Observers;

use App\Models\EquipmentUnit;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de bajas: una unidad que pasa a `dado_de_baja` o un insumo que suma cantidad dada de baja.
 * (Los contadores de equipos/maquetas se derivan de sus unidades, así que se audita la unidad.)
 */
class InventoryAuditObserver
{
    public function updated(Model $model): void
    {
        if ($model instanceof EquipmentUnit
            && $model->wasChanged('status')
            && $model->status === EquipmentUnit::STATUS_WRITTEN_OFF) {
            AuditLogger::record('equipment_unit.written_off', $model,
                ['status' => $model->getOriginal('status')],
                ['status' => $model->status, 'code' => $model->code, 'product_id' => $model->product_id]);
        }

        if ($model instanceof Product
            && ! $model->hasUnits()
            && $model->wasChanged('quantity_written_off')
            && (int) $model->quantity_written_off > (int) $model->getOriginal('quantity_written_off')) {
            AuditLogger::record('product.written_off', $model,
                ['quantity_written_off' => (int) $model->getOriginal('quantity_written_off')],
                ['quantity_written_off' => (int) $model->quantity_written_off, 'name' => $model->name]);
        }
    }
}
