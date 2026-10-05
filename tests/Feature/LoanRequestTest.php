<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanRequestTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $otherTeacher;

    protected User $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'email' => 'docente_prest_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->otherTeacher = User::factory()->create([
            'email' => 'docente2_prest_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_prest_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create(['quantity' => 20]);
    }

    public function test_teacher_can_send_remote_loan_request(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/loans/requests', [
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
            'subject' => 'Taller de Electricidad',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(3)->toDateString(),
            'time_block' => 'Bloque 1-3',
        ]);

        $response->assertStatus(201);

        // Debe confirmar explícitamente que la solicitud fue enviada (REQ-09)
        $this->assertStringContainsString(
            'solicitud enviada',
            strtolower((string) $response->json('message'))
        );

        $response->assertJsonPath('data.status', Loan::STATUS_PENDING);
        $response->assertJsonPath('data.type', Loan::TYPE_REMOTE);
        $response->assertJsonPath('data.requested_by', $this->teacher->id);
        $response->assertJsonPath('data.borrower_name', $this->teacher->name);
        $response->assertJsonPath('data.subject', 'Taller de Electricidad');
        $response->assertJsonPath('data.room', 'Sala 201');
        $response->assertJsonPath('data.items.0.name', $this->product->name);
        $response->assertJsonPath('data.items.0.quantity', 2);

        // El registro creado también viene en la clave `loan`
        $response->assertJsonPath('loan.id', $response->json('data.id'));

        $this->assertStringStartsWith('REM-', $response->json('data.code'));

        $this->assertDatabaseHas('loans', [
            'id' => $response->json('data.id'),
            'status' => Loan::STATUS_PENDING,
            'requested_by' => $this->teacher->id,
        ]);

        $this->assertDatabaseHas('loan_items', [
            'loan_id' => $response->json('data.id'),
            'product_id' => $this->product->id,
            'quantity' => 2,
        ]);
    }

    public function test_loan_request_requires_items_subject_room_and_date(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/loans/requests', [
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $base = [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];

        $this->postJson('/api/loans/requests', $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'room', 'loan_date']);

        $this->postJson('/api/loans/requests', array_merge($base, [
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
            'items' => [['product_id' => 999999, 'quantity' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->postJson('/api/loans/requests', array_merge($base, [
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 0]],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_loan_request_validates_stock_availability(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/loans/requests', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 50]],
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        $inactive = Product::factory()->create(['is_active' => false]);

        $this->postJson('/api/loans/requests', [
            'items' => [['product_id' => $inactive->id, 'quantity' => 1]],
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_loan_request_accepts_spanish_field_aliases(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/loans/requests', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'asignatura' => 'Electrónica de Potencia',
            'sala' => 'Taller B',
            'fecha' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.subject', 'Electrónica de Potencia');
        $response->assertJsonPath('data.room', 'Taller B');
        $response->assertJsonPath('data.loan_date', now()->addDays(2)->toDateString());
    }

    public function test_teacher_lists_own_requests_and_filters_by_status(): void
    {
        Sanctum::actingAs($this->teacher);

        Loan::factory()->create([
            'requested_by' => $this->teacher->id,
            'status' => Loan::STATUS_PENDING,
        ]);

        Loan::factory()->inProgress()->create([
            'requested_by' => $this->teacher->id,
        ]);

        // Solicitud de otro docente: no debe aparecer
        Loan::factory()->create([
            'requested_by' => $this->otherTeacher->id,
        ]);

        $all = $this->getJson('/api/loans/requests');
        $all->assertStatus(200);
        $this->assertCount(2, $all->json('data'));

        foreach ($all->json('data') as $loan) {
            $this->assertEquals($this->teacher->id, $loan['requested_by']);
        }

        $filtered = $this->getJson('/api/loans/requests?estado=en_proceso');
        $filtered->assertStatus(200);
        $this->assertCount(1, $filtered->json('data'));
        $filtered->assertJsonPath('data.0.status', Loan::STATUS_IN_PROGRESS);

        // Alias del catálogo: GET /api/loans/my-requests
        $alias = $this->getJson('/api/loans/my-requests');
        $alias->assertStatus(200);
        $this->assertCount(2, $alias->json('data'));

        $this->getJson('/api/loans/requests?estado=inexistente')
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_only_teachers_can_use_remote_loan_requests(): void
    {
        // Pañol no puede enviar ni listar solicitudes de docentes
        Sanctum::actingAs($this->warehouse);

        $this->postJson('/api/loans/requests', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
        ])->assertStatus(403);

        $this->getJson('/api/loans/requests')->assertStatus(403);

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_guest_cannot_send_remote_loan_request(): void
    {
        $this->postJson('/api/loans/requests', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'subject' => 'Taller',
            'room' => 'Sala 201',
            'loan_date' => now()->addDays(1)->toDateString(),
        ])->assertStatus(401);

        $this->getJson('/api/loans/requests')->assertStatus(401);

        $this->assertDatabaseCount('loans', 0);
    }
}
