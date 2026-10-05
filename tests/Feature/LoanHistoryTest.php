<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $otherTeacher;

    protected User $warehouse;

    /** @var array<int, Product> */
    protected array $products = [];

    /** @var array<string, Loan> */
    protected array $loans = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'name' => 'Gonzalo Histórico',
            'email' => 'docente_hist_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->otherTeacher = User::factory()->create([
            'name' => 'Marcela Vera',
            'email' => 'docente2_hist_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'panol_hist_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->products = [
            'multimetro' => Product::factory()->create(['name' => 'Multímetro Digital Fluke', 'quantity' => 30]),
            'soldador' => Product::factory()->create(['name' => 'Soldador de Pistola 60W', 'quantity' => 15]),
        ];

        $this->loans = [
            'pendiente' => $this->makeLoan([
                'requested_by' => $this->teacher->id,
                'status' => Loan::STATUS_PENDING,
                'room' => 'Sala 201',
                'borrower_name' => 'Gonzalo Histórico',
            ], 'multimetro'),
            'en_proceso' => $this->makeLoan([
                'requested_by' => $this->teacher->id,
                'status' => Loan::STATUS_IN_PROGRESS,
                'room' => 'Taller B',
                'borrower_name' => 'Gonzalo Histórico',
            ], 'soldador'),
            'procesado' => $this->makeLoan([
                'requested_by' => $this->otherTeacher->id,
                'status' => Loan::STATUS_PROCESSED,
                'room' => 'Sala 201',
                'borrower_name' => 'Marcela Vera',
            ], 'multimetro'),
            'rechazado' => $this->makeLoan([
                'requested_by' => $this->otherTeacher->id,
                'status' => Loan::STATUS_REJECTED,
                'room' => 'Taller A',
                'borrower_name' => 'Marcela Vera',
            ], 'soldador'),
        ];
    }

    protected function makeLoan(array $attributes, string $productKey): Loan
    {
        $loan = Loan::factory()->create($attributes);

        LoanItem::create([
            'loan_id' => $loan->id,
            'product_id' => $this->products[$productKey]->id,
            'name' => $this->products[$productKey]->name,
            'quantity' => 1,
        ]);

        return $loan;
    }

    public function test_warehouse_can_list_loans_with_filters(): void
    {
        Sanctum::actingAs($this->warehouse);

        // Listado completo
        $all = $this->getJson('/api/loans');
        $all->assertStatus(200);
        $this->assertCount(4, $all->json('data'));

        // Filtro por sala
        $byRoom = $this->getJson('/api/loans?sala=Sala 201');
        $byRoom->assertStatus(200);
        $this->assertCount(2, $byRoom->json('data'));

        // Filtro por profesor (nombre del docente solicitante / retiro)
        $byTeacher = $this->getJson('/api/loans?profesor=Gonzalo');
        $byTeacher->assertStatus(200);
        $this->assertCount(2, $byTeacher->json('data'));

        $byBorrower = $this->getJson('/api/loans?profesor=Marcela Vera');
        $byBorrower->assertStatus(200);
        $this->assertCount(2, $byBorrower->json('data'));

        // Filtro por insumo / producto
        $byProduct = $this->getJson('/api/loans?insumo=Multímetro');
        $byProduct->assertStatus(200);
        $this->assertCount(2, $byProduct->json('data'));

        $byProductId = $this->getJson('/api/loans?product_id=' . $this->products['soldador']->id);
        $byProductId->assertStatus(200);
        $this->assertCount(2, $byProductId->json('data'));

        // Filtro por estado
        $byStatus = $this->getJson('/api/loans?estado=en_proceso');
        $byStatus->assertStatus(200);
        $this->assertCount(1, $byStatus->json('data'));
        $byStatus->assertJsonPath('data.0.id', $this->loans['en_proceso']->id);

        // Filtro por tipo
        $byType = $this->getJson('/api/loans?tipo=remoto');
        $byType->assertStatus(200);
        $this->assertCount(4, $byType->json('data'));

        // Búsqueda por código
        $bySearch = $this->getJson('/api/loans?search=' . $this->loans['procesado']->code);
        $bySearch->assertStatus(200);
        $this->assertCount(1, $bySearch->json('data'));

        // Estado o tipo inválidos
        $this->getJson('/api/loans?estado=inexistente')
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $this->getJson('/api/loans?tipo=inexistente')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
    }

    public function test_response_explicitly_distinguishes_processed_from_in_progress(): void
    {
        Sanctum::actingAs($this->warehouse);

        $response = $this->getJson('/api/loans');
        $response->assertStatus(200);

        $byId = collect($response->json('data'))->keyBy('id');

        // Estado explícito + booleanos para el estilo visual del cliente
        $procesado = $byId->get($this->loans['procesado']->id);
        $this->assertEquals(Loan::STATUS_PROCESSED, $procesado['status']);
        $this->assertTrue($procesado['is_processed']);
        $this->assertFalse($procesado['is_in_progress']);
        $this->assertEquals('Procesado', $procesado['status_label']);

        $enProceso = $byId->get($this->loans['en_proceso']->id);
        $this->assertEquals(Loan::STATUS_IN_PROGRESS, $enProceso['status']);
        $this->assertTrue($enProceso['is_in_progress']);
        $this->assertFalse($enProceso['is_processed']);
        $this->assertEquals('En proceso', $enProceso['status_label']);

        $pendiente = $byId->get($this->loans['pendiente']->id);
        $this->assertTrue($pendiente['is_pending']);

        $rechazado = $byId->get($this->loans['rechazado']->id);
        $this->assertTrue($rechazado['is_rejected']);
        $this->assertEquals([], $rechazado['allowed_transitions']);
    }

    public function test_loan_detail_includes_items_people_and_transitions(): void
    {
        Sanctum::actingAs($this->warehouse);

        $loan = $this->loans['en_proceso'];

        $response = $this->getJson("/api/loans/{$loan->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $loan->id);
        $response->assertJsonPath('data.status', Loan::STATUS_IN_PROGRESS);
        $response->assertJsonPath('data.requester.id', $this->teacher->id);
        $response->assertJsonPath('data.items.0.name', $this->products['soldador']->name);
        $response->assertJsonPath('data.items.0.available_stock', 15);
        $response->assertJsonPath('data.allowed_transitions.0', Loan::STATUS_PROCESSED);
        $response->assertJsonPath('data.items.0.location.sala', $this->products['soldador']->location->sala);

        $this->getJson('/api/loans/999999')->assertStatus(404);
    }

    public function test_loan_history_is_restricted_to_warehouse_director_and_admin(): void
    {
        // Docente no accede al historial general
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/loans')->assertStatus(403);
        $this->getJson("/api/loans/{$this->loans['pendiente']->id}")->assertStatus(403);

        // Director sí
        Sanctum::actingAs(User::factory()->create([
            'email' => 'dir_hist_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]));
        $this->getJson('/api/loans')->assertStatus(200);

        // Administrador sí
        Sanctum::actingAs(User::factory()->create([
            'email' => 'admin_hist_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]));
        $this->getJson('/api/loans')->assertStatus(200);
        $this->getJson("/api/loans/{$this->loans['pendiente']->id}")->assertStatus(200);
    }
}
