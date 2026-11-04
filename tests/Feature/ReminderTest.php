<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Notifications\LoanDueNotification;
use App\Notifications\MaintenanceDueNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Avisos programados: préstamos que vencen hoy o atrasados (docente) y mantenciones próximas (PAN-01).
 */
class ReminderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Aísla de datos previos de la BD de pruebas.
        Loan::query()->update(['due_reminder_sent_at' => now(), 'overdue_notified_at' => now()]);
        EquipmentUnit::query()->update(['next_maintenance_at' => null]);
    }

    private function loan(User $teacher, array $attributes = []): Loan
    {
        return Loan::factory()->processed()->create([
            'requested_by' => $teacher->id,
            'loan_date' => today()->subDays(2)->toDateString(),
            ...$attributes,
        ]);
    }

    public function test_teacher_is_notified_once_when_loan_is_due_and_once_when_overdue(): void
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $dueToday = $this->loan($teacher, ['due_date' => today()->toDateString()]);
        $overdue = $this->loan($teacher, ['due_date' => today()->subDay()->toDateString()]);
        $this->loan($teacher, ['due_date' => today()->addDays(3)->toDateString()]);
        $this->loan($teacher, ['due_date' => today()->subDay()->toDateString(), 'returned_at' => now()]);
        Loan::factory()->processed()->create(['requested_by' => null, 'due_date' => today()->subDay()->toDateString()]);

        $this->artisan('sgia:send-reminders')
            ->expectsOutputToContain('Préstamos que vencen hoy: 1. Atrasados: 1.')
            ->assertSuccessful();

        Notification::assertSentToTimes($teacher, LoanDueNotification::class, 2);
        Notification::assertSentTo($teacher, LoanDueNotification::class, fn ($n) => $n->loan->is($dueToday) && $n->kind === 'due'
            && str_contains($n->message(), 'vence hoy'));
        Notification::assertSentTo($teacher, LoanDueNotification::class, fn ($n) => $n->loan->is($overdue) && $n->kind === 'overdue'
            && $n->toExpo($teacher)['data']['type'] === 'loan_overdue');

        // Una segunda corrida no repite avisos.
        $this->artisan('sgia:send-reminders')->expectsOutputToContain('Préstamos que vencen hoy: 0. Atrasados: 0.');
        Notification::assertSentToTimes($teacher, LoanDueNotification::class, 2);
    }

    public function test_inactive_teacher_is_not_notified(): void
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => false]);
        $loan = $this->loan($teacher, ['due_date' => today()->toDateString()]);

        $this->artisan('sgia:send-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($teacher);
        $this->assertNotNull($loan->fresh()->due_reminder_sent_at);
    }

    public function test_warehouse_gets_maintenance_digest_once_per_date(): void
    {
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);

        $soon = EquipmentUnit::factory()->create(['next_maintenance_at' => now()->addDays(3)]);
        $late = EquipmentUnit::factory()->create(['next_maintenance_at' => now()->subDays(5)]);
        EquipmentUnit::factory()->create(['next_maintenance_at' => now()->addDays(30)]);
        EquipmentUnit::factory()->create(['next_maintenance_at' => now()->subDay(), 'status' => 'dado_de_baja']);
        $inWorkshop = EquipmentUnit::factory()->create(['next_maintenance_at' => now()->subDay(), 'status' => 'en_reparacion']);
        MaintenanceRecord::factory()->create(['equipment_unit_id' => $inWorkshop->id]);

        $this->artisan('sgia:send-reminders')->expectsOutputToContain('Unidades con mantención próxima: 2.');

        Notification::assertSentTo($warehouse, MaintenanceDueNotification::class, fn ($n) => collect($n->units)->pluck('id')->sort()->values()->all() === collect([$soon->id, $late->id])->sort()->values()->all());
        Notification::assertNotSentTo($director, MaintenanceDueNotification::class);

        $this->artisan('sgia:send-reminders')->expectsOutputToContain('Unidades con mantención próxima: 0.');

        // Si la fecha cambia, se vuelve a avisar.
        $soon->update(['next_maintenance_at' => now()->addDays(5)]);
        $this->artisan('sgia:send-reminders')->expectsOutputToContain('Unidades con mantención próxima: 1.');
    }

    public function test_command_is_scheduled_daily_in_chile(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'sgia:send-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertSame('America/Santiago', $event->timezone);
    }
}
