<?php

namespace Tests\Concerns;

use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Supplier;

trait CreatesQuotations
{
    /**
     * Cotización aceptada con `$responded` proveedores que ya respondieron (el primero es `$supplier`, si se indica).
     */
    protected function acceptedQuotation(?Supplier $supplier = null, int $responded = 3): Quotation
    {
        $quotation = Quotation::factory()->accepted()->create();

        for ($i = 0; $i < $responded; $i++) {
            $quotation->suppliers()->create([
                'supplier_id' => ($i === 0 && $supplier ? $supplier : Supplier::factory()->create())->id,
                'status' => $i === 0 ? QuotationSupplier::STATUS_ACCEPTED : QuotationSupplier::STATUS_REJECTED,
                'responded_at' => now(),
            ]);
        }

        return $quotation;
    }
}
