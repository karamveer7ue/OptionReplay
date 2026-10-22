<?php
declare(strict_types=1);

namespace App\Market;

use App\Core\Database;

/**
 * Trading calendar and time stepping for the simulator.
 * Market hours 09:15 to 15:30 IST. Weekdays are trading days unless listed in `holidays`.
 */
final class TradingCalendar
{
    public const OPEN = '09:15';
    public const CLOSE = '15:30';
    public const TZ = 'Asia/Kolkata';
    public const SESSION_MINUTES = 375;

    /** @var array<string,true>|null */
    private static ?array $holidays = null;

    /** Override the holiday set (tests, or callers without a database). @param string[] $days */
    public static function setHolidays(array $days): void
    {
        self::$holidays = array_fill_keys($days, true);
    }

    /** @return array<string,true> */
    private static function holidays(): array
    {
        if (self::$holidays === null) {
            self::$holidays = [];
            foreach (Database::all('SELECT day FROM holidays') as $r) {
                self::$holidays[$r['day']] = true;
            }
        }
        return self::$holidays;
    }

    public static function isTradingDay(\DateTimeImmutable $d): bool
    {
        $dow = (int) $d->format('N');
        return $dow <= 5 && !isset(self::holidays()[$d->format('Y-m-d')]);
    }

    public static function nextTradingDay(\DateTimeImmutable $d): \DateTimeImmutable
    {
        do {
            $d = $d->modify('+1 day');
        } while (!self::isTradingDay($d));
        return $d;
    }

    public static function prevTradingDay(\DateTimeImmutable $d): \DateTimeImmutable
    {
        do {
            $d = $d->modify('-1 day');
        } while (!self::isTradingDay($d));
        return $d;
    }

    private static function at(\DateTimeImmutable $d, string $hm): \DateTimeImmutable
    {
        return $d->setTime((int) substr($hm, 0, 2), (int) substr($hm, 3, 2), 0);
    }

    /** Clamp a timestamp into market hours of a trading day (rolling forward when outside). */
    public static function normalise(\DateTimeImmutable $t): \DateTimeImmutable
    {
        $t = $t->setTimezone(new \DateTimeZone(self::TZ))->setTime((int) $t->format('H'), (int) $t->format('i'), 0);
        if (!self::isTradingDay($t)) {
            return self::at(self::nextTradingDay($t), self::OPEN);
        }
        if ($t < self::at($t, self::OPEN)) {
            return self::at($t, self::OPEN);
        }
        if ($t > self::at($t, self::CLOSE)) {
            return self::at(self::nextTradingDay($t), self::OPEN);
        }
        return $t;
    }

    /**
     * Apply a step: +5m -5m +15m -15m +1h -1h sod eod +1d -1d.
     * Intraday steps roll across the session boundary into the adjacent trading day.
     */
    public static function step(\DateTimeImmutable $t, string $step): \DateTimeImmutable
    {
        $t = self::normalise($t);
        $open = self::at($t, self::OPEN);
        $close = self::at($t, self::CLOSE);
        switch ($step) {
            case 'sod':
                return $open;
            case 'eod':
                return $close;
            case '+1d':
                return self::at(self::nextTradingDay($t), $t->format('H:i'));
            case '-1d':
                return self::at(self::prevTradingDay($t), $t->format('H:i'));
        }
        if (!preg_match('/^([+-])(\d+)(m|h)$/', $step, $m)) {
            throw new \InvalidArgumentException("Unknown step {$step}");
        }
        // The session is a grid of 375 minutes: index 0 = 09:15 ... 374 = 15:29, and 15:30 is index 375,
        // which is the same instant as the next session's 09:15 for stepping purposes.
        $minutes = (int) $m[2] * ($m[3] === 'h' ? 60 : 1) * ($m[1] === '+' ? 1 : -1);
        $index = (int) (($t->getTimestamp() - $open->getTimestamp()) / 60) + $minutes;
        $day = $t;
        while ($index >= self::SESSION_MINUTES) {
            $index -= self::SESSION_MINUTES;
            $day = self::nextTradingDay($day);
        }
        while ($index < 0) {
            $index += self::SESSION_MINUTES;
            $day = self::prevTradingDay($day);
        }
        return self::at($day, self::OPEN)->modify("+{$index} minutes");
    }

    /** Trading days remaining until expiry, as a fraction of a year, from timestamp $t. */
    public static function yearsToExpiry(\DateTimeImmutable $t, string $expiry): float
    {
        $exp = (new \DateTimeImmutable($expiry . ' ' . self::CLOSE, new \DateTimeZone(self::TZ)));
        $seconds = $exp->getTimestamp() - $t->getTimestamp();
        return max(0.0, $seconds / (365.0 * 86400));
    }

    /** @return array<int,string> trading days (Y-m-d) between two dates inclusive */
    public static function tradingDaysBetween(string $from, string $to): array
    {
        $out = [];
        $d = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($d <= $end) {
            if (self::isTradingDay($d)) {
                $out[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
        }
        return $out;
    }
}
