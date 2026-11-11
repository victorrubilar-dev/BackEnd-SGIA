<?php

namespace Tests\Feature;

use App\Models\PurchaseRequestQuotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPurchaseRequests;
use Tests\TestCase;

/**
 * Cotizaciones recibidas (archivos) y elección libre con mínimo 3 (Fase 4).
 */
class PurchaseRequestQuotationTest extends TestCase
{
    use BuildsPurchaseRequests, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPurchaseFlow();
    }

    private function postMultipart(string $uri, array $data)
    {
        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    public function test_upload_stores_private_file_and_reaches_quoted_at_three(): void
    {
        [$request, $suppliers] = $this->sentRequest(3);
        [$a, $b, $c] = $suppliers->values()->all();

        $first = $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $a, 150000))
            ->assertCreated()
            ->assertJsonPath('quotation.supplier_id', $a->id)
            ->assertJsonPath('quotation.total', 150000)
            ->assertJsonPath('quotation.original_name', 'cotizacion.pdf')
            ->assertJsonCount(2, 'quotation.items')
            ->assertJsonPath('purchase_request.status', 'enviada')
            ->assertJsonPath('purchase_request.quotations_count', 1);

        $quotation = PurchaseRequestQuotation::findOrFail($first->json('quotation.id'));
        Storage::disk('local')->assertExists($quotation->file_path);

        $this->uploadQuotation($request, $b, 120000);
        $this->postJson("/api/purchase-requests/{$request->id}/select-quotation", ['quotation_id' => $quotation->id])
            ->assertStatus(422)->assertJsonValidationErrors('quotation_id');

        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $c, 130000))
            ->assertCreated()->assertJsonPath('purchase_request.status', 'cotizada')->assertJsonPath('purchase_request.can_select_quotation', true);

        // Descarga autenticada.
        Sanctum::actingAs($this->userWithRole(User::ROLE_WAREHOUSE));
        $this->get("/api/purchase-requests/{$request->id}/quotations/{$quotation->id}/file")->assertOk()->assertDownload('cotizacion.pdf');
    }

    public function test_free_choice_not_only_cheapest(): void
    {
        // Totales 300.000 / 200.000 / 250.000: se elige la más cara (índice 0).
        [$request, $selected] = $this->quotedRequest(selectIndex: 0);

        $this->assertSame($selected, $request->selected_quotation_id);
        $this->assertEquals(300000, PurchaseRequestQuotation::findOrFail($selected)->total);

        $this->getJson("/api/purchase-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.selected_quotation_id', $selected)
            ->assertJsonPath('data.can_issue_order', true)
            ->assertJsonPath('data.can_remove_quotation', false)
            ->assertJsonCount(3, 'data.quotations');
    }

    public function test_upload_validation(): void
    {
        [$request, $suppliers] = $this->sentRequest(3);
        $supplier = $suppliers->first();
        $notContacted = Supplier::factory()->withAreas(['diseno'])->create();

        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $notContacted, 1000))
            ->assertStatus(422)->assertJsonValidationErrors('supplier_id');

        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $supplier, 1000, File::fake()->create('virus.exe', 10)))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $payload = $this->quotationPayload($request, $supplier, 0);
        array_pop($payload['items']);
        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['total', 'items']);

        $this->uploadQuotation($request, $supplier, 1000);
        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $supplier, 2000))
            ->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $this->assertSame(1, $request->quotations()->count());
    }

    public function test_cannot_upload_before_sending(): void
    {
        $request = $this->approvedRequest();
        $supplier = Supplier::factory()->create();
        $request->suppliers()->create(['supplier_id' => $supplier->id]);

        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $supplier, 1000))
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame([], Storage::disk('local')->allFiles('purchase-requests/quotations'));
    }

    public function test_delete_before_selection_reverts_to_sent(): void
    {
        [$request, $suppliers] = $this->sentRequest(3);
        $ids = $suppliers->values()->map(fn ($s, $i) => $this->uploadQuotation($request, $s, 1000 + $i))->all();
        $this->assertSame('cotizada', $request->refresh()->status);
        $path = PurchaseRequestQuotation::findOrFail($ids[0])->file_path;

        $this->deleteJson("/api/purchase-requests/{$request->id}/quotations/{$ids[0]}")->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('enviada', $request->refresh()->status);

        $this->uploadQuotation($request, $suppliers->first(), 999);
        $this->postJson("/api/purchase-requests/{$request->id}/select-quotation", ['quotation_id' => $ids[1]])->assertOk();

        $this->deleteJson("/api/purchase-requests/{$request->id}/quotations/{$ids[2]}")
            ->assertStatus(422)->assertJsonValidationErrors('quotation');
    }

    public function test_quotation_of_other_request_is_404_or_422(): void
    {
        [$request] = $this->quotedRequest();
        $other = $this->approvedRequest();
        $foreign = $request->quotations()->value('id');

        $this->deleteJson("/api/purchase-requests/{$other->id}/quotations/{$foreign}")->assertNotFound();
        $this->getJson("/api/purchase-requests/{$other->id}/quotations/{$foreign}/file")->assertNotFound();
        $this->postJson("/api/purchase-requests/{$other->id}/select-quotation", ['quotation_id' => $foreign])
            ->assertStatus(422)->assertJsonValidationErrors('quotation_id');
    }

    public function test_only_director_uploads_or_selects(): void
    {
        [$request, $suppliers] = $this->sentRequest(3);
        Sanctum::actingAs($this->userWithRole(User::ROLE_ADMIN));

        $this->postMultipart("/api/purchase-requests/{$request->id}/quotations", $this->quotationPayload($request, $suppliers->first(), 1000))->assertForbidden();
        $this->postJson("/api/purchase-requests/{$request->id}/select-quotation", ['quotation_id' => 1])->assertForbidden();
    }
}
