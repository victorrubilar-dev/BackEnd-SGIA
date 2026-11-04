<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

/**
 * Avisos diarios de préstamos por vencer/atrasados y de mantenciones próximas.
 * Programado en `routes/console.php`; en producción requiere el cron de `schedule:run`.
 */
class SendReminders extends Command
{
    protected $signature = 'sgia:send-reminders {--days=7 : Días de anticipación para las mantenciones}';

    protected $description = 'Avisa a docentes de préstamos que vencen hoy o están atrasados y a PAN-01 de mantenciones próximas.';

    public function handle(ReminderService $reminders): int
    {
        $loans = $reminders->sendLoanReminders();
        $units = $reminders->sendMaintenanceReminders(max(0, (int) $this->option('days')));

        $this->info("Préstamos que vencen hoy: {$loans['due']}. Atrasados: {$loans['overdue']}. Unidades con mantención próxima: {$units}.");

        return self::SUCCESS;
    }
}
