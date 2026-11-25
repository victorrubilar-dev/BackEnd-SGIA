<?php

namespace Tests\Feature;

use App\Models\FailedLogin;
use App\Models\IncidentReport;
use App\Models\InvoiceIntake;
use App\Models\Loan;
use App\Models\LoginRecord;
use App\Models\PurchaseRequest;
use App\Models\Student;
use App\Models\User;
use App\Rules\ChileanRut;
use App\Services\PurchaseRequestStateMachine;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los datos de presentación deben poder leerse por la API (antes `/novedades` daba 500 por `problems` mal formados).
 */
class RealisticDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeded_incident_reports_are_listed(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->seed(RealisticDemoSeeder::class);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));

        $this->getJson('/api/incident-reports?per_page=100')
            ->assertOk()
            ->assertJsonStructure(['data' => [['problems' => [['type', 'description', 'label']]]]]);
    }

    /** Sin las imágenes de pruebas-facturas/ el seeder funciona igual y deja datos de cada etapa para la presentación. */
    public function test_seeds_variety_for_every_module(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->seed(RealisticDemoSeeder::class);

        // Todas las etapas de la solicitud de compra, incluida una facturada.
        $this->assertEqualsCanonicalizing(
            PurchaseRequestStateMachine::STATUSES,
            PurchaseRequest::distinct()->pluck('status')->all()
        );
        // Préstamos en cola, en proceso y atrasados; la cuenta de presentación tiene los suyos.
        $this->assertGreaterThan(0, Loan::where('status', Loan::STATUS_PENDING)->count());
        $this->assertGreaterThanOrEqual(3, Loan::where('status', Loan::STATUS_PROCESSED)->whereNull('returned_at')->where('due_date', '<', today())->count());
        $this->assertGreaterThanOrEqual(4, Loan::whereHas('requester', fn ($q) => $q->where('email', 'docente@sgia.cl'))->count());
        $this->assertGreaterThanOrEqual(4, IncidentReport::whereHas('reporter', fn ($q) => $q->where('email', 'docente@sgia.cl'))->count());
        // Estudiantes con RUT válido y algunos inactivos; un docente inactivo; accesos para Monitoreo.
        $this->assertTrue(Student::all()->every(fn (Student $s) => ChileanRut::isValid($s->rut)));
        $this->assertGreaterThan(0, Student::where('is_active', false)->count());
        $this->assertSame(1, User::withRole(User::ROLE_TEACHER)->where('is_active', false)->count());
        $this->assertGreaterThan(0, LoginRecord::count());
        $this->assertGreaterThan(0, FailedLogin::where('reason', FailedLogin::REASON_THROTTLED)->count());
        // Cada factura sembrada tiene su archivo (PDF de demostración si falta la imagen original).
        foreach (InvoiceIntake::pluck('pdf_path') as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }
}
