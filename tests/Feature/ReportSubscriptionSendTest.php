<?php

namespace Tests\Feature;

use App\Jobs\SendReportSubscriptionJob;
use App\Mail\ReportSubscriptionMail;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ReportSubscription;
use App\Models\Student;
use App\Models\User;
use App\Reports\ReportRoutes;
use App\Services\ReportSubrequest;
use App\Services\ReportSubscriptionRunner;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Envío de la suscripción de reportes (docs/suscripcion-reportes.md, decisiones 4 a 11).
 */
class ReportSubscriptionSendTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        // Martes 1 de diciembre de 2026: toca la mensual, con noviembre.
        $this->travelTo('2026-12-01 07:00:00');
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
    }

    private function subscribe(User $user, string $reportId = 'inventario', array $params = [], string $frequency = 'monthly'): ReportSubscription
    {
        Sanctum::actingAs($user);
        $path = ReportRoutes::path($reportId);
        $id = $this->postJson('/api/me/report-subscriptions', ['report_id' => $reportId, 'title' => ucfirst($reportId), 'path' => $path, 'params' => $params, 'frequency' => $frequency])
            ->assertCreated()->json('data.id');

        return ReportSubscription::findOrFail($id);
    }

    private function sentMail(): ReportSubscriptionMail
    {
        $sent = Mail::sent(ReportSubscriptionMail::class);
        $this->assertCount(1, $sent);

        return $sent->first();
    }

    public function test_the_attachment_is_the_same_workbook_as_the_download_and_it_is_audited_as_email(): void
    {
        $user = $this->user(User::ROLE_DIRECTOR);
        Product::factory()->create(['name' => 'Cable suscrito', 'type' => 'insumo']);
        $subscription = $this->subscribe($user, 'inventario', ['search' => 'Cable suscrito']);

        $this->artisan('sgia:send-report-subscriptions')->assertSuccessful();

        $mail = $this->sentMail();
        $mail->assertHasTo($user->email);
        $mail->assertHasSubject('SGIA · Inventario · 01-12-2026');
        $this->assertSame('attachment', $mail->delivery);
        $this->assertMatchesRegularExpression('/^inventario-\d{8}-\d{6}\.xlsx$/', $mail->file['name']);
        $html = $mail->render();
        $this->assertStringContainsString('Cable suscrito', $html);
        $this->assertStringContainsString('El Excel va adjunto', $html);
        $this->assertStringContainsString('/reportes?reporte=inventario', $html);
        $this->assertCount(1, $mail->attachments());

        Sanctum::actingAs($user);
        $download = $this->get('/api/products/export?search=Cable+suscrito')->assertOk();
        $attached = new TestResponse(response($mail->file['content']));
        $expected = $this->readXlsx($download);
        $actual = $this->readXlsx($attached);
        unset($expected['Info'], $actual['Info']);
        $this->assertSame($expected, $actual);

        $audit = AuditLog::where('user_id', $user->id)->where('action', AuditLog::REPORT_EMAIL_ACTION)->sole();
        $this->assertSame('inventario', $audit->new_values['report']);
        $this->assertSame('attachment', $audit->new_values['delivery']);
        $this->assertSame($user->email, $audit->new_values['email']);
        $this->assertSame('2026-11', $audit->new_values['period']['key']);
        $this->assertGreaterThan(0, $audit->new_values['rows']);
        // Solo la descarga manual aparece en «Mis descargas».
        $this->assertCount(1, $this->getJson('/api/me/report-downloads')->json('data'));

        $subscription->refresh();
        $this->assertSame('sent', $subscription->last_status);
        $this->assertSame('2026-11', $subscription->last_period);
    }

    public function test_period_reports_use_the_previous_period_and_only_once(): void
    {
        $user = $this->user(User::ROLE_DIRECTOR);
        $this->subscribe($user, 'informe-periodo');

        $this->artisan('sgia:send-report-subscriptions')->assertSuccessful();
        $this->artisan('sgia:send-report-subscriptions')->assertSuccessful();

        $mail = $this->sentMail();
        $mail->assertHasSubject('SGIA · Informe-periodo · noviembre 2026');
        $this->assertStringContainsString('Noviembre 2026 (01-11-2026 a 30-11-2026)', $mail->render());
        $audit = AuditLog::where('user_id', $user->id)->where('action', AuditLog::REPORT_EMAIL_ACTION)->sole();
        $this->assertEquals(['from' => '2026-11-01', 'to' => '2026-11-30'], array_intersect_key($audit->new_values['query'], ['from' => 1, 'to' => 1]));
    }

    public function test_personal_data_goes_only_with_the_link(): void
    {
        $user = $this->user(User::ROLE_WAREHOUSE);
        Student::factory()->create();
        $this->subscribe($user, 'estudiantes');

        $this->artisan('sgia:send-report-subscriptions');

        $mail = $this->sentMail();
        $this->assertSame('link', $mail->delivery);
        $this->assertSame([], $mail->attachments());
        $this->assertStringContainsString('datos personales', $mail->render());
        $this->assertDatabaseMissing('audit_logs', ['user_id' => $user->id, 'action' => 'report.export']);
    }

    public function test_no_rows_no_attachment(): void
    {
        $user = $this->user(User::ROLE_DIRECTOR);
        $subscription = $this->subscribe($user, 'inventario', ['search' => 'nada-que-coincida-xyz']);

        $this->artisan('sgia:send-report-subscriptions');

        $mail = $this->sentMail();
        $this->assertSame('none', $mail->delivery);
        $this->assertSame([], $mail->attachments());
        $this->assertStringContainsString('Sin movimientos en el período', $mail->render());
        $this->assertSame('empty', $subscription->refresh()->last_status);
    }

    public function test_a_file_over_the_limit_goes_only_with_the_link(): void
    {
        $this->app->bind(ReportSubscriptionRunner::class, fn ($app) => new ReportSubscriptionRunner($app->make(ReportSubrequest::class), 100));
        Product::factory()->create(['type' => 'insumo']);
        $this->subscribe($this->user(User::ROLE_DIRECTOR));

        $this->artisan('sgia:send-report-subscriptions');

        $mail = $this->sentMail();
        $this->assertSame('link', $mail->delivery);
        $this->assertSame([], $mail->attachments());
        $this->assertStringContainsString('demasiado grande', $mail->render());
    }

    public function test_only_the_frequencies_due_today_are_queued(): void
    {
        $user = $this->user(User::ROLE_DIRECTOR);
        $monthly = $this->subscribe($user, 'inventario', [], 'monthly');
        $this->subscribe($user, 'inventario', [], 'weekly');
        $this->subscribe($user, 'inventario', [], 'semester');
        $paused = $this->subscribe($user, 'proveedores', [], 'monthly');
        $paused->update(['is_active' => false]);
        Queue::fake();

        $this->artisan('sgia:send-report-subscriptions')->assertSuccessful();
        Queue::assertPushed(SendReportSubscriptionJob::class, 1);
        Queue::assertPushed(SendReportSubscriptionJob::class, fn ($job) => $job->subscriptionId === $monthly->id && $job->period->key === '2026-11' && $job->scheduled);
    }

    public function test_losing_the_permission_or_being_inactive_pauses_the_subscription(): void
    {
        $demoted = $this->user(User::ROLE_DIRECTOR);
        $first = $this->subscribe($demoted, 'proveedores');
        $demoted->update(['role' => User::ROLE_WAREHOUSE]);
        $inactive = $this->user(User::ROLE_DIRECTOR);
        $second = $this->subscribe($inactive, 'inventario');
        $inactive->update(['is_active' => false]);

        $this->artisan('sgia:send-report-subscriptions');

        Mail::assertNothingSent();
        $this->assertFalse($first->refresh()->is_active);
        $this->assertSame('Tu rol ya no puede ver este reporte.', $first->paused_reason);
        $this->assertFalse($second->refresh()->is_active);
        $this->assertSame('Tu usuario está inactivo.', $second->paused_reason);
    }

    public function test_server_errors_retry_and_end_as_failed(): void
    {
        $subscription = $this->subscribe($this->user(User::ROLE_DIRECTOR));
        $job = new SendReportSubscriptionJob($subscription->id, ReportSubscriptionPeriod::previous('monthly'));
        $this->assertSame(3, $job->tries);

        $job->failed(new RuntimeException('El reporte respondió 500.'));

        $this->assertSame('failed', $subscription->refresh()->last_status);
        $this->assertTrue($subscription->is_active);
        $this->assertNull($subscription->last_period);
    }

    public function test_a_server_error_is_thrown_for_the_queue_to_retry(): void
    {
        $subscription = $this->subscribe($this->user(User::ROLE_DIRECTOR));
        $this->mock(ReportSubrequest::class)->shouldReceive('get')->andReturn(response()->json(['message' => 'Error'], 500));

        try {
            (new SendReportSubscriptionJob($subscription->id, ReportSubscriptionPeriod::previous('monthly')))->handle(app(ReportSubscriptionRunner::class));
            $this->fail('El trabajo debe fallar para reintentarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('El reporte respondió 500.', $e->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertTrue($subscription->refresh()->is_active);
        $this->assertNull($subscription->last_period);
    }

    public function test_send_now_mails_the_last_period_without_touching_last_period_and_is_throttled(): void
    {
        $user = $this->user(User::ROLE_DIRECTOR);
        $subscription = $this->subscribe($user, 'inventario');
        Sanctum::actingAs($user);

        $this->postJson("/api/me/report-subscriptions/{$subscription->id}/send")->assertStatus(202);

        $this->sentMail();
        $subscription->refresh();
        $this->assertNull($subscription->last_period);
        $this->assertNotNull($subscription->last_sent_at);

        for ($i = 0; $i < 9; $i++) {
            $this->postJson("/api/me/report-subscriptions/{$subscription->id}/send")->assertStatus(202);
        }
        $this->postJson("/api/me/report-subscriptions/{$subscription->id}/send")->assertStatus(429);
    }
}
