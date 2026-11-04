<?php

namespace App\Observers;

use App\Models\InvoiceIntake;
use App\Models\Purchase;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de compras (alta, cambios de estado y cancelación) y de ingresos de facturas
 * (confirmación y anulación, que mueven presupuesto).
 */
class PurchasingAuditObserver
{
    public function created(Model $model): void
    {
        if ($model instanceof Purchase) {
            AuditLogger::record('purchase.created', $model, null, [
                'code' => $model->code,
                'status' => $model->status,
                'total' => $model->total,
                'supplier_id' => $model->supplier_id,
                'annual_budget_id' => $model->annual_budget_id,
            ]);
        }
    }

    public function updated(Model $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        $old = ['status' => $model->getOriginal('status')];

        if ($model instanceof Purchase) {
            AuditLogger::record(
                $model->status === Purchase::STATUS_CANCELLED ? 'purchase.cancelled' : 'purchase.status_changed',
                $model, $old, ['status' => $model->status, 'code' => $model->code]
            );
        }

        if ($model instanceof InvoiceIntake && in_array($model->status, [InvoiceIntake::STATUS_CONFIRMED, InvoiceIntake::STATUS_CANCELLED], true)) {
            AuditLogger::record(
                $model->status === InvoiceIntake::STATUS_CONFIRMED ? 'invoice_intake.confirmed' : 'invoice_intake.cancelled',
                $model, $old, [
                    'status' => $model->status,
                    'document_number' => $model->document_number,
                    'total_deducted' => $model->total_deducted,
                    'annual_budget_id' => $model->annual_budget_id,
                ]
            );
        }
    }
}
