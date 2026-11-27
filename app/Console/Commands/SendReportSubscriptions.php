<?php

namespace App\Console\Commands;

use App\Jobs\SendReportSubscriptionJob;
use App\Models\ReportSubscription;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Console\Command;

/**
 * Encola las suscripciones de reportes que tocan hoy (docs/suscripcion-reportes.md): lunes las semanales, día 1 las
 * mensuales, 1 de enero y 1 de julio las semestrales. Programado a las 07:00 de Chile en `routes/console.php`.
 */
class SendReportSubscriptions extends Command
{
    protected $signature = 'sgia:send-report-subscriptions';

    protected $description = 'Envía por correo los reportes suscritos que tocan hoy.';

    public function handle(): int
    {
        $queued = 0;

        foreach (ReportSubscriptionPeriod::dueOn() as $frequency) {
            $period = ReportSubscriptionPeriod::previous($frequency);

            ReportSubscription::query()
                ->where('is_active', true)
                ->where('frequency', $frequency)
                ->where(fn ($q) => $q->whereNull('last_period')->orWhere('last_period', '!=', $period->key))
                ->chunkById(100, function ($subscriptions) use ($period, &$queued) {
                    foreach ($subscriptions as $subscription) {
                        // Un envío que falla no debe dejar sin reporte a los demás (cola `sync`).
                        rescue(fn () => SendReportSubscriptionJob::dispatch($subscription->id, $period));
                        $queued++;
                    }
                });
        }

        $this->info("Suscripciones en cola: {$queued}.");

        return self::SUCCESS;
    }
}
