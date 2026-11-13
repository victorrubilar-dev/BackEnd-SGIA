<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\BuildsPurchaseRequests;
use Tests\TestCase;

/**
 * Las alertas de stock informan si el producto ya está en una solicitud de compra abierta.
 */
class StockAlertOpenRequestTest extends TestCase
{
    use BuildsPurchaseRequests, DatabaseTransactions;

    public function test_alert_exposes_open_purchase_request_until_it_is_closed(): void
    {
        $this->setUpPurchaseFlow();
        $low = Product::factory()->supply()->create(['quantity' => 1, 'stock_minimo' => 5, 'is_active' => true]);
        $free = Product::factory()->supply()->create(['quantity' => 1, 'stock_minimo' => 5, 'is_active' => true]);
        $request = $this->createRequest([[$low, 10]]);

        $rows = fn () => collect($this->getJson('/api/alerts/critical-stock?per_page=100')->assertOk()->json('data'))->keyBy('product_id');

        $this->assertSame(
            ['id' => $request->id, 'code' => $request->code, 'status' => 'borrador'],
            $rows()[$low->id]['open_purchase_request'],
        );
        $this->assertNull($rows()[$free->id]['open_purchase_request']);

        $this->postJson("/api/purchase-requests/{$request->id}/cancel")->assertOk();
        $this->assertSame(PurchaseRequest::STATUS_CANCELLED, $request->refresh()->status);
        $this->assertNull($rows()[$low->id]['open_purchase_request']);
    }
}
