<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Rango de fechas de los dashboards (se aplica a `loans.loan_date`).
 *
 * Acepta un preajuste (`LAST_7_DAYS`, `LAST_30_DAYS`, `LAST_90_DAYS`,
 * `CURRENT_MONTH`, `CURRENT_SEMESTER`, `YEAR`), un semestre (`2026-1`, `2026-2`),
 * un año (`2026`) o un rango explícito `from` / `to`.
 */
final class DashboardPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * @throws ValidationException si el valor no corresponde a ningún formato
     */
    public static function resolve(?string $period, ?string $from = null, ?string $to = null): ?self
    {
        if ($from !== null || $to !== null) {
            $start = $from !== null ? CarbonImmutable::parse($from)->startOfDay() : CarbonImmutable::create(2000, 1, 1)->startOfDay();
            $end = $to !== null ? CarbonImmutable::parse($to)->startOfDay() : today()->toImmutable();

            if ($end->lt($start)) {
                throw ValidationException::withMessages(['to' => 'La fecha final no puede ser anterior a la inicial.']);
            }

            return new self($start, $end);
        }

        if ($period === null || $period === '') {
            return null;
        }

        $today = today()->toImmutable();
        $key = strtoupper($period);

        if (preg_match('/^(\d{4})-([12])$/', $period, $m)) {
            $start = CarbonImmutable::create((int) $m[1], $m[2] === '1' ? 1 : 7, 1);

            return new self($start, $start->addMonths(6)->subDay());
        }

        if (preg_match('/^\d{4}$/', $period)) {
            return new self(CarbonImmutable::create((int) $period, 1, 1), CarbonImmutable::create((int) $period, 12, 31));
        }

        return match ($key) {
            'LAST_7_DAYS' => new self($today->subDays(6), $today),
            'LAST_30_DAYS' => new self($today->subDays(29), $today),
            'LAST_90_DAYS' => new self($today->subDays(89), $today),
            'CURRENT_MONTH' => new self($today->startOfMonth(), $today->endOfMonth()->startOfDay()),
            'CURRENT_SEMESTER' => self::resolve($today->year.'-'.($today->month <= 6 ? 1 : 2)),
            'YEAR' => self::resolve((string) $today->year),
            default => throw ValidationException::withMessages([
                'period' => 'Período inválido. Usa LAST_7_DAYS, LAST_30_DAYS, LAST_90_DAYS, CURRENT_MONTH, CURRENT_SEMESTER, YEAR, un semestre (2026-1) o un año (2026).',
            ]),
        };
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
