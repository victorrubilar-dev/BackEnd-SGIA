<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\ChartTemplate;
use App\Models\Role;
use App\Models\Storeroom;
use App\Models\User;
use App\Models\UserChart;
use Database\Seeders\CareerSeeder;
use Database\Seeders\ChartTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreModelsSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_roles_exist_and_user_role_id_is_synced_from_role(): void
    {
        foreach (User::ROLES as $code) {
            $this->assertDatabaseHas('roles', ['code' => $code]);
        }

        $user = User::factory()->create(['role' => User::ROLE_DIRECTOR]);
        $this->assertSame(User::ROLE_DIRECTOR, $user->roleModel->code);

        $user->update(['role' => User::ROLE_WAREHOUSE]);
        $this->assertSame(User::ROLE_WAREHOUSE, $user->fresh()->roleModel->code);
        $this->assertTrue(Role::where('code', User::ROLE_WAREHOUSE)->firstOrFail()->users->contains($user));
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $before = Role::count();
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame($before, Role::count());
    }

    public function test_default_storeroom_and_relations(): void
    {
        $storeroom = Storeroom::default();
        $this->assertSame(Storeroom::DEFAULT_CODE, $storeroom->code);

        $user = User::factory()->create(['storeroom_id' => $storeroom->id]);
        $this->assertTrue($user->storeroom->is($storeroom));
        $this->assertTrue($storeroom->users->contains($user));
    }

    public function test_career_seeder_assigns_codes_without_duplicating(): void
    {
        $existing = Career::create(['name' => 'ingenieria en informatica', 'level' => Career::LEVEL_TECHNICIAN]);

        $this->seed(CareerSeeder::class);
        $this->seed(CareerSeeder::class);

        $this->assertSame('II', $existing->fresh()->code);
        $this->assertSame(1, Career::where('code', 'II')->count());
        foreach (Career::CODES as $code) {
            $this->assertSame(1, Career::where('code', $code)->count());
        }
        $this->assertSame(Career::LEVEL_TECHNICIAN, Career::where('code', 'AP')->value('level'));
        $this->assertSame(Career::LEVEL_PROFESSIONAL, Career::where('code', 'ISD')->value('level'));
    }

    public function test_career_code_check_rejects_unknown_code(): void
    {
        $this->expectException(QueryException::class);

        Career::create(['name' => 'Otra', 'level' => Career::LEVEL_TECHNICIAN, 'code' => 'XX']);
    }

    public function test_career_user_pivot(): void
    {
        $career = Career::factory()->create();
        $user = User::factory()->create();
        $user->careers()->attach($career);

        $this->assertTrue($career->users->contains($user));
        $this->expectException(QueryException::class);
        $user->careers()->attach($career);
    }

    public function test_career_user_backfill_matches_area_by_name(): void
    {
        $career = Career::factory()->create(['name' => 'Carrera Backfill']);
        $user = User::factory()->create(['area' => 'carrera backfill']);

        DB::statement(<<<'SQL'
            INSERT INTO career_user (user_id, career_id, created_at, updated_at)
            SELECT u.id, c.id, NOW(), NOW() FROM users u JOIN careers c ON LOWER(c.name) = LOWER(u.area)
            WHERE u.id = ?
        SQL, [$user->id]);

        $this->assertTrue($user->careers->contains($career));
    }

    public function test_chart_templates_seeded(): void
    {
        $this->seed(ChartTemplateSeeder::class);
        $this->seed(ChartTemplateSeeder::class);

        $this->assertSame(1, ChartTemplate::where('code', 'bar')->count());
        $this->assertSame(4, ChartTemplate::whereIn('code', ['bar', 'line', 'pie', 'kpi'])->count());
    }

    public function test_user_chart_position_is_unique_per_user_and_limited_to_five(): void
    {
        $user = User::factory()->create();
        $template = ChartTemplate::factory()->create();
        $make = fn (int $position) => UserChart::factory()->create([
            'user_id' => $user->id, 'chart_template_id' => $template->id, 'position' => $position,
        ]);

        $chart = $make(1);
        $this->assertTrue($chart->user->is($user));
        $this->assertTrue($chart->template->is($template));

        try {
            $make(6);
            $this->fail('Posición 6 debía ser rechazada.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_user_chart_unique_position_is_deferrable_for_swaps(): void
    {
        $user = User::factory()->create();
        $template = ChartTemplate::factory()->create();
        $a = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template->id, 'position' => 1]);
        $b = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template->id, 'position' => 2]);

        // Intercambio dentro de la misma transacción: la restricción se evalúa al final.
        $a->update(['position' => 2]);
        $b->update(['position' => 1]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame(2, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
    }

    public function test_duplicate_position_for_same_user_fails(): void
    {
        $user = User::factory()->create();
        $template = ChartTemplate::factory()->create();
        UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template->id, 'position' => 1]);
        UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template->id, 'position' => 1]);

        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
