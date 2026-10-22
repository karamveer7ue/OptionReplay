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
     * Simulatable range: the span where both spot and option candles exist (options are the
     * binding constraint, Kotak publishes them with a lag). Falls back to spot when no options yet.
     * @return array{min:?string,max:?string}
     */
    public static function dataRange(int $underlyingId): array
    {
        $spot = Database::one('SELECT MIN(ts) mn, MAX(ts) mx FROM spot_candles_1m WHERE underlying_id = ?', [$underlyingId]);
        $opt = Database::one(
            'SELECT MIN(x.mn) mn, MAX(x.mx) mx FROM (
                SELECT (SELECT MIN(ts) FROM candles_1m c WHERE c.instrument_id = i.id) mn,
                       (SELECT MAX(ts) FROM candles_1m c WHERE c.instrument_id = i.id AND TIME(c.ts) <= \'15:30:00\') mx
                FROM instruments i WHERE i.underlying_id = ?) x',
            [$underlyingId],
        );
        $min = $spot['mn'] ?? null;
        $max = $spot['mx'] ?? null;
        if (!empty($opt['mn']) && $min !== null) {
            $min = max($min, $opt['mn']);
        }
        if (!empty($opt['mx']) && $max !== null) {
            $max = min($max, $opt['mx']);
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
     * Last known 1-minute quote (close, volume, oi) for many instruments at $t, same session only.
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
        $rows = Database::all(
            "SELECT c1.instrument_id, c1.ts, c1.c, c1.volume, c1.oi
             FROM candles_1m c1
             JOIN (SELECT instrument_id, MAX(ts) ts FROM candles_1m WHERE instrument_id IN ({$in}) AND ts >= ? AND ts <= ? GROUP BY instrument_id) m
               ON m.instrument_id = c1.instrument_id AND m.ts = c1.ts",
            [...$ids, "{$day} 09:00:00", $t->format('Y-m-d H:i:s')],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['instrument_id']] = ['ltp' => $r['c'] / 100, 'volume' => (int) $r['volume'], 'oi' => $r['oi'] !== null ? (int) $r['oi'] : null, 'ts' => $r['ts'], 'stale' => false];
        }
        $missing = array_values(array_diff($ids, array_keys($out)));
        if ($missing) {
            $in2 = implode(',', array_fill(0, count($missing), '?'));
            $prev = Database::all(
                "SELECT d1.instrument_id, d1.day, d1.c, d1.oi FROM candles_1d d1
                 JOIN (SELECT instrument_id, MAX(day) day FROM candles_1d WHERE instrument_id IN ({$in2}) AND day < ? GROUP BY instrument_id) m
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
     * Close series for instruments between two instants, sampled every $stepMinutes.
     * @param int[] $ids
     * @return array<int,array<int,array{0:string,1:float}>> instrument_id => [[ts, close], ...]
     */
    public static function closeSeries(array $ids, \DateTimeImmutable $from, \DateTimeImmutable $to, int $stepMinutes): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT instrument_id, ts, c FROM candles_1m WHERE instrument_id IN ({$in}) AND ts >= ? AND ts <= ? AND MOD(MINUTE(ts) + 60*HOUR(ts) - 555, ?) = 0 ORDER BY ts",
            [...array_map('intval', $ids), $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), max(1, $stepMinutes)],
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
