<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Market\TradingCalendar;
use PHPUnit\Framework\TestCase;

final class TradingCalendarTest extends TestCase
{
    protected function setUp(): void
    {
        TradingCalendar::setHolidays(['2026-10-20']); // Tuesday holiday (Dussehra)
    }

    private function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('Asia/Kolkata'));
    }

    public function testWeekendsAndHolidaysAreNotTradingDays(): void
    {
        $this->assertFalse(TradingCalendar::isTradingDay($this->t('2026-10-17'))); // Saturday
        $this->assertFalse(TradingCalendar::isTradingDay($this->t('2026-10-20'))); // holiday
        $this->assertTrue(TradingCalendar::isTradingDay($this->t('2026-10-21')));
    }

    public function testPlusFiveMinutesRollsToNextSession(): void
    {
        $this->assertSame('2026-10-22 09:15', TradingCalendar::step($this->t('2026-10-21 15:25'), '+5m')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-22 09:20', TradingCalendar::step($this->t('2026-10-21 15:30'), '+5m')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 15:25', TradingCalendar::step($this->t('2026-10-21 15:30'), '-5m')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 09:20', TradingCalendar::step($this->t('2026-10-21 09:15'), '+5m')->format('Y-m-d H:i'));
    }

    public function testMinusStepsRollToPreviousSessionSkippingHolidayAndWeekend(): void
    {
        // 21 Oct 2026 is Wednesday; 20 Oct is a holiday so previous session is Monday 19 Oct.
        $this->assertSame('2026-10-19 15:25', TradingCalendar::step($this->t('2026-10-21 09:15'), '-5m')->format('Y-m-d H:i'));
        // Monday 09:15 -1h -> Friday
        $this->assertSame('2026-10-16 14:30', TradingCalendar::step($this->t('2026-10-19 09:15'), '-1h')->format('Y-m-d H:i'));
    }

    public function testDaySteps(): void
    {
        $this->assertSame('2026-10-22 11:05', TradingCalendar::step($this->t('2026-10-21 11:05'), '+1d')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-19 11:05', TradingCalendar::step($this->t('2026-10-21 11:05'), '-1d')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 11:05', TradingCalendar::step($this->t('2026-10-23 11:05'), '+1d')->format('Y-m-d H:i')); // Fri -> Mon
    }

    public function testSodEodAndNormalise(): void
    {
        $this->assertSame('2026-10-21 09:15', TradingCalendar::step($this->t('2026-10-21 11:05'), 'sod')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 15:30', TradingCalendar::step($this->t('2026-10-21 11:05'), 'eod')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 09:15', TradingCalendar::normalise($this->t('2026-10-20 12:00'))->format('Y-m-d H:i')); // holiday -> next open
        $this->assertSame('2026-10-22 09:15', TradingCalendar::normalise($this->t('2026-10-21 16:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 09:15', TradingCalendar::normalise($this->t('2026-10-21 08:00'))->format('Y-m-d H:i'));
    }

    public function testPlusOneHourAcrossClose(): void
    {
        // 15:00 is index 345; +60 = 405 -> next day index 30 = 09:45
        $this->assertSame('2026-10-22 09:45', TradingCalendar::step($this->t('2026-10-21 15:00'), '+1h')->format('Y-m-d H:i'));
    }
}
