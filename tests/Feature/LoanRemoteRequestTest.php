<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\EquipmentUnitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Solicitud remota del profesor ampliada: carrera, horario, laboratorio y pañol (REQ-21 web del profesor).
 */
class LoanRemoteRequestTest extends TestCase
{
    use DatabaseTransactions;

    private User $teacher;

    private Storeroom $storeroom;

    private Location $lab;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $this->storeroom = Storeroom::factory()->create();
        $this->lab = Location::factory()->create(['tipo' => Location::TIPO_SALA, 'storeroom_id' => $this->storeroom->id, 'nombre' => 'Laboratorio 3']);
        $this->product = Product::factory()->supply()->create(['quantity' => 20, 'is_active' => true]);
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return $override + [
            'career_id' => Career::factory()->create()->id,
            'subject' => 'Redes de Computadores',
            'loan_date' => today()->addDays(2)->toDateString(),
            'start_time' => '08:30',
            'end_time' => '10:00',
            'storeroom_id' => $this->storeroom->id,
            'location_id' => $this->lab->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
            'notes' => 'Traer cables UTP.',
        ];
    }

    public function test_teacher_creates_a_full_request_and_the_teacher_comes_from_the_token(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $payload = $this->payload();
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/loans/requests', $payload + ['user_id' => $other->id, 'teacher_id' => $other->id, 'requested_by' => $other->id])
            ->assertCreated()
            ->assertJsonPath('message', 'Solicitud enviada correctamente.')
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.career_id', $payload['career_id'])
            ->assertJsonPath('data.storeroom_id', $this->storeroom->id)
            ->assertJsonPath('data.storeroom.name', $this->storeroom->name)
            ->assertJsonPath('data.location_id', $this->lab->id)
            ->assertJsonPath('data.location.nombre', 'Laboratorio 3')
            ->assertJsonPath('data.location.tipo', 'sala')
            ->assertJsonPath('data.start_time', '08:30')
            ->assertJsonPath('data.end_time', '10:00')
            ->assertJsonPath('data.time_block', '08:30 - 10:00')
            ->assertJsonPath('data.room', 'Laboratorio 3')
            ->assertJsonPath('data.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.items.0.product.id', $this->product->id)
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->assertSame($response->json('data'), $response->json('loan'));
        $this->assertDatabaseHas('loans', ['id' => $response->json('data.id'), 'requested_by' => $this->teacher->id]);
        $this->assertSame('Laboratorio 3', Loan::findOrFail($response->json('data.id'))->room);
    }

    public function test_teacher_without_assigned_careers_can_request_for_any_career(): void
    {
        $this->assertCount(0, $this->teacher->careers);
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/loans/requests', $this->payload())->assertCreated();
    }

    public function test_each_rule_answers_422_with_the_field(): void
    {
        Sanctum::actingAs($this->teacher);
        $post = fn (array $override, string $field) => $this->postJson('/api/loans/requests', $override + $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors($field);

        $post(['career_id' => null], 'career_id');
        $post(['career_id' => 999999], 'career_id');
        $post(['subject' => null], 'subject');
        $post(['subject' => str_repeat('a', 256)], 'subject');
        $post(['loan_date' => today()->subDays(2)->toDateString()], 'loan_date');
        $post(['loan_date' => '05/11/2026'], 'loan_date');
        $post(['start_time' => null], 'start_time');
        $post(['start_time' => '8:30 am'], 'start_time');
        $post(['end_time' => '08:30'], 'end_time');   // igual al inicio
        $post(['end_time' => '07:00'], 'end_time');   // anterior al inicio
        $post(['storeroom_id' => null, 'location_id' => $this->lab->id, 'room' => null], 'storeroom_id');
        $post(['location_id' => null, 'storeroom_id' => $this->storeroom->id], 'location_id');
        $post(['items' => []], 'items');
        $post(['items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->product->id, 'quantity' => 2]]], 'items.0.product_id');
        $post(['items' => [['product_id' => $this->product->id, 'quantity' => 0]]], 'items.0.quantity');
        $post(['items' => [['product_id' => $this->product->id, 'quantity' => 21]]], 'items.0.quantity'); // stock insuficiente

        $inactive = Product::factory()->supply()->create(['quantity' => 5, 'is_active' => false]);
        $post(['items' => [['product_id' => $inactive->id, 'quantity' => 1]]], 'items.0.product_id');

        $this->assertDatabaseMissing('loans', ['requested_by' => $this->teacher->id]);
    }

    public function test_equipment_availability_counts_only_units_of_the_requested_storeroom(): void
    {
        $equipment = Product::factory()->create(['quantity' => 0, 'stock_minimo' => 0, 'is_active' => true, 'name' => 'Router Cisco 1941']);
        $own = Cajon::factory()->create(['location_id' => $this->lab->id]);
        $foreign = Cajon::factory()->create(['location_id' => Location::factory()->create(['storeroom_id' => Storeroom::factory()->create()->id])->id]);
        EquipmentUnit::factory()->create(['product_id' => $equipment->id, 'cajon_id' => $own->id]);
        EquipmentUnit::factory()->count(3)->create(['product_id' => $equipment->id, 'cajon_id' => $foreign->id]);
        app(EquipmentUnitService::class)->syncProductCounters($equipment);
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/loans/requests', $this->payload(['items' => [['product_id' => $equipment->id, 'quantity' => 2]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity' => "Stock insuficiente para \"Router Cisco 1941\" en {$this->storeroom->name}: se solicitaron 2 y hay 1 disponibles."]);

        $this->postJson('/api/loans/requests', $this->payload(['items' => [['product_id' => $equipment->id, 'quantity' => 1]]]))
            ->assertCreated();
    }

    public function test_laboratory_must_be_a_room_or_workshop_of_the_selected_storeroom(): void
    {
        Sanctum::actingAs($this->teacher);
        $otherStoreroom = Storeroom::factory()->create();
        $foreign = Location::factory()->create(['tipo' => Location::TIPO_SALA, 'storeroom_id' => $otherStoreroom->id]);
        $warehouse = Location::factory()->create(['tipo' => Location::TIPO_PANOL, 'storeroom_id' => $this->storeroom->id]);
        $bodega = Location::factory()->create(['tipo' => Location::TIPO_BODEGA, 'storeroom_id' => $this->storeroom->id]);
        $workshop = Location::factory()->create(['tipo' => Location::TIPO_TALLER, 'storeroom_id' => $this->storeroom->id]);

        foreach ([$foreign, $warehouse, $bodega] as $invalid) {
            $this->postJson('/api/loans/requests', $this->payload(['location_id' => $invalid->id]))
                ->assertStatus(422)->assertJsonValidationErrors('location_id');
        }

        $this->postJson('/api/loans/requests', $this->payload(['location_id' => $workshop->id]))->assertCreated();
    }

    public function test_old_clients_with_only_time_block_and_room_keep_working(): void
    {
        Sanctum::actingAs($this->teacher);
        $legacy = [
            'career_id' => Career::factory()->create()->id, 'subject' => 'Taller',
            'room' => 'Sala 201', 'loan_date' => today()->addDay()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];

        // Texto libre: se conserva tal cual, sin horario ni laboratorio.
        $this->postJson('/api/loans/requests', $legacy + ['time_block' => 'Bloque 1-3'])
            ->assertCreated()
            ->assertJsonPath('data.room', 'Sala 201')
            ->assertJsonPath('data.time_block', 'Bloque 1-3')
            ->assertJsonPath('data.start_time', null)
            ->assertJsonPath('data.location_id', null);

        // "HH:MM - HH:MM": se derivan las horas.
        $this->postJson('/api/loans/requests', $legacy + ['time_block' => '9:00 - 10:30'])
            ->assertCreated()
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '10:30')
            ->assertJsonPath('data.time_block', '09:00 - 10:30');

        // Si llegan las horas, se ignora el time_block y se regenera.
        $this->postJson('/api/loans/requests', $this->payload(['time_block' => 'cualquier cosa']))
            ->assertCreated()->assertJsonPath('data.time_block', '08:30 - 10:00');

        // Sin pañol explícito cae en el pañol por defecto.
        $this->assertSame(Storeroom::default()->id, Loan::where('room', 'Sala 201')->first()->storeroom_id);
    }

    public function test_only_teachers_create_requests_and_see_their_own(): void
    {
        foreach ([User::ROLE_WAREHOUSE, User::ROLE_DIRECTOR, User::ROLE_ADMIN] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->postJson('/api/loans/requests', $this->payload())->assertForbidden();
        }

        Sanctum::actingAs($this->teacher);
        $id = $this->postJson('/api/loans/requests', $this->payload())->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));
        $this->getJson('/api/loans/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/loans/requests/$id")->assertForbidden();

        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/loans/requests/$id")->assertNoContent();
    }

    public function test_loan_date_uses_chile_today_when_utc_is_already_tomorrow(): void
    {
        // 01:00 UTC del 10 de noviembre son las 22:00 del 9 de noviembre en Temuco.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 01:00:00', 'UTC'));
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/loans/requests', $this->payload(['loan_date' => '2026-11-09']))
            ->assertCreated();

        $this->postJson('/api/loans/requests', $this->payload(['loan_date' => '2026-11-08']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('loan_date');
    }

    public function test_my_requests_filters_by_storeroom_career_and_date(): void
    {
        Sanctum::actingAs($this->teacher);
        $career = Career::factory()->create();
        $otherStoreroom = Storeroom::factory()->create();
        $otherLab = Location::factory()->create(['tipo' => Location::TIPO_SALA, 'storeroom_id' => $otherStoreroom->id]);
        $date = today()->addDays(5)->toDateString();

        $a = $this->postJson('/api/loans/requests', $this->payload(['career_id' => $career->id, 'loan_date' => $date]))->assertCreated()->json('data.id');
        $b = $this->postJson('/api/loans/requests', $this->payload(['storeroom_id' => $otherStoreroom->id, 'location_id' => $otherLab->id]))->assertCreated()->json('data.id');

        $ids = fn (string $query) => collect($this->getJson("/api/loans/requests?$query")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a], $ids("career_id={$career->id}"));
        $this->assertEqualsCanonicalizing([$a], $ids("loan_date=$date"));
        $this->assertEqualsCanonicalizing([$b], $ids("storeroom_id={$otherStoreroom->id}"));
        $this->assertEqualsCanonicalizing([$a], $ids("storeroom_id={$this->storeroom->id}"));
        $this->assertEqualsCanonicalizing([$a, $b], $ids('status=pendiente'));
    }

    public function test_warehouse_queue_shows_only_its_storeroom_and_admin_sees_all(): void
    {
        Sanctum::actingAs($this->teacher);
        $otherStoreroom = Storeroom::factory()->create();
        $otherLab = Location::factory()->create(['tipo' => Location::TIPO_SALA, 'storeroom_id' => $otherStoreroom->id]);
        $mine = $this->postJson('/api/loans/requests', $this->payload())->assertCreated()->json('data.id');
        $theirs = $this->postJson('/api/loans/requests', $this->payload(['storeroom_id' => $otherStoreroom->id, 'location_id' => $otherLab->id]))->assertCreated()->json('data.id');

        $queue = fn () => collect($this->getJson('/api/loans/pending?per_page=100')->assertOk()->json('data'));

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true, 'storeroom_id' => $this->storeroom->id]));
        $rows = $queue();
        $this->assertSame([$mine], $rows->pluck('id')->all());
        // La cola muestra pañol, laboratorio, horario y carrera.
        $this->assertSame('08:30', $rows[0]['start_time']);
        $this->assertSame('Laboratorio 3', $rows[0]['location']['nombre']);
        $this->assertSame($this->storeroom->id, $rows[0]['storeroom']['id']);
        $this->assertNotNull($rows[0]['career_id']);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->assertEqualsCanonicalizing([$mine, $theirs], $queue()->pluck('id')->intersect([$mine, $theirs])->all());
        $this->getJson("/api/loans/pending?storeroom_id={$otherStoreroom->id}&per_page=100")
            ->assertOk()->assertJsonPath('data.0.id', $theirs);
    }

    public function test_teacher_reads_the_catalogs_for_the_form(): void
    {
        Sanctum::actingAs($this->teacher);
        $taller = Location::factory()->create(['tipo' => Location::TIPO_TALLER, 'storeroom_id' => $this->storeroom->id]);
        Location::factory()->create(['tipo' => Location::TIPO_SALA, 'storeroom_id' => Storeroom::factory()->create()->id]);

        $this->getJson('/api/storerooms')->assertOk();
        $this->getJson('/api/careers')->assertOk();
        $this->getJson('/api/products?is_active=1')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'barcode', 'quantity']]]);

        $locations = $this->getJson("/api/locations?tipo=taller&storeroom_id={$this->storeroom->id}&per_page=100")->assertOk();
        $this->assertSame([$taller->id], collect($locations->json('data'))->pluck('id')->all());
        $locations->assertJsonStructure(['data' => [['id', 'nombre', 'tipo', 'storeroom_id']]]);
    }

    public function test_migration_backfills_hours_from_time_block(): void
    {
        $loan = Loan::factory()->create(['time_block' => '08:30 - 10:00']);
        \DB::table('loans')->where('id', $loan->id)->update(['start_time' => null, 'end_time' => null]);

        \DB::statement(<<<'SQL'
            UPDATE loans
            SET start_time = (substring(time_block from '^\s*(\d{1,2}:\d{2})'))::time,
                end_time = (substring(time_block from '(\d{1,2}:\d{2})\s*$'))::time
            WHERE id = ? AND time_block ~ '^\s*([01]?\d|2[0-3]):[0-5]\d\s*-\s*([01]?\d|2[0-3]):[0-5]\d\s*$'
        SQL, [$loan->id]);

        $this->assertSame('08:30', substr((string) $loan->fresh()->start_time, 0, 5));
        $this->assertSame('10:00', substr((string) $loan->fresh()->end_time, 0, 5));
    }
}
