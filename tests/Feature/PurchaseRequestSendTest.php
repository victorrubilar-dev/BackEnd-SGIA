<?php

namespace Tests\Feature;

use App\Mail\PurchaseRequestQuotationMail;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\BuildsPurchaseRequests;
use Tests\TestCase;

/**
 * Envío de la solicitud por áreas de proveedor (Fase 3).
 */
class PurchaseRequestSendTest extends TestCase
{
    use BuildsPurchaseRequests, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchaseFlow();
        // Aislar de proveedores ya existentes en la BD de pruebas.
        Supplier::query()->update(['is_active' => false]);
        Mail::fake();
    }

    public function test_sends_to_all_active_suppliers_of_one_area(): void
    {
        $a = Supplier::factory()->withAreas(['diseno'])->create();
        $b = Supplier::factory()->withAreas(['diseno'])->create();
        $inactive = Supplier::factory()->withAreas(['diseno'])->create(['is_active' => false]);
        $other = Supplier::factory()->withAreas(['informatica'])->create();
        $request = $this->approvedRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['diseno']])
            ->assertOk()
            ->assertJsonPath('emails_sent', 2)
            ->assertJsonPath('emails_failed', 0)
            ->assertJsonPath('purchase_request.status', 'enviada')
            ->assertJsonPath('purchase_request.areas', ['diseno'])
            ->assertJsonCount(2, 'purchase_request.suppliers')
            ->assertJsonPath('purchase_request.suppliers.0.email_status', 'enviado');

        Mail::assertSent(PurchaseRequestQuotationMail::class, 2);
        Mail::assertSent(PurchaseRequestQuotationMail::class, fn ($m) => $m->hasTo($a->email));
        Mail::assertSent(PurchaseRequestQuotationMail::class, fn ($m) => $m->hasTo($b->email));
        Mail::assertNotSent(PurchaseRequestQuotationMail::class, fn ($m) => $m->hasTo($inactive->email) || $m->hasTo($other->email));
        $this->assertNotNull($request->refresh()->sent_at);
    }

    public function test_supplier_in_two_areas_receives_one_email(): void
    {
        $both = Supplier::factory()->withAreas(['diseno', 'ciberseguridad'])->create();
        Supplier::factory()->withAreas(['ciberseguridad'])->create();
        $request = $this->approvedRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['diseno', 'ciberseguridad']])
            ->assertOk()->assertJsonPath('emails_sent', 2)
            ->assertJsonPath('purchase_request.areas', ['ciberseguridad', 'diseno']);

        Mail::assertSent(PurchaseRequestQuotationMail::class, fn ($m) => $m->hasTo($both->email));
        Mail::assertSent(PurchaseRequestQuotationMail::class, 2);
        $this->assertSame(1, DB::table('purchase_request_suppliers')->where('purchase_request_id', $request->id)->where('supplier_id', $both->id)->count());
    }

    public function test_422_without_suppliers_or_without_approval(): void
    {
        Supplier::factory()->withAreas(['diseno'])->create(['is_active' => false]);
        $request = $this->approvedRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['diseno']])
            ->assertStatus(422)->assertJsonValidationErrors('areas');
        $this->assertSame('aprobada', $request->refresh()->status);

        Supplier::factory()->withAreas(['diseno'])->create();
        $draft = $this->createRequest();
        $this->postJson("/api/purchase-requests/{$draft->id}/send", ['areas' => ['diseno']])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => []])->assertStatus(422)->assertJsonValidationErrors('areas');
        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['cocina']])->assertStatus(422)->assertJsonValidationErrors('areas.0');
        Mail::assertNothingSent();
    }

    public function test_only_director_sends(): void
    {
        Supplier::factory()->withAreas(['diseno'])->create();
        $request = $this->approvedRequest();
        Sanctum::actingAs($this->userWithRole(User::ROLE_ADMIN));

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['diseno']])->assertForbidden();
        $this->postJson("/api/purchase-requests/{$request->id}/resend")->assertForbidden();
    }

    public function test_failed_email_is_recorded_and_resend_retries_only_failed(): void
    {
        $ok = Supplier::factory()->withAreas(['telecomunicaciones'])->create();
        $noEmail = Supplier::factory()->withAreas(['telecomunicaciones'])->create(['email' => null]);
        $request = $this->approvedRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['telecomunicaciones']])
            ->assertOk()->assertJsonPath('emails_sent', 1)->assertJsonPath('emails_failed', 1);

        $failed = DB::table('purchase_request_suppliers')->where('purchase_request_id', $request->id)->where('supplier_id', $noEmail->id)->first();
        $this->assertNull($failed->email_sent_at);
        $this->assertSame('El proveedor no tiene correo registrado.', $failed->email_error);

        $noEmail->update(['email' => 'nuevo@proveedor.cl']);

        $this->postJson("/api/purchase-requests/{$request->id}/resend")
            ->assertOk()->assertJsonPath('emails_sent', 1)->assertJsonPath('emails_failed', 0);

        Mail::assertSent(PurchaseRequestQuotationMail::class, 2);
        Mail::assertSent(PurchaseRequestQuotationMail::class, fn ($m) => $m->hasTo('nuevo@proveedor.cl'));
        $this->assertCount(1, Mail::sent(PurchaseRequestQuotationMail::class, fn ($m) => $m->hasTo($ok->email)));
    }

    public function test_mail_exception_does_not_stop_the_rest(): void
    {
        Supplier::factory()->count(2)->withAreas(['ciberseguridad'])->create();
        $request = $this->approvedRequest();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

        $this->postJson("/api/purchase-requests/{$request->id}/send", ['areas' => ['ciberseguridad']])
            ->assertOk()->assertJsonPath('emails_sent', 0)->assertJsonPath('emails_failed', 2)
            ->assertJsonPath('purchase_request.status', 'enviada')
            ->assertJsonPath('purchase_request.suppliers.0.email_status', 'fallido');
    }

    public function test_resend_requires_sent_request(): void
    {
        $request = $this->approvedRequest();

        $this->postJson("/api/purchase-requests/{$request->id}/resend")->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
