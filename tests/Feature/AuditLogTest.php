<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EquipmentUnit;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-NF-01: registro de eventos críticos en `audit_logs` y su consulta (AD-01).
 */
class AuditLogTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function actionsFor(string $type, int $id): array
    {
        return AuditLog::where('auditable_type', $type)->where('auditable_id', $id)->orderBy('id')->pluck('action')->all();
    }

    public function test_user_lifecycle_is_audited_with_actor(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);

        $id = $this->postJson('/api/users', [
            'name' => 'Auditado', 'email' => 'auditado_'.uniqid().'@inacap.cl', 'role' => User::ROLE_TEACHER,
            'password' => 'Secreto123', 'password_confirmation' => 'Secreto123',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/users/{$id}", ['role' => User::ROLE_WAREHOUSE, 'name' => 'Auditado 2'])->assertOk();
        $this->patchJson("/api/users/{$id}/status", ['is_active' => false])->assertOk();
        $this->patchJson("/api/users/{$id}", ['password' => 'Otra12345', 'password_confirmation' => 'Otra12345'])->assertOk();
        $this->deleteJson("/api/users/{$id}")->assertNoContent();

        $this->assertSame(
            ['user.created', 'user.role_changed', 'user.updated', 'user.deactivated', 'user.password_changed', 'user.deleted'],
            $this->actionsFor('user', $id)
        );

        $roleChange = AuditLog::where('auditable_id', $id)->where('action', 'user.role_changed')->firstOrFail();
        $this->assertSame($admin->id, $roleChange->user_id);
        $this->assertSame(['role' => User::ROLE_TEACHER], $roleChange->old_values);
        $this->assertSame(['role' => User::ROLE_WAREHOUSE], $roleChange->new_values);
        $this->assertSame('127.0.0.1', $roleChange->ip_address);

        $password = AuditLog::where('auditable_id', $id)->where('action', 'user.password_changed')->firstOrFail();
        $this->assertNull($password->new_values);
    }

    public function test_login_does_not_create_audit_entries(): void
    {
        $user = User::factory()->create(['email' => 'login_audit_'.uniqid().'@inacap.cl', 'is_active' => true]);
        $before = AuditLog::count();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'web'])->assertOk();

        $this->assertSame($before, AuditLog::count());
    }

    public function test_write_offs_are_audited(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();
        $supply = Product::factory()->supply()->create(['quantity' => 10, 'stock_minimo' => 0]);

        $this->patchJson("/api/equipment-units/{$unit->id}/status", ['status' => 'dado_de_baja'])->assertOk();
        $this->patchJson("/api/products/{$supply->id}/condition", ['action' => 'write_off', 'quantity' => 3, 'source' => 'stock'])->assertOk();

        $this->assertSame(['equipment_unit.written_off'], $this->actionsFor('equipment_unit', $unit->id));
        $this->assertSame(['product.written_off'], $this->actionsFor('product', $supply->id));
        // Los contadores derivados del equipo no generan un segundo registro.
        $this->assertSame([], $this->actionsFor('product', $unit->product_id));

        $log = AuditLog::where('action', 'product.written_off')->where('auditable_id', $supply->id)->firstOrFail();
        $this->assertSame(3, $log->new_values['quantity_written_off'] - $log->old_values['quantity_written_off']);
    }

    public function test_purchases_and_invoice_intakes_are_audited(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $purchase = Purchase::factory()->create();
        $purchase->update(['status' => Purchase::STATUS_IN_TRANSIT]);
        $purchase->update(['status' => Purchase::STATUS_CANCELLED]);

        $this->assertSame(['purchase.created', 'purchase.status_changed', 'purchase.cancelled'], $this->actionsFor('purchase', $purchase->id));

        $intake = InvoiceIntake::factory()->create();
        $intake->update(['status' => InvoiceIntake::STATUS_CONFIRMED]);
        $intake->update(['status' => InvoiceIntake::STATUS_CANCELLED]);

        $this->assertSame(['invoice_intake.confirmed', 'invoice_intake.cancelled'], $this->actionsFor('invoice_intake', $intake->id));
    }

    public function test_admin_lists_and_filters_audit_logs(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);
        $target = User::factory()->create(['role' => User::ROLE_TEACHER]);
        $this->patchJson("/api/users/{$target->id}", ['role' => User::ROLE_DIRECTOR])->assertOk();

        $this->getJson("/api/audit-logs?auditable_type=user&auditable_id={$target->id}")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'user.role_changed')
            ->assertJsonPath('data.0.user.id', $admin->id)
            ->assertJsonPath('data.0.new_values.role', User::ROLE_DIRECTOR);

        $actions = collect($this->getJson("/api/audit-logs?action=user.&user_id={$admin->id}&from=".today()->toDateString())->assertOk()->json('data'))->pluck('action');
        $this->assertTrue($actions->every(fn ($a) => str_starts_with($a, 'user.')));
        $this->assertContains('user.role_changed', $actions);

        $this->getJson('/api/audit-logs?action=user.role_changed&auditable_id='.$target->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/audit-logs?auditable_type=planeta')->assertStatus(422)->assertJsonValidationErrors('auditable_type');
    }

    public function test_only_admin_reads_audit_logs(): void
    {
        foreach ([User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/audit-logs')->assertForbidden();
        }
    }
}
