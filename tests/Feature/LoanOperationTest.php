<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LoanStatusNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanOperationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $warehouse;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'email' => 'docente_ops_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_ops_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin_ops_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'quantity' => 20,
            'stock_minimo' => 2,
        ]);
    }

    protected function makeRemoteLoan(array $attributes = [], int $quantity = 3): Loan
    {
        $loan = Loan::factory()->create(array_merge([
            'requested_by' => $this->teacher->id,
            'status' => Loan::STATUS_PENDING,
            'type' => Loan::TYPE_REMOTE,
        ], $attributes));

        LoanItem::create([
            'loan_id' => $loan->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => $quantity,
        ]);

        return $loan;
    }

    public function test_warehouse_lists_pending_requests_with_stock_and_location(): void
    {
        $pending = $this->makeRemoteLoan();
        $this->makeRemoteLoan(['status' => Loan::STATUS_IN_PROGRESS]);
        $this->makeRemoteLoan(['type' => Loan::TYPE_DIRECT, 'status' => Loan::STATUS_PROCESSED]);

        Sanctum::actingAs($this->warehouse);

        $response = $this->getJson('/api/loans/pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $pending->id);
        $response->assertJsonPath('data.0.status', Loan::STATUS_PENDING);

        // Cantidad solicitada, disponible y ubicación física (REQ-10)
        $response->assertJsonPath('data.0.items.0.quantity', 3);
        $response->assertJsonPath('data.0.items.0.available_stock', 20);
        $response->assertJsonPath('data.0.items.0.location.sala', $this->product->location->sala);
        $response->assertJsonPath('data.0.items.0.location.cajon', $this->product->location->cajon);
    }

    public function test_approve_discounts_stock_and_notifies_requester(): void
    {
        Notification::fake();

        $loan = $this->makeRemoteLoan(quantity: 4);

        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson("/api/loans/{$loan->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('loan.status', Loan::STATUS_IN_PROGRESS);
        $response->assertJsonPath('loan.approved_by', $this->warehouse->id);

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => Loan::STATUS_IN_PROGRESS,
            'approved_by' => $this->warehouse->id,
        ]);

        // Stock descontado: 20 - 4
        $this->assertEquals(16, $this->product->fresh()->quantity);

        Notification::assertSentTo($this->teacher, LoanStatusNotification::class);
    }

    public function test_approve_fails_when_stock_is_not_enough(): void
    {
        $loan = $this->makeRemoteLoan(quantity: 5);

        // El stock baja después de crear la solicitud
        $this->product->update(['quantity' => 2]);

        Sanctum::actingAs($this->warehouse);

        $this->postJson("/api/loans/{$loan->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertEquals(2, $this->product->fresh()->quantity);
        $this->assertEquals(Loan::STATUS_PENDING, $loan->fresh()->status);
    }

    public function test_approve_invalid_transition_returns_422(): void
    {
        $loan = $this->makeRemoteLoan(['status' => Loan::STATUS_PROCESSED], quantity: 1);

        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson("/api/loans/{$loan->id}/approve");

        $response->assertStatus(422);
        $response->assertJsonPath('current_status', Loan::STATUS_PROCESSED);
        $this->assertEquals(20, $this->product->fresh()->quantity);
    }

    public function test_reject_requires_reason_and_notifies_requester(): void
    {
        Notification::fake();

        $loan = $this->makeRemoteLoan();

        Sanctum::actingAs($this->warehouse);

        $this->postJson("/api/loans/{$loan->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rejection_reason');

        $response = $this->postJson("/api/loans/{$loan->id}/reject", [
            'rejection_reason' => 'Material reservado para la sección de madrugada.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('loan.status', Loan::STATUS_REJECTED);

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => Loan::STATUS_REJECTED,
            'rejection_reason' => 'Material reservado para la sección de madrugada.',
        ]);

        // El stock no se descuenta en un rechazo
        $this->assertEquals(20, $this->product->fresh()->quantity);

        Notification::assertSentTo($this->teacher, LoanStatusNotification::class);
    }

    public function test_warehouse_registers_direct_on_site_loan(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson('/api/loans/checkout', [
            'borrower_name' => 'Estudiante Javiera Peña',
            'borrower_document' => '12345678-9',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'subject' => 'Electricidad Básica',
            'room' => 'Taller A',
            'loan_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', Loan::STATUS_PROCESSED);
        $response->assertJsonPath('data.type', Loan::TYPE_DIRECT);
        $response->assertJsonPath('data.borrower_name', 'Estudiante Javiera Peña');
        $response->assertJsonPath('data.processed_by', $this->warehouse->id);
        $this->assertStringStartsWith('PRE-', $response->json('data.code'));

        $this->assertEquals(18, $this->product->fresh()->quantity);
        $this->assertDatabaseHas('loans', [
            'id' => $response->json('data.id'),
            'status' => Loan::STATUS_PROCESSED,
            'room' => 'Taller A',
        ]);
    }

    public function test_checkout_validates_borrower_and_stock(): void
    {
        Sanctum::actingAs($this->warehouse);

        $base = [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'subject' => 'Electricidad',
            'room' => 'Taller A',
            'loan_date' => now()->toDateString(),
        ];

        $this->postJson('/api/loans/checkout', $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors('borrower_name');

        $this->postJson('/api/loans/checkout', array_merge($base, [
            'borrower_name' => 'Prof. Soto',
            'items' => [['product_id' => $this->product->id, 'quantity' => 25]],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->assertEquals(20, $this->product->fresh()->quantity);
    }

    public function test_deliver_moves_loan_to_processed_and_notifies(): void
    {
        Notification::fake();

        $loan = $this->makeRemoteLoan(['status' => Loan::STATUS_IN_PROGRESS], quantity: 2);

        Sanctum::actingAs($this->warehouse);

        $response = $this->postJson("/api/loans/{$loan->id}/deliver");

        $response->assertStatus(200);
        $response->assertJsonPath('loan.status', Loan::STATUS_PROCESSED);

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => Loan::STATUS_PROCESSED,
            'processed_by' => $this->warehouse->id,
        ]);

        Notification::assertSentTo($this->teacher, LoanStatusNotification::class);

        // Entregar no vuelve a descontar stock
        $this->assertEquals(20, $this->product->fresh()->quantity);
    }

    public function test_only_warehouse_and_admin_can_operate_loans(): void
    {
        $loan = $this->makeRemoteLoan();

        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/loans/pending')->assertStatus(403);
        $this->postJson("/api/loans/{$loan->id}/approve")->assertStatus(403);
        $this->postJson("/api/loans/{$loan->id}/reject", ['rejection_reason' => 'Motivo de rechazo'])
            ->assertStatus(403);
        $this->postJson('/api/loans/checkout', [
            'borrower_name' => 'Docente',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'subject' => 'Taller',
            'room' => 'Sala 1',
            'loan_date' => now()->toDateString(),
        ])->assertStatus(403);

        // El director no opera préstamos según el catálogo
        Sanctum::actingAs(User::factory()->create([
            'email' => 'dir_ops_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]));

        $this->getJson('/api/loans/pending')->assertStatus(403);

        // El administrador sí puede operar
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/loans/pending')->assertStatus(200);
    }
}
