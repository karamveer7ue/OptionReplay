<?php
declare(strict_types=1);

namespace App\Market;

use App\Core\Database;

/**
 * Resolves option expiry dates for a date in the past.
 * Known dates come from `expiry_calendar` (filled from broker discovery); otherwise the exchange rule
 * is applied: weekly = next occurrence of the weekly weekday, monthly = last such weekday of the month,
 * both shifted back to the previous trading day when they fall on a holiday.
 */
final class ExpiryRule
{
    /** @var array<int,array<string,string>> underlying_id => [expiry => kind] */
    private static array $known = [];

    /** @param array<string,mixed> $u underlying row (weekly_expiry_dow, monthly_expiry_dow) */
    public static function next(array $u, \DateTimeImmutable $date, string $kind): string
    {
        $uid = (int) $u['id'];
        $day = $date->format('Y-m-d');
        foreach (self::known($uid) as $expiry => $k) {
            if ($k === $kind && $expiry >= $day) {
                return $expiry;
            }
        }
        if ($kind === 'weekly') {
            $dow = (int) ($u['weekly_expiry_dow'] ?? 0);
            if ($dow <= 0) {
                throw new \RuntimeException('Underlying has no weekly expiry');
            }
            // Walk forward to the next weekly weekday; if a holiday moved it earlier, the shifted date is still >= today check.
            $d = $date->setTime(0, 0);
            for ($i = 0; $i < 14; $i++) {
                if ((int) $d->format('N') === $dow) {
                    $shifted = self::shift($d);
                    if ($shifted >= $date->setTime(0, 0)) {
                        return $shifted->format('Y-m-d');
                    }
                }
                $d = $d->modify('+1 day');
            }
            throw new \RuntimeException('Could not resolve weekly expiry');
        }
        $dow = (int) ($u['monthly_expiry_dow'] ?? 4);
        $d = $date->setTime(0, 0);
        for ($m = 0; $m < 3; $m++) {
            $month = $d->modify("+{$m} month")->modify('first day of this month');
            $last = $month->modify('last day of this month');
            while ((int) $last->format('N') !== $dow) {
                $last = $last->modify('-1 day');
            }
            $shifted = self::shift($last);
            if ($shifted >= $date->setTime(0, 0)) {
                return $shifted->format('Y-m-d');
            }
        }
        throw new \RuntimeException('Could not resolve monthly expiry');
    }

    private static function shift(\DateTimeImmutable $d): \DateTimeImmutable
    {
        while (!TradingCalendar::isTradingDay($d)) {
            $d = $d->modify('-1 day');
        }
        return $d;
    }

    /** @return array<string,string> expiry => kind, ascending */
    private static function known(int $uid): array
    {
        if (!isset(self::$known[$uid])) {
            self::$known[$uid] = [];
            foreach (Database::all('SELECT expiry, kind FROM expiry_calendar WHERE underlying_id = ? ORDER BY expiry', [$uid]) as $r) {
                self::$known[$uid][$r['expiry']] = $r['kind'];
            }
        }
        return self::$known[$uid];
    }

    /** Record broker-discovered expiries. Monthly = the last expiry of its calendar month in the list. @param string[] $expiries */
    public static function record(int $uid, array $expiries): void
    {
        sort($expiries);
        $byMonth = [];
        foreach ($expiries as $e) {
            $byMonth[substr($e, 0, 7)] = $e; // last one per month wins
        }
        foreach ($expiries as $e) {
            $kind = ($byMonth[substr($e, 0, 7)] === $e) ? 'monthly' : 'weekly';
            Database::run('INSERT INTO expiry_calendar (underlying_id, expiry, kind, source) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE kind = VALUES(kind), source = VALUES(source)', [$uid, $e, $kind, 'broker']);
        }
        unset(self::$known[$uid]);
    }

    /** Test seam. @param array<string,string> $map */
    public static function setKnown(int $uid, array $map): void
    {
        self::$known[$uid] = $map;
    }
}
