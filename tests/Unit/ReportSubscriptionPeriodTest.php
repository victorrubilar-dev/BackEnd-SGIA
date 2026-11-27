<?php

namespace Tests\Unit;

use App\Support\ReportSubscriptionPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportSubscriptionPeriodTest extends TestCase
{
    private function at(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/Santiago');
    }

    public function test_previous_complete_period_by_frequency(): void
    {
        $cases = [
            ['weekly', '2026-11-23 07:00', ['2026-W47', '2026-11-16', '2026-11-22']],
            ['weekly', '2026-11-29 23:00', ['2026-W47', '2026-11-16', '2026-11-22']],
            // La semana ISO 1 de 2027 empieza el 4 de enero; la anterior es la 53 de 2026.
            ['weekly', '2027-01-04 07:00', ['2026-W53', '2026-12-28', '2027-01-03']],
            ['monthly', '2027-01-01 07:00', ['2026-12', '2026-12-01', '2026-12-31']],
            ['monthly', '2026-03-31 07:00', ['2026-02', '2026-02-01', '2026-02-28']],
            ['semester', '2027-01-01 07:00', ['2026-2', '2026-07-01', '2026-12-31']],
            ['semester', '2026-07-01 07:00', ['2026-1', '2026-01-01', '2026-06-30']],
        ];

        foreach ($cases as [$frequency, $now, $expected]) {
            $period = ReportSubscriptionPeriod::previous($frequency, $this->at($now));
            $this->assertSame($expected, [$period->key, $period->from, $period->to], "{$frequency} {$now}");
        }
    }

    public function test_labels(): void
    {
        $this->assertSame('semana del 16-11 al 22-11-2026', ReportSubscriptionPeriod::previous('weekly', $this->at('2026-11-23'))->label());
        $this->assertSame('noviembre 2026', ReportSubscriptionPeriod::previous('monthly', $this->at('2026-12-01'))->label());
        $this->assertSame('1.er semestre 2026', ReportSubscriptionPeriod::previous('semester', $this->at('2026-07-01'))->label());
    }

    public function test_send_days_and_next_send_at_seven_in_chile(): void
    {
        $this->assertSame(['monthly'], ReportSubscriptionPeriod::dueOn($this->at('2026-12-01 07:00')));
        $this->assertSame(['weekly'], ReportSubscriptionPeriod::dueOn($this->at('2026-11-23 07:00')));
        $this->assertSame(['monthly', 'semester'], ReportSubscriptionPeriod::dueOn($this->at('2027-01-01 07:00')));
        $this->assertSame([], ReportSubscriptionPeriod::dueOn($this->at('2026-11-26 07:00')));
        // 10:00 UTC del 1 de diciembre son las 07:00 de Chile.
        $this->assertSame(['monthly'], ReportSubscriptionPeriod::dueOn(CarbonImmutable::parse('2026-12-01 10:00', 'UTC')));

        $next = fn (string $frequency, string $now) => ReportSubscriptionPeriod::nextSendAt($frequency, $this->at($now))->format('Y-m-d H:i');
        $this->assertSame('2026-11-23 07:00', $next('weekly', '2026-11-23 06:59'));
        $this->assertSame('2026-11-30 07:00', $next('weekly', '2026-11-23 07:00'));
        $this->assertSame('2026-12-01 07:00', $next('monthly', '2026-11-26 12:00'));
        $this->assertSame('2027-01-01 07:00', $next('semester', '2026-11-26 12:00'));
        $this->assertSame('2027-07-01 07:00', $next('semester', '2027-01-01 08:00'));
    }
}
