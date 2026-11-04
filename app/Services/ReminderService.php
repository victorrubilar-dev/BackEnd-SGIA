<?php

namespace App\Services;

use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Notifications\LoanDueNotification;
use App\Notifications\MaintenanceDueNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos diarios (`sgia:send-reminders`):
 *  - al docente, el día que vence su préstamo y una vez cuando queda atrasado;
 *  - a PAN-01, un resumen de unidades con mantención vencida o próxima.
 * Cada aviso se envía una sola vez (columnas `*_sent_at` / `maintenance_notified_for`).
 */
class ReminderService
{
    /**
     * @return array{due: int, overdue: int}
     */
    public function sendLoanReminders(): array
    {
        $today = today()->toDateString();

        $due = $this->notifyLoans(
            $this->pendingReturns()->whereRaw('coalesce(due_date, loan_date) = ?', [$today])->whereNull('due_reminder_sent_at'),
            LoanDueNotification::KIND_DUE,
            'due_reminder_sent_at'
        );

        $overdue = $this->notifyLoans(
            $this->pendingReturns()->whereRaw('coalesce(due_date, loan_date) < ?', [$today])->whereNull('overdue_notified_at'),
            LoanDueNotification::KIND_OVERDUE,
            'overdue_notified_at'
        );

        return ['due' => $due, 'overdue' => $overdue];
    }

    /**
     * Unidades con `next_maintenance_at` vencida o dentro de `$withinDays`, sin mantención en curso
     * ni de baja, que aún no se avisaron para esa fecha. Devuelve cuántas se avisaron.
     */
    public function sendMaintenanceReminders(int $withinDays = 7): int
    {
        $units = EquipmentUnit::with('product')
            ->whereNotNull('next_maintenance_at')
            ->where('next_maintenance_at', '<=', now()->addDays($withinDays)->endOfDay())
            ->where('status', '!=', EquipmentUnit::STATUS_WRITTEN_OFF)
            ->whereDoesntHave('maintenanceRecords', fn ($q) => $q->where('status', MaintenanceRecord::STATUS_IN_PROGRESS))
            ->where(fn ($q) => $q->whereNull('maintenance_notified_for')
                ->orWhereRaw('maintenance_notified_for <> next_maintenance_at::date'))
            ->orderBy('next_maintenance_at')
            ->get();

        if ($units->isEmpty()) {
            return 0;
        }

        $recipients = User::withRole(User::ROLE_WAREHOUSE)->where('is_active', true)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new MaintenanceDueNotification(
                $units->map(fn (EquipmentUnit $unit) => [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'product' => (string) $unit->product?->name,
                    'next_maintenance_at' => $unit->next_maintenance_at->format('d-m-Y'),
                ])->values()->all(),
                $withinDays
            ));
        }

        foreach ($units as $unit) {
            $unit->forceFill(['maintenance_notified_for' => $unit->next_maintenance_at->toDateString()])->saveQuietly();
        }

        return $units->count();
    }

    /**
     * Préstamos entregados, sin devolver, con docente solicitante (los presenciales sin usuario no se avisan).
     *
     * @return Builder<Loan>
     */
    private function pendingReturns(): Builder
    {
        return Loan::query()
            ->with('requester')
            ->where('status', Loan::STATUS_PROCESSED)
            ->whereNull('returned_at')
            ->whereNotNull('requested_by');
    }

    /**
     * @param  Builder<Loan>  $query
     */
    private function notifyLoans(Builder $query, string $kind, string $column): int
    {
        $sent = 0;

        $query->chunkById(200, function ($loans) use ($kind, $column, &$sent) {
            foreach ($loans as $loan) {
                if ($loan->requester?->is_active) {
                    $loan->requester->notify(new LoanDueNotification($loan, $kind));
                    $sent++;
                }
                $loan->forceFill([$column => now()])->saveQuietly();
            }
        });

        return $sent;
    }
}
