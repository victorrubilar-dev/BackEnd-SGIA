<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Career;
use App\Models\InvoiceIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnualBudgetTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_admin_creates_lists_updates_and_deletes_budget(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_ADMIN));
        $career = Career::factory()->create();

        $id = $this->postJson('/api/budgets', ['career_id' => $career->id, 'year' => 2031, 'initial_amount' => 500000])
            ->assertCreated()
            ->assertJsonPath('data.available_amount', '500000.00')
            ->assertJsonPath('data.consumed_amount', '0.00')
            ->json('data.id');

        $this->getJson("/api/budgets?year=2031&career_id={$career->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/budgets/$id", ['initial_amount' => 800000])
            ->assertOk()->assertJsonPath('data.available_amount', '800000.00');

        $this->deleteJson("/api/budgets/$id")->assertNoContent();
        $this->assertDatabaseMissing('annual_budgets', ['id' => $id]);
    }

    public function test_update_recalculates_available_from_consumed(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_ADMIN));
        $budget = AnnualBudget::factory()->create(['initial_amount' => 1000000, 'available_amount' => 700000]);

        $this->patchJson("/api/budgets/{$budget->id}", ['initial_amount' => 900000])
            ->assertOk()
            ->assertJsonPath('data.available_amount', '600000.00')
            ->assertJsonPath('data.consumed_amount', '300000.00');

        $this->patchJson("/api/budgets/{$budget->id}", ['initial_amount' => 200000])
            ->assertStatus(422)->assertJsonValidationErrors('initial_amount');

        $this->assertSame('600000.00', $budget->fresh()->available_amount);
    }

    public function test_validation_errors(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_ADMIN));
        $existing = AnnualBudget::factory()->create(['year' => 2032]);

        $this->postJson('/api/budgets', ['career_id' => $existing->career_id, 'year' => 2032, 'initial_amount' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('year');
        $this->postJson('/api/budgets', ['career_id' => $existing->career_id, 'year' => 2033, 'initial_amount' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('initial_amount');
        $this->postJson('/api/budgets', [])->assertStatus(422);

        InvoiceIntake::factory()->create(['annual_budget_id' => $existing->id]);
        $this->deleteJson("/api/budgets/{$existing->id}")->assertStatus(422)->assertJsonValidationErrors('annual_budget');
    }

    public function test_roles_are_enforced(): void
    {
        $budget = AnnualBudget::factory()->create();
        $payload = ['career_id' => $budget->career_id, 'year' => 2040, 'initial_amount' => 1000];

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/budgets')->assertForbidden();
            $this->getJson("/api/budgets/{$budget->id}")->assertForbidden();
            $this->postJson('/api/budgets', $payload)->assertForbidden();
        }

        // DIR-01 sobre una carrera que no tiene asignada.
        Sanctum::actingAs($this->user(User::ROLE_DIRECTOR));
        $this->postJson('/api/budgets', $payload)->assertForbidden();
        $this->patchJson("/api/budgets/{$budget->id}", ['initial_amount' => 5])->assertForbidden();
        $this->deleteJson("/api/budgets/{$budget->id}")->assertForbidden();
    }

    public function test_director_manages_budgets_of_own_careers(): void
    {
        $director = $this->user(User::ROLE_DIRECTOR);
        $career = Career::factory()->create();
        $director->careers()->attach($career->id);
        Sanctum::actingAs($director);

        $id = $this->postJson('/api/budgets', ['career_id' => $career->id, 'year' => 2045, 'initial_amount' => 100000])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/budgets/$id", ['initial_amount' => 150000])
            ->assertOk()->assertJsonPath('data.available_amount', '150000.00');
        $this->deleteJson("/api/budgets/$id")->assertNoContent();
    }

    public function test_director_only_sees_own_careers(): void
    {
        $director = $this->user(User::ROLE_DIRECTOR);
        $mine = AnnualBudget::factory()->create();
        $other = AnnualBudget::factory()->create();
        $director->careers()->attach($mine->career_id);
        Sanctum::actingAs($director);

        $ids = collect($this->getJson('/api/budgets')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($other->id));

        $this->getJson("/api/budgets/{$mine->id}")->assertOk();
        $this->getJson("/api/budgets/{$other->id}")->assertForbidden();
    }

    public function test_show_lists_only_confirmed_intakes(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_ADMIN));
        $budget = AnnualBudget::factory()->create();
        InvoiceIntake::factory()->create(['annual_budget_id' => $budget->id, 'status' => InvoiceIntake::STATUS_CONFIRMED]);
        InvoiceIntake::factory()->create(['annual_budget_id' => $budget->id]);

        $this->getJson("/api/budgets/{$budget->id}")
            ->assertOk()->assertJsonCount(1, 'data.invoice_intakes');
    }
}
