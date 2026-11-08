<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\Student;
use App\Models\User;
use App\Services\EquipmentPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cada pañolero solo ve y opera los préstamos del pañol que le corresponde.
 */
class LoanStoreroomAccessTest extends TestCase
{
    use DatabaseTransactions;

    private Storeroom $mine;

    private Storeroom $other;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->mine = Storeroom::factory()->create();
        $this->other = Storeroom::factory()->create();
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true, 'storeroom_id' => $this->mine->id]);
        Sanctum::actingAs($this->warehouse);
    }

    private function loanIn(Storeroom $storeroom, string $status = 'pendiente', array $attributes = []): Loan
    {
        return Loan::factory()->create(['storeroom_id' => $storeroom->id, 'status' => $status] + $attributes);
    }

    public function test_warehouse_cannot_view_or_operate_loans_of_another_storeroom(): void
    {
        $pending = $this->loanIn($this->other);
        $inProgress = $this->loanIn($this->other, 'en_proceso');

        $this->getJson("/api/loans/{$pending->id}")->assertForbidden();
        $this->getJson("/api/loans/{$pending->id}/review")->assertForbidden();
        $this->postJson("/api/loans/{$pending->id}/approve")->assertForbidden();
        $this->postJson("/api/loans/{$pending->id}/reject", ['rejection_reason' => 'Sin stock disponible'])->assertForbidden();
        $this->postJson("/api/loans/{$inProgress->id}/deliver")->assertForbidden();

        $this->assertSame('pendiente', $pending->fresh()->status);
        $this->assertSame('en_proceso', $inProgress->fresh()->status);
    }

    public function test_warehouse_cannot_register_the_return_of_a_loan_of_another_storeroom(): void
    {
        $loan = $this->loanIn($this->other, 'procesado');

        $this->postJson('/api/loans/checkin', [
            'loan_id' => $loan->id,
            'items' => [['product_id' => 1, 'condition' => 'bueno']],
        ])->assertForbidden();

        $this->assertNull($loan->fresh()->returned_at);
    }

    public function test_warehouse_operates_loans_of_its_storeroom(): void
    {
        $pending = $this->loanIn($this->mine);
        $toReject = $this->loanIn($this->mine);

        $this->getJson("/api/loans/{$pending->id}")->assertOk();
        $this->getJson("/api/loans/{$pending->id}/review")->assertOk();
        $this->postJson("/api/loans/{$pending->id}/approve")->assertOk();
        $this->postJson("/api/loans/{$pending->id}/deliver")->assertOk();
        $this->postJson("/api/loans/{$toReject->id}/reject", ['rejection_reason' => 'Sin stock disponible'])->assertOk();
    }

    public function test_history_lists_only_the_warehouse_storeroom_even_when_filtering_by_another(): void
    {
        $mine = $this->loanIn($this->mine);
        $this->loanIn($this->other);

        $ids = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$mine->id], $ids->all());

        $this->getJson("/api/loans?storeroom_id={$this->other->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_export_contains_only_the_warehouse_storeroom(): void
    {
        $mine = $this->loanIn($this->mine);
        $foreign = $this->loanIn($this->other);

        $csv = $this->get('/api/loans/export?format=csv')->assertOk()->streamedContent();

        $this->assertStringContainsString($mine->code, $csv);
        $this->assertStringNotContainsString($foreign->code, $csv);
    }

    public function test_student_history_shows_only_the_warehouse_storeroom(): void
    {
        $student = Student::factory()->create();
        $mine = $this->loanIn($this->mine, 'procesado', ['student_id' => $student->id]);
        $this->loanIn($this->other, 'procesado', ['student_id' => $student->id]);

        $ids = collect($this->getJson("/api/students/{$student->id}/loans")->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_warehouse_without_storeroom_uses_the_default_one(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true, 'storeroom_id' => null]));
        $default = $this->loanIn(Storeroom::default());
        $foreign = $this->loanIn($this->other);

        $this->getJson("/api/loans/{$default->id}")->assertOk();
        $this->getJson("/api/loans/{$foreign->id}")->assertForbidden();
    }

    public function test_admin_and_director_are_not_limited_by_storeroom(): void
    {
        $foreign = $this->loanIn($this->other);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));
        $this->getJson("/api/loans/{$foreign->id}")->assertOk();
        $this->getJson("/api/loans?storeroom_id={$this->other->id}")->assertOk()->assertJsonPath('data.0.id', $foreign->id);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->getJson("/api/loans/{$foreign->id}/review")->assertOk();
        $this->postJson("/api/loans/{$foreign->id}/approve")->assertOk();
    }

    public function test_teacher_still_reaches_only_own_loans(): void
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $own = $this->loanIn($this->other, 'pendiente', ['requested_by' => $teacher->id]);
        $foreign = $this->loanIn($this->other);

        Sanctum::actingAs($teacher);

        $this->getJson('/api/loans/requests')->assertOk()->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/loans/{$foreign->id}")->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutBody(array $extra = []): array
    {
        $product = Product::factory()->create(['type' => Product::TYPE_SUPPLY, 'quantity' => 5, 'stock_minimo' => 0]);

        return $extra + [
            'borrower_name' => 'Ana Prestataria',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'subject' => 'Redes',
            'career_id' => Career::factory()->create()->id,
            'room' => 'Lab 1',
            'loan_date' => now()->toDateString(),
        ];
    }

    public function test_warehouse_cannot_register_a_checkout_in_another_storeroom(): void
    {
        $before = Loan::count();

        $this->postJson('/api/loans/checkout', $this->checkoutBody(['storeroom_id' => $this->other->id]))->assertForbidden();

        $this->assertSame($before, Loan::count());
    }

    public function test_warehouse_registers_checkouts_in_its_storeroom(): void
    {
        $this->postJson('/api/loans/checkout', $this->checkoutBody())
            ->assertCreated()->assertJsonPath('data.storeroom_id', $this->mine->id);
        $this->postJson('/api/loans/checkout', $this->checkoutBody(['storeroom_id' => $this->mine->id]))
            ->assertCreated()->assertJsonPath('data.storeroom_id', $this->mine->id);
    }

    public function test_invalid_checkout_storeroom_still_responds_validation_error(): void
    {
        $this->postJson('/api/loans/checkout', $this->checkoutBody(['storeroom_id' => 'abc']))
            ->assertUnprocessable()->assertJsonValidationErrors('storeroom_id');
    }

    public function test_admin_registers_checkouts_in_any_storeroom(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));

        $this->postJson('/api/loans/checkout', $this->checkoutBody(['storeroom_id' => $this->other->id]))
            ->assertCreated()->assertJsonPath('data.storeroom_id', $this->other->id);
    }

    private function unitOnLoanIn(Storeroom $storeroom): array
    {
        $unit = EquipmentUnit::factory()->onLoan()->create();
        $loan = $this->loanIn($storeroom, 'procesado');
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $unit->product_id, 'name' => 'x', 'quantity' => 1]);
        $item->units()->attach($unit->id);

        return [$unit, $loan];
    }

    public function test_unit_detail_hides_the_active_loan_of_another_storeroom(): void
    {
        [$foreignUnit, $foreignLoan] = $this->unitOnLoanIn($this->other);
        [$ownUnit, $ownLoan] = $this->unitOnLoanIn($this->mine);

        $this->getJson("/api/equipment-units/{$foreignUnit->id}")->assertOk()->assertJsonPath('data.active_loan', null);
        $this->getJson("/api/equipment-units/{$ownUnit->id}")->assertOk()->assertJsonPath('data.active_loan.id', $ownLoan->id);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->getJson("/api/equipment-units/{$foreignUnit->id}")->assertOk()->assertJsonPath('data.active_loan.id', $foreignLoan->id);
    }

    public function test_unit_life_sheet_lists_only_the_loans_the_warehouse_can_see(): void
    {
        [$unit, $foreignLoan] = $this->unitOnLoanIn($this->other);
        $ownLoan = $this->loanIn($this->mine, 'procesado');
        $item = LoanItem::create(['loan_id' => $ownLoan->id, 'product_id' => $unit->product_id, 'name' => 'x', 'quantity' => 1]);
        $item->units()->attach($unit->id);
        $pdf = app(EquipmentPdfService::class);

        $forWarehouse = $pdf->unitTechnicalSheetView($unit, $this->warehouse)->render();
        $this->assertStringContainsString($ownLoan->code, $forWarehouse);
        $this->assertStringNotContainsString($foreignLoan->code, $forWarehouse);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->assertStringContainsString($foreignLoan->code, $pdf->unitTechnicalSheetView($unit, $admin)->render());
    }
}
