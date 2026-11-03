<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsProductPayload;
use Tests\TestCase;

/**
 * REQ-23: anulación de una factura confirmada con reposición de presupuesto y reversa de stock.
 */
class InvoiceIntakeCancellationTest extends TestCase
{
    use BuildsProductPayload;
    use DatabaseTransactions;

    private User $admin;

    private AnnualBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->budget = AnnualBudget::factory()->create(['initial_amount' => 1000000, 'available_amount' => 1000000]);
        Sanctum::actingAs($this->admin);
    }

    /** @return array{0: InvoiceIntake, 1: Product, 2: Product} */
    private function confirmedIntake(): array
    {
        $intake = InvoiceIntake::factory()->create([
            'annual_budget_id' => $this->budget->id,
            'pdf_path' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')->store('invoice-intakes', 'local'),
        ]);
        $supply = Product::factory()->supply()->create(['quantity' => 3, 'stock_minimo' => 1]);
        $equipment = Product::factory()->create(array_merge($this->unitPayload(), ['quantity' => 0]));

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-100',
            'items' => [
                ['product_id' => $supply->id, 'quantity' => 10, 'unit_price' => 1500],
                ['product_id' => $equipment->id, 'quantity' => 2, 'unit_price' => 250000, 'cajon_id' => Cajon::factory()->create()->id],
            ],
        ])->assertOk();

        return [$intake->fresh(), $supply, $equipment];
    }

    public function test_cancel_confirmed_restores_budget_stock_and_units(): void
    {
        [$intake, $supply, $equipment] = $this->confirmedIntake();
        $this->assertSame('485000.00', $this->budget->fresh()->available_amount);

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'anulado');

        $this->assertSame('1000000.00', $this->budget->fresh()->available_amount);
        $this->assertSame(3, $supply->fresh()->quantity);
        $this->assertSame(2, EquipmentUnit::where('product_id', $equipment->id)->where('status', 'dado_de_baja')->count());
        $this->assertSame(0, $equipment->fresh()->quantity);
        $this->assertDatabaseHas('budget_movements', ['invoice_intake_id' => $intake->id, 'type' => 'reposicion', 'amount' => '515000.00']);

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_cancel_is_rejected_when_supply_stock_was_already_used(): void
    {
        [$intake, $supply] = $this->confirmedIntake();
        $supply->forceFill(['quantity' => 5])->save();

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('confirmado', $intake->fresh()->status);
        $this->assertSame('485000.00', $this->budget->fresh()->available_amount);
        $this->assertSame(5, $supply->fresh()->quantity);
        $this->assertSame(2, EquipmentUnit::where('status', 'disponible')->where('invoice_intake_item_id', '!=', null)->count());
    }

    public function test_cancel_is_rejected_when_a_unit_is_not_available(): void
    {
        [$intake, , $equipment] = $this->confirmedIntake();
        EquipmentUnit::where('product_id', $equipment->id)->first()->forceFill(['status' => EquipmentUnit::STATUS_ON_LOAN])->save();

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('confirmado', $intake->fresh()->status);
        $this->assertSame('485000.00', $this->budget->fresh()->available_amount);
    }

    public function test_cancel_is_rejected_for_historical_units_without_traceability(): void
    {
        [$intake, , $equipment] = $this->confirmedIntake();
        EquipmentUnit::where('product_id', $equipment->id)->update(['invoice_intake_item_id' => null]);

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame('confirmado', $intake->fresh()->status);
    }

    public function test_roles_and_foreign_career_are_forbidden(): void
    {
        [$intake] = $this->confirmedIntake();

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertForbidden();
    }
}
