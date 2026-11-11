<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\PurchaseRequestQuotation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPurchaseRequests;
use Tests\TestCase;

/**
 * OC emitida fuera del sistema y subida a la solicitud: reserva presupuesto (Fase 5).
 */
class PurchaseRequestOrderTest extends TestCase
{
    use BuildsPurchaseRequests, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPurchaseFlow();
    }

    private function postOrder(int $requestId, array $data)
    {
        return $this->post("/api/purchase-requests/{$requestId}/order", $data, ['Accept' => 'application/json']);
    }

    private function orderPayload(): array
    {
        return ['order_number' => 'OC-EXT-778', 'file' => File::fake()->create('oc.pdf', 40, 'application/pdf')];
    }

    public function test_order_creates_purchase_and_reserves_selected_total(): void
    {
        [$request, $selected] = $this->quotedRequest(selectIndex: 0);
        $quotation = PurchaseRequestQuotation::with('items')->findOrFail($selected);

        $response = $this->postOrder($request->id, $this->orderPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'con_oc')
            ->assertJsonPath('data.order.order_number', 'OC-EXT-778')
            ->assertJsonPath('data.order.supplier_id', $quotation->supplier_id)
            ->assertJsonPath('data.order.total', 300000)
            ->assertJsonPath('data.order.reserved_amount', '300000.00')
            ->assertJsonPath('data.order.purchase_request_id', $request->id)
            ->assertJsonPath('data.order.has_order_file', true);

        $purchase = Purchase::with('items')->findOrFail($response->json('data.order.id'));
        $this->assertSame('pendiente', $purchase->status);
        $this->assertSame($request->code, $purchase->quotation_reference);
        $this->assertCount(2, $purchase->items);
        $this->assertEqualsCanonicalizing([2, 5], $purchase->items->pluck('quantity')->all());
        $this->assertEquals(1000, $purchase->items->first()->unit_price);
        Storage::disk('local')->assertExists($purchase->order_file_path);

        $this->budget->refresh();
        $this->assertEquals(300000, $this->budget->reserved_amount);
        $this->assertEquals(1000000, $this->budget->available_amount);

        Sanctum::actingAs($this->userWithRole(User::ROLE_WAREHOUSE));
        $this->get("/api/purchase-requests/{$request->id}/order/file")->assertOk()->assertDownload();
    }

    public function test_422_for_insufficient_budget_without_side_effects(): void
    {
        $this->setUpPurchaseFlow(budgetAmount: 100000);
        [$request] = $this->quotedRequest();

        $this->postOrder($request->id, $this->orderPayload())->assertStatus(422)->assertJsonValidationErrors('total');

        $this->assertSame('cotizada', $request->refresh()->status);
        $this->assertSame(0, Purchase::where('purchase_request_id', $request->id)->count());
        $this->assertEquals(0, $this->budget->refresh()->reserved_amount);
        $this->assertSame([], Storage::disk('local')->allFiles('purchase-requests/orders'));
    }

    public function test_requires_selected_quotation_and_valid_body(): void
    {
        [$request, $suppliers] = $this->sentRequest(3);
        foreach ($suppliers as $supplier) {
            $this->uploadQuotation($request, $supplier, 1000);
        }

        $this->postOrder($request->id, $this->orderPayload())->assertStatus(422)->assertJsonValidationErrors('quotation_id');
        $this->postOrder($request->id, ['file' => File::fake()->create('oc.txt', 1)])->assertStatus(422)->assertJsonValidationErrors(['order_number', 'file']);

        Sanctum::actingAs($this->userWithRole(User::ROLE_ADMIN));
        $this->postOrder($request->id, $this->orderPayload())->assertForbidden();
    }

    public function test_order_file_404_without_order(): void
    {
        $request = $this->createRequest();

        $this->getJson("/api/purchase-requests/{$request->id}/order/file")->assertNotFound();
    }

    public function test_cancel_with_order_releases_reservation(): void
    {
        $request = $this->orderedRequest();
        $purchase = Purchase::where('purchase_request_id', $request->id)->firstOrFail();

        // La OC de una solicitud se cancela desde la solicitud.
        $this->postJson("/api/purchases/{$purchase->id}/cancel")->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson("/api/purchase-requests/{$request->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelada');

        $this->assertSame('cancelada', $purchase->refresh()->status);
        $this->assertEquals(0, $purchase->reserved_amount);
        $this->assertEquals(0, $this->budget->refresh()->reserved_amount);
    }

    public function test_cannot_cancel_when_order_already_completed(): void
    {
        $request = $this->orderedRequest();
        Purchase::where('purchase_request_id', $request->id)->update(['status' => Purchase::STATUS_COMPLETED]);

        $this->postJson("/api/purchase-requests/{$request->id}/cancel")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('con_oc', $request->refresh()->status);
    }
}
