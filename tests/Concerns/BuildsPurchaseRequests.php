<?php

namespace Tests\Concerns;

use App\Models\AnnualBudget;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/**
 * Atajos para llevar una solicitud de compra por el flujo nuevo vía API.
 */
trait BuildsPurchaseRequests
{
    protected User $director;

    protected AnnualBudget $budget;

    protected function setUpPurchaseFlow(float $budgetAmount = 1000000): void
    {
        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        $this->budget = AnnualBudget::factory()->create([
            'initial_amount' => $budgetAmount, 'available_amount' => $budgetAmount, 'reserved_amount' => 0,
        ]);
    }

    protected function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>|null  $lines
     */
    protected function createRequest(?array $lines = null): PurchaseRequest
    {
        $lines ??= [[Product::factory()->supply()->create(), 2], [Product::factory()->supply()->create(), 5]];
        Sanctum::actingAs($this->director);

        $id = $this->postJson('/api/purchase-requests', [
            'annual_budget_id' => $this->budget->id,
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
        ])->assertCreated()->json('data.id');

        return PurchaseRequest::findOrFail($id);
    }

    protected function approvedRequest(): PurchaseRequest
    {
        $request = $this->createRequest();
        $this->postJson("/api/purchase-requests/{$request->id}/approve")->assertOk();

        return $request->refresh();
    }

    /**
     * Solicitud enviada a `$count` proveedores de `informatica`.
     *
     * @return array{0: PurchaseRequest, 1: Collection<int, Supplier>}
     */
    protected function sentRequest(int $count = 3): array
    {
        Mail::fake();
        $suppliers = Supplier::factory()->count($count)->withAreas(['informatica'])->create();
        $request = $this->approvedRequest();
        Supplier::query()->whereNotIn('id', $suppliers->modelKeys())->whereHas('areas', fn ($q) => $q->where('area', 'informatica'))->update(['is_active' => false]);
        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['informatica']])->assertOk();

        return [$request->refresh(), $suppliers];
    }

    /**
     * @return array<string, mixed>
     */
    protected function quotationPayload(PurchaseRequest $request, Supplier $supplier, float $total, ?UploadedFile $file = null): array
    {
        return [
            'supplier_id' => $supplier->id,
            'total' => $total,
            'file' => $file ?? File::fake()->create('cotizacion.pdf', 100, 'application/pdf'),
            'items' => $request->items()->get()->map(fn ($item) => ['purchase_request_item_id' => $item->id, 'unit_price' => 1000])->all(),
        ];
    }

    protected function uploadQuotation(PurchaseRequest $request, Supplier $supplier, float $total): int
    {
        return $this->post("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $supplier, $total), ['Accept' => 'application/json'])
            ->assertCreated()->json('quotation.id');
    }

    /**
     * Solicitud cotizada (3 cotizaciones) con la elegida.
     *
     * @return array{0: PurchaseRequest, 1: int} solicitud e id de la cotización elegida
     */
    protected function quotedRequest(int $selectIndex = 1, array $totals = [300000, 200000, 250000]): array
    {
        [$request, $suppliers] = $this->sentRequest(3);
        $ids = [];
        foreach ($suppliers->values() as $i => $supplier) {
            $ids[] = $this->uploadQuotation($request, $supplier, $totals[$i]);
        }
        $this->postJson("/api/purchase-requests/{$request->id}/select-quotation", ['quotation_id' => $ids[$selectIndex]])->assertOk();

        return [$request->refresh(), $ids[$selectIndex]];
    }

    protected function orderedRequest(): PurchaseRequest
    {
        [$request] = $this->quotedRequest();
        $this->post("/api/purchase-requests/{$request->id}/order", [
            'order_number' => 'OC-EXT-123',
            'file' => File::fake()->create('oc.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        return $request->refresh();
    }
}
