<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Storeroom;
use App\Models\Student;
use App\Models\User;
use App\Services\EquipmentUnitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Préstamos de productos `equipo` / `maqueta` con asignación de unidades (plan Equipos, fase 7).
 */
class LoanUnitsTest extends TestCase
{
    use DatabaseTransactions;

    private User $warehouse;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
    }

    /**
     * @return array{0: Product, 1: Collection<int, EquipmentUnit>}
     */
    private function productWithUnits(int $count = 3, array $product = []): array
    {
        $p = Product::factory()->create(['quantity' => 0, 'stock_minimo' => 0, ...$product]);
        $units = EquipmentUnit::factory()->count($count)->create(['product_id' => $p->id]);
        app(EquipmentUnitService::class)->syncProductCounters($p);

        return [$p->fresh(), $units];
    }

    private function checkout(array $items, array $extra = []): TestResponse
    {
        return $this->postJson('/api/loans/checkout', [
            'borrower_name' => 'Pedro Alumno', 'subject' => 'Redes', 'room' => 'Lab 1', 'loan_date' => today()->toDateString(),
            'items' => $items, ...$extra,
        ]);
    }

    public function test_checkout_without_unit_ids_assigns_available_units_automatically(): void
    {
        [$product, $units] = $this->productWithUnits(3);
        Sanctum::actingAs($this->warehouse);

        $response = $this->checkout([['product_id' => $product->id, 'quantity' => 2]])->assertCreated();

        $item = LoanItem::with('units')->findOrFail($response->json('data.items.0.id'));
        $this->assertSame($units->take(2)->pluck('id')->all(), $item->units->pluck('id')->sort()->values()->all());
        $this->assertSame(2, $item->delivered_quantity);
        $response->assertJsonCount(2, 'data.items.0.units')->assertJsonPath('data.items.0.delivered_quantity', 2);
        $this->assertSame(['prestado', 'prestado', 'disponible'], $units->map(fn ($u) => $u->fresh()->status)->all());
        $this->assertSame(1, $product->fresh()->quantity);
    }

    public function test_checkout_with_specific_unit_ids(): void
    {
        [$product, $units] = $this->productWithUnits(3);
        Sanctum::actingAs($this->warehouse);

        $this->checkout([['product_id' => $product->id, 'quantity' => 1, 'unit_ids' => [$units[2]->id]]])->assertCreated();

        $this->assertSame('prestado', $units[2]->fresh()->status);
        $this->assertSame('disponible', $units[0]->fresh()->status);
    }

    public function test_checkout_rejects_foreign_unavailable_wrong_count_and_supply_unit_ids(): void
    {
        [$product, $units] = $this->productWithUnits(2);
        [, $foreign] = $this->productWithUnits(1);
        $units[1]->update(['status' => 'en_reparacion']);
        $supply = Product::factory()->supply()->create(['quantity' => 5]);
        Sanctum::actingAs($this->warehouse);

        $this->checkout([['product_id' => $product->id, 'quantity' => 1, 'unit_ids' => [$foreign[0]->id]]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->checkout([['product_id' => $product->id, 'quantity' => 1, 'unit_ids' => [$units[1]->id]]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->checkout([['product_id' => $product->id, 'quantity' => 2, 'unit_ids' => [$units[0]->id]]])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
        $this->checkout([['product_id' => $supply->id, 'quantity' => 1, 'unit_ids' => [$units[0]->id]]])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
        $this->checkout([['product_id' => $product->id, 'quantity' => 1, 'unit_ids' => [999999]]])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids.0');
        $this->checkout([['product_id' => $product->id, 'quantity' => 2]])->assertStatus(422);

        $this->assertSame('disponible', $units[0]->fresh()->status);
        $this->assertSame(0, Loan::where('borrower_name', 'Pedro Alumno')->count());
    }

    public function test_checkout_creates_student_when_document_is_a_rut_and_uses_storeroom(): void
    {
        $supply = Product::factory()->supply()->create(['quantity' => 5]);
        $this->warehouse->update(['storeroom_id' => Storeroom::factory()->create()->id]);
        Sanctum::actingAs($this->warehouse);

        $loanId = $this->checkout([['product_id' => $supply->id, 'quantity' => 1]], ['borrower_document' => '12.345.678-5'])->assertCreated()->json('data.id');
        $loan = Loan::findOrFail($loanId);

        $this->assertSame('12345678-5', Student::findOrFail($loan->student_id)->rut);
        $this->assertSame($this->warehouse->storeroom_id, $loan->storeroom_id);

        $second = $this->checkout([['product_id' => $supply->id, 'quantity' => 1]], ['borrower_document' => '12345678-5'])->assertCreated()->json('data.id');
        $this->assertSame($loan->student_id, Loan::findOrFail($second)->student_id);
        $this->assertSame(1, Student::where('rut', '12345678-5')->count());

        $other = $this->checkout([['product_id' => $supply->id, 'quantity' => 1]], ['borrower_document' => 'CRED-123'])->json('data.id');
        $this->assertNull(Loan::findOrFail($other)->student_id);
    }

    public function test_approve_assigns_units_automatically_and_deliver_marks_quantities(): void
    {
        [$product, $units] = $this->productWithUnits(3);
        $loan = Loan::factory()->create(['requested_by' => $this->teacher->id]);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 2]);
        Sanctum::actingAs($this->warehouse);

        $this->postJson("/api/loans/{$loan->id}/approve")->assertOk()->assertJsonCount(2, 'loan.items.0.units');
        $this->assertSame(0, $item->fresh()->delivered_quantity);
        $this->assertSame(1, $product->fresh()->quantity);

        $this->postJson("/api/loans/{$loan->id}/deliver")->assertOk();
        $this->assertSame(2, $item->fresh()->delivered_quantity);
        $this->assertSame(['prestado', 'prestado', 'disponible'], $units->map(fn ($u) => $u->fresh()->status)->all());
    }

    public function test_deliver_can_replace_assigned_units(): void
    {
        [$product, $units] = $this->productWithUnits(3);
        $loan = Loan::factory()->create(['requested_by' => $this->teacher->id]);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 1]);
        Sanctum::actingAs($this->warehouse);
        $this->postJson("/api/loans/{$loan->id}/approve")->assertOk();
        $this->assertSame([$units[0]->id], $item->units()->pluck('equipment_units.id')->all());

        $this->postJson("/api/loans/{$loan->id}/deliver", ['items' => [['item_id' => $item->id, 'unit_ids' => [$units[2]->id]]]])
            ->assertOk()->assertJsonPath('loan.items.0.units.0.id', $units[2]->id);

        $this->assertSame('disponible', $units[0]->fresh()->status);
        $this->assertSame('prestado', $units[2]->fresh()->status);
        $this->assertSame([$units[2]->id], $item->units()->pluck('equipment_units.id')->all());
        $this->assertSame(2, $product->fresh()->quantity);
    }

    public function test_deliver_replacement_validations(): void
    {
        [$product, $units] = $this->productWithUnits(2);
        $supply = Product::factory()->supply()->create(['quantity' => 5]);
        $loan = Loan::factory()->create(['requested_by' => $this->teacher->id]);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 1]);
        $supplyItem = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $supply->id, 'name' => $supply->name, 'quantity' => 1]);
        Sanctum::actingAs($this->warehouse);
        $this->postJson("/api/loans/{$loan->id}/approve")->assertOk();
        $units[1]->update(['status' => 'en_reparacion']);

        $this->postJson("/api/loans/{$loan->id}/deliver", ['items' => [['item_id' => $item->id, 'unit_ids' => [$units[1]->id]]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
        $this->postJson("/api/loans/{$loan->id}/deliver", ['items' => [['item_id' => $supplyItem->id, 'unit_ids' => [$units[0]->id]]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.item_id');
        $this->postJson("/api/loans/{$loan->id}/deliver", ['items' => [['item_id' => $item->id, 'unit_ids' => []]]])
            ->assertStatus(422);

        $this->assertSame('en_proceso', $loan->fresh()->status);
        $this->assertSame([$units[0]->id], $item->units()->pluck('equipment_units.id')->all());
    }

    public function test_full_checkin_updates_units_by_condition(): void
    {
        [$product, $units] = $this->productWithUnits(3);
        Sanctum::actingAs($this->warehouse);
        $response = $this->checkout([['product_id' => $product->id, 'quantity' => 3]])->assertCreated();
        $loanId = $response->json('data.id');
        $itemId = $response->json('data.items.0.id');

        $this->postJson('/api/loans/checkin', [
            'loan_id' => $loanId,
            'items' => [['item_id' => $itemId, 'condition' => 'regular']],
        ])->assertOk()->assertJsonPath('data.is_returned', true)->assertJsonPath('data.items.0.returned_quantity', 3);

        $this->assertSame(3, $product->fresh()->quantity);
        $this->assertSame(['disponible', 'disponible', 'disponible'], $units->map(fn ($u) => $u->fresh()->status)->all());
        $this->assertNotNull(Loan::findOrFail($loanId)->returned_at);
        $this->assertSame('regular', LoanItem::findOrFail($itemId)->return_condition);
    }

    public function test_partial_checkin_by_unit_with_worst_condition_and_counters(): void
    {
        [$product, $units] = $this->productWithUnits(3, ['stock_minimo' => 0]);
        Sanctum::actingAs($this->warehouse);
        $response = $this->checkout([['product_id' => $product->id, 'quantity' => 3]])->assertCreated();
        $loanId = $response->json('data.id');
        $itemId = $response->json('data.items.0.id');
        $ids = collect($response->json('data.items.0.units'))->pluck('id')->sort()->values();

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'unit_ids' => [$ids[0]]],
        ]])->assertOk()->assertJsonPath('data.is_returned', false)->assertJsonPath('data.items.0.returned_quantity', 1)->assertJsonPath('data.items.0.is_returned', false);
        $this->assertSame(1, $product->fresh()->quantity);
        $this->assertNull(LoanItem::findOrFail($itemId)->returned_at);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'danado', 'unit_ids' => [$ids[1]]],
            ['item_id' => $itemId, 'condition' => 'extraviado', 'unit_ids' => [$ids[2]]],
        ]])->assertOk()->assertJsonPath('data.is_returned', true);

        $fresh = $product->fresh();
        $this->assertSame([1, 1, 1], [$fresh->quantity, $fresh->quantity_in_repair, $fresh->quantity_written_off]);
        $this->assertSame('en_reparacion', EquipmentUnit::findOrFail($ids[1])->status);
        $this->assertSame('dado_de_baja', EquipmentUnit::findOrFail($ids[2])->status);
        $this->assertSame('extraviado', LoanItem::findOrFail($itemId)->return_condition);
        $this->assertSame(3, LoanItem::findOrFail($itemId)->returned_quantity);
    }

    public function test_checkin_unit_validations(): void
    {
        [$product, $units] = $this->productWithUnits(3);
        Sanctum::actingAs($this->warehouse);
        $response = $this->checkout([['product_id' => $product->id, 'quantity' => 2]])->assertCreated();
        $loanId = $response->json('data.id');
        $itemId = $response->json('data.items.0.id');
        $ids = collect($response->json('data.items.0.units'))->pluck('id')->values();
        $notBorrowed = $units->pluck('id')->diff($ids)->first();

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [['item_id' => $itemId, 'condition' => 'bueno', 'unit_ids' => [$notBorrowed]]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'unit_ids' => [$ids[0]]],
            ['item_id' => $itemId, 'condition' => 'bueno', 'unit_ids' => [$ids[0]]],
        ]])->assertStatus(422)->assertJsonValidationErrors('items.1.unit_ids.0');

        // La operación fallida no deja devoluciones a medias.
        $this->assertSame(0, LoanItem::findOrFail($itemId)->returned_quantity);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [['item_id' => $itemId, 'condition' => 'bueno']]])->assertOk();
        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [['item_id' => $itemId, 'condition' => 'bueno']]])
            ->assertStatus(422);
    }

    public function test_checkin_updates_stock_alerts(): void
    {
        [$product, $units] = $this->productWithUnits(2, ['stock_minimo' => 1]);
        Sanctum::actingAs($this->warehouse);
        $response = $this->checkout([['product_id' => $product->id, 'quantity' => 2]])->assertCreated();

        $this->assertDatabaseHas('stock_alerts', ['product_id' => $product->id, 'alert_type' => StockAlert::TYPE_CRITICAL, 'is_resolved' => false]);

        $product->update(['stock_minimo' => 0]);
        $this->postJson('/api/loans/checkin', ['loan_id' => $response->json('data.id'), 'items' => [['item_id' => $response->json('data.items.0.id'), 'condition' => 'extraviado']]])->assertOk();

        $this->assertSame(2, $product->fresh()->quantity_written_off);
        $this->assertSame(0, $product->fresh()->quantity);
    }

    public function test_second_checkout_for_the_last_unit_fails(): void
    {
        [$product, $units] = $this->productWithUnits(1);
        Sanctum::actingAs($this->warehouse);

        $this->checkout([['product_id' => $product->id, 'quantity' => 1]])->assertCreated();
        $this->checkout([['product_id' => $product->id, 'quantity' => 1]])->assertStatus(422);

        $this->assertSame(1, \DB::table('loan_item_units')->where('equipment_unit_id', $units[0]->id)->count());
    }

    public function test_show_history_and_export_include_unit_codes(): void
    {
        [$product, $units] = $this->productWithUnits(2);
        $units[0]->update(['code' => 'PRO-IC-000771']);
        $units[1]->update(['code' => 'PRO-IC-000772']);
        Sanctum::actingAs($this->warehouse);
        $loanId = $this->checkout([['product_id' => $product->id, 'quantity' => 2]])->assertCreated()->json('data.id');

        $this->getJson("/api/loans/{$loanId}")->assertOk()
            ->assertJsonPath('data.items.0.units.0.code', 'PRO-IC-000771')
            ->assertJsonPath('data.items.0.units.1.code', 'PRO-IC-000772');
        $this->getJson('/api/loans?search='.Loan::findOrFail($loanId)->code)->assertOk()->assertJsonCount(2, 'data.0.items.0.units');

        $csv = $this->get('/api/loans/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('[PRO-IC-000771, PRO-IC-000772]', $csv);
    }

    public function test_insumo_flow_is_unchanged_and_has_no_units(): void
    {
        $supply = Product::factory()->supply()->create(['quantity' => 10]);
        Sanctum::actingAs($this->warehouse);

        $response = $this->checkout([['product_id' => $supply->id, 'quantity' => 4]])->assertCreated();

        $this->assertSame(6, $supply->fresh()->quantity);
        $response->assertJsonPath('data.items.0.delivered_quantity', 4)->assertJsonCount(0, 'data.items.0.units');

        $this->postJson('/api/loans/checkin', ['loan_id' => $response->json('data.id'), 'items' => [['item_id' => $response->json('data.items.0.id'), 'condition' => 'bueno']]])
            ->assertOk()->assertJsonPath('data.items.0.returned_quantity', 4);
        $this->assertSame(10, $supply->fresh()->quantity);
    }
}
