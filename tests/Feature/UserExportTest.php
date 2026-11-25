<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de usuarios `GET /api/users/export` (docs/reportes-excel.md).
 */
class UserExportTest extends TestCase
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

    public function test_exports_users_with_the_list_filters_and_audits_the_export(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);
        $tag = 'USR'.fake()->unique()->numerify('#####');
        $storeroom = Storeroom::factory()->create();
        $careers = Career::factory()->count(2)->create();
        $warehouse = User::factory()->create([
            'name' => "{$tag} Ana Pañol", 'email' => strtolower($tag).'@sgia.cl', 'role' => User::ROLE_WAREHOUSE,
            'area' => 'Informática', 'storeroom_id' => $storeroom->id, 'last_login_at' => now()->subHour(),
        ]);
        $warehouse->careers()->sync($careers->modelKeys());
        User::factory()->create(['name' => "{$tag} Beto Docente", 'role' => User::ROLE_TEACHER, 'is_active' => false]);
        User::factory()->create(['name' => 'Usuario sin etiqueta']);

        $response = $this->get("/api/users/export?search={$tag}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="usuarios-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Usuarios', 'Info'], array_keys($this->readXlsx($response)));

        $users = collect($this->xlsxRecords($response, 'Usuarios'))->keyBy('Nombre');
        $this->assertSame(["{$tag} Ana Pañol", "{$tag} Beto Docente"], $users->keys()->all());
        $row = $users["{$tag} Ana Pañol"];
        $this->assertSame($warehouse->email, $row['Correo']);
        $this->assertSame(User::ROLE_WAREHOUSE, $row['Rol']);
        $this->assertSame('Informática', $row['Área']);
        $this->assertSame($careers->pluck('name')->sort()->implode(', '), $row['Carreras']);
        $this->assertSame($storeroom->name, $row['Pañol']);
        $this->assertSame('sí', $row['Activo']);
        $this->assertIsNumeric($row['Último acceso']);
        $this->assertSame('no', $users["{$tag} Beto Docente"]['Activo']);

        $audit = AuditLog::where('user_id', $admin->id)->where('action', AuditLog::REPORT_EXPORT_ACTION)->sole();
        $this->assertSame('usuarios', $audit->new_values['report']);
        $this->assertSame(['Búsqueda' => $tag], $audit->new_values['filters']);
    }

    public function test_role_and_active_filters_exclude_users(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $tag = 'USF'.fake()->unique()->numerify('#####');
        User::factory()->create(['name' => "{$tag} Uno", 'role' => User::ROLE_WAREHOUSE]);
        User::factory()->create(['name' => "{$tag} Dos", 'role' => User::ROLE_TEACHER]);
        User::factory()->create(['name' => "{$tag} Tres", 'role' => User::ROLE_TEACHER, 'is_active' => false]);

        $response = $this->get("/api/users/export?search={$tag}&role=PRO-01&is_active=1")->assertOk();
        $this->assertSame(["{$tag} Dos"], array_column($this->xlsxRecords($response, 'Usuarios'), 'Nombre'));
        $this->assertStringContainsString('Rol: PRO-01', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/users/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_ADMIN);
        $this->getJson('/api/users/export?role=XX-99')->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->getJson('/api/users/export?storeroom_id=abc')->assertUnprocessable()->assertJsonValidationErrors('storeroom_id');
    }
}
