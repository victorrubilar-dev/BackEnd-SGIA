<?php

namespace App\Services;

use App\Models\FailedLogin;
use App\Models\LoginRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Monitoreo de seguridad y salud del sistema para AD-01.
 */
class SecurityMonitorService
{
    /** Fallos en la ventana a partir de los cuales una cuenta o IP se marca para revisar. */
    public const FLAG_THRESHOLD = 5;

    /**
     * Actividad de inicio de sesión de las últimas `$hours` horas, por hora.
     *
     * @return array<string, mixed>
     */
    public function loginActivity(int $hours): array
    {
        $to = now();
        $from = $to->copy()->subHours($hours - 1)->startOfHour();

        // shortcut: agrupa por hora en PHP; con decenas de miles de intentos por semana, pasar a date_trunc en SQL.
        $successful = LoginRecord::whereBetween('logged_in_at', [$from, $to])->pluck('logged_in_at');
        $failed = FailedLogin::whereBetween('attempted_at', [$from, $to])->get(['reason', 'attempted_at']);

        $byHour = [];
        for ($hour = $from->copy(); $hour <= $to; $hour->addHour()) {
            $byHour[$hour->format('Y-m-d H')] = ['hour' => $hour->toIso8601String(), 'successful' => 0, 'failed' => 0];
        }
        foreach ($successful as $at) {
            $byHour[Carbon::parse($at)->format('Y-m-d H')]['successful']++;
        }
        foreach ($failed as $attempt) {
            $byHour[$attempt->attempted_at->format('Y-m-d H')]['failed']++;
        }

        $failedIn = fn () => DB::table('failed_logins')->whereBetween('attempted_at', [$from, $to]);

        return [
            'window_hours' => $hours,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'totals' => [
                'successful' => $successful->count(),
                'failed' => $failed->where('reason', '!=', FailedLogin::REASON_THROTTLED)->count(),
                'throttled' => $failed->where('reason', FailedLogin::REASON_THROTTLED)->count(),
            ],
            'by_hour' => array_values($byHour),
            'top_ips' => $failedIn()->select('ip_address', DB::raw('count(*) as failed'), DB::raw('max(attempted_at) as last_attempt_at'))
                ->groupBy('ip_address')->orderByDesc('failed')->limit(5)->get()
                ->map(fn ($row) => [
                    'ip_address' => $row->ip_address,
                    'failed' => (int) $row->failed,
                    'last_attempt_at' => Carbon::parse($row->last_attempt_at)->toIso8601String(),
                ])->all(),
            'flagged_accounts' => $failedIn()->select('email', DB::raw('count(*) as failed'), DB::raw('max(attempted_at) as last_attempt_at'))
                ->groupBy('email')->havingRaw('count(*) >= ?', [self::FLAG_THRESHOLD])->orderByDesc('failed')->limit(20)->get()
                ->map(fn ($row) => [
                    'email' => $row->email,
                    'failed' => (int) $row->failed,
                    'last_attempt_at' => Carbon::parse($row->last_attempt_at)->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Estado de base de datos, almacenamiento privado y cola.
     *
     * @return array{status: string, checked_at: string, checks: array<string, array<string, mixed>>}
     */
    public function health(): array
    {
        $checks = [
            'database' => $this->check(function () {
                $start = hrtime(true);
                DB::select('select 1');

                return ['latency_ms' => round((hrtime(true) - $start) / 1e6, 1)];
            }),
            'storage' => $this->check(function () {
                $disk = Storage::disk('local');
                $probe = '.health-'.bin2hex(random_bytes(4));
                $disk->put($probe, 'ok');
                $readable = $disk->get($probe) === 'ok';
                $disk->delete($probe);

                return ['ok' => $readable, 'disk' => 'local'];
            }),
            'queue' => $this->check(function () {
                $connection = config('queue.default');
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

                return [
                    'connection' => $connection,
                    'pending' => $connection === 'sync' ? 0 : Queue::size(),
                    'failed' => $failed,
                    // Trabajos fallidos no tumban el sistema, pero hay que mirarlos.
                    'ok' => $failed === 0,
                ];
            }),
        ];

        return [
            'status' => collect($checks)->every(fn ($check) => $check['ok']) ? 'ok' : 'degraded',
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, ...$probe()];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'No responde.'];
        }
    }
}
