<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected User $director;

    protected User $teacherA;

    protected User $teacherB;

    protected Product $multimetro;

    protected Product $osciloscopio;

    protected Product $neverUsed;

    protected const ENDPOINTS = [
        'top-products',
        'top-supplies',
        'careers-distribution',
        'least-demanded',
        'top-teachers',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create([
            'email' => 'dir_dash_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'area' => 'Informática',
            'is_active' => true,
        ]);

        $this->teacherA = User::factory()->create([
            'email' => 'pro_dash_a_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'area' => 'Electricidad',
            'is_active' => true,
        ]);

        $this->teacherB = User::factory()->create([
            'email' => 'pro_dash_b_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'area' => 'Informática',
            'is_active' => true,
        ]);

        $this->multimetro = Product::factory()->create(['name' => 'Multimetro Digital']);
        $this->osciloscopio = Product::factory()->create(['name' => 'Osciloscopio Basico']);
        $this->neverUsed = Product::factory()->create(['name' => 'Generador de Funciones']);

        // teacherA: 3 solicitudes con 2 unidades del multimetro cada una.
        $this->loanWith($this->teacherA, [[$this->multimetro, 2]]);
        $this->loanWith($this->teacherA, [[$this->multimetro, 2]]);
        $this->loanWith($this->teacherA, [[$this->multimetro, 2], [$this->osciloscopio, 1]]);

        // teacherB: 1 solicitud con 10 unidades del osciloscopio.
        $this->loanWith($this->teacherB, [[$this->osciloscopio, 10]]);

        // Solicitud rechazada: no debe contar en ningún indicador.
        $this->loanWith($this->teacherB, [[$this->multimetro, 50]], Loan::STATUS_REJECTED);
    }

    /**
     * Crea un préstamo con sus ítems para el solicitante indicado.
     *
     * @param  array<int, array{0: Product, 1: int}>  $items
     */
    private function loanWith(User $requester, array $items, string $status = Loan::STATUS_PROCESSED): Loan
    {
        $loan = Loan::factory()->create([
            'requested_by' => $requester->id,
            'status' => $status,
        ]);

        foreach ($items as [$product, $quantity]) {
            $loan->items()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
            ]);
        }

        return $loan;
    }

    public function test_guest_cannot_access_dashboards(): void
    {
        $this->getJson('/api/dashboard/top-products')->assertStatus(401);
    }

    public function test_teacher_and_warehouse_cannot_access_dashboards(): void
    {
        $warehouse = User::factory()->create([
            'email' => 'pan_dash_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        foreach (self::ENDPOINTS as $endpoint) {
            Sanctum::actingAs($this->teacherA);
            $this->getJson("/api/dashboard/{$endpoint}")->assertStatus(403);

            Sanctum::actingAs($warehouse);
            $this->getJson("/api/dashboard/{$endpoint}")->assertStatus(403);
        }
    }

    public function test_director_can_access_all_dashboard_endpoints(): void
    {
        Sanctum::actingAs($this->director);

        foreach (self::ENDPOINTS as $endpoint) {
            $response = $this->getJson("/api/dashboard/{$endpoint}");

            $response->assertStatus(200);
            $response->assertJsonStructure(['data', 'generated_at']);
        }
    }

    public function test_top_products_ranks_by_number_of_requests(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/dashboard/top-products');

        $response->assertStatus(200);

        $rows = collect($response->json('data'));

        // El multimetro fue pedido en 3 solicitudes; el osciloscopio en 2.
        $this->assertSame($this->multimetro->id, $rows[0]['product_id']);
        $this->assertSame(3, $rows[0]['requests']);
        $this->assertSame(6, $rows[0]['units_lent']);
        $this->assertSame($this->osciloscopio->id, $rows[1]['product_id']);
        $this->assertSame(2, $rows[1]['requests']);

        // El equipo nunca prestado no aparece en este ranking.
        $this->assertFalse($rows->contains('product_id', $this->neverUsed->id));
    }

    public function test_top_supplies_ranks_by_units_lent(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/dashboard/top-supplies');

        $response->assertStatus(200);

        $rows = collect($response->json('data'));

        // El osciloscopio concentra más unidades entregadas (1 + 10 = 11).
        $this->assertSame($this->osciloscopio->id, $rows[0]['product_id']);
        $this->assertSame(11, $rows[0]['units_lent']);
        $this->assertSame($this->multimetro->id, $rows[1]['product_id']);
        $this->assertSame(6, $rows[1]['units_lent']);
    }

    public function test_careers_distribution_groups_loans_by_area(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/dashboard/careers-distribution');

        $response->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('area');

        $this->assertArrayHasKey('Electricidad', $rows);
        $this->assertArrayHasKey('Informática', $rows);

        // teacherA (Electricidad): 3 préstamos, 7 unidades (2+2+2+1).
        $this->assertSame(3, $rows['Electricidad']['loans']);
        $this->assertSame(7, $rows['Electricidad']['units_lent']);

        // teacherB (Informática): solo su préstamo no rechazado (10 unidades).
        $this->assertSame(1, $rows['Informática']['loans']);
        $this->assertSame(10, $rows['Informática']['units_lent']);
    }

    public function test_least_demanded_includes_unused_equipment(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/dashboard/least-demanded');

        $response->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('product_id');

        // Nunca prestado → 0 unidades y 0 solicitudes.
        $this->assertSame(0, $rows[$this->neverUsed->id]['units_lent']);
        $this->assertSame(0, $rows[$this->neverUsed->id]['requests']);

        // Orden ascendente: sin uso < menos unidades (multimetro 6) <
        // más unidades (osciloscopio 11).
        $ids = collect($response->json('data'))->pluck('product_id');
        $this->assertLessThan(
            $ids->search($this->multimetro->id),
            $ids->search($this->neverUsed->id)
        );
        $this->assertLessThan(
            $ids->search($this->osciloscopio->id),
            $ids->search($this->multimetro->id)
        );
    }

    public function test_top_teachers_ranks_by_number_of_requests(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/dashboard/top-teachers');

        $response->assertStatus(200);

        $rows = collect($response->json('data'));

        $this->assertSame($this->teacherA->id, $rows[0]['user_id']);
        $this->assertSame(3, $rows[0]['requests']);
        $this->assertSame(7, $rows[0]['units_lent']);
        $this->assertSame('Electricidad', $rows[0]['area']);

        $this->assertSame($this->teacherB->id, $rows[1]['user_id']);
        // Solo su solicitud no rechazada: la rechazada con 50 unidades no cuenta.
        $this->assertSame(1, $rows[1]['requests']);
        $this->assertSame(10, $rows[1]['units_lent']);
    }

    public function test_dashboard_limit_query_param_is_applied(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->getJson('/api/dashboard/least-demanded?limit=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }
}
