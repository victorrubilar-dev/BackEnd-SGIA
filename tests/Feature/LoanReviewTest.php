<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\EquipmentUnitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Revisión de la solicitud remota y elección de unidades (`docs/revision-solicitud-prestamo.md`).
 */
class LoanReviewTest extends TestCase
{
    use DatabaseTransactions;

    private User $warehouse;

    private Storeroom $storeroom;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->storeroom = Storeroom::default();
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        Sanctum::actingAs($this->warehouse);
    }

    private function unitProduct(): Product
    {
        return Product::factory()->create(['type' => Product::TYPE_EQUIPMENT, 'quantity' => 0, 'stock_minimo' => 0]);
    }

    /**
     * @return Collection<int, EquipmentUnit>
     */
    private function units(Product $product, int $count = 1, ?Storeroom $storeroom = null, string $sala = 'Sala Z', string $cajon = 'C-9'): Collection
    {
        $location = Location::factory()->create(['storeroom_id' => ($storeroom ?? $this->storeroom)->id, 'tipo' => Location::TIPO_SALA, 'nombre' => $sala]);
        $box = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => $cajon]);
        $units = EquipmentUnit::factory()->count($count)->create(['product_id' => $product->id, 'cajon_id' => $box->id]);
        app(EquipmentUnitService::class)->syncProductCounters($product);

        return $units;
    }

    /**
     * @return array{0: Loan, 1: LoanItem}
     */
    private function loan(Product $product, int $quantity): array
    {
        $loan = Loan::factory()->create(['storeroom_id' => $this->storeroom->id]);

        return [$loan, $this->item($loan, $product, $quantity)];
    }

    private function item(Loan $loan, Product $product, int $quantity): LoanItem
    {
        return LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => $quantity]);
    }

    /**
     * @param  array<int, int>  $unitIds
     */
    private function approve(Loan $loan, LoanItem $item, array $unitIds)
    {
        return $this->postJson("/api/loans/{$loan->id}/approve", ['items' => [['item_id' => $item->id, 'unit_ids' => $unitIds]]]);
    }

    public function test_review_suggests_the_units_approve_would_assign_with_their_location(): void
    {
        $product = $this->unitProduct();
        $units = $this->units($product, 3, sala: 'Laboratorio de Redes', cajon: 'C-02');
        [$loan, $item] = $this->loan($product, 2);

        $response = $this->getJson("/api/loans/{$loan->id}/review")
            ->assertOk()
            ->assertJsonPath('data.loan_id', $loan->id)
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.storeroom.id', $this->storeroom->id)
            ->assertJsonPath('data.storeroom.name', $this->storeroom->name)
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.items.0.item_id', $item->id)
            ->assertJsonPath('data.items.0.has_units', true)
            ->assertJsonPath('data.items.0.available_quantity', 3)
            ->assertJsonPath('data.items.0.shortage', 0)
            ->assertJsonPath('data.items.0.location', null)
            ->assertJsonPath('data.items.0.suggested_units.0.location.nombre', 'Laboratorio de Redes')
            ->assertJsonPath('data.items.0.suggested_units.0.location.storeroom_id', $this->storeroom->id)
            ->assertJsonPath('data.items.0.suggested_units.0.cajon.codigo', 'C-02')
            ->assertJsonCount(3, 'data.items.0.available_units');

        $suggested = array_column($response->json('data.items.0.suggested_units'), 'id');
        $this->assertSame($units->take(2)->pluck('id')->all(), $suggested);

        // La revisión no reserva nada.
        $this->assertSame(0, $units->filter(fn ($unit) => $unit->fresh()->status !== 'disponible')->count());

        $this->postJson("/api/loans/{$loan->id}/approve")->assertOk();
        $this->assertSame($suggested, $item->units()->orderBy('equipment_units.id')->pluck('equipment_units.id')->all());
    }

    public function test_review_sorts_available_units_by_location_and_ignores_other_units(): void
    {
        $product = $this->unitProduct();
        $last = $this->units($product, sala: 'Sala B', cajon: 'C-1')->first();
        $second = $this->units($product, sala: 'Sala A', cajon: 'C-10')->first();
        $first = $this->units($product, sala: 'Sala A', cajon: 'C-2')->first();
        $foreign = $this->units($product, storeroom: Storeroom::factory()->create())->first();
        $this->units($product)->first()->update(['status' => EquipmentUnit::STATUS_IN_REPAIR]);
        [$loan] = $this->loan($product, 1);

        $response = $this->getJson("/api/loans/{$loan->id}/review")->assertOk();

        $ids = array_column($response->json('data.items.0.available_units'), 'id');
        $this->assertSame([$first->id, $second->id, $last->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
        $response->assertJsonPath('data.items.0.suggested_units.0.id', $last->id);
    }

    public function test_review_of_a_supply_shows_the_product_location_without_units(): void
    {
        $location = Location::factory()->create(['storeroom_id' => $this->storeroom->id, 'nombre' => 'Bodega']);
        $cajon = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'C-07']);
        $product = Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'quantity' => 40, 'location_id' => $location->id, 'cajon_id' => $cajon->id]);
        [$loan] = $this->loan($product, 5);

        $this->getJson("/api/loans/{$loan->id}/review")
            ->assertOk()
            ->assertJsonPath('data.items.0.has_units', false)
            ->assertJsonPath('data.items.0.available_quantity', 40)
            ->assertJsonPath('data.items.0.location.nombre', 'Bodega')
            ->assertJsonPath('data.items.0.cajon.codigo', 'C-07')
            ->assertJsonPath('data.items.0.suggested_units', [])
            ->assertJsonPath('data.items.0.available_units', []);
    }

    public function test_review_reports_shortage_counting_only_the_loan_storeroom(): void
    {
        $product = $this->unitProduct();
        $this->units($product, 1);
        $this->units($product, 5, Storeroom::factory()->create());
        $supply = Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'quantity' => 1]);
        [$loan] = $this->loan($product, 3);
        $this->item($loan, $supply, 1);

        $this->getJson("/api/loans/{$loan->id}/review")
            ->assertOk()
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.items.0.available_quantity', 1)
            ->assertJsonPath('data.items.0.shortage', 2)
            ->assertJsonCount(1, 'data.items.0.suggested_units')
            ->assertJsonPath('data.items.1.shortage', 0);
    }

    public function test_review_query_count_does_not_grow_with_units_or_items(): void
    {
        $count = function (int $units, int $products): int {
            $loan = Loan::factory()->create(['storeroom_id' => $this->storeroom->id]);
            for ($i = 0; $i < $products; $i++) {
                $product = $this->unitProduct();
                $this->item($loan, $product, 1);
                for ($j = 0; $j < $units; $j++) {
                    $this->units($product);
                }
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/loans/{$loan->id}/review")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1, 1), $count(3, 3));
    }

    public function test_review_is_forbidden_for_other_roles(): void
    {
        [$loan] = $this->loan($this->unitProduct(), 1);

        foreach ([User::ROLE_TEACHER, User::ROLE_DIRECTOR] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson("/api/loans/{$loan->id}/review")->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->getJson("/api/loans/{$loan->id}/review")->assertOk();
    }

    public function test_review_rejects_a_loan_that_is_not_pending(): void
    {
        $product = $this->unitProduct();
        $this->units($product);
        [$loan] = $this->loan($product, 1);
        $this->postJson("/api/loans/{$loan->id}/approve")->assertOk();

        $this->getJson("/api/loans/{$loan->id}/review")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status')
            ->assertJsonPath('current_status', 'en_proceso');
    }

    public function test_approve_assigns_the_chosen_units(): void
    {
        $product = $this->unitProduct();
        $units = $this->units($product, 3);
        [$loan, $item] = $this->loan($product, 2);

        $this->approve($loan, $item, [$units[1]->id, $units[2]->id])
            ->assertOk()
            ->assertJsonPath('loan.status', 'en_proceso')
            ->assertJsonStructure(['message', 'allowed_transitions', 'loan' => ['items' => [['units' => [['cajon', 'location']]]]]]);

        $this->assertSame(['disponible', 'prestado', 'prestado'], $units->map(fn ($u) => $u->fresh()->status)->all());
        $this->assertEqualsCanonicalizing([$units[1]->id, $units[2]->id], $item->units()->pluck('equipment_units.id')->all());
        $this->assertSame(1, $product->fresh()->quantity);
    }

    public function test_approve_assigns_chosen_units_before_the_automatic_ones(): void
    {
        $auto = $this->unitProduct();
        $autoUnits = $this->units($auto, 2);
        $chosen = $this->unitProduct();
        $chosenUnits = $this->units($chosen, 2);
        $supply = Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'quantity' => 10, 'stock_minimo' => 0]);
        [$loan, $autoItem] = $this->loan($auto, 1);
        $chosenItem = $this->item($loan, $chosen, 1);
        $this->item($loan, $supply, 3);

        $this->approve($loan, $chosenItem, [$chosenUnits[1]->id])->assertOk();

        $this->assertSame([$autoUnits[0]->id], $autoItem->units()->pluck('equipment_units.id')->all());
        $this->assertSame([$chosenUnits[1]->id], $chosenItem->units()->pluck('equipment_units.id')->all());
        $this->assertSame(7, $supply->fresh()->quantity);
    }

    public function test_automatic_assignment_does_not_collide_with_a_chosen_unit_of_the_same_product(): void
    {
        $product = $this->unitProduct();
        $units = $this->units($product, 2);
        [$loan, $autoItem] = $this->loan($product, 1);
        $chosenItem = $this->item($loan, $product, 1);

        $this->approve($loan, $chosenItem, [$units[0]->id])->assertOk();

        $this->assertSame([$units[1]->id], $autoItem->units()->pluck('equipment_units.id')->all());
        $this->assertSame([$units[0]->id], $chosenItem->units()->pluck('equipment_units.id')->all());
    }

    public function test_approve_rejects_an_item_of_another_loan_or_a_supply(): void
    {
        $product = $this->unitProduct();
        $units = $this->units($product);
        [$loan, $item] = $this->loan($product, 1);
        [, $foreignItem] = $this->loan($product, 1);
        $supplyItem = $this->item($loan, Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'quantity' => 5]), 1);

        foreach ([$foreignItem, $supplyItem] as $wrong) {
            $this->approve($loan, $wrong, [$units[0]->id])->assertStatus(422)->assertJsonValidationErrors('items.0.item_id');
        }

        $this->postJson("/api/loans/{$loan->id}/approve", ['items' => [
            ['item_id' => $item->id, 'unit_ids' => [$units[0]->id]],
            ['item_id' => $item->id, 'unit_ids' => [$units[0]->id]],
        ]])->assertStatus(422);

        $this->assertSame('pendiente', $loan->fresh()->status);
    }

    public function test_approve_rejects_a_wrong_number_of_units(): void
    {
        $product = $this->unitProduct();
        $units = $this->units($product, 3);
        [$loan, $item] = $this->loan($product, 2);

        $this->approve($loan, $item, [$units[0]->id])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
        $this->approve($loan, $item, $units->pluck('id')->all())->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
    }

    public function test_approve_rejects_units_that_cannot_be_assigned(): void
    {
        $product = $this->unitProduct();
        $this->units($product, 2);
        [$loan, $item] = $this->loan($product, 1);

        $otherProduct = $this->units($this->unitProduct())->first();
        $otherStoreroom = $this->units($product, storeroom: Storeroom::factory()->create())->first();
        $unavailable = $this->units($product)->first();
        $unavailable->update(['status' => EquipmentUnit::STATUS_IN_REPAIR]);

        foreach ([999999999, $otherProduct->id, $otherStoreroom->id, $unavailable->id] as $unitId) {
            $this->approve($loan, $item, [$unitId])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids');
        }

        $this->assertSame('pendiente', $loan->fresh()->status);
    }

    public function test_approve_rejects_repeated_units_within_and_between_items(): void
    {
        $first = $this->unitProduct();
        $units = $this->units($first, 2);
        [$loan, $item] = $this->loan($first, 2);
        $other = $this->item($loan, $this->unitProduct(), 1);

        $this->approve($loan, $item, [$units[0]->id, $units[0]->id])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_ids.1');

        $this->postJson("/api/loans/{$loan->id}/approve", ['items' => [
            ['item_id' => $item->id, 'unit_ids' => [$units[0]->id, $units[1]->id]],
            ['item_id' => $other->id, 'unit_ids' => [$units[0]->id]],
        ]])->assertStatus(422)->assertJsonValidationErrors('items.1.unit_ids.0');
    }

    public function test_a_failed_approval_changes_nothing(): void
    {
        $good = $this->unitProduct();
        $goodUnits = $this->units($good, 2);
        $bad = $this->unitProduct();
        $supply = Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'quantity' => 10, 'stock_minimo' => 0]);
        [$loan, $goodItem] = $this->loan($good, 1);
        $this->item($loan, $supply, 2);
        // Sin unidades: la asignación automática falla después de asignar la elegida.
        $this->item($loan, $bad, 1);

        $this->approve($loan, $goodItem, [$goodUnits[1]->id])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('pendiente', $loan->fresh()->status);
        $this->assertNull($loan->fresh()->approved_by);
        $this->assertSame('disponible', $goodUnits[1]->fresh()->status);
        $this->assertSame(0, $goodItem->units()->count());
        $this->assertSame(10, $supply->fresh()->quantity);
    }

    public function test_approve_without_body_or_with_empty_items_assigns_automatically(): void
    {
        foreach ([[], ['items' => []]] as $body) {
            $product = $this->unitProduct();
            $units = $this->units($product, 2);
            [$loan, $item] = $this->loan($product, 1);

            $this->postJson("/api/loans/{$loan->id}/approve", $body)->assertOk()->assertJsonPath('loan.status', 'en_proceso');

            $this->assertSame([$units[0]->id], $item->units()->pluck('equipment_units.id')->all());
        }
    }

    public function test_approve_with_invalid_body_or_role(): void
    {
        $product = $this->unitProduct();
        $this->units($product);
        [$loan] = $this->loan($product, 1);

        $this->postJson("/api/loans/{$loan->id}/approve", ['items' => [['unit_ids' => []]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.item_id', 'items.0.unit_ids']);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));
        $this->postJson("/api/loans/{$loan->id}/approve")->assertForbidden();
    }
}
