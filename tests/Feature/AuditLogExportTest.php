<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Reports\AuditLogsReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de auditoría `GET /api/audit-logs/export` (docs/reportes-excel.md).
 */
class AuditLogExportTest extends TestCase
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

    private function log(User $actor, string $action, ?array $old = null, ?array $new = null, string $type = 'product'): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => 42,
            'old_values' => $old, 'new_values' => $new, 'ip_address' => '10.0.0.7',
        ]);
    }

    public function test_exports_events_with_changes_as_text_and_audits_the_export(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);
        $actor = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'name' => 'Pañolero Auditado']);
        $this->log($actor, 'product.updated', ['name' => 'Cable', 'quantity' => 3], ['name' => 'Cable UTP', 'quantity' => 5]);
        $this->log($actor, 'user.created', null, ['email' => 'nuevo@sgia.cl', 'is_active' => true], 'user');
        $this->log(User::factory()->create(), 'product.updated');

        $response = $this->get("/api/audit-logs/export?user_id={$actor->id}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="auditoria-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Eventos', 'Resumen', 'Info'], array_keys($this->readXlsx($response)));

        $events = collect($this->xlsxRecords($response, 'Eventos'))->keyBy('Acción');
        $this->assertCount(2, $events);
        $updated = $events['product.updated'];
        $this->assertSame('Pañolero Auditado', $updated['Usuario']);
        $this->assertSame(User::ROLE_WAREHOUSE, $updated['Rol']);
        $this->assertSame('Producto', $updated['Entidad']);
        $this->assertSame(42, $updated['ID']);
        $this->assertSame('name: Cable → Cable UTP; quantity: 3 → 5', $updated['Cambios']);
        $this->assertSame('10.0.0.7', $updated['IP']);
        $this->assertIsNumeric($updated['Fecha y hora']);
        $this->assertSame('email: nuevo@sgia.cl; is_active: sí', $events['user.created']['Cambios']);
        $this->assertSame("Usuario: {$actor->name}", $this->xlsxInfo($response)['Filtros']);

        $audit = AuditLog::where('user_id', $admin->id)->where('action', AuditLog::REPORT_EXPORT_ACTION)->sole();
        $this->assertSame(AuditLog::REPORT_TYPE, $audit->auditable_type);
        $this->assertSame('auditoria', $audit->new_values['report']);
        $this->assertSame(2, $audit->new_values['rows']);
    }

    public function test_action_prefix_and_report_type_filters(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $actor = User::factory()->create();
        $this->log($actor, 'user.created', type: 'user');
        $this->log($actor, 'user.updated', type: 'user');
        $this->log($actor, 'product.updated');
        $this->log($actor, AuditLog::REPORT_EXPORT_ACTION, null, ['report' => 'usuarios'], AuditLog::REPORT_TYPE);

        $response = $this->get("/api/audit-logs/export?user_id={$actor->id}&action=user.")->assertOk();
        $this->assertEqualsCanonicalizing(['user.created', 'user.updated'], array_column($this->xlsxRecords($response, 'Eventos'), 'Acción'));
        $this->assertStringContainsString('Acción: user.*', $this->xlsxInfo($response)['Filtros']);

        $response = $this->get("/api/audit-logs/export?user_id={$actor->id}&auditable_type=report")->assertOk();
        $events = $this->xlsxRecords($response, 'Eventos');
        $this->assertSame([AuditLog::REPORT_EXPORT_ACTION], array_column($events, 'Acción'));
        $this->assertSame('Reporte', $events[0]['Entidad']);
    }

    public function test_period_and_summary_by_action_and_user(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $tag = 'AUD'.fake()->unique()->numerify('#####');
        $actor = User::factory()->create(['name' => "{$tag} Actor"]);
        $at = fn (string $action, string $date) => $this->log($actor, $action)->forceFill(['created_at' => $date])->save();
        $at('product.updated', '2026-03-01 00:10:00');
        $at('product.updated', '2026-03-15 10:00:00');
        $at('product.created', '2026-03-31 23:50:00');
        $at('product.created', '2026-04-01 00:05:00');

        $response = $this->get("/api/audit-logs/export?user_id={$actor->id}&from=2026-03-01&to=2026-03-31")->assertOk();
        $this->assertCount(3, $this->xlsxRecords($response, 'Eventos'));
        $summary = $this->xlsxSummary($response);
        $this->assertContains('Acción|product.updated|2', $summary);
        $this->assertContains('Acción|product.created|1', $summary);
        $this->assertContains("Usuario|{$tag} Actor|3", $summary);
    }

    public function test_changes_text_handles_deleted_values(): void
    {
        $this->assertSame('name: Cable → —', AuditLogsReport::changes(['name' => 'Cable'], null));
        $this->assertNull(AuditLogsReport::changes(null, null));
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $user = $this->actingAsRole($role);
            $this->getJson('/api/audit-logs/export')->assertForbidden();
            $this->assertFalse(AuditLog::where('user_id', $user->id)->exists(), 'un 403 no queda como exportación');
        }

        $admin = $this->actingAsRole(User::ROLE_ADMIN);
        $this->getJson('/api/audit-logs/export?auditable_type=nave')->assertUnprocessable()->assertJsonValidationErrors('auditable_type');
        $this->getJson('/api/audit-logs/export?from=2026-11-10&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->assertFalse(AuditLog::where('user_id', $admin->id)->exists(), 'un 422 no queda como exportación');
    }
}
