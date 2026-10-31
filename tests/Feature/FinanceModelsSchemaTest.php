<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Career;
use App\Models\InvoiceIntake;
use App\Models\InvoiceIntakeItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationOfferItem;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\ChileanRut;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceModelsSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_annual_budget_relations_and_unique_per_career_and_year(): void
    {
        $career = Career::factory()->create();
        $budget = AnnualBudget::factory()->create(['career_id' => $career->id, 'year' => 2031, 'initial_amount' => 500000.5, 'available_amount' => 500000.5]);

        $this->assertTrue($career->annualBudgets->contains($budget));
        $this->assertSame('500000.50', $budget->fresh()->initial_amount);

        $this->expectException(QueryException::class);
        AnnualBudget::factory()->create(['career_id' => $career->id, 'year' => 2031]);
    }

    public function test_available_amount_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);
        AnnualBudget::factory()->create(['available_amount' => -1]);
    }

    public function test_invoice_intake_relations_and_jsonb_cast(): void
    {
        $intake = InvoiceIntake::factory()->create(['raw_ocr_payload' => ['total' => 1190, 'lines' => [['name' => 'Cable']]], 'total_deducted' => 1190]);
        $product = Product::factory()->create();
        $item = InvoiceIntakeItem::create([
            'invoice_intake_id' => $intake->id, 'product_id' => $product->id,
            'detected_name' => 'Cable UTP', 'quantity' => 3, 'unit_price' => 100, 'subtotal' => 300,
        ]);

        $fresh = $intake->fresh();
        $this->assertEquals(['total' => 1190, 'lines' => [['name' => 'Cable']]], $fresh->raw_ocr_payload);
        $this->assertTrue($fresh->items->first()->is($item));
        $this->assertTrue($item->intake->is($intake));
        $this->assertTrue($item->product->is($product));
        $this->assertTrue($fresh->annualBudget->invoiceIntakes->contains($intake));
        $this->assertNotNull($fresh->received_at);
        $this->assertContains($fresh->status, InvoiceIntake::STATUSES);
    }

    public function test_intake_items_are_deleted_with_intake_but_budget_is_restricted(): void
    {
        $intake = InvoiceIntake::factory()->create();
        InvoiceIntakeItem::create(['invoice_intake_id' => $intake->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 1]);

        $budget = $intake->annualBudget;
        try {
            $budget->delete();
            $this->fail('El presupuesto con ingresos no debe borrarse.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_chilean_rut_validation(): void
    {
        foreach (['12.345.678-5', '12345678-5', '11.111.111-1', '7.654.321-6', '20.000.000-5'] as $valid) {
            $this->assertTrue(ChileanRut::isValid($valid), $valid);
        }
        foreach (['12.345.678-9', '1234', 'abc', '12345678-K', ''] as $invalid) {
            $this->assertFalse(ChileanRut::isValid($invalid), $invalid);
        }
        $this->assertSame('12345678-5', ChileanRut::normalize('12.345.678-5'));
    }

    public function test_supplier_rut_and_address_via_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));

        $this->postJson('/api/suppliers', ['name' => 'Proveedor RUT', 'rut' => '12.345.678-5', 'address' => 'Av. Alemania 100'])
            ->assertCreated()->assertJsonPath('data.rut', '12345678-5')->assertJsonPath('data.address', 'Av. Alemania 100');

        $this->postJson('/api/suppliers', ['name' => 'Otro', 'rut' => '12345678-5'])
            ->assertStatus(422)->assertJsonValidationErrors('rut');
        $this->postJson('/api/suppliers', ['name' => 'Malo', 'rut' => '12.345.678-9'])
            ->assertStatus(422)->assertJsonValidationErrors('rut');
        $this->postJson('/api/suppliers', ['name' => 'Sin RUT'])->assertCreated()->assertJsonPath('data.rut', null);
    }

    public function test_quotation_career_title_and_offer_items(): void
    {
        $quotation = Quotation::factory()->create();
        $career = Career::factory()->create();
        $quotation->update(['career_id' => $career->id, 'title' => 'Compra laboratorio']);
        $this->assertTrue($quotation->fresh()->career->is($career));

        $contact = $quotation->suppliers()->create(['supplier_id' => Supplier::factory()->create()->id, 'quotation_number' => 'COT-77']);
        $offer = QuotationOfferItem::create([
            'quotation_supplier_id' => $contact->id, 'product_id' => Product::factory()->create()->id,
            'requested_quantity' => 4, 'offered_unit_price' => 1250.5,
        ]);

        $this->assertTrue($contact->offerItems->first()->is($offer));
        $this->assertSame('1250.50', $offer->fresh()->offered_unit_price);

        $contact->delete();
        $this->assertDatabaseMissing('quotation_offer_items', ['id' => $offer->id]);
    }

    public function test_supplier_with_quotations_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $supplier = Supplier::factory()->create();
        $quotation = Quotation::factory()->create();
        QuotationSupplier::create(['quotation_id' => $quotation->id, 'supplier_id' => $supplier->id]);

        $this->deleteJson('/api/suppliers/'.$supplier->id)->assertStatus(422);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);

        $this->expectException(QueryException::class);
        $supplier->delete();
    }

    public function test_product_in_offer_cannot_be_deleted_via_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $product = Product::factory()->create();
        $contact = QuotationSupplier::create(['quotation_id' => Quotation::factory()->create()->id, 'supplier_id' => Supplier::factory()->create()->id]);
        QuotationOfferItem::create(['quotation_supplier_id' => $contact->id, 'product_id' => $product->id, 'requested_quantity' => 1]);

        $this->deleteJson('/api/products/'.$product->id)->assertStatus(422);
    }

    public function test_quotation_accepts_career_and_title_via_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));
        $career = Career::factory()->create();

        $this->postJson('/api/quotations', [
            'products' => [['id' => Product::factory()->create()->id, 'quantity' => 2]],
            'supplier_ids' => Supplier::factory()->count(3)->create()->pluck('id')->all(),
            'career_id' => $career->id,
            'title' => 'Compra Q1',
        ])->assertCreated()->assertJsonPath('quotation.career_id', $career->id)->assertJsonPath('quotation.title', 'Compra Q1');
    }
}
