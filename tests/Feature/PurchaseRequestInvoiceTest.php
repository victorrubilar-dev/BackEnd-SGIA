<?php

namespace Tests\Feature;

use App\Models\InvoiceIntake;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPurchaseRequests;
use Tests\TestCase;

/**
 * Factura ligada a la solicitud de compra por su código (Fase 6): confirmar → `facturada`, anular → `con_oc`.
 */
class PurchaseRequestInvoiceTest extends TestCase
{
    use BuildsPurchaseRequests, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Process::fake(['*' => Process::result('')]);
        $this->setUpPurchaseFlow();
    }

    private function uploadInvoice(array $extra): TestResponse
    {
        return $this->post('/api/invoice-intakes', ['file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf')] + $extra, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmPayload(PurchaseRequest $request, int $unitPrice = 1000): array
    {
        return [
            'document_number' => 'F-'.$request->id,
            'items' => $request->items()->get()->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity, 'unit_price' => $unitPrice])->all(),
        ];
    }

    public function test_invoice_by_request_code_links_order_and_marks_request_invoiced(): void
    {
        $request = $this->orderedRequest();
        $purchase = Purchase::where('purchase_request_id', $request->id)->firstOrFail();

        $intakeId = $this->uploadInvoice(['purchase_request_code' => $request->code])
            ->assertCreated()
            ->assertJsonPath('data.purchase_id', $purchase->id)
            ->assertJsonPath('data.annual_budget_id', $this->budget->id)
            ->json('data.id');

        // 2 + 5 unidades a $1.000 = $7.000 consumidos; la reserva de $200.000 se libera.
        $this->postJson("/api/invoice-intakes/{$intakeId}/confirm", $this->confirmPayload($request))
            ->assertOk()->assertJsonPath('data.status', 'confirmado');

        $this->assertSame('facturada', $request->refresh()->status);
        $this->assertSame('completa', $purchase->refresh()->status);
        $this->budget->refresh();
        $this->assertEquals(0, $this->budget->reserved_amount);
        $this->assertEquals(993000, $this->budget->available_amount);

        $this->getJson("/api/purchase-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.order.invoices.0.id', $intakeId)
            ->assertJsonPath('data.can_cancel', false);
    }

    public function test_cancelling_confirmed_invoice_returns_request_to_ordered(): void
    {
        $request = $this->orderedRequest();
        $intakeId = $this->uploadInvoice(['purchase_request_code' => $request->code])->assertCreated()->json('data.id');
        $this->postJson("/api/invoice-intakes/{$intakeId}/confirm", $this->confirmPayload($request))->assertOk();

        $this->postJson("/api/invoice-intakes/{$intakeId}/cancel")->assertOk()->assertJsonPath('data.status', 'anulado');

        $this->assertSame('con_oc', $request->refresh()->status);
        $this->assertEquals(1000000, $this->budget->refresh()->available_amount);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'purchase_request', 'auditable_id' => $request->id, 'action' => 'purchase_request.status_changed']);
    }

    public function test_reinvoicing_by_code_after_cancelling_works_without_reservation(): void
    {
        $request = $this->orderedRequest();
        $purchase = Purchase::where('purchase_request_id', $request->id)->firstOrFail();
        $first = $this->uploadInvoice(['purchase_request_code' => $request->code])->assertCreated()->json('data.id');
        $this->postJson("/api/invoice-intakes/{$first}/confirm", $this->confirmPayload($request))->assertOk();
        $this->postJson("/api/invoice-intakes/{$first}/cancel")->assertOk();
        $this->assertEquals(0, $purchase->refresh()->reserved_amount);

        // La reserva ya se liberó con la primera factura: subir por código igual liga la OC.
        $second = $this->uploadInvoice(['purchase_request_code' => $request->code])
            ->assertCreated()->assertJsonPath('data.purchase_id', $purchase->id)->json('data.id');
        $this->postJson("/api/invoice-intakes/{$second}/confirm", ['document_number' => 'F-BIS'] + $this->confirmPayload($request, 1200))
            ->assertOk()->assertJsonPath('data.purchase_id', $purchase->id);

        $this->assertSame('facturada', $request->refresh()->status);
        // 7 unidades a $1.200 = $8.400 consumidos sobre $1.000.000 (la primera se repuso al anularla).
        $this->assertEquals(991600, $this->budget->refresh()->available_amount);
        $this->assertEquals(0, $this->budget->reserved_amount);

        // Por `purchase_id` sigue exigiendo reserva vigente (comportamiento anterior sin cambios).
        $this->postJson("/api/invoice-intakes/{$second}/cancel")->assertOk();
        $this->uploadInvoice(['purchase_id' => $purchase->id])->assertStatus(422)->assertJsonValidationErrors('purchase_id');
    }

    public function test_confirm_accepts_request_code_and_any_linked_invoice_marks_it(): void
    {
        $request = $this->orderedRequest();
        $intake = InvoiceIntake::factory()->create([
            'annual_budget_id' => $this->budget->id,
            'pdf_path' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')->store('invoice-intakes', 'local'),
        ]);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $this->confirmPayload($request) + ['purchase_request_code' => $request->code])
            ->assertOk()->assertJsonPath('data.purchase_id', Purchase::where('purchase_request_id', $request->id)->value('id'));

        $this->assertSame('facturada', $request->refresh()->status);
    }

    public function test_request_code_must_have_pending_order(): void
    {
        $draft = $this->createRequest();

        $this->uploadInvoice(['purchase_request_code' => $draft->code])->assertStatus(422)->assertJsonValidationErrors('purchase_request_code');
        $this->uploadInvoice(['purchase_request_code' => 'SC-1999-0001'])->assertStatus(422)->assertJsonValidationErrors('purchase_request_code');

        $ordered = $this->orderedRequest();
        $other = Purchase::factory()->create(['annual_budget_id' => $this->budget->id]);
        $this->uploadInvoice(['purchase_request_code' => $ordered->code, 'purchase_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_id');
    }

    public function test_trace_includes_request_and_quotations(): void
    {
        $request = $this->orderedRequest();
        $purchase = Purchase::where('purchase_request_id', $request->id)->firstOrFail();

        $this->getJson("/api/purchases/{$purchase->id}/trace")->assertOk()
            ->assertJsonPath('purchase_request.code', $request->code)
            ->assertJsonCount(3, 'purchase_request.quotations')
            ->assertJsonPath('purchase.purchase_request_id', $request->id);
    }
}
