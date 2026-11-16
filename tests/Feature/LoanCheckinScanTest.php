<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
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
 * Devolución con escaneo de producto/unidad y cajón (`docs/codigos-ubicacion.md`, Fase 2).
 */
class LoanCheckinScanTest extends TestCase
{
    use DatabaseTransactions;

    private User $warehouse;

    private Cajon $shelf;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $location = Location::factory()->create(['code' => 'L206', 'storeroom_id' => Storeroom::default()->id]);
        $this->shelf = Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Rack R1']);
        Sanctum::actingAs($this->warehouse);
    }

    /**
     * Préstamo presencial de `$count` unidades de un equipo, ya entregado.
     *
     * @return array{0: int, 1: int, 2: Collection<int, EquipmentUnit>}
     */
    private function unitLoan(int $count = 2): array
    {
        $product = Product::factory()->create(['quantity' => 0, 'stock_minimo' => 0]);
        EquipmentUnit::factory()->count($count)->create(['product_id' => $product->id]);
        app(EquipmentUnitService::class)->syncProductCounters($product);

        $response = $this->postJson('/api/loans/checkout', [
            'borrower_name' => 'Pedro Alumno', 'subject' => 'Redes', 'career_id' => Career::factory()->create()->id,
            'room' => 'Lab 1', 'loan_date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $count]],
        ])->assertCreated();

        $units = EquipmentUnit::whereKey(collect($response->json('data.items.0.units'))->pluck('id'))->orderBy('id')->get();

        return [$response->json('data.id'), $response->json('data.items.0.id'), $units];
    }

    private function supplyLoan(Product $product, int $quantity = 2): Loan
    {
        $loan = Loan::factory()->processed()->create(['loan_date' => today()->toDateString(), 'due_date' => today()->toDateString()]);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => $quantity]);

        return $loan->load('items');
    }

    private function foreignCajon(): Cajon
    {
        $location = Location::factory()->create(['storeroom_id' => Storeroom::factory()->create()->id]);

        return Cajon::factory()->create(['location_id' => $location->id, 'codigo' => 'Mesa A']);
    }

    public function test_returned_unit_is_stored_in_the_scanned_cajon(): void
    {
        [$loanId, $itemId, $units] = $this->unitLoan(1);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'units' => [['id' => $units[0]->id, 'cajon_id' => $this->shelf->id]]],
        ]])->assertOk()
            ->assertJsonPath('data.is_returned', true)
            ->assertJsonPath('data.items.0.units.0.cajon.id', $this->shelf->id)
            ->assertJsonPath('data.items.0.units.0.cajon.barcode', $this->shelf->barcode);

        $this->assertSame($this->shelf->id, $units[0]->fresh()->cajon_id);
        $this->assertSame('disponible', $units[0]->fresh()->status);
    }

    public function test_unit_without_cajon_or_without_units_is_rejected(): void
    {
        [$loanId, $itemId, $units] = $this->unitLoan(1);
        $units[0]->update(['code' => 'EQP-IC-009001']);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'units' => [['id' => $units[0]->id]]],
        ]])->assertUnprocessable()
            ->assertJsonPath('errors', ['items.0.units.0.cajon_id' => ['Escanea el cajón donde se guarda la unidad EQP-IC-009001.']]);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [['item_id' => $itemId, 'condition' => 'bueno']]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['items.0.units' => ['Escanea las unidades que se devuelven.']]);

        // El contrato anterior (`unit_ids`) ya no devuelve nada.
        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [['item_id' => $itemId, 'condition' => 'bueno', 'unit_ids' => [$units[0]->id]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.units');

        $this->assertSame('prestado', $units[0]->fresh()->status);
        $this->assertSame(0, LoanItem::findOrFail($itemId)->returned_quantity);
    }

    public function test_lost_unit_needs_no_cajon_and_keeps_its_cajon(): void
    {
        [$loanId, $itemId, $units] = $this->unitLoan(1);
        $before = $units[0]->cajon_id;

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'extraviado', 'units' => [['id' => $units[0]->id, 'cajon_id' => $this->shelf->id]]],
        ]])->assertOk()->assertJsonPath('data.is_returned', true);

        $this->assertSame('dado_de_baja', $units[0]->fresh()->status);
        $this->assertSame($before, $units[0]->fresh()->cajon_id);
    }

    public function test_only_scanned_units_are_returned(): void
    {
        [$loanId, $itemId, $units] = $this->unitLoan(2);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'units' => [['id' => $units[0]->id, 'cajon_id' => $this->shelf->id]]],
        ]])->assertOk()
            ->assertJsonPath('data.is_returned', false)
            ->assertJsonPath('data.items.0.returned_quantity', 1);

        $this->assertSame(['disponible', 'prestado'], $units->map(fn ($u) => $u->fresh()->status)->all());
        $this->assertNull(Loan::findOrFail($loanId)->returned_at);
        $this->assertSame(1, DB::table('loan_item_units')->where('loan_item_id', $itemId)->whereNull('returned_at')->count());
    }

    public function test_good_and_damaged_units_of_the_same_item_in_two_rows(): void
    {
        [$loanId, $itemId, $units] = $this->unitLoan(2);
        $repairShelf = Cajon::factory()->create(['location_id' => $this->shelf->location_id, 'codigo' => 'Taller']);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'units' => [['id' => $units[0]->id, 'cajon_id' => $this->shelf->id]]],
            ['item_id' => $itemId, 'condition' => 'dañado', 'units' => [['id' => $units[1]->id, 'cajon_id' => $repairShelf->id]]],
        ]])->assertOk()->assertJsonPath('data.is_returned', true);

        $this->assertSame(['disponible', 'en_reparacion'], $units->map(fn ($u) => $u->fresh()->status)->all());
        $this->assertSame([$this->shelf->id, $repairShelf->id], $units->map(fn ($u) => $u->fresh()->cajon_id)->all());
        $this->assertSame('danado', LoanItem::findOrFail($itemId)->return_condition);
    }

    public function test_supply_returned_to_its_registered_cajon(): void
    {
        $product = Product::factory()->supply()->create(['quantity' => 5, 'cajon_id' => $this->shelf->id, 'location_id' => $this->shelf->location_id]);
        $loan = $this->supplyLoan($product);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [
            ['product_id' => $product->id, 'condition' => 'bueno', 'cajon_id' => $this->shelf->id],
        ]])->assertOk()->assertJsonPath('data.is_returned', true);

        $this->assertSame(7, $product->fresh()->quantity);
    }

    public function test_supply_scanned_in_another_cajon_is_rejected(): void
    {
        $product = Product::factory()->supply()->create(['name' => 'Cable UTP', 'quantity' => 5, 'cajon_id' => $this->shelf->id]);
        $other = Cajon::factory()->create(['location_id' => $this->shelf->location_id, 'codigo' => 'Rack R2']);
        $loan = $this->supplyLoan($product);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [
            ['product_id' => $product->id, 'condition' => 'bueno', 'cajon_id' => $other->id],
        ]])->assertUnprocessable()
            ->assertJsonPath('errors', ['items.0.cajon_id' => ['Cable UTP se guarda en el cajón L206-RACK-R1; escanea ese cajón o reubica el producto.']]);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [['product_id' => $product->id, 'condition' => 'bueno']]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['items.0.cajon_id' => ['Escanea el cajón donde se guarda Cable UTP.']]);

        $this->assertSame(5, $product->fresh()->quantity);
    }

    public function test_supply_without_cajon_registers_the_scanned_one(): void
    {
        $product = Product::factory()->supply()->create(['quantity' => 5, 'cajon_id' => null, 'location_id' => null]);
        $loan = $this->supplyLoan($product);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [
            ['product_id' => $product->id, 'condition' => 'regular', 'cajon_id' => $this->shelf->id],
        ]])->assertOk()->assertJsonPath('data.items.0.cajon.barcode', 'L206-RACK-R1');

        $fresh = $product->fresh();
        $this->assertSame($this->shelf->id, $fresh->cajon_id);
        $this->assertSame($this->shelf->location_id, $fresh->location_id);
        $this->assertSame(7, $fresh->quantity);
    }

    public function test_lost_supply_needs_no_cajon(): void
    {
        $product = Product::factory()->supply()->create(['quantity' => 5]);
        $loan = $this->supplyLoan($product);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [['product_id' => $product->id, 'condition' => 'extraviado']]])
            ->assertOk();

        $this->assertSame(2, $product->fresh()->quantity_written_off);
    }

    public function test_cajon_of_another_storeroom_is_rejected(): void
    {
        $foreign = $this->foreignCajon();

        [$loanId, $itemId, $units] = $this->unitLoan(1);
        $this->postJson('/api/loans/checkin', ['loan_id' => $loanId, 'items' => [
            ['item_id' => $itemId, 'condition' => 'bueno', 'units' => [['id' => $units[0]->id, 'cajon_id' => $foreign->id]]],
        ]])->assertUnprocessable()
            ->assertJsonPath('errors', ['items.0.units.0.cajon_id' => ["El cajón {$foreign->barcode} no pertenece al pañol del préstamo."]]);
        $this->assertSame('prestado', $units[0]->fresh()->status);

    }

    /**
     * Los insumos no se controlan por pañol: su cajón registrado puede estar en otro pañol que el
     * del préstamo y la devolución a ese cajón es válida (caso real: cable en el Pañol de Redes).
     */
    public function test_supply_registered_in_another_storeroom_is_returned_to_its_cajon(): void
    {
        $foreign = $this->foreignCajon();
        $product = Product::factory()->supply()->create(['quantity' => 5, 'cajon_id' => $foreign->id, 'location_id' => $foreign->location_id]);
        $loan = $this->supplyLoan($product);

        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [
            ['product_id' => $product->id, 'condition' => 'bueno', 'cajon_id' => $foreign->id],
        ]])->assertOk()->assertJsonPath('data.is_returned', true);

        $this->assertSame($foreign->id, $product->fresh()->cajon_id);
    }

    public function test_show_and_index_include_the_full_code_of_item_and_unit_cajones(): void
    {
        $product = Product::factory()->supply()->create(['quantity' => 5, 'cajon_id' => $this->shelf->id, 'location_id' => $this->shelf->location_id]);
        $loan = $this->supplyLoan($product);
        [$unitLoanId] = $this->unitLoan(1);

        $this->getJson("/api/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.items.0.cajon.id', $this->shelf->id)
            ->assertJsonPath('data.items.0.cajon.barcode', 'L206-RACK-R1');

        $unitCajon = EquipmentUnit::findOrFail($this->getJson("/api/loans/{$unitLoanId}")->json('data.items.0.units.0.id'))->cajon;
        $this->getJson("/api/loans/{$unitLoanId}")->assertJsonPath('data.items.0.units.0.cajon.barcode', $unitCajon->barcode);

        $this->getJson('/api/loans?search='.$loan->code)->assertOk()
            ->assertJsonPath('data.0.items.0.cajon.barcode', 'L206-RACK-R1');
    }
}
