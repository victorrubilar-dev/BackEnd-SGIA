<?php

namespace Tests\Feature;

use App\Http\Resources\ProductResource;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanReturnTest extends TestCase
{
    use DatabaseTransactions;

    protected User $warehouse;

    protected User $teacher;

    protected User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    private function deliveredLoan(array $lines, array $attributes = []): Loan
    {
        $loan = Loan::factory()->processed()->create($attributes + [
            'requested_by' => $this->teacher->id,
            'loan_date' => today()->toDateString(),
            'due_date' => today()->toDateString(),
        ]);

        foreach ($lines as [$product, $qty]) {
            LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty]);
        }

        return $loan->load('items');
    }

    public function test_full_return_in_good_condition_restocks_and_closes_the_loan(): void
    {
        $product = Product::factory()->create(['quantity' => 10]);
        $loan = $this->deliveredLoan([[$product, 3]]);

        Sanctum::actingAs($this->warehouse);

        $this->postJson('/api/loans/checkin', [
            'loan_id' => $loan->id,
            'general_notes' => 'Todo en orden',
            'items' => [['item_id' => $loan->items[0]->id, 'condition' => 'optimo', 'damaged' => false]],
        ])->assertOk()
            ->assertJsonPath('data.is_returned', true)
            ->assertJsonPath('data.items.0.return_condition', 'bueno');

        $this->assertSame(13, $product->fresh()->quantity);
        $this->assertNotNull($loan->fresh()->returned_at);
        $this->assertSame($this->warehouse->id, $loan->fresh()->returned_by);
        $this->assertSame('Todo en orden', $loan->fresh()->return_notes);
    }

    public function test_damaged_goes_to_repair_and_lost_goes_to_write_off_without_restocking(): void
    {
        $damaged = Product::factory()->create(['quantity' => 10]);
        $lost = Product::factory()->create(['quantity' => 10]);
        $loan = $this->deliveredLoan([[$damaged, 2], [$lost, 4]]);

        Sanctum::actingAs($this->warehouse);

        $this->postJson('/api/loans/checkin', [
            'loan_id' => $loan->id,
            'items' => [
                ['product_id' => $damaged->id, 'condition' => 'bueno', 'damaged' => true],
                ['product_id' => $lost->id, 'condition' => 'extraviado'],
            ],
        ])->assertOk();

        $this->assertSame(10, $damaged->fresh()->quantity);
        $this->assertSame(2, $damaged->fresh()->quantity_in_repair);
        $this->assertSame(4, $lost->fresh()->quantity_written_off);
        $this->assertSame('en_reparacion', (new ProductResource(Product::factory()->make(['quantity' => 0, 'quantity_in_repair' => 1])))->resolve()['estado']);
    }

    public function test_partial_return_keeps_the_loan_open_until_all_items_are_back(): void
    {
        $a = Product::factory()->create(['quantity' => 5]);
        $b = Product::factory()->create(['quantity' => 5]);
        $loan = $this->deliveredLoan([[$a, 1], [$b, 1]]);

        Sanctum::actingAs($this->warehouse);

        $this->postJson('/api/loans/checkin', [
            'loan_id' => $loan->id,
            'items' => [['product_id' => $a->id, 'condition' => 'bueno']],
        ])->assertOk()->assertJsonPath('data.is_returned', false);

        $this->assertNull($loan->fresh()->returned_at);

        $this->postJson('/api/loans/checkin', [
            'loan_id' => $loan->id,
            'items' => [['product_id' => $b->id, 'condition' => 'regular']],
        ])->assertOk()->assertJsonPath('data.is_returned', true);
    }

    public function test_checkin_validations(): void
    {
        $product = Product::factory()->create(['quantity' => 5]);
        $loan = $this->deliveredLoan([[$product, 1]]);
        $pending = Loan::factory()->create();
        $other = $this->deliveredLoan([[Product::factory()->create(), 1]]);

        Sanctum::actingAs($this->warehouse);

        // Préstamo no entregado
        $this->postJson('/api/loans/checkin', ['loan_id' => $pending->id, 'items' => [['product_id' => 1, 'condition' => 'bueno']]])
            ->assertUnprocessable()->assertJsonValidationErrors('loan_id');

        // Ítem de otro préstamo
        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [['item_id' => $other->items[0]->id, 'condition' => 'bueno']]])
            ->assertUnprocessable();

        // Condición inválida
        $this->postJson('/api/loans/checkin', ['loan_id' => $loan->id, 'items' => [['product_id' => $product->id, 'condition' => 'roto']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.condition');

        // Doble devolución
        $payload = ['loan_id' => $loan->id, 'items' => [['product_id' => $product->id, 'condition' => 'bueno']]];
        $this->postJson('/api/loans/checkin', $payload)->assertOk();
        $this->postJson('/api/loans/checkin', $payload)->assertUnprocessable();
        $this->assertSame(6, $product->fresh()->quantity);
    }

    public function test_only_warehouse_and_admin_can_check_in(): void
    {
        $product = Product::factory()->create();
        $loan = $this->deliveredLoan([[$product, 1]]);
        $payload = ['loan_id' => $loan->id, 'items' => [['product_id' => $product->id, 'condition' => 'bueno']]];

        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/loans/checkin', $payload)->assertForbidden();

        Sanctum::actingAs($this->director);
        $this->postJson('/api/loans/checkin', $payload)->assertForbidden();
    }

    public function test_overdue_flag_and_filter(): void
    {
        $product = Product::factory()->create();
        $late = $this->deliveredLoan([[$product, 1]], ['loan_date' => today()->subDays(5)->toDateString(), 'due_date' => today()->subDays(3)->toDateString()]);
        $onTime = $this->deliveredLoan([[$product, 1]]);

        Sanctum::actingAs($this->warehouse);

        $this->getJson('/api/loans/'.$late->id)->assertJsonPath('data.is_overdue', true);
        $this->getJson('/api/loans/'.$onTime->id)->assertJsonPath('data.is_overdue', false);

        $ids = collect($this->getJson('/api/loans?atrasado=1&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($late->id));
        $this->assertFalse($ids->contains($onTime->id));

        // Al devolverlo deja de estar atrasado
        $this->postJson('/api/loans/checkin', ['loan_id' => $late->id, 'items' => [['product_id' => $product->id, 'condition' => 'bueno']]])->assertOk();
        $this->getJson('/api/loans/'.$late->id)->assertJsonPath('data.is_overdue', false);
    }

    public function test_due_date_defaults_to_loan_date_and_must_not_precede_it(): void
    {
        $product = Product::factory()->create(['quantity' => 10]);
        Sanctum::actingAs($this->teacher);

        $base = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'subject' => 'Redes', 'room' => 'Sala 1'];
        $date = today()->addDays(3)->toDateString();

        $this->postJson('/api/loans/requests', $base + ['loan_date' => $date])
            ->assertCreated()->assertJsonPath('data.due_date', $date);

        $this->postJson('/api/loans/requests', $base + ['loan_date' => $date, 'due_date' => today()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
    }

    public function test_teacher_can_cancel_only_own_pending_request(): void
    {
        $own = Loan::factory()->create(['requested_by' => $this->teacher->id]);
        $approved = Loan::factory()->inProgress()->create(['requested_by' => $this->teacher->id]);
        $foreign = Loan::factory()->create();

        Sanctum::actingAs($this->teacher);

        $this->deleteJson('/api/loans/requests/'.$approved->id)->assertUnprocessable();
        $this->deleteJson('/api/loans/requests/'.$foreign->id)->assertForbidden();
        $this->deleteJson('/api/loans/requests/'.$own->id)->assertNoContent();

        $this->assertDatabaseMissing('loans', ['id' => $own->id]);
        $this->assertDatabaseHas('loans', ['id' => $approved->id]);

        Sanctum::actingAs($this->warehouse);
        $this->deleteJson('/api/loans/requests/'.$foreign->id)->assertForbidden();
    }

    public function test_export_as_csv_and_pdf_respects_filters_and_roles(): void
    {
        $product = Product::factory()->create();
        $this->deliveredLoan([[$product, 2]], ['room' => 'Sala Export 77']);
        $this->deliveredLoan([[$product, 1]], ['room' => 'Otra sala']);

        Sanctum::actingAs($this->director);

        $csv = $this->get('/api/loans/export?sala=Export 77');
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Sala Export 77', $body);
        $this->assertStringNotContainsString('Otra sala', $body);

        $pdf = $this->get('/api/loans/export?format=pdf');
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->getJson('/api/loans/export?format=docx')->assertUnprocessable();

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/loans/export')->assertForbidden();
    }
}
