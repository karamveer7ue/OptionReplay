<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Market\ExpiryRule;
use App\Market\TradingCalendar;
use PHPUnit\Framework\TestCase;

final class ExpiryRuleTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $nifty = ['id' => 1, 'weekly_expiry_dow' => 2, 'monthly_expiry_dow' => 2];

    protected function setUp(): void
    {
        TradingCalendar::setHolidays(['2026-10-20']);
        ExpiryRule::setKnown(1, []);
    }

    private function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('Asia/Kolkata'));
    }

    public function testWeeklyIsNextTuesday(): void
    {
        $this->assertSame('2026-10-13', ExpiryRule::next($this->nifty, $this->t('2026-10-08 10:00'), 'weekly'));
        $this->assertSame('2026-10-13', ExpiryRule::next($this->nifty, $this->t('2026-10-13 15:00'), 'weekly')); // expiry day itself
    }

    public function testWeeklyOnHolidayShiftsToPreviousTradingDay(): void
    {
        // Tuesday 20 Oct 2026 is a holiday -> Monday 19 Oct
        $this->assertSame('2026-10-19', ExpiryRule::next($this->nifty, $this->t('2026-10-14 10:00'), 'weekly'));
        $this->assertSame('2026-10-27', ExpiryRule::next($this->nifty, $this->t('2026-10-21 10:00'), 'weekly'));
    }

    public function testMonthlyIsLastTuesdayOfMonth(): void
    {
        $this->assertSame('2026-10-27', ExpiryRule::next($this->nifty, $this->t('2026-10-05 10:00'), 'monthly'));
        $this->assertSame('2026-11-24', ExpiryRule::next($this->nifty, $this->t('2026-10-28 10:00'), 'monthly'));
    }

    public function testKnownCalendarWins(): void
    {
        ExpiryRule::setKnown(1, ['2026-10-15' => 'weekly', '2026-10-29' => 'monthly']);
        $this->assertSame('2026-10-15', ExpiryRule::next($this->nifty, $this->t('2026-10-14 10:00'), 'weekly'));
        $this->assertSame('2026-10-29', ExpiryRule::next($this->nifty, $this->t('2026-10-14 10:00'), 'monthly'));
    }
}
