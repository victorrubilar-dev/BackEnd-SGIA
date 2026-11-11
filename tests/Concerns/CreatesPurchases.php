<?php

namespace Tests\Concerns;

use App\Models\AnnualBudget;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;

/**
 * OC histórica (sin solicitud) con su total reservado. Reemplaza a `POST /api/purchases`, que se retiró:
 * la API solo crea OC desde la solicitud de compra.
 */
trait CreatesPurchases
{
    /**
     * @param  array<int, array{product_id?: int|null, name: string, quantity: int, unit_price: int|float}>  $items
     */
    protected function reservedPurchase(AnnualBudget $budget, array $items, ?Supplier $supplier = null, ?User $user = null): Purchase
    {
        $user ??= User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);

        return app(PurchaseService::class)->createReserved([
            'supplier_id' => ($supplier ?? Supplier::factory()->create())->id,
            'annual_budget_id' => $budget->id,
            'items' => $items,
        ], $user);
    }
}
