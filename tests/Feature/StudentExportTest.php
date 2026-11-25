<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Loan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de estudiantes `GET /api/students/export` (docs/reportes-excel.md).
 */
class StudentExportTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_exports_students_with_loan_counts_and_audits_the_export(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);
        $career = Career::factory()->create();
        $student = Student::factory()->create(['career_id' => $career->id, 'full_name' => 'Ana Estudiante Prueba']);
        Student::factory()->create(['career_id' => $career->id, 'is_active' => false]);
        Loan::factory()->inProgress()->create(['student_id' => $student->id]);
        Loan::factory()->processed()->create(['student_id' => $student->id]);

        $response = $this->get("/api/students/export?career_id={$career->id}&is_active=1")->assertOk();
        $rows = $this->xlsxRecords($response, 'Estudiantes');
        $this->assertCount(1, $rows);
        $this->assertSame($student->rut, $rows[0]['RUT']);
        $this->assertSame('Ana Estudiante Prueba', $rows[0]['Nombre']);
        $this->assertSame(2, $rows[0]['Préstamos']);
        $this->assertSame(1, $rows[0]['Préstamos en curso']);
        $this->assertSame("Carrera: {$career->name}; Activo: sí", $this->xlsxInfo($response)['Filtros']);

        $log = AuditLog::where('user_id', $user->id)->where('action', AuditLog::REPORT_EXPORT_ACTION)->sole();
        $this->assertSame('estudiantes', $log->new_values['report']);
        $this->assertSame(1, $log->new_values['rows']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER, User::ROLE_DIRECTOR] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/students/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $this->getJson('/api/students/export?career_id=x')->assertUnprocessable()->assertJsonValidationErrors('career_id');
    }
}
