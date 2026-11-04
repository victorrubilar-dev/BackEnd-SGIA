<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Location;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pañoles: lectura para todos los autenticados, escritura solo AD-01.
 */
class StoreroomTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_admin_creates_updates_and_deletes_storeroom(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);

        $id = $this->postJson('/api/storerooms', ['name' => 'Pañol Norte', 'code' => 'pan-norte', 'general_location' => 'Edificio B'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'PAN-NORTE')
            ->assertJsonPath('data.created_by', $admin->id)
            ->assertJsonPath('data.locations_count', 0)
            ->json('data.id');

        $this->patchJson("/api/storerooms/{$id}", ['name' => 'Pañol Norte 2'])->assertOk()->assertJsonPath('data.name', 'Pañol Norte 2');
        $this->getJson('/api/storerooms?search=norte')->assertOk()->assertJsonPath('data.0.id', $id);

        $this->deleteJson("/api/storerooms/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('storerooms', ['id' => $id]);
    }

    public function test_validation(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        Storeroom::factory()->create(['code' => 'PAN-DUP']);

        $this->postJson('/api/storerooms', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'code']);
        $this->postJson('/api/storerooms', ['name' => 'X', 'code' => 'pan-dup'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/storerooms', ['name' => 'X', 'code' => 'PAN TEMUCO!'])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_delete_is_blocked_with_locations_users_or_loans(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);

        $withLocation = Storeroom::factory()->create();
        Location::factory()->create(['storeroom_id' => $withLocation->id]);
        $withUser = Storeroom::factory()->create();
        User::factory()->create(['storeroom_id' => $withUser->id]);
        $withLoan = Storeroom::factory()->create();
        Loan::factory()->create(['storeroom_id' => $withLoan->id]);

        foreach ([$withLocation, $withUser, $withLoan] as $storeroom) {
            $this->deleteJson("/api/storerooms/{$storeroom->id}")
                ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['storeroom']]);
        }
    }

    public function test_everyone_reads_only_admin_writes(): void
    {
        $storeroom = Storeroom::factory()->create();

        foreach ([User::ROLE_TEACHER, User::ROLE_WAREHOUSE, User::ROLE_DIRECTOR] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/storerooms')->assertOk();
            $this->getJson("/api/storerooms/{$storeroom->id}")->assertOk()->assertJsonPath('data.code', $storeroom->code);
            $this->postJson('/api/storerooms', ['name' => 'X', 'code' => 'X-1'])->assertForbidden();
            $this->patchJson("/api/storerooms/{$storeroom->id}", ['name' => 'Y'])->assertForbidden();
            $this->deleteJson("/api/storerooms/{$storeroom->id}")->assertForbidden();
        }
    }
}
