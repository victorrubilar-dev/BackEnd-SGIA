<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FailedLogin;
use App\Models\LoginRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de monitoreo `GET /api/monitoring/export` (docs/reportes-excel.md).
 */
class MonitoringExportTest extends TestCase
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

    public function test_exports_logins_failed_attempts_and_sessions_and_audits_the_export(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);
        $tag = 'mon'.fake()->unique()->numerify('#####');
        $teacher = User::factory()->create(['name' => "{$tag} Docente", 'email' => "{$tag}@sgia.cl", 'role' => User::ROLE_TEACHER]);
        LoginRecord::create(['user_id' => $teacher->id, 'device_type' => 'mobile', 'ip_address' => '10.1.1.1', 'user_agent' => 'Expo', 'logged_in_at' => now()->subMinutes(10)]);
        LoginRecord::create(['user_id' => $teacher->id, 'device_type' => 'web', 'ip_address' => '10.1.1.2', 'logged_in_at' => now()->subHours(5)]);
        FailedLogin::create(['email' => "{$tag}@sgia.cl", 'reason' => FailedLogin::REASON_INVALID_CREDENTIALS, 'ip_address' => '10.9.9.9', 'attempted_at' => now()->subMinutes(5)]);
        FailedLogin::create(['email' => 'otro@sgia.cl', 'reason' => FailedLogin::REASON_THROTTLED, 'ip_address' => '10.9.9.8', 'attempted_at' => now()]);
        $teacher->createToken("{$tag}-mobile", ['*'], now()->addDay());
        $teacher->createToken("{$tag}-old", ['*'], now()->subMinute());

        $response = $this->get("/api/monitoring/export?hours=2&email={$tag}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="monitoreo-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Inicios de sesión', 'Fallidos', 'Sesiones activas', 'Info'], array_keys($this->readXlsx($response)));

        $logins = collect($this->xlsxRecords($response, 'Inicios de sesión'))->where('Correo', "{$tag}@sgia.cl")->values();
        $this->assertCount(1, $logins, 'el de hace 5 horas queda fuera de la ventana de 2 horas');
        $this->assertSame('mobile', $logins[0]['Dispositivo']);
        $this->assertSame('10.1.1.1', $logins[0]['IP']);
        $this->assertSame(User::ROLE_TEACHER, $logins[0]['Rol']);

        $failed = $this->xlsxRecords($response, 'Fallidos');
        $this->assertCount(1, $failed);
        $this->assertSame('Credenciales inválidas', $failed[0]['Motivo']);
        $this->assertSame("{$tag}@sgia.cl", $failed[0]['Correo']);
        $this->assertSame('10.9.9.9', $failed[0]['IP']);

        $sessions = collect($this->xlsxRecords($response, 'Sesiones activas'))->where('Correo', "{$tag}@sgia.cl")->values();
        $this->assertSame(["{$tag}-mobile"], $sessions->pluck('Dispositivo')->all());

        $filters = $this->xlsxInfo($response)['Filtros'];
        $this->assertStringContainsString('Inicios de sesión: últimas 2 horas', $filters);
        $this->assertStringContainsString("Correo (fallidos): {$tag}", $filters);

        $audit = AuditLog::where('user_id', $admin->id)->where('action', AuditLog::REPORT_EXPORT_ACTION)->sole();
        $this->assertSame('monitoreo', $audit->new_values['report']);
    }

    public function test_reason_filter_excludes_failed_attempts(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $ip = '10.77.'.fake()->numberBetween(1, 250).'.'.fake()->numberBetween(1, 250);
        FailedLogin::create(['email' => 'a@sgia.cl', 'reason' => FailedLogin::REASON_INACTIVE, 'ip_address' => $ip, 'attempted_at' => now()]);
        FailedLogin::create(['email' => 'b@sgia.cl', 'reason' => FailedLogin::REASON_THROTTLED, 'ip_address' => $ip, 'attempted_at' => now()]);

        $response = $this->get("/api/monitoring/export?ip_address={$ip}&reason=inactive")->assertOk();
        $this->assertSame(['a@sgia.cl'], array_column($this->xlsxRecords($response, 'Fallidos'), 'Correo'));
        $this->assertStringContainsString('Motivo (fallidos): Usuario desactivado', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/monitoring/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_ADMIN);
        $this->getJson('/api/monitoring/export?hours=0')->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->getJson('/api/monitoring/export?hours=500')->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->getJson('/api/monitoring/export?reason=hackeo')->assertUnprocessable()->assertJsonValidationErrors('reason');
    }
}
