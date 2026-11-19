<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FailedLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Monitoreo de seguridad y salud (AD-01): intentos de conexión, sesiones activas y estado de servicios.
 */
class MonitoringTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function login(string $email, string $password = 'Admin1234!', string $ip = '10.0.0.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', ['email' => $email, 'password' => $password, 'device_name' => 'web']);
    }

    public function test_rejected_logins_are_recorded_with_their_reason(): void
    {
        $user = User::factory()->create(['email' => 'ana@sgia.cl', 'password' => 'Admin1234!', 'is_active' => true]);
        $inactive = User::factory()->create(['email' => 'beto@sgia.cl', 'password' => 'Admin1234!', 'is_active' => false]);

        $this->login('ana@sgia.cl', 'mala')->assertUnauthorized();
        $this->login('nadie@sgia.cl', 'mala')->assertUnauthorized();
        $this->login('beto@sgia.cl')->assertForbidden();
        $this->login('ana@sgia.cl')->assertOk();

        $this->assertDatabaseHas('failed_logins', ['email' => 'ana@sgia.cl', 'user_id' => $user->id, 'reason' => 'invalid_credentials', 'ip_address' => '10.0.0.7']);
        $this->assertDatabaseHas('failed_logins', ['email' => 'nadie@sgia.cl', 'user_id' => null, 'reason' => 'invalid_credentials']);
        $this->assertDatabaseHas('failed_logins', ['email' => 'beto@sgia.cl', 'user_id' => $inactive->id, 'reason' => 'inactive']);
        $this->assertSame(3, FailedLogin::whereIn('email', ['ana@sgia.cl', 'nadie@sgia.cl', 'beto@sgia.cl'])->count());
    }

    public function test_throttled_logins_are_recorded(): void
    {
        RateLimiter::clear(md5('login'.'carla@sgia.cl|10.0.0.9'));

        for ($i = 0; $i < 5; $i++) {
            $this->login('carla@sgia.cl', 'mala', '10.0.0.9')->assertUnauthorized();
        }
        $this->login('carla@sgia.cl', 'mala', '10.0.0.9')->assertStatus(429);

        $this->assertDatabaseHas('failed_logins', ['email' => 'carla@sgia.cl', 'reason' => 'throttled', 'ip_address' => '10.0.0.9']);
    }

    public function test_login_activity_summarizes_by_hour_and_flags_accounts_and_ips(): void
    {
        $this->travelTo(now()->setTime(10, 30));
        $target = User::factory()->create(['email' => 'victima@sgia.cl', 'password' => 'Admin1234!', 'is_active' => true]);
        for ($i = 0; $i < 6; $i++) {
            $this->login('victima@sgia.cl', 'mala', '200.1.1.1');
            RateLimiter::clear(md5('login'.'victima@sgia.cl|200.1.1.1'));
        }
        $this->login('victima@sgia.cl', 'Admin1234!', '10.0.0.2')->assertOk();
        // Fuera de la ventana de 24 h: no cuenta.
        FailedLogin::create(['email' => 'viejo@sgia.cl', 'reason' => 'invalid_credentials', 'ip_address' => '1.1.1.1', 'attempted_at' => now()->subDays(3)]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/monitoring/logins')->assertOk()->json('data');

        $this->assertSame(24, $data['window_hours']);
        $this->assertCount(24, $data['by_hour']);
        $this->assertSame(['successful' => 1, 'failed' => 6, 'throttled' => 0], $data['totals']);
        $this->assertSame(['successful' => 1, 'failed' => 6], array_intersect_key(end($data['by_hour']), ['successful' => 0, 'failed' => 0]));
        $this->assertSame('200.1.1.1', $data['top_ips'][0]['ip_address']);
        $this->assertSame(6, $data['top_ips'][0]['failed']);
        $this->assertSame([['email' => 'victima@sgia.cl', 'failed' => 6]], array_map(fn ($a) => array_intersect_key($a, ['email' => 0, 'failed' => 0]), $data['flagged_accounts']));

        $this->getJson('/api/monitoring/logins?hours=200')->assertStatus(422)->assertJsonValidationErrors('hours');
        $this->getJson('/api/monitoring/failed-logins?email=victima')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.reason', 'invalid_credentials');
        $this->assertNotNull($target->id);
    }

    public function test_admin_lists_and_revokes_other_sessions_with_audit(): void
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $teacherToken = $teacher->createToken('mobile', ['*'], now()->addDay());
        $teacher->createToken('web', ['*'], now()->subMinute()); // vencida: no aparece
        $adminToken = $this->admin->createToken('web', ['*'], now()->addHour())->plainTextToken;

        $sessions = $this->withToken($adminToken)->getJson('/api/monitoring/sessions')->assertOk()->json('data');
        $mine = collect($sessions)->firstWhere('is_current', true);
        $this->assertSame($this->admin->id, $mine['user']['id']);
        $this->assertSame(['mobile'], collect($sessions)->where('user.id', $teacher->id)->pluck('device_name')->all());

        $this->withToken($adminToken)->deleteJson("/api/monitoring/sessions/{$mine['id']}")
            ->assertStatus(422)->assertJsonValidationErrors('session');
        $this->withToken($adminToken)->deleteJson("/api/monitoring/sessions/{$teacherToken->accessToken->id}")->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $teacherToken->accessToken->id]);
        $log = AuditLog::where('action', 'user.session_revoked')->firstOrFail();
        $this->assertSame($teacher->id, $log->auditable_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('mobile', $log->new_values['device_name']);
        $this->withToken($adminToken)->deleteJson('/api/monitoring/sessions/999999')->assertNotFound();
    }

    public function test_health_reports_database_storage_and_queue(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/monitoring/health')->assertOk()
            ->assertJsonPath('data.checks.database.ok', true)
            ->assertJsonPath('data.checks.storage.ok', true)
            ->assertJsonStructure(['data' => ['status', 'checked_at', 'checks' => ['database' => ['latency_ms'], 'queue' => ['connection', 'pending', 'failed']]]]);

        DB::table('failed_jobs')->insert(['uuid' => 'x-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom']);
        $this->getJson('/api/monitoring/health')->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.queue.ok', false)
            ->assertJsonPath('data.checks.queue.failed', 1);
    }

    public function test_public_health_only_exposes_states(): void
    {
        $response = $this->getJson('/api/health')->assertOk();

        $this->assertSame(['database' => 'ok', 'storage' => 'ok', 'queue' => $response->json('checks.queue')], $response->json('checks'));
        $this->assertArrayNotHasKey('latency_ms', $response->json('checks'));
    }

    public function test_only_admin_reaches_monitoring(): void
    {
        foreach ([User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            foreach (['/api/monitoring/logins', '/api/monitoring/failed-logins', '/api/monitoring/sessions', '/api/monitoring/health'] as $uri) {
                $this->getJson($uri)->assertForbidden();
            }
            $this->deleteJson('/api/monitoring/sessions/1')->assertForbidden();
        }
    }
}
