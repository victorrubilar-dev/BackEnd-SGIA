<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\EquipmentUnitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/products?storeroom_id=`: existencias del producto en el pañol elegido
 * (docs/disponibilidad-por-panol.md).
 */
class ProductStoreroomAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    private Storeroom $storeroom;

    private Storeroom $other;

    private Location $lab;

    private Cajon $cajon;

    private Cajon $otherCajon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeroom = Storeroom::factory()->create();
        $this->other = Storeroom::factory()->create();
        $this->lab = Location::factory()->create(['tipo' => Location::TIPO_SALA, 'storeroom_id' => $this->storeroom->id]);
        $this->cajon = Cajon::factory()->create(['location_id' => $this->lab->id]);
        $this->otherCajon = Cajon::factory()->create(['location_id' => Location::factory()->create(['storeroom_id' => $this->other->id])->id]);
    }

    private function equipment(string $type = Product::TYPE_EQUIPMENT): Product
    {
        $product = Product::factory()->create(['type' => $type, 'quantity' => 0, 'stock_minimo' => 0, 'is_active' => true]);

        return $product;
    }

    private function sync(Product $product): void
    {
        app(EquipmentUnitService::class)->syncProductCounters($product);
    }

    /** @return array<string, mixed>|null */
    private function row(string $query, Product $product, ?User $user = null): ?array
    {
        Sanctum::actingAs($user ?? User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));

        $rows = $this->getJson("/api/products?per_page=100&{$query}")->assertOk()->json('data');

        return collect($rows)->firstWhere('id', $product->id);
    }

    public function test_equipment_counts_available_units_in_the_storeroom_and_zero_elsewhere(): void
    {
        $product = $this->equipment();
        EquipmentUnit::factory()->count(2)->create(['product_id' => $product->id, 'cajon_id' => $this->cajon->id]);
        EquipmentUnit::factory()->count(3)->create(['product_id' => $product->id, 'cajon_id' => $this->otherCajon->id]);
        $this->sync($product);

        $mine = $this->row("storeroom_id={$this->storeroom->id}", $product);
        $elsewhere = $this->row("storeroom_id={$this->other->id}", $product);

        $this->assertSame(2, $mine['available_in_storeroom']);
        $this->assertSame(3, $elsewhere['available_in_storeroom']);
        $this->assertSame(5, $mine['quantity']);

        $empty = Storeroom::factory()->create();
        $row = $this->row("storeroom_id={$empty->id}", $product);
        $this->assertSame(0, $row['available_in_storeroom']);
        $this->assertSame(5, $row['quantity']);
    }

    public function test_only_available_units_count(): void
    {
        $product = $this->equipment();
        EquipmentUnit::factory()->create(['product_id' => $product->id, 'cajon_id' => $this->cajon->id, 'status' => EquipmentUnit::STATUS_AVAILABLE]);
        EquipmentUnit::factory()->create(['product_id' => $product->id, 'cajon_id' => $this->cajon->id, 'status' => EquipmentUnit::STATUS_ON_LOAN]);
        EquipmentUnit::factory()->create(['product_id' => $product->id, 'cajon_id' => $this->cajon->id, 'status' => EquipmentUnit::STATUS_IN_REPAIR]);
        EquipmentUnit::factory()->create(['product_id' => $product->id, 'cajon_id' => $this->cajon->id, 'status' => EquipmentUnit::STATUS_WRITTEN_OFF]);
        $this->sync($product);

        $this->assertSame(1, $this->row("storeroom_id={$this->storeroom->id}", $product)['available_in_storeroom']);
    }

    public function test_scale_model_behaves_like_equipment(): void
    {
        $model = $this->equipment(Product::TYPE_SCALE_MODEL);
        EquipmentUnit::factory()->count(2)->create(['product_id' => $model->id, 'cajon_id' => $this->cajon->id]);
        $this->sync($model);

        $this->assertSame(2, $this->row("storeroom_id={$this->storeroom->id}", $model)['available_in_storeroom']);
        $this->assertSame(0, $this->row("storeroom_id={$this->other->id}", $model)['available_in_storeroom']);
    }

    public function test_supply_returns_its_total_quantity(): void
    {
        $supply = Product::factory()->supply()->create(['quantity' => 20, 'is_active' => true]);

        $this->assertSame(20, $this->row("storeroom_id={$this->storeroom->id}", $supply)['available_in_storeroom']);
    }

    public function test_key_is_absent_without_storeroom_id(): void
    {
        $product = $this->equipment();

        $this->assertArrayNotHasKey('available_in_storeroom', $this->row('', $product));
    }

    public function test_invalid_storeroom_id_answers_422(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));

        $this->getJson('/api/products?storeroom_id=999999')->assertStatus(422)->assertJsonValidationErrors('storeroom_id');
        $this->getJson('/api/products?storeroom_id=abc')->assertStatus(422)->assertJsonValidationErrors('storeroom_id');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/products?storeroom_id={$this->storeroom->id}")->assertUnauthorized();
    }

    public function test_request_validation_agrees_with_the_reported_availability(): void
    {
        $product = $this->equipment();
        EquipmentUnit::factory()->count(2)->create(['product_id' => $product->id, 'cajon_id' => $this->cajon->id]);
        $this->sync($product);

        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $available = $this->row("storeroom_id={$this->storeroom->id}", $product, $teacher)['available_in_storeroom'];

        $payload = fn (int $quantity) => [
            'career_id' => Career::factory()->create()->id,
            'subject' => 'Redes',
            'loan_date' => today()->addDay()->toDateString(),
            'start_time' => '08:30',
            'end_time' => '10:00',
            'storeroom_id' => $this->storeroom->id,
            'location_id' => $this->lab->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ];

        $this->postJson('/api/loans/requests', $payload($available + 1))->assertStatus(422);
        $this->postJson('/api/loans/requests', $payload($available))->assertCreated();
    }
}
