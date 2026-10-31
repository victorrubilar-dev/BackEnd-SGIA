<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\Category;
use App\Models\CodeSequence;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\CodeSequenceService;
use Database\Seeders\CodeSequenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CodeSequenceTest extends TestCase
{
    use DatabaseTransactions;

    private function career(string $code): Career
    {
        return Career::where('code', $code)->first()
            ?? Career::factory()->create(['code' => $code]);
    }

    public function test_next_generates_formatted_correlative_code_per_prefix_and_career(): void
    {
        CodeSequence::where('prefix', 'INS')->where('career', 'ISD')->update(['current_index' => 0]);
        CodeSequence::where('prefix', 'INS')->where('career', 'II')->update(['current_index' => 0]);
        CodeSequence::where('prefix', 'MAQ')->where('career', 'ISD')->update(['current_index' => 0]);
        $service = app(CodeSequenceService::class);

        $this->assertSame('INS-ISD-000001', $service->next('INS', 'ISD'));
        $this->assertSame('INS-ISD-000002', $service->next('INS', 'ISD'));
        $this->assertSame('INS-II-000001', $service->next('INS', 'II'));
        $this->assertSame('MAQ-ISD-000001', $service->next('MAQ', 'ISD'));
    }

    public function test_unknown_combination_raises_validation_error_and_check_rejects_bad_career(): void
    {
        try {
            app(CodeSequenceService::class)->next('XXX', 'IC');
            $this->fail('Debía fallar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No existe un secuenciador', $e->errors()['career_id'][0]);
        }

        $this->expectException(QueryException::class);
        CodeSequence::create(['name' => 'x', 'prefix' => 'INS', 'career' => 'ZZ']);
    }

    public function test_for_product_picks_prefix_by_type_and_requires_career_with_code(): void
    {
        $service = app(CodeSequenceService::class);
        $career = $this->career('IC');

        $this->assertStringStartsWith('INS-IC-', $service->forProduct(Product::factory()->supply()->make(['career_id' => $career->id])));
        $this->assertStringStartsWith('PRO-IC-', $service->forProduct(Product::factory()->make(['career_id' => $career->id])));
        $this->assertStringStartsWith('MAQ-IC-', $service->forProduct(Product::factory()->scaleModel()->make(['career_id' => $career->id])));

        $noCode = Career::factory()->create(['code' => null]);
        foreach ([null, $noCode->id] as $careerId) {
            try {
                $service->forProduct(Product::factory()->supply()->make(['career_id' => $careerId]));
                $this->fail('Debía fallar.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('career_id', $e->errors());
            }
        }
    }

    public function test_seeder_creates_twelve_combinations_without_resetting_index(): void
    {
        CodeSequence::where('prefix', 'PRO')->where('career', 'AP')->update(['current_index' => 7]);
        $this->seed(CodeSequenceSeeder::class);

        $this->assertSame(12, CodeSequence::count());
        $this->assertSame(7, CodeSequence::where('prefix', 'PRO')->where('career', 'AP')->value('current_index'));
    }

    public function test_function_is_sequential_inside_database(): void
    {
        CodeSequence::where('prefix', 'PRO')->where('career', 'II')->update(['current_index' => 41]);

        $this->assertSame('PRO-II-000042', DB::selectOne("select fn_generar_codigo_sgia('PRO', 'II') as code")->code);
    }

    public function test_supply_creation_generates_code_with_career(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));
        $career = $this->career('ISD');
        $category = Category::factory()->create();

        $barcode = $this->postJson('/api/products', [
            'name' => 'Cable UTP', 'type' => 'insumo', 'quantity' => 10,
            'category_id' => $category->id, 'career_id' => $career->id,
        ])->assertCreated()->assertJsonPath('data.career_id', $career->id)->json('data.barcode');

        $this->assertMatchesRegularExpression('/^INS-ISD-\d{6}$/', $barcode);
    }

    public function test_supply_without_career_and_barcode_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));
        $category = Category::factory()->create();

        $this->postJson('/api/products', ['name' => 'Cable', 'type' => 'insumo', 'quantity' => 1, 'category_id' => $category->id])
            ->assertStatus(422)->assertJsonValidationErrors('career_id');

        $noCode = Career::factory()->create(['code' => null]);
        $this->postJson('/api/products', ['name' => 'Cable', 'type' => 'insumo', 'quantity' => 1, 'category_id' => $category->id, 'career_id' => $noCode->id])
            ->assertStatus(422)->assertJsonValidationErrors('career_id');

        // Con barcode explícito no hace falta carrera.
        $this->postJson('/api/products', ['name' => 'Cable', 'type' => 'insumo', 'quantity' => 1, 'category_id' => $category->id, 'barcode' => 'MANUAL-1'])
            ->assertCreated();
    }

    public function test_product_requires_category_and_accepts_scale_model_type(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));

        $this->postJson('/api/products', ['name' => 'Maqueta Rack', 'type' => 'maqueta', 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->postJson('/api/products', [
            'name' => 'Maqueta Rack', 'type' => 'maqueta', 'quantity' => 1,
            'category_id' => Category::factory()->create()->id,
            'maintenance_priority' => 'A', 'requires_calibration' => true,
            'maintenance_frequency_months' => 6, 'reference_price' => 15990.5,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'maqueta')
            ->assertJsonPath('data.maintenance_priority', 'A')
            ->assertJsonPath('data.requires_calibration', true)
            ->assertJsonPath('data.reference_price', '15990.50');

        $this->postJson('/api/products', ['name' => 'X', 'quantity' => 1, 'category_id' => Category::factory()->create()->id, 'maintenance_priority' => 'Z'])
            ->assertStatus(422)->assertJsonValidationErrors('maintenance_priority');
        $this->assertTrue(Product::factory()->scaleModel()->make()->hasUnits());
        $this->assertFalse(Product::factory()->supply()->make()->hasUnits());
    }

    public function test_priority_check_constraint(): void
    {
        $this->expectException(QueryException::class);
        Product::factory()->create(['maintenance_priority' => 'Z']);
    }

    public function test_cajon_gets_barcode_and_location_gets_default_storeroom(): void
    {
        $location = Location::create(['nombre' => 'Sala X', 'sala' => 'Sala X', 'tipo' => 'sala']);
        $this->assertSame(Storeroom::default()->id, $location->storeroom_id);

        $cajon = Cajon::create(['codigo' => 'C-1', 'location_id' => $location->id]);
        $this->assertMatchesRegularExpression('/^EST-\d{8}$/', $cajon->barcode);
    }

    public function test_location_with_cajones_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $cajon = Cajon::factory()->create();

        $this->deleteJson('/api/locations/'.$cajon->location_id)->assertStatus(422)->assertJsonPath('cajones_count', 1);

        $this->expectException(QueryException::class);
        $cajon->location->delete();
    }

    public function test_location_accepts_storeroom_building_and_floor(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));
        $storeroom = Storeroom::factory()->create();

        $this->postJson('/api/locations', ['nombre' => 'Lab Z', 'storeroom_id' => $storeroom->id, 'building' => 'A', 'floor' => 3])
            ->assertCreated()
            ->assertJsonPath('data.storeroom_id', $storeroom->id)
            ->assertJsonPath('data.building', 'A')
            ->assertJsonPath('data.floor', 3);

        $this->postJson('/api/locations', ['nombre' => 'Lab W'])->assertCreated()
            ->assertJsonPath('data.storeroom_id', Storeroom::default()->id);
        $this->postJson('/api/locations', ['nombre' => 'Lab V', 'storeroom_id' => 999999])->assertStatus(422);
    }

    public function test_cajon_barcode_is_unique_across_scans(): void
    {
        $a = Cajon::factory()->create();
        $this->expectException(QueryException::class);
        Cajon::factory()->create(['barcode' => $a->barcode]);
    }
}
