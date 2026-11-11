<?php

namespace Tests\Feature;

use App\Models\ChartTemplate;
use App\Models\User;
use App\Models\UserChart;
use Database\Seeders\ChartTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-15: dashboard personalizado por usuario (hasta 5 gráficos).
 */
class UserChartTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartTemplateSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function template(string $code): ChartTemplate
    {
        return ChartTemplate::where('code', $code)->firstOrFail();
    }

    public function test_templates_are_listed_with_their_defaults(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);

        $templates = collect($this->getJson('/api/chart-templates')->assertOk()->json('data'))->keyBy('code');

        $this->assertTrue($templates->has('bar'));
        $this->assertSame('LOANS', $templates['loans_by_status']['entity']);
        $this->assertSame('status', $templates['loans_by_status']['group_by']);
        $this->assertSame('DOUGHNUT', $templates['loans_by_status']['chart_type']);
    }

    public function test_create_inherits_from_template_and_lists_by_position(): void
    {
        $user = $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->postJson('/api/me/charts', ['chart_template_id' => $this->template('loans_by_status')->id, 'position' => 3])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.custom_title', 'Préstamos por estado')
            ->assertJsonPath('data.entity', 'LOANS')
            ->assertJsonPath('data.group_by', 'status')
            ->assertJsonPath('data.type', 'DOUGHNUT')
            ->assertJsonPath('data.template.code', 'loans_by_status')
            ->assertJsonPath('data.metric_input', [
                'entity' => 'LOANS', 'metric' => 'COUNT', 'timeRange' => 'LAST_30_DAYS',
                'chartType' => 'DOUGHNUT', 'title' => 'Préstamos por estado', 'groupBy' => 'status',
            ]);

        // Plantilla genérica: el usuario envía la consulta; sin posición toma la primera libre.
        $this->postJson('/api/me/charts', [
            'chart_template_id' => $this->template('bar')->id,
            'custom_title' => 'Maquetas por sala',
            'entity' => 'SCALE_MODELS',
            'metric' => 'COUNT',
            'group_by' => 'location',
            'period' => 'CUSTOM',
            'date_from' => '2026-03-01',
            'date_to' => '2026-07-31',
        ])->assertCreated()
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.type', 'BAR')
            ->assertJsonPath('data.metric_input.dateFrom', '2026-03-01');

        $this->getJson('/api/me/charts')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.position', 1)->assertJsonPath('data.1.position', 3);
    }

    public function test_validation_errors(): void
    {
        $user = $this->actingAsRole(User::ROLE_ADMIN);
        $generic = $this->template('bar')->id;

        $this->postJson('/api/me/charts', ['chart_template_id' => $generic, 'position' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('position');
        $this->postJson('/api/me/charts', ['chart_template_id' => $generic, 'entity' => 'PLANETS'])
            ->assertStatus(422)->assertJsonValidationErrors('entity');
        $this->postJson('/api/me/charts', ['chart_template_id' => $generic])
            ->assertStatus(422)->assertJsonValidationErrors(['entity', 'metric', 'period']);
        $this->postJson('/api/me/charts', ['chart_template_id' => $generic, 'entity' => 'LOANS', 'metric' => 'COUNT', 'period' => 'LAST_7_DAYS', 'group_by' => 'location'])
            ->assertStatus(422)->assertJsonValidationErrors('group_by');
        $this->postJson('/api/me/charts', ['chart_template_id' => $generic, 'entity' => 'LOANS', 'metric' => 'COUNT', 'period' => 'CUSTOM'])
            ->assertStatus(422)->assertJsonValidationErrors('date_from');
        $this->postJson('/api/me/charts', ['chart_template_id' => $this->template('kpi')->id, 'entity' => 'LOANS', 'metric' => 'COUNT', 'period' => 'LAST_7_DAYS'])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $template = $this->template('loans_trend')->id;
        $this->postJson('/api/me/charts', ['chart_template_id' => $template, 'position' => 2])->assertCreated();
        $this->postJson('/api/me/charts', ['chart_template_id' => $template, 'position' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('position');

        foreach ([1, 3, 4, 5] as $position) {
            UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template, 'position' => $position]);
        }
        $this->postJson('/api/me/charts', ['chart_template_id' => $template])
            ->assertStatus(422)->assertJsonValidationErrors('position');
    }

    public function test_the_same_chart_cannot_be_added_twice(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $template = $this->template('loans_by_status')->id;

        $first = $this->postJson('/api/me/charts', ['chart_template_id' => $template])->assertCreated()->json('data.id');

        // Segundo clic sobre la misma plantilla: mismo gráfico, aunque cambie el título.
        $this->postJson('/api/me/charts', ['chart_template_id' => $template])
            ->assertStatus(422)->assertJsonValidationErrors('chart');
        $this->postJson('/api/me/charts', ['chart_template_id' => $template, 'custom_title' => 'Otro nombre'])
            ->assertStatus(422)->assertJsonValidationErrors('chart');
        // La misma consulta desde la plantilla genérica también es el mismo gráfico.
        $this->postJson('/api/me/charts', [
            'chart_template_id' => $this->template('pie')->id, 'entity' => 'LOANS', 'metric' => 'COUNT',
            'group_by' => 'status', 'period' => 'LAST_30_DAYS',
        ])->assertStatus(422)->assertJsonValidationErrors('chart');
        $this->getJson('/api/me/charts')->assertJsonCount(1, 'data');

        // Oculto sigue contando, y el mensaje lo dice.
        $this->patchJson("/api/me/charts/{$first}", ['is_active' => false])->assertOk();
        $this->postJson('/api/me/charts', ['chart_template_id' => $template])
            ->assertStatus(422)->assertJsonPath('errors.chart.0', fn (string $message) => str_contains($message, 'oculto'));

        // Otro tipo de gráfico, otro período u otro rango CUSTOM son gráficos distintos.
        $this->postJson('/api/me/charts', ['chart_template_id' => $template, 'type' => 'BAR'])->assertCreated();
        $this->postJson('/api/me/charts', ['chart_template_id' => $template, 'period' => 'LAST_7_DAYS'])->assertCreated();
        $custom = ['chart_template_id' => $template, 'period' => 'CUSTOM', 'date_from' => '2026-03-01'];
        $second = $this->postJson('/api/me/charts', $custom)->assertCreated()->json('data.id');
        $this->postJson('/api/me/charts', $custom)->assertStatus(422)->assertJsonValidationErrors('chart');

        // Editar uno hasta igualarlo a otro tampoco se permite; renombrarlo sí.
        $this->patchJson("/api/me/charts/{$second}", ['period' => 'LAST_7_DAYS'])
            ->assertStatus(422)->assertJsonValidationErrors('chart');
        $this->patchJson("/api/me/charts/{$second}", ['custom_title' => 'Renombrado'])->assertOk();

        // Otro usuario puede tener el mismo gráfico.
        $this->actingAsRole(User::ROLE_ADMIN);
        $this->postJson('/api/me/charts', ['chart_template_id' => $template])->assertCreated();
    }

    public function test_every_seeded_template_with_a_query_creates_a_valid_chart(): void
    {
        foreach (ChartTemplate::whereNotNull('entity')->get() as $template) {
            $this->actingAsRole(User::ROLE_DIRECTOR);
            $this->postJson('/api/me/charts', ['chart_template_id' => $template->id])
                ->assertCreated()
                ->assertJsonPath('data.chart_template_id', $template->id)
                ->assertJsonPath('data.template.code', $template->code)
                ->assertJsonPath('data.type', $template->chart_type);
        }
    }

    public function test_update_fields_and_hide_without_deleting(): void
    {
        $user = $this->actingAsRole(User::ROLE_DIRECTOR);
        $chart = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $this->template('bar')->id, 'position' => 1]);
        UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $this->template('bar')->id, 'position' => 2]);

        $this->patchJson("/api/me/charts/{$chart->id}", ['custom_title' => 'Nuevo', 'is_active' => false, 'position' => 4, 'group_by' => null])
            ->assertOk()
            ->assertJsonPath('data.custom_title', 'Nuevo')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.position', 4)
            ->assertJsonPath('data.group_by', null);

        $this->getJson('/api/me/charts?active=1')->assertOk()->assertJsonCount(1, 'data');

        // Cambiar a una plantilla con consulta toma su configuración.
        $this->patchJson("/api/me/charts/{$chart->id}", ['chart_template_id' => $this->template('maintenance_by_type')->id])
            ->assertOk()->assertJsonPath('data.entity', 'MAINTENANCE')->assertJsonPath('data.group_by', 'maintenance_type');

        $this->patchJson("/api/me/charts/{$chart->id}", ['position' => 2])->assertStatus(422)->assertJsonValidationErrors('position');
        $this->patchJson("/api/me/charts/{$chart->id}", ['entity' => 'ALERTS'])->assertStatus(422)->assertJsonValidationErrors('group_by');
        $this->patchJson("/api/me/charts/{$chart->id}", ['user_id' => User::factory()->create()->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_reorder_swaps_positions_in_one_transaction(): void
    {
        $user = $this->actingAsRole(User::ROLE_DIRECTOR);
        $template = $this->template('bar')->id;
        $a = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template, 'position' => 1]);
        $b = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template, 'position' => 2]);
        $c = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $template, 'position' => 3]);

        $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $a->id, 'position' => 2], ['id' => $b->id, 'position' => 1]]])
            ->assertOk()->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.1.id', $a->id);

        $this->assertSame(2, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);

        // Un segundo reordenamiento en la misma transacción también funciona.
        $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $a->id, 'position' => 3], ['id' => $c->id, 'position' => 2]]])->assertOk();
        $this->assertSame(3, $a->fresh()->position);

        // Dejaría dos en la misma posición (b sigue en 1).
        $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $a->id, 'position' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('positions');
        $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $a->id, 'position' => 1], ['id' => $b->id, 'position' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('positions.1.position');
        $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $a->id, 'position' => 6]]])
            ->assertStatus(422)->assertJsonValidationErrors('positions.0.position');
    }

    public function test_charts_are_isolated_between_users(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_DIRECTOR]);
        $foreign = UserChart::factory()->create(['user_id' => $other->id, 'chart_template_id' => $this->template('bar')->id, 'position' => 1]);

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/me/charts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/me/charts/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/me/charts/{$foreign->id}", ['custom_title' => 'x'])->assertNotFound();
        $this->deleteJson("/api/me/charts/{$foreign->id}")->assertNotFound();
        $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $foreign->id, 'position' => 2]]])
            ->assertStatus(422)->assertJsonValidationErrors('positions.0.id');

        // Ocupar la posición 1 propia no choca con la del otro usuario.
        $this->postJson('/api/me/charts', ['chart_template_id' => $this->template('loans_trend')->id, 'position' => 1])->assertCreated();
        $this->assertNotSame('x', $foreign->fresh()->custom_title);
        $this->assertSame(1, $foreign->fresh()->position);
    }

    public function test_delete_returns_204(): void
    {
        $user = $this->actingAsRole(User::ROLE_ADMIN);
        $chart = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $this->template('bar')->id]);

        $this->deleteJson("/api/me/charts/{$chart->id}")->assertNoContent();
        $this->assertDatabaseMissing('user_charts', ['id' => $chart->id]);
    }

    public function test_warehouse_and_teacher_are_forbidden(): void
    {
        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $user = $this->actingAsRole($role);
            $chart = UserChart::factory()->create(['user_id' => $user->id, 'chart_template_id' => $this->template('bar')->id]);

            $this->getJson('/api/chart-templates')->assertForbidden();
            $this->getJson('/api/me/charts')->assertForbidden();
            $this->postJson('/api/me/charts', ['chart_template_id' => $this->template('bar')->id])->assertForbidden();
            $this->patchJson("/api/me/charts/{$chart->id}", ['custom_title' => 'x'])->assertForbidden();
            $this->putJson('/api/me/charts/positions', ['positions' => [['id' => $chart->id, 'position' => 2]]])->assertForbidden();
            $this->deleteJson("/api/me/charts/{$chart->id}")->assertForbidden();
        }
    }
}
