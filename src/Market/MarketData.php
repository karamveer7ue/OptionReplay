<?php
declare(strict_types=1);

namespace App\Market;

use App\Core\Database;
use App\Core\Settings;
use App\Pricing\BlackScholes;

/**
 * Read model for the simulator: what the market looked like at a given instant.
 * Prices in the DB are paise; everything returned here is in rupees.
 */
final class MarketData
{
    /** @return array<string,mixed>|null */
    public static function underlying(string $symbol): ?array
    {
        return Database::one('SELECT * FROM underlyings WHERE symbol = ? AND enabled = 1', [strtoupper($symbol)]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function underlyings(): array
    {
        $rows = Database::all('SELECT * FROM underlyings WHERE enabled = 1 ORDER BY sort_order, symbol');
        foreach ($rows as &$r) {
            $r['range'] = self::dataRange((int) $r['id']);
            $r['lot_size'] = (int) $r['lot_size'];
            $r['strike_step'] = (float) $r['strike_step'];
            unset($r['lookback_days'], $r['strike_band'], $r['expiry_window_days']);
        }
        return $rows;
    }

    /**
     * Simulatable range: the span where both spot and option candles exist. Brokers publish option
     * history with a lag and a few contracts at a time, so the upper bound is the latest day on which at
     * least half of the contracts that were live and already trading have candles. Falls back to spot
     * when no options exist yet.
     * @return array{min:?string,max:?string}
     */
    public static function dataRange(int $underlyingId): array
    {
        return \App\Core\Cache::remember("range_{$underlyingId}", 120, fn () => self::computeDataRange($underlyingId));
    }

    /** @return array{min:?string,max:?string} */
    private static function computeDataRange(int $underlyingId): array
    {
        $spot = Database::one('SELECT MIN(ts) mn, MAX(ts) mx FROM spot_candles_1m WHERE underlying_id = ?', [$underlyingId]);
        $min = $spot['mn'] ?? null;
        $max = $spot['mx'] ?? null;
        $rows = Database::all(
            'SELECT i.expiry,
                    (SELECT MIN(ts) FROM candles_5m c WHERE c.instrument_id = i.id) mn,
                    (SELECT DATE_ADD(MAX(ts), INTERVAL 5 MINUTE) FROM candles_5m c WHERE c.instrument_id = i.id AND TIME(c.ts) <= \'15:25:00\') mx
             FROM instruments i WHERE i.underlying_id = ? HAVING mn IS NOT NULL',
            [$underlyingId],
        );
        if (!$rows || $min === null) {
            return ['min' => $min, 'max' => $max];
        }
        $optMin = min(array_column($rows, 'mn'));
        $min = max($min, $optMin);
        // Candidate days, newest first: for each, count contracts live that day and already listed
        // (first candle on or before the day) versus those whose data reaches the day.
        $days = array_unique(array_map(fn ($r) => substr((string) $r['mx'], 0, 10), $rows));
        rsort($days);
        $covered = null;
        foreach ($days as $day) {
            $live = 0;
            $has = 0;
            foreach ($rows as $r) {
                if ($r['expiry'] >= $day && substr((string) $r['mn'], 0, 10) <= $day) {
                    $live++;
                    if (substr((string) $r['mx'], 0, 10) >= $day) {
                        $has++;
                    }
                }
            }
            if ($live > 0 && $has / $live >= 0.5) {
                $covered = $day;
                break;
            }
        }
        if ($covered !== null) {
            $dayMax = max(array_map(fn ($r) => (string) $r['mx'], array_filter($rows, fn ($r) => substr((string) $r['mx'], 0, 10) === $covered)));
            $max = min($max, $dayMax);
        }
        return ['min' => $min, 'max' => $max];
    }

    /** Spot at or before $t within the same session, with day open and previous close. @return array<string,mixed> */
    public static function spot(int $underlyingId, \DateTimeImmutable $t): array
    {
        $day = $t->format('Y-m-d');
        $row = Database::one(
            'SELECT ts, c FROM spot_candles_1m WHERE underlying_id = ? AND ts >= ? AND ts <= ? ORDER BY ts DESC LIMIT 1',
            [$underlyingId, "{$day} 09:00:00", $t->format('Y-m-d H:i:s')],
        );
        $open = Database::scalar('SELECT o FROM spot_candles_1m WHERE underlying_id = ? AND ts >= ? ORDER BY ts ASC LIMIT 1', [$underlyingId, "{$day} 09:00:00"]);
        $prev = Database::one('SELECT day, c FROM spot_candles_1d WHERE underlying_id = ? AND day < ? ORDER BY day DESC LIMIT 1', [$underlyingId, $day]);
        $ltp = $row ? $row['c'] / 100 : null;
        if ($ltp === null && $prev) {
            $ltp = $prev['c'] / 100; // before the first tick of the day: show previous close
        }
        $prevClose = $prev ? $prev['c'] / 100 : null;
        return [
            'ltp' => $ltp,
            'ts' => $row['ts'] ?? null,
            'open' => $open !== null ? $open / 100 : null,
            'prev_close' => $prevClose,
            'change' => ($ltp !== null && $prevClose !== null) ? round($ltp - $prevClose, 2) : null,
            'change_pct' => ($ltp !== null && $prevClose) ? round(($ltp - $prevClose) / $prevClose * 100, 2) : null,
            'stale' => $row === null,
        ];
    }

    /** Expiries that were live at $t (expiry >= date) and have at least one contract. @return array<int,array<string,mixed>> */
    public static function expiries(int $underlyingId, \DateTimeImmutable $t): array
    {
        $day = $t->format('Y-m-d');
        $rows = Database::all(
            "SELECT expiry, COUNT(*) n FROM instruments WHERE underlying_id = ? AND kind IN ('CE','PE') AND expiry >= ? GROUP BY expiry ORDER BY expiry",
            [$underlyingId, $day],
        );
        $out = [];
        foreach ($rows as $r) {
            $dte = (int) (new \DateTimeImmutable($day))->diff(new \DateTimeImmutable($r['expiry']))->days;
            $out[] = ['expiry' => $r['expiry'], 'dte' => $dte, 'contracts' => (int) $r['n']];
        }
        return $out;
    }

    /**
     * Quote for many instruments at $t from 5-minute candles, same session only. A candle labelled
     * 09:15 covers 09:15:00 to 09:19:59, so the price "at" $t is the close of the last candle that
     * ENDED by $t (label <= t - 5min); at the session open the first candle's open is used instead.
     * Falls back to the previous daily close (flagged stale) when the contract has not traded yet that day.
     * @param int[] $ids
     * @return array<int,array{ltp:float,volume:int,oi:?int,ts:string,stale:bool}>
     */
    public static function quotesAt(array $ids, \DateTimeImmutable $t): array
    {
        if (!$ids) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $day = $t->format('Y-m-d');
        $cutoff = $t->modify('-5 minutes')->format('Y-m-d H:i:s');
        $rows = Database::all(
            "SELECT c1.instrument_id, c1.ts, c1.c, c1.volume, c1.oi
             FROM candles_5m c1
             JOIN (SELECT instrument_id, MAX(ts) ts FROM candles_5m WHERE instrument_id IN ({$in}) AND ts >= ? AND ts <= ? GROUP BY instrument_id) m
               ON m.instrument_id = c1.instrument_id AND m.ts = c1.ts",
            [...$ids, "{$day} 09:00:00", $cutoff],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['instrument_id']] = ['ltp' => $r['c'] / 100, 'volume' => (int) $r['volume'], 'oi' => $r['oi'] !== null ? (int) $r['oi'] : null, 'ts' => $r['ts'], 'stale' => false];
        }
        $missing = array_values(array_diff($ids, array_keys($out)));
        if ($missing) {
            // Within the first bucket of the day: the opening print of the candle that starts at or before $t.
            $in2 = implode(',', array_fill(0, count($missing), '?'));
            $opens = Database::all(
                "SELECT o1.instrument_id, o1.ts, o1.o, o1.oi FROM candles_5m o1
                 JOIN (SELECT instrument_id, MIN(ts) ts FROM candles_5m WHERE instrument_id IN ({$in2}) AND ts >= ? AND ts <= ? GROUP BY instrument_id) m
                   ON m.instrument_id = o1.instrument_id AND m.ts = o1.ts",
                [...$missing, "{$day} 09:00:00", $t->format('Y-m-d H:i:s')],
            );
            foreach ($opens as $r) {
                $out[(int) $r['instrument_id']] = ['ltp' => $r['o'] / 100, 'volume' => 0, 'oi' => $r['oi'] !== null ? (int) $r['oi'] : null, 'ts' => $r['ts'], 'stale' => false];
            }
            $missing = array_values(array_diff($ids, array_keys($out)));
        }
        if ($missing) {
            $in3 = implode(',', array_fill(0, count($missing), '?'));
            $prev = Database::all(
                "SELECT d1.instrument_id, d1.day, d1.c, d1.oi FROM candles_1d d1
                 JOIN (SELECT instrument_id, MAX(day) day FROM candles_1d WHERE instrument_id IN ({$in3}) AND day < ? GROUP BY instrument_id) m
                   ON m.instrument_id = d1.instrument_id AND m.day = d1.day",
                [...$missing, $day],
            );
            foreach ($prev as $r) {
                $out[(int) $r['instrument_id']] = ['ltp' => $r['c'] / 100, 'volume' => 0, 'oi' => $r['oi'] !== null ? (int) $r['oi'] : null, 'ts' => $r['day'] . ' 15:30:00', 'stale' => true];
            }
        }
        return $out;
    }

    /**
     * Full option chain for one expiry at $t with IV and Greeks.
     * @return array{atm:?float,rows:array<int,array<string,mixed>>,lot_size:int}
     */
    public static function chain(array $u, string $expiry, \DateTimeImmutable $t, ?float $spot): array
    {
        $uid = (int) $u['id'];
        $insts = Database::all(
            "SELECT id, kind, strike, lot_size FROM instruments WHERE underlying_id = ? AND expiry = ? AND kind IN ('CE','PE') ORDER BY strike",
            [$uid, $expiry],
        );
        $quotes = self::quotesAt(array_map(fn ($i) => (int) $i['id'], $insts), $t);
        $r = Settings::float('risk_free_rate');
        $tte = TradingCalendar::yearsToExpiry($t, $expiry);

        $byStrike = [];
        $lot = 0;
        foreach ($insts as $i) {
            $lot = $lot ?: (int) $i['lot_size'];
            $strike = (float) $i['strike'];
            $q = $quotes[(int) $i['id']] ?? null;
            $cell = ['id' => (int) $i['id'], 'ltp' => $q['ltp'] ?? null, 'oi' => $q['oi'] ?? null, 'volume' => $q['volume'] ?? null, 'stale' => $q['stale'] ?? true, 'iv' => null, 'delta' => null, 'theta' => null, 'gamma' => null, 'vega' => null];
            if ($q && $spot) {
                $iv = BlackScholes::impliedVol($i['kind'], $q['ltp'], $spot, $strike, $tte, $r);
                if ($iv !== null) {
                    $g = BlackScholes::greeks($i['kind'], $spot, $strike, $tte, $r, $iv);
                    $cell['iv'] = round($iv * 100, 2);
                    $cell['delta'] = round($g['delta'], 3);
                    $cell['theta'] = round($g['theta'], 2);
                    $cell['gamma'] = round($g['gamma'], 5);
                    $cell['vega'] = round($g['vega'], 2);
                } else {
                    $intrinsic = max(0.0, $i['kind'] === 'CE' ? $spot - $strike : $strike - $spot);
                    $cell['delta'] = $intrinsic > 0 ? ($i['kind'] === 'CE' ? 1.0 : -1.0) : 0.0;
                }
            }
            $byStrike[(string) $strike][$i['kind']] = $cell;
            $byStrike[(string) $strike]['strike'] = $strike;
        }
        ksort($byStrike, SORT_NUMERIC);
        $atm = null;
        if ($spot !== null) {
            foreach (array_keys($byStrike) as $s) {
                if ($atm === null || abs((float) $s - $spot) < abs($atm - $spot)) {
                    $atm = (float) $s;
                }
            }
        }
        return ['atm' => $atm, 'rows' => array_values($byStrike), 'lot_size' => $lot ?: (int) $u['lot_size']];
    }

    /**
     * Expected move (standard deviation of the spot) to the nearest weekly and the monthly expiry,
     * from the at-the-money implied volatility at $t: sd = spot * iv * sqrt(T).
     * @param array<int,array<string,mixed>> $expiries from self::expiries()
     * @return array<string,array<string,mixed>|null> ['weekly' => {...}, 'monthly' => {...}]
     */
    public static function expectedMoves(array $u, \DateTimeImmutable $t, ?float $spot, array $expiries): array
    {
        $out = ['weekly' => null, 'monthly' => null];
        if ($spot === null || !$expiries) {
            return $out;
        }
        $dates = array_column($expiries, 'expiry');
        $lastOfMonth = [];
        foreach ($dates as $d) {
            $lastOfMonth[substr($d, 0, 7)] = $d;
        }
        // Weekly = nearest expiry. Monthly = nearest month-end expiry other than that one, so the two
        // horizons always differ (when the nearest expiry is itself the monthly, use next month's).
        $weekly = $dates[0];
        $monthly = null;
        foreach ($dates as $d) {
            if ($d !== $weekly && $lastOfMonth[substr($d, 0, 7)] === $d) {
                $monthly = $d;
                break;
            }
        }
        foreach (['weekly' => $weekly, 'monthly' => $monthly] as $kind => $expiry) {
            if ($expiry === null) {
                continue;
            }
            $iv = self::atmIv($u, $expiry, $t, $spot);
            if ($iv === null) {
                continue;
            }
            $tte = TradingCalendar::yearsToExpiry($t, $expiry);
            $sd = $spot * $iv * sqrt($tte);
            $out[$kind] = ['expiry' => $expiry, 'iv' => round($iv * 100, 2), 'sd1' => round($sd, 2), 'sd2' => round(2 * $sd, 2), 'days' => round($tte * 365, 1)];
        }
        return $out;
    }

    /** Average implied volatility of the ATM call and put for one expiry, or null when neither prices. */
    public static function atmIv(array $u, string $expiry, \DateTimeImmutable $t, float $spot): ?float
    {
        $strike = Database::scalar(
            "SELECT strike FROM instruments WHERE underlying_id = ? AND expiry = ? AND kind = 'CE' ORDER BY ABS(strike - ?) LIMIT 1",
            [$u['id'], $expiry, $spot],
        );
        if ($strike === null) {
            return null;
        }
        $insts = Database::all("SELECT id, kind FROM instruments WHERE underlying_id = ? AND expiry = ? AND strike = ? AND kind IN ('CE','PE')", [$u['id'], $expiry, $strike]);
        $quotes = self::quotesAt(array_map(fn ($i) => (int) $i['id'], $insts), $t);
        $tte = TradingCalendar::yearsToExpiry($t, $expiry);
        $r = Settings::float('risk_free_rate');
        $ivs = [];
        foreach ($insts as $i) {
            $q = $quotes[(int) $i['id']] ?? null;
            if ($q) {
                $iv = BlackScholes::impliedVol($i['kind'], $q['ltp'], $spot, (float) $strike, $tte, $r);
                if ($iv !== null) {
                    $ivs[] = $iv;
                }
            }
        }
        return $ivs ? array_sum($ivs) / count($ivs) : null;
    }

    /** Nearest futures contract price at $t, or a synthetic one. @return array<string,mixed> */
    public static function future(array $u, \DateTimeImmutable $t, ?float $spot): array
    {
        $day = $t->format('Y-m-d');
        $fut = Database::one("SELECT id, expiry FROM instruments WHERE underlying_id = ? AND kind = 'FUT' AND expiry >= ? ORDER BY expiry LIMIT 1", [$u['id'], $day]);
        if ($fut) {
            $q = self::quotesAt([(int) $fut['id']], $t);
            if (isset($q[(int) $fut['id']])) {
                return ['price' => $q[(int) $fut['id']]['ltp'], 'expiry' => $fut['expiry'], 'synthetic' => false, 'stale' => $q[(int) $fut['id']]['stale']];
            }
        }
        if ($spot === null) {
            return ['price' => null, 'expiry' => $fut['expiry'] ?? null, 'synthetic' => true, 'stale' => true];
        }
        $expiry = $fut['expiry'] ?? self::lastThursdayOfMonth($t);
        $tte = TradingCalendar::yearsToExpiry($t, $expiry);
        return ['price' => round($spot * exp(Settings::float('risk_free_rate') * $tte), 2), 'expiry' => $expiry, 'synthetic' => true, 'stale' => false];
    }

    private static function lastThursdayOfMonth(\DateTimeImmutable $t): string
    {
        $d = new \DateTimeImmutable('last thursday of ' . $t->format('F Y'));
        if ($d < $t->setTime(0, 0)) {
            $d = new \DateTimeImmutable('last thursday of ' . $t->modify('first day of next month')->format('F Y'));
        }
        return $d->format('Y-m-d');
    }

    /**
     * Close series for instruments between two instants, sampled every $stepMinutes (a multiple of 5).
     * Each point is timestamped at the END of its 5-minute candle, i.e. the moment that close was known.
     * @param int[] $ids
     * @return array<int,array<int,array{0:string,1:float}>> instrument_id => [[ts, close], ...]
     */
    public static function closeSeries(array $ids, \DateTimeImmutable $from, \DateTimeImmutable $to, int $stepMinutes): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $step = max(5, (int) (ceil($stepMinutes / 5) * 5));
        $rows = Database::all(
            "SELECT instrument_id, DATE_ADD(ts, INTERVAL 5 MINUTE) ts, c FROM candles_5m
             WHERE instrument_id IN ({$in}) AND ts >= ? AND ts < ? AND TIME(ts) <= '15:25:00' AND MOD(MINUTE(ts) + 60*HOUR(ts) - 555 + 5, ?) = 0 ORDER BY ts",
            [...array_map('intval', $ids), $from->modify('-5 minutes')->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $step],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['instrument_id']][] = [$r['ts'], $r['c'] / 100];
        }
        return $out;
    }

