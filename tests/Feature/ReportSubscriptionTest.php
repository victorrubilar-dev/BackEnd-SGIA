<?php

namespace Tests\Feature;

use App\Models\ReportSubscription;
use App\Models\User;
use App\Reports\ReportRoutes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD de la suscripción de reportes por correo (docs/suscripcion-reportes.md).
 */
class ReportSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->travelTo('2026-11-26 12:00:00');
    }

    private function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return [
            'report_id' => 'prestamos',
            'title' => 'Historial de préstamos',
            'path' => '/loans/export?format=excel',
            'params' => ['status' => 'procesado', 'devuelto' => '0', 'from' => '2020-01-01', 'to' => '2020-01-31', 'preview' => 1, 'format' => 'pdf'],
            'frequency' => 'monthly',
            ...$override,
        ];
    }

    public function test_subscribes_saving_the_filters_without_the_period_nor_the_fixed_parameters(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);

        $response = $this->postJson('/api/me/report-subscriptions', $this->payload())->assertCreated();

        $response->assertJsonPath('data.params', ['status' => 'procesado', 'devuelto' => '0'])
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.paused_reason', null)
            ->assertJsonPath('data.last_status', null)
            ->assertJsonPath('data.next_send_at', '2026-12-01T07:00:00-03:00');
        $this->assertSame(['Estado', 'Devuelto'], array_keys($response->json('data.filters')), 'sin Desde/Hasta');
        $this->assertDatabaseHas('report_subscriptions', ['user_id' => $user->id, 'path' => '/loans/export?format=excel']);
        $this->assertDatabaseMissing('audit_logs', ['user_id' => $user->id, 'action' => 'report.export']);
        // La subpetición no cambia quién está autenticado.
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        Mail::assertNothingSent();
    }

    public function test_every_report_of_the_catalog_can_be_subscribed_by_its_role(): void
    {
        $byRole = [
            User::ROLE_DIRECTOR => ['inventario', 'unidades', 'prestamos', 'alertas', 'mantenciones', 'novedades', 'ubicaciones', 'solicitudes', 'ordenes', 'facturas', 'presupuestos', 'proveedores', 'proveedores-mas-compras', 'profesores-mas-prestamos', 'informe-periodo'],
            User::ROLE_WAREHOUSE => ['estudiantes'],
            User::ROLE_ADMIN => ['auditoria', 'usuarios', 'monitoreo'],
        ];

        $covered = [];
        foreach ($byRole as $role => $ids) {
            $this->actingAsRole($role);
            foreach ($ids as $id) {
                [$path] = ReportRoutes::ALLOWED[$id];
                $this->postJson('/api/me/report-subscriptions', ['report_id' => $id, 'title' => $id, 'path' => $path, 'frequency' => 'weekly'])
                    ->assertCreated();
                $covered[] = $id;
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(ReportRoutes::ALLOWED), $covered);
    }

    public function test_role_without_the_report_gets_403_and_nothing_is_saved(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $this->postJson('/api/me/report-subscriptions', $this->payload(['report_id' => 'usuarios', 'path' => '/users/export', 'params' => []]))
            ->assertForbidden();
        $this->assertDatabaseCount('report_subscriptions', 0);

        $this->actingAsRole(User::ROLE_TEACHER);
        $this->getJson('/api/me/report-subscriptions')->assertForbidden();
        $this->postJson('/api/me/report-subscriptions', $this->payload())->assertForbidden();
    }

    public function test_validation_closed_list_filters_and_frequency(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->postJson('/api/me/report-subscriptions', $this->payload(['path' => '/users']))->assertStatus(422)->assertJsonValidationErrors('path');
        $this->postJson('/api/me/report-subscriptions', $this->payload(['report_id' => 'otro']))->assertStatus(422)->assertJsonValidationErrors('report_id');
        $this->postJson('/api/me/report-subscriptions', $this->payload(['frequency' => 'daily']))->assertStatus(422)->assertJsonValidationErrors('frequency');
        $this->postJson('/api/me/report-subscriptions', $this->payload(['params' => ['status' => ['a']]]))->assertStatus(422)->assertJsonValidationErrors('params');
        // La validación de la propia ruta del reporte.
        $this->postJson('/api/me/report-subscriptions', $this->payload(['report_id' => 'inventario', 'path' => '/products/export', 'params' => ['type' => 'avion']]))
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('report_subscriptions', 0);
    }

    public function test_duplicates_and_limit(): void
    {
        $user = $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->postJson('/api/me/report-subscriptions', $this->payload(['params' => ['status' => 'procesado', 'devuelto' => '0']]))->assertCreated();
        $this->postJson('/api/me/report-subscriptions', $this->payload(['params' => ['devuelto' => 0, 'status' => 'procesado']]))
            ->assertStatus(422)->assertJsonValidationErrors('report_id');
        $weekly = $this->postJson('/api/me/report-subscriptions', $this->payload(['frequency' => 'weekly', 'params' => ['status' => 'procesado', 'devuelto' => '0']]))
            ->assertCreated()->json('data.id');
        // Cambiar la frecuencia a una que ya tiene repetiría la suscripción.
        $this->patchJson("/api/me/report-subscriptions/{$weekly}", ['frequency' => 'monthly'])->assertStatus(422)->assertJsonValidationErrors('frequency');

        for ($i = $user->reportSubscriptions()->count(); $i < ReportSubscription::LIMIT; $i++) {
            $this->postJson('/api/me/report-subscriptions', $this->payload(['params' => ['search' => "caso {$i}"]]))->assertCreated();
        }
        $this->postJson('/api/me/report-subscriptions', $this->payload(['params' => ['search' => 'una más']]))->assertStatus(422);
        $this->assertSame(ReportSubscription::LIMIT, $user->reportSubscriptions()->count());
    }

    public function test_lists_updates_and_deletes_only_my_subscriptions(): void
    {
        $other = $this->actingAsRole(User::ROLE_DIRECTOR);
        $theirs = $this->postJson('/api/me/report-subscriptions', $this->payload())->json('data.id');

        $me = $this->actingAsRole(User::ROLE_DIRECTOR);
        $mine = $this->postJson('/api/me/report-subscriptions', $this->payload())->json('data.id');

        $this->getJson('/api/me/report-subscriptions')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine)
            ->assertJsonPath('meta.limit', 20)->assertJsonPath('meta.mail_enabled', false);
        config(['mail.default' => 'smtp']);
        $this->getJson('/api/me/report-subscriptions')->assertJsonPath('meta.mail_enabled', true);
        config(['mail.default' => 'array']);

        $this->patchJson("/api/me/report-subscriptions/{$theirs}", ['is_active' => false])->assertNotFound();
        $this->patchJson("/api/me/report-subscriptions/{$theirs}", ['frequency' => 'nunca'])->assertNotFound();
        $this->deleteJson("/api/me/report-subscriptions/{$theirs}")->assertNotFound();
        $this->postJson("/api/me/report-subscriptions/{$theirs}/send")->assertNotFound();
        $this->deleteJson('/api/me/report-subscriptions/abc')->assertNotFound();

        $this->patchJson("/api/me/report-subscriptions/{$mine}", ['is_active' => false])->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.next_send_at', null);
        ReportSubscription::findOrFail($mine)->update(['paused_reason' => 'Tu rol ya no puede ver este reporte.']);
        $this->patchJson("/api/me/report-subscriptions/{$mine}", ['is_active' => true, 'frequency' => 'semester'])->assertOk()
            ->assertJsonPath('data.paused_reason', null)->assertJsonPath('data.frequency', 'semester')
            ->assertJsonPath('data.next_send_at', '2027-01-01T07:00:00-03:00');

        $this->deleteJson("/api/me/report-subscriptions/{$mine}")->assertNoContent();
        $this->assertSame(0, $me->reportSubscriptions()->count());
        $this->assertSame(1, $other->reportSubscriptions()->count());
    }
}
