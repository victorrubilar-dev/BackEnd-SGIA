<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\Loan;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-20: registro de estudiantes y su uso en el préstamo presencial.
 */
class StudentTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(Product $product, array $extra = []): array
    {
        return [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'subject' => 'Redes', 'career_id' => Career::factory()->create()->id,
            'room' => 'Lab 1',
            'loan_date' => now()->toDateString(),
            ...$extra,
        ];
    }

    public function test_warehouse_registers_updates_and_lists_students(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $career = Career::factory()->create();

        $id = $this->postJson('/api/students', [
            'rut' => '12.345.678-5',
            'full_name' => 'Ana Pérez',
            'email' => 'ana@inacapmail.cl',
            'career_id' => $career->id,
        ])->assertCreated()
            ->assertJsonPath('data.rut', '12345678-5')
            ->assertJsonPath('data.career.id', $career->id)
            ->assertJsonPath('data.loans_count', 0)
            ->json('data.id');

        $this->patchJson("/api/students/{$id}", ['full_name' => 'Ana María Pérez'])
            ->assertOk()->assertJsonPath('data.full_name', 'Ana María Pérez');

        $this->getJson('/api/students?search=ana maría')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/students?search=12.345.678')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/students?career_id={$career->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/students/{$id}")->assertOk()->assertJsonPath('data.email', 'ana@inacapmail.cl');
    }

    public function test_lookup_by_rut_accepts_any_format(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $student = Student::factory()->create(['rut' => '7654321-6']);

        foreach (['7654321-6', '7.654.321-6', '76543216'] as $rut) {
            $this->getJson("/api/students/by-rut/{$rut}")->assertOk()->assertJsonPath('data.id', $student->id);
        }

        $this->getJson('/api/students/by-rut/11111111-1')->assertNotFound();
    }

    public function test_k_check_digit_is_normalized_to_uppercase(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);

        $this->postJson('/api/students', ['rut' => '10.000.013-k'])
            ->assertCreated()->assertJsonPath('data.rut', '10000013-K');
        $this->getJson('/api/students/by-rut/10000013-k')->assertOk();
    }

    public function test_validation_errors(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        Student::factory()->create(['rut' => '12345678-5']);

        $this->postJson('/api/students', [])->assertStatus(422)->assertJsonValidationErrors('rut');
        $this->postJson('/api/students', ['rut' => '12345678-9'])->assertStatus(422)->assertJsonValidationErrors('rut');
        $this->postJson('/api/students', ['rut' => '12.345.678-5'])->assertStatus(422)->assertJsonValidationErrors('rut');
        $this->postJson('/api/students', ['rut' => '7654321-6', 'email' => 'no-es-correo', 'career_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'career_id']);
    }

    public function test_roles_without_access_get_403(): void
    {
        $student = Student::factory()->create();

        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER, User::ROLE_DIRECTOR] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/students')->assertForbidden();
            $this->postJson('/api/students', ['rut' => '7654321-6'])->assertForbidden();
            $this->patchJson("/api/students/{$student->id}", ['full_name' => 'X'])->assertForbidden();
            $this->patchJson("/api/students/{$student->id}/status", ['is_active' => false])->assertForbidden();
            $this->getJson("/api/students/{$student->id}/loans")->assertForbidden();
        }

        // Los estudiantes no se borran (auditoría).
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $this->deleteJson("/api/students/{$student->id}")->assertStatus(405);
    }

    public function test_warehouse_deactivates_and_reactivates_a_student_without_losing_history(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $student = Student::factory()->create(['rut' => '12345678-5']);
        Loan::factory()->create(['student_id' => $student->id]);
        $product = Product::factory()->supply()->create(['quantity' => 5, 'stock_minimo' => 0]);

        $this->patchJson("/api/students/{$student->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.loans_count', 1);
        $this->getJson('/api/students?is_active=0')->assertOk()->assertJsonPath('data.0.id', $student->id);
        $this->getJson('/api/students?is_active=1')->assertOk()->assertJsonCount(0, 'data');

        // Desactivado: no recibe préstamos, ni por id ni por RUT.
        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_id' => $student->id]))
            ->assertStatus(422)->assertJsonValidationErrors('student_id');
        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_rut' => '12.345.678-5']))
            ->assertStatus(422)->assertJsonValidationErrors('student_id');

        $this->patchJson("/api/students/{$student->id}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_id' => $student->id]))->assertCreated();
    }

    public function test_status_requires_a_boolean(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $student = Student::factory()->create();

        $this->patchJson("/api/students/{$student->id}/status", [])->assertStatus(422)->assertJsonValidationErrors('is_active');
        $this->patchJson("/api/students/{$student->id}/status", ['is_active' => 'tal vez'])->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_checkout_with_existing_student_rut_links_and_fills_borrower(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $student = Student::factory()->create(['rut' => '12345678-5', 'full_name' => 'Ana Pérez']);
        $product = Product::factory()->supply()->create(['quantity' => 5, 'stock_minimo' => 0]);

        $loanId = $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_rut' => '12.345.678-5']))
            ->assertCreated()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.student.rut', '12345678-5')
            ->assertJsonPath('data.borrower_name', 'Ana Pérez')
            ->assertJsonPath('data.borrower_document', '12345678-5')
            ->json('data.id');

        $this->getJson("/api/students/{$student->id}/loans")->assertOk()->assertJsonPath('data.0.id', $loanId);
        $this->getJson("/api/loans/{$loanId}")->assertOk()->assertJsonPath('data.student.full_name', 'Ana Pérez');
    }

    public function test_checkout_with_new_student_rut_creates_student(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $product = Product::factory()->supply()->create(['quantity' => 5, 'stock_minimo' => 0]);

        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_rut' => '7.654.321-6', 'borrower_name' => 'Luis Soto']))
            ->assertCreated()->assertJsonPath('data.borrower_name', 'Luis Soto');
        $this->assertDatabaseHas('students', ['rut' => '7654321-6', 'full_name' => 'Luis Soto']);

        // Sin nombre se registra solo con el RUT.
        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_rut' => '10000013-K']))
            ->assertCreated()->assertJsonPath('data.borrower_name', '10000013-K');
        $this->assertDatabaseHas('students', ['rut' => '10000013-K', 'full_name' => null]);
    }

    public function test_checkout_with_student_id_and_validation(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $student = Student::factory()->create();
        $product = Product::factory()->supply()->create(['quantity' => 5, 'stock_minimo' => 0]);

        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_id' => $student->id]))
            ->assertCreated()->assertJsonPath('data.student_id', $student->id)->assertJsonPath('data.borrower_name', $student->full_name);

        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product))
            ->assertStatus(422)->assertJsonValidationErrors('borrower_name');
        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_rut' => '12345678-9']))
            ->assertStatus(422)->assertJsonValidationErrors('student_rut');
        $this->postJson('/api/loans/checkout', $this->checkoutPayload($product, ['student_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('student_id');
    }
}