    /**
     * Spot OHLC series for charts.
     * @return array<int,array{time:int,open:float,high:float,low:float,close:float}>
     */
    public static function spotSeries(int $underlyingId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        if ($interval === '1d') {
            $rows = Database::all('SELECT day ts, o, h, l, c FROM spot_candles_1d WHERE underlying_id = ? AND day BETWEEN ? AND ? ORDER BY day', [$underlyingId, $from->format('Y-m-d'), $to->format('Y-m-d')]);
            return array_map(fn ($r) => ['time' => (new \DateTimeImmutable($r['ts'] . ' 09:15', new \DateTimeZone('Asia/Kolkata')))->getTimestamp(), 'open' => $r['o'] / 100, 'high' => $r['h'] / 100, 'low' => $r['l'] / 100, 'close' => $r['c'] / 100], $rows);
        }
        $mins = ['1m' => 1, '5m' => 5, '15m' => 15, '1h' => 60][$interval] ?? 5;
        $rows = Database::all(
            'SELECT ts, o, h, l, c FROM spot_candles_1m WHERE underlying_id = ? AND ts >= ? AND ts <= ? ORDER BY ts',
            [$underlyingId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
        );
        // Aggregate 1-minute bars into the requested bucket, aligned to 09:15.
        $out = [];
        $bucketKey = null;
        $cur = null;
        foreach ($rows as $r) {
            $ts = new \DateTimeImmutable($r['ts'], new \DateTimeZone('Asia/Kolkata'));
            $minuteOfSession = ((int) $ts->format('H') * 60 + (int) $ts->format('i')) - 555;
            $key = $ts->format('Y-m-d') . ':' . intdiv(max(0, $minuteOfSession), $mins);
            if ($key !== $bucketKey) {
                if ($cur) {
                    $out[] = $cur;
                }
                $bucketKey = $key;
                $cur = ['time' => $ts->getTimestamp(), 'open' => $r['o'] / 100, 'high' => $r['h'] / 100, 'low' => $r['l'] / 100, 'close' => $r['c'] / 100];
            } else {
                $cur['high'] = max($cur['high'], $r['h'] / 100);
                $cur['low'] = min($cur['low'], $r['l'] / 100);
                $cur['close'] = $r['c'] / 100;
            }
        }
        if ($cur) {
            $out[] = $cur;
        }
        return $out;
    }
}
