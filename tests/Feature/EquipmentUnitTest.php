<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Services\BarcodeService;
use App\Services\EquipmentUnitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsProductPayload;
use Tests\TestCase;

class EquipmentUnitTest extends TestCase
{
    use BuildsProductPayload;
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create([
            'career_id' => $this->careerWithCode('IC')->id,
            'quantity' => 0,
            'stock_minimo' => 0,
            ...$attributes,
        ]);
    }

    public function test_warehouse_creates_single_unit_with_generated_code_and_counters_sync(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $product = $this->product();
        $cajon = Cajon::factory()->create();

        $response = $this->postJson("/api/products/{$product->id}/units", ['cajon_id' => $cajon->id, 'serial_number' => 'SN-001'])
            ->assertCreated()
            ->assertJsonPath('data.serial_number', 'SN-001')
            ->assertJsonPath('data.status', 'disponible')
            ->assertJsonPath('data.cajon_id', $cajon->id);

        $this->assertMatchesRegularExpression('/^PRO-IC-\d{6}$/', $response->json('data.code'));
        $this->assertSame(1, $product->fresh()->quantity);
    }

    public function test_bulk_creation_with_count_and_manual_code_rules(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $product = $this->product();
        $cajon = Cajon::factory()->create();

        $codes = collect($this->postJson("/api/products/{$product->id}/units", ['cajon_id' => $cajon->id, 'count' => 3])
            ->assertCreated()->assertJsonCount(3, 'data')->json('data'))->pluck('code');
        $this->assertCount(3, $codes->unique());
        $this->assertSame(3, $product->fresh()->quantity);

        $this->postJson("/api/products/{$product->id}/units", ['cajon_id' => $cajon->id, 'count' => 2, 'code' => 'MANUAL'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson("/api/products/{$product->id}/units", ['cajon_id' => $cajon->id, 'code' => 'LEG-001'])
            ->assertCreated()->assertJsonPath('data.code', 'LEG-001');
        $this->postJson("/api/products/{$product->id}/units", ['cajon_id' => $cajon->id, 'code' => 'LEG-001'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_unit_creation_validation_errors(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $product = $this->product();
        $noCareer = Product::factory()->create(['career_id' => null]);
        $supply = Product::factory()->supply()->create();
        $cajon = Cajon::factory()->create();

        $this->postJson("/api/products/{$product->id}/units", [])->assertStatus(422)->assertJsonValidationErrors('cajon_id');
        $this->postJson("/api/products/{$product->id}/units", ['cajon_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('cajon_id');
        $this->postJson("/api/products/{$product->id}/units", ['cajon_id' => $cajon->id, 'count' => 0])->assertStatus(422)->assertJsonValidationErrors('count');
        $this->postJson("/api/products/{$noCareer->id}/units", ['cajon_id' => $cajon->id])->assertStatus(422)->assertJsonValidationErrors('career_id');
        $this->postJson("/api/products/{$supply->id}/units", ['cajon_id' => $cajon->id])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_teacher_cannot_write_units_but_can_read_them(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $this->actingAsRole(User::ROLE_TEACHER);

        $this->postJson("/api/products/{$unit->product_id}/units", ['cajon_id' => $unit->cajon_id])->assertForbidden();
        $this->patchJson("/api/equipment-units/{$unit->id}", ['serial_number' => 'X'])->assertForbidden();
        $this->patchJson("/api/equipment-units/{$unit->id}/status", ['status' => 'en_reparacion'])->assertForbidden();
        $this->deleteJson("/api/equipment-units/{$unit->id}")->assertForbidden();

        $this->getJson("/api/products/{$unit->product_id}/units")->assertOk()->assertJsonPath('data.0.id', $unit->id);
        $this->getJson("/api/equipment-units/{$unit->id}")->assertOk();
        $this->getJson("/api/equipment-units/{$unit->id}/barcode")->assertOk();
    }

    public function test_warehouse_cannot_delete_units(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        $this->deleteJson("/api/equipment-units/{$unit->id}")->assertForbidden();
    }

    public function test_global_listing_filters(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $a = EquipmentUnit::factory()->create(['code' => 'PRO-IC-000901', 'serial_number' => 'ABC-111']);
        $b = EquipmentUnit::factory()->inRepair()->create(['code' => 'PRO-IC-000902']);
        $c = EquipmentUnit::factory()->create(['code' => 'PRO-IC-000903', 'next_maintenance_at' => now()->subDay()]);
        $d = EquipmentUnit::factory()->create(['code' => 'PRO-IC-000904', 'next_maintenance_at' => now()->addMonths(2)]);

        $ids = fn (string $query) => collect($this->getJson("/api/equipment-units?{$query}&per_page=100")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$b->id], $ids('status=en_reparacion&search=PRO-IC-00090'));
        $this->assertSame([$a->id], $ids('search=abc-111'));
        $this->assertSame([$c->id], $ids('maintenance_due=1&search=PRO-IC-00090'));
        $this->assertSame([$a->id], $ids("product_id={$a->product_id}"));
        $this->assertSame([$d->id], $ids("cajon_id={$d->cajon_id}"));
        $this->assertSame([$c->id], $ids("location_id={$c->cajon->location_id}"));
        $this->assertContains($b->id, $ids("storeroom_id={$b->cajon->location->storeroom_id}"));
    }

    public function test_by_code_returns_unit_with_location_and_404_when_missing(): void
    {
        $this->actingAsRole(User::ROLE_TEACHER);
        $unit = EquipmentUnit::factory()->create(['code' => 'MAQ-AP-000007']);

        $this->getJson('/api/equipment-units/by-code/MAQ-AP-000007')
            ->assertOk()
            ->assertJsonPath('data.id', $unit->id)
            ->assertJsonPath('data.cajon.id', $unit->cajon_id)
            ->assertJsonPath('data.location.id', $unit->cajon->location_id)
            ->assertJsonPath('data.storeroom.id', $unit->cajon->location->storeroom_id)
            ->assertJsonPath('data.product.id', $unit->product_id)
            ->assertJsonPath('data.active_loan', null);
        $this->getJson('/api/equipment-units/by-code/NO-EXISTE')->assertNotFound();
    }

    public function test_show_includes_active_loan(): void
    {
        $this->actingAsRole(User::ROLE_TEACHER);
        $unit = EquipmentUnit::factory()->onLoan()->create();
        $loan = Loan::factory()->create(['status' => Loan::STATUS_PROCESSED, 'borrower_name' => 'Ana Prestataria']);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $unit->product_id, 'name' => 'x', 'quantity' => 1]);
        $item->units()->attach($unit->id);

        $this->getJson("/api/equipment-units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.active_loan.id', $loan->id)
            ->assertJsonPath('data.active_loan.borrower_name', 'Ana Prestataria');
    }

    public function test_update_changes_data_but_not_status_or_product(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();
        $other = EquipmentUnit::factory()->create();
        $cajon = Cajon::factory()->create();

        $this->patchJson("/api/equipment-units/{$unit->id}", [
            'code' => 'NUEVO-1', 'serial_number' => 'SN-NEW', 'cajon_id' => $cajon->id, 'next_maintenance_at' => '2027-01-15',
        ])->assertOk()->assertJsonPath('data.code', 'NUEVO-1')->assertJsonPath('data.cajon_id', $cajon->id);
        $this->assertSame($user->id, $unit->fresh()->updated_by);

        $this->patchJson("/api/equipment-units/{$unit->id}", ['status' => 'dado_de_baja'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson("/api/equipment-units/{$unit->id}", ['product_id' => 1])->assertStatus(422)->assertJsonValidationErrors('product_id');
        $this->patchJson("/api/equipment-units/{$unit->id}", ['code' => $other->code])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_status_change_syncs_counters_and_alerts(): void
    {
        Notification::fake();
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $product = $this->product(['stock_minimo' => 1]);
        $units = EquipmentUnit::factory()->count(3)->create(['product_id' => $product->id]);
        app(EquipmentUnitService::class)->syncProductCounters($product);
        $this->assertSame(3, $product->fresh()->quantity);

        $this->patchJson("/api/equipment-units/{$units[0]->id}/status", ['status' => 'en_reparacion'])
            ->assertOk()->assertJsonPath('data.status', 'en_reparacion');
        $this->patchJson("/api/equipment-units/{$units[1]->id}/status", ['status' => 'dado_de_baja'])->assertOk();

        $fresh = $product->fresh();
        $this->assertSame([1, 1, 1], [$fresh->quantity, $fresh->quantity_in_repair, $fresh->quantity_written_off]);
        $this->assertDatabaseHas('stock_alerts', ['product_id' => $product->id, 'alert_type' => StockAlert::TYPE_CRITICAL, 'is_resolved' => false]);

        $this->patchJson("/api/equipment-units/{$units[0]->id}/status", ['status' => 'disponible'])->assertOk();
        $this->assertSame(2, $product->fresh()->quantity);
    }

    public function test_invalid_transitions_and_loan_only_status_are_rejected(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $written = EquipmentUnit::factory()->writtenOff()->create();
        $available = EquipmentUnit::factory()->create();
        $onLoan = EquipmentUnit::factory()->onLoan()->create();

        $this->patchJson("/api/equipment-units/{$written->id}/status", ['status' => 'disponible'])
            ->assertStatus(422)->assertJsonPath('allowed_transitions', []);
        $this->patchJson("/api/equipment-units/{$available->id}/status", ['status' => 'prestado'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson("/api/equipment-units/{$onLoan->id}/status", ['status' => 'disponible'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson("/api/equipment-units/{$available->id}/status", ['status' => 'inventado'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_change_status_from_loan_is_allowed_internally(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $service = app(EquipmentUnitService::class);

        $service->changeStatus($unit, 'prestado', null, true);
        $this->assertSame('prestado', $unit->fresh()->status);
        $service->changeStatus($unit, 'disponible', null, true);
        $this->assertSame('disponible', $unit->fresh()->status);

        $this->expectException(ValidationException::class);
        $service->changeStatus($unit, 'prestado', null);
    }

    public function test_delete_rules(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $product = $this->product();
        $free = EquipmentUnit::factory()->create(['product_id' => $product->id]);
        $onLoan = EquipmentUnit::factory()->onLoan()->create(['product_id' => $product->id]);
        $withLoans = EquipmentUnit::factory()->create(['product_id' => $product->id]);
        $item = LoanItem::create(['loan_id' => Loan::factory()->create()->id, 'product_id' => $product->id, 'name' => 'x', 'quantity' => 1]);
        $item->units()->attach($withLoans->id);

        $this->deleteJson("/api/equipment-units/{$onLoan->id}")->assertStatus(422);
        $this->deleteJson("/api/equipment-units/{$withLoans->id}")->assertStatus(422);
        $this->deleteJson("/api/equipment-units/{$free->id}")->assertNoContent();
        $this->assertDatabaseMissing('equipment_units', ['id' => $free->id]);
        $this->assertSame(1, $product->fresh()->quantity);
    }

    public function test_barcode_endpoint_returns_unit_code(): void
    {
        $this->actingAsRole(User::ROLE_TEACHER);
        $unit = EquipmentUnit::factory()->create(['code' => 'PRO-IC-000555']);

        $this->getJson("/api/equipment-units/{$unit->id}/barcode")
            ->assertOk()
            ->assertJsonPath('code', 'PRO-IC-000555')
            ->assertJsonStructure(['barcode_svg', 'barcode_image_uri', 'barcode_html']);
    }

    public function test_product_creation_with_quantity_creates_units(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $payload = $this->unitPayload('AP');

        $id = $this->postJson('/api/products', [
            ...$payload, 'name' => 'Router Cisco', 'quantity' => 3, 'stock_minimo' => 1,
            'units' => [['serial_number' => 'S-1'], ['serial_number' => 'S-2']],
        ])->assertCreated()->assertJsonPath('data.quantity', 3)->json('data.id');

        $units = EquipmentUnit::where('product_id', $id)->orderBy('id')->get();
        $this->assertCount(3, $units);
        $this->assertSame(['S-1', 'S-2', null], $units->pluck('serial_number')->all());
        $this->assertMatchesRegularExpression('/^PRO-AP-\d{6}$/', $units[0]->code);
        $this->assertSame($payload['cajon_id'], $units[0]->cajon_id);
    }

    public function test_scale_model_creation_uses_maq_prefix(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        $id = $this->postJson('/api/products', [
            ...$this->unitPayload('ISD'), 'name' => 'Maqueta Antena', 'type' => 'maqueta', 'quantity' => 1,
        ])->assertCreated()->json('data.id');

        $this->assertMatchesRegularExpression('/^MAQ-ISD-\d{6}$/', EquipmentUnit::where('product_id', $id)->value('code'));
    }

    public function test_equipment_creation_requires_cajon_and_career_when_quantity_positive(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $payload = $this->unitPayload();

        $noCajon = $payload;
        unset($noCajon['cajon_id']);
        $this->postJson('/api/products', [...$noCajon, 'name' => 'Sin cajon', 'quantity' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('cajon_id');

        $noCareer = $payload;
        unset($noCareer['career_id']);
        $this->postJson('/api/products', [...$noCareer, 'name' => 'Sin carrera', 'quantity' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('career_id');

        $this->postJson('/api/products', [...$noCareer, 'name' => 'Cero unidades', 'quantity' => 0])->assertCreated();
        $this->postJson('/api/products', [...$payload, 'name' => 'Muchas series', 'quantity' => 1, 'units' => [['serial_number' => 'a'], ['serial_number' => 'b']]])
            ->assertStatus(422)->assertJsonValidationErrors('units');
    }

    public function test_product_with_units_rejects_quantity_edit_and_condition(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();
        $productId = $unit->product_id;

        $this->patchJson("/api/products/{$productId}", ['quantity' => 5])
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', 'La cantidad de un equipo se calcula a partir de sus unidades.');
        $this->patchJson("/api/products/{$productId}", ['stock_minimo' => 2])->assertOk();
        $this->patchJson("/api/products/{$productId}/condition", ['action' => 'send_to_repair', 'quantity' => 1])
            ->assertStatus(422);
    }

    public function test_type_change_rules(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();

        $this->patchJson("/api/products/{$unit->product_id}", ['type' => 'insumo'])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $supply = Product::factory()->supply()->create([
            'career_id' => $this->careerWithCode('II')->id, 'quantity' => 2, 'quantity_in_repair' => 1, 'quantity_written_off' => 1,
        ]);
        $this->patchJson("/api/products/{$supply->id}", ['type' => 'equipo'])->assertOk();

        $created = EquipmentUnit::with('cajon')->where('product_id', $supply->id)->get();
        $this->assertCount(4, $created);
        $this->assertSame(2, $created->where('status', 'disponible')->count());
        $this->assertSame(1, $created->where('status', 'en_reparacion')->count());
        $this->assertSame(1, $created->where('status', 'dado_de_baja')->count());
        $this->assertMatchesRegularExpression('/^PRO-II-\d{6}$/', $created->first()->code);
        $this->assertSame(2, $supply->fresh()->quantity);
        $this->assertSame($supply->cajon_id, $created->first()->cajon_id);

        $noCajon = Product::factory()->supply()->create(['career_id' => $this->careerWithCode('II')->id, 'quantity' => 1, 'cajon_id' => null]);
        $this->patchJson("/api/products/{$noCajon->id}", ['type' => 'maqueta'])->assertOk();
        $this->assertSame('SIN-ASIGNAR', EquipmentUnit::with('cajon')->where('product_id', $noCajon->id)->first()->cajon->codigo);
    }

    public function test_barcode_service_avoids_collisions_with_unit_codes(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $code = app(BarcodeService::class)->generateUniqueCode();

        $this->assertNotSame($unit->code, $code);
        $this->assertDatabaseMissing('equipment_units', ['code' => $code]);
    }
}
