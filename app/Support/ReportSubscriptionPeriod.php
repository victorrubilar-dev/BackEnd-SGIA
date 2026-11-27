<?php

namespace App\Support;

use App\Models\ReportSubscription;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * El período completo que manda cada frecuencia y cuándo toca enviarlo (docs/suscripcion-reportes.md, decisiones 2 y 3):
 * semanal (lunes, la semana anterior), mensual (día 1, el mes anterior) o semestral (1 de enero y 1 de julio, el
 * semestre que terminó). Todo en hora de Chile.
 */
final class ReportSubscriptionPeriod
{
    public const TIMEZONE = 'America/Santiago';

    public const SEND_HOUR = 7;

    private const MONTHS = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        /** `2026-W47`, `2026-11` o `2026-2`. */
        public readonly string $key,
        public readonly string $frequency,
        public readonly string $from,
        public readonly string $to,
    ) {}

    /** El último período completo antes de `$now`. */
    public static function previous(string $frequency, ?CarbonImmutable $now = null): self
    {
        $today = self::local($now)->startOfDay();

        switch ($frequency) {
            case ReportSubscription::FREQUENCY_WEEKLY:
                $from = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeek();

                return new self($from->format('o-\WW'), $frequency, $from->toDateString(), $from->addDays(6)->toDateString());
            case ReportSubscription::FREQUENCY_MONTHLY:
                $from = $today->startOfMonth()->subMonth();

                return new self($from->format('Y-m'), $frequency, $from->toDateString(), $from->endOfMonth()->toDateString());
            case ReportSubscription::FREQUENCY_SEMESTER:
                $from = $today->month <= 6 ? $today->setDate($today->year - 1, 7, 1) : $today->setDate($today->year, 1, 1);

                return new self($from->year.'-'.($from->month === 1 ? 1 : 2), $frequency, $from->toDateString(), $from->addMonths(6)->subDay()->toDateString());
        }

        throw new InvalidArgumentException("Frecuencia desconocida: {$frequency}");
    }

    /** Frecuencias cuyo día de envío es la fecha de `$now`. */
    public static function dueOn(?CarbonImmutable $now = null): array
    {
        $today = self::local($now);

        return array_values(array_filter(
            ReportSubscription::FREQUENCIES,
            fn (string $frequency) => self::isSendDay($frequency, $today),
        ));
    }

    /** Próximo envío programado (07:00 de Chile) después de `$now`. */
    public static function nextSendAt(string $frequency, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = self::local($now);
        $day = $now->startOfDay();
        while (! self::isSendDay($frequency, $day) || $day->setTime(self::SEND_HOUR, 0)->lessThanOrEqualTo($now)) {
            $day = $day->addDay();
        }

        return $day->setTime(self::SEND_HOUR, 0);
    }

    /** «noviembre 2026», «semana del 16-11 al 22-11-2026», «2.º semestre 2026». */
    public function label(): string
    {
        $from = CarbonImmutable::parse($this->from, self::TIMEZONE);
        $to = CarbonImmutable::parse($this->to, self::TIMEZONE);

        return match ($this->frequency) {
            ReportSubscription::FREQUENCY_WEEKLY => 'semana del '.$from->format($from->year === $to->year ? 'd-m' : 'd-m-Y').' al '.$to->format('d-m-Y'),
            ReportSubscription::FREQUENCY_MONTHLY => self::MONTHS[$from->month].' '.$from->year,
            default => ($from->month === 1 ? '1.er' : '2.º').' semestre '.$from->year,
        };
    }

    /** «16-11-2026 a 22-11-2026». */
    public function range(): string
    {
        return CarbonImmutable::parse($this->from)->format('d-m-Y').' a '.CarbonImmutable::parse($this->to)->format('d-m-Y');
    }

    private static function isSendDay(string $frequency, CarbonImmutable $day): bool
    {
        return match ($frequency) {
            ReportSubscription::FREQUENCY_WEEKLY => $day->isMonday(),
            ReportSubscription::FREQUENCY_MONTHLY => $day->day === 1,
            ReportSubscription::FREQUENCY_SEMESTER => $day->day === 1 && ($day->month === 1 || $day->month === 7),
            default => false,
        };
    }

    private static function local(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE);
    }
}
