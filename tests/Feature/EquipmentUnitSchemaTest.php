<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

class EquipmentUnitSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private function runBackfill(): void
    {
        DB::table('equipment_units')->delete();
        $migration = require database_path('migrations/2026_11_03_000000_create_equipment_units_table.php');
        $method = new ReflectionMethod($migration, 'backfill');
        $method->invoke($migration);
    }

    public function test_backfill_creates_units_from_counters_and_active_loans(): void
    {
        $product = Product::factory()->create([
            'barcode' => 'SGIA-LEGACY01', 'quantity' => 3, 'quantity_in_repair' => 1, 'quantity_written_off' => 1,
        ]);
        $loan = Loan::factory()->create(['status' => Loan::STATUS_PROCESSED]);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 2]);

        $returned = Loan::factory()->create(['status' => Loan::STATUS_PROCESSED]);
        LoanItem::create(['loan_id' => $returned->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 4, 'returned_at' => now()]);

        $this->runBackfill();

        $units = EquipmentUnit::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(7, $units);
        $this->assertSame(3, $units->where('status', 'disponible')->count());
        $this->assertSame(2, $units->where('status', 'prestado')->count());
        $this->assertSame(1, $units->where('status', 'en_reparacion')->count());
        $this->assertSame(1, $units->where('status', 'dado_de_baja')->count());
        $this->assertSame($product->cajon_id, $units->first()->cajon_id);
        $this->assertSame(['SGIA-LEGACY01-001', 'SGIA-LEGACY01-002', 'SGIA-LEGACY01-003'], $units->take(3)->pluck('code')->all());
    }

    public function test_backfill_skips_supplies_and_places_products_without_cajon_in_unassigned(): void
    {
        $supply = Product::factory()->supply()->create(['quantity' => 5]);
        $orphan = Product::factory()->create(['cajon_id' => null, 'quantity' => 2, 'barcode' => 'SGIA-ORPHAN01']);

        $this->runBackfill();

        $this->assertSame(0, EquipmentUnit::where('product_id', $supply->id)->count());
        $units = EquipmentUnit::where('product_id', $orphan->id)->get();
        $this->assertCount(2, $units);
        $cajon = Cajon::findOrFail($units->first()->cajon_id);
        $this->assertSame('SIN-ASIGNAR', $cajon->codigo);
        $this->assertSame('Sin asignar', $cajon->location->nombre);
        $this->assertNotNull($cajon->location->storeroom_id);
    }

    public function test_relations_and_location_through_cajon(): void
    {
        $unit = EquipmentUnit::factory()->onLoan()->create();

        $this->assertSame('prestado', $unit->status);
        $this->assertTrue($unit->product->units->contains($unit));
        $this->assertTrue($unit->cajon->equipmentUnits->contains($unit));
        $this->assertSame($unit->cajon->location_id, $unit->location->id);
        $this->assertSame('en_reparacion', EquipmentUnit::factory()->inRepair()->make()->status);
        $this->assertSame('dado_de_baja', EquipmentUnit::factory()->writtenOff()->make()->status);
        $this->assertSame('disponible', EquipmentUnit::factory()->available()->make()->status);
    }

    public function test_code_and_serial_number_are_unique(): void
    {
        $unit = EquipmentUnit::factory()->create(['serial_number' => 'SN-1']);

        try {
            EquipmentUnit::factory()->create(['code' => $unit->code]);
            $this->fail('code duplicado');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_product_with_units_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $unit = EquipmentUnit::factory()->create();

        $this->deleteJson('/api/products/'.$unit->product_id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'El producto tiene equipos registrados; dalos de baja en su lugar.');
        $this->assertDatabaseHas('products', ['id' => $unit->product_id]);
    }

    public function test_cajon_with_units_cannot_be_deleted_even_in_bulk(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $unit = EquipmentUnit::factory()->create();
        $free = Cajon::factory()->create();

        $this->deleteJson('/api/cajones/'.$unit->cajon_id)->assertStatus(422)->assertJsonPath('units_count', 1);

        $this->postJson('/api/cajones/bulk-delete', ['ids' => [$free->id, $unit->cajon_id]])
            ->assertStatus(422)->assertJsonPath('blocked_ids', [$unit->cajon_id]);
        $this->postJson('/api/cajones/bulk-delete', ['ids' => [$free->id, $unit->cajon_id], 'partial' => true])->assertOk();
        $this->assertDatabaseHas('cajones', ['id' => $unit->cajon_id]);
        $this->assertDatabaseMissing('cajones', ['id' => $free->id]);
    }
}
