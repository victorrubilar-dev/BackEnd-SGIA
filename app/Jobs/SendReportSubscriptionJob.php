<?php

namespace App\Jobs;

use App\Exceptions\ReportDeliveryRejected;
use App\Models\ReportSubscription;
use App\Services\ReportSubscriptionRunner;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Envía el período completo de una suscripción (docs/suscripcion-reportes.md, decisiones 9 y 10). El envío programado
 * guarda `last_period` para no repetirlo; «Enviar ahora» no lo toca.
 */
class SendReportSubscriptionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $subscriptionId,
        public ReportSubscriptionPeriod $period,
        public bool $scheduled = true,
    ) {}

    public function uniqueId(): string
    {
        return $this->subscriptionId.':'.($this->scheduled ? $this->period->key : 'manual');
    }

    /** @return list<int> Segundos antes de cada reintento. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ReportSubscriptionRunner $runner): void
    {
        $subscription = ReportSubscription::query()->with('user')->find($this->subscriptionId);
        if (! $subscription || ($this->scheduled && (! $subscription->is_active || $subscription->last_period === $this->period->key))) {
            return;
        }

        if (! $subscription->user->is_active) {
            $subscription->pause('Tu usuario está inactivo.');

            return;
        }

        try {
            $status = $runner->send($subscription, $this->period);
        } catch (ReportDeliveryRejected $e) {
            $subscription->pause($e->getMessage());

            return;
        }

        $subscription->update([
            'last_sent_at' => now(),
            'last_status' => $status,
            ...($this->scheduled ? ['last_period' => $this->period->key] : []),
        ]);
    }

    /** Se agotaron los reintentos. */
    public function failed(?Throwable $exception): void
    {
        ReportSubscription::query()->whereKey($this->subscriptionId)->update(['last_status' => ReportSubscription::STATUS_FAILED]);
    }
}
