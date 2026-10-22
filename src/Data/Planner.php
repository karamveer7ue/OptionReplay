<?php
declare(strict_types=1);

namespace App\Data;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Data\Repository\InstrumentRepository;
use App\Data\Sources\MarketDataSource;

/**
 * Turns the admin configuration into concrete, idempotent fetch jobs.
 *
 *  - spot_1m / spot_1d: index candles for the whole lookback window, chunked to the source's limits.
 *  - discover: per underlying, resolve expiries within the window and the strike band around spot,
 *    register instruments, then queue inst_1m / inst_1d jobs for each.
 */
final class Planner
{
    public const CHUNK_EPOCH = '2020-01-01';

    public function __construct(private readonly MarketDataSource $source)
    {
    }

    /** @param array<string,mixed> $u underlying row */
    public function planUnderlying(array $u, string $today): int
    {
        $uid = (int) $u['id'];
        $lookback = (int) ($u['lookback_days'] ?? null ?: Settings::int('lookback_days'));
        $from = (new \DateTimeImmutable($today))->modify("-{$lookback} days")->format('Y-m-d');

        $created = 0;
        foreach ($this->chunks($from, $today, $this->source->maxDays('1min')) as [$a, $b]) {
            $created += (int) JobQueue::enqueue('spot_1m', $uid, null, $a, $b, 10);
        }
        foreach ($this->chunks($from, $today, $this->source->maxDays('D')) as [$a, $b]) {
            $created += (int) JobQueue::enqueue('spot_1d', $uid, null, $a, $b, 5);
        }
        // Discovery is re-run daily: key includes the date.
        $created += (int) JobQueue::enqueue('discover', $uid, null, $today, $today, 20);
        return $created;
    }

    /**
     * Executes a discover job: register contracts and queue their candle jobs.
     * @param array<string,mixed> $u underlying row
     */
    public function discover(array $u, string $today): int
    {
        $uid = (int) $u['id'];
        $windowDays = (int) ($u['expiry_window_days'] ?? null ?: Settings::int('expiry_window_days'));
        $band = (int) ($u['strike_band'] ?? null ?: Settings::int('strike_band'));
        $lookback = (int) ($u['lookback_days'] ?? null ?: Settings::int('lookback_days'));
        $windowEnd = (new \DateTimeImmutable($today))->modify("+{$windowDays} days")->format('Y-m-d');
        $earliest = (new \DateTimeImmutable($today))->modify("-{$lookback} days")->format('Y-m-d');

        $allExpiries = $this->source->expiries($u['fo_exchange'], $u['symbol']);
        \App\Market\ExpiryRule::record($uid, $allExpiries);
        $expiries = array_filter($allExpiries, fn ($d) => $d >= $today && $d <= $windowEnd);
        $created = 0;
        $instrumentIds = [];
        $lot = 0;
        $strikes = [];

        foreach ($expiries as $expiry) {
            $this->pause();
            $contracts = $this->source->optionChain($u['fo_exchange'], $u['symbol'], $expiry, $band * 2);
            foreach ($contracts as $c) {
                $lot = $lot ?: $c->lotSize;
                $strikes[] = $c->strike;
                $instrumentIds[] = [InstrumentRepository::upsert($uid, $c, $this->source->name()), $expiry];
            }
            Logger::info('fetch', "Discovered {$u['symbol']} {$expiry}: " . count($contracts) . ' contracts');
        }

        $this->pause();
        foreach ($this->source->futuresChain($u['fo_exchange'], $u['symbol']) as $f) {
            if ($f->expiry >= $today) {
                $instrumentIds[] = [InstrumentRepository::upsert($uid, $f, $this->source->name()), $f->expiry];
            }
        }

        // Refresh lot size and strike step from live data.
        $strikes = array_values(array_unique($strikes));
        sort($strikes);
        $step = null;
        for ($i = 1; $i < count($strikes); $i++) {
            $d = $strikes[$i] - $strikes[$i - 1];
            if ($d > 0 && ($step === null || $d < $step)) {
                $step = $d;
            }
        }
        if ($lot > 0 || $step !== null) {
            Database::run('UPDATE underlyings SET lot_size = IF(? > 0, ?, lot_size), strike_step = COALESCE(?, strike_step) WHERE id = ?', [$lot, $lot, $step, $uid]);
        }

        // Queue candle history for each contract. Contracts are not listed arbitrarily far back:
        // start at the later of (lookback start) and (expiry - 120 days).
        foreach ($instrumentIds as [$instId, $expiry]) {
            $listFrom = max($earliest, (new \DateTimeImmutable($expiry))->modify('-120 days')->format('Y-m-d'));
            $to = min($today, $expiry);
            foreach (array_reverse($this->chunks($listFrom, $to, $this->source->maxDays('1min'))) as $i => [$a, $b]) {
                // Newest chunk first (priority 30), daily candles next (35), older chunks after (40+).
                $created += (int) JobQueue::enqueue('inst_1m', $uid, $instId, $a, $b, $i === 0 ? 30 : 40 + $i);
            }
            foreach ($this->chunks($listFrom, $to, $this->source->maxDays('D')) as [$a, $b]) {
                $created += (int) JobQueue::enqueue('inst_1d', $uid, $instId, $a, $b, 35);
            }
        }
        return $created;
    }

    /**
     * Daily top-up: today's candles for every live contract and the spot.
     * @param array<string,mixed> $u
     */
    public function planDailyRefresh(array $u, string $today): int
    {
        $uid = (int) $u['id'];
        $from = (new \DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');
        $created = (int) JobQueue::enqueue('spot_1m', $uid, null, $from, $today, 10);
        $created += (int) JobQueue::enqueue('spot_1d', $uid, null, $from, $today, 5);
        foreach (InstrumentRepository::liveForUnderlying($uid, $from) as $inst) {
            $created += (int) JobQueue::enqueue('inst_1m', $uid, (int) $inst['id'], $from, min($today, $inst['expiry']), 15);
            $created += (int) JobQueue::enqueue('inst_1d', $uid, (int) $inst['id'], $from, min($today, $inst['expiry']), 15);
        }
        return $created;
    }

    /**
     * Dhan expired-options backfill: for every 30-day window of the lookback that precedes Kotak's option
     * coverage, one job per (expiry flag, strike offset, side). Requires dhan_segment/dhan_security_id.
     * @param array<string,mixed> $u
     */
    public function planDhan(array $u, string $today): int
    {
        if (empty($u['dhan_segment']) || empty($u['dhan_security_id'])) {
            return 0;
        }
        $uid = (int) $u['id'];
        $lookback = (int) ($u['lookback_days'] ?? null ?: Settings::int('lookback_days'));
        $band = min(10, (int) ($u['strike_band'] ?? null ?: Settings::int('strike_band')));
        $from = (new \DateTimeImmutable($today))->modify("-{$lookback} days")->format('Y-m-d');
        $covered = Database::scalar('SELECT MIN(c.ts) FROM candles_1m c JOIN instruments i ON i.id = c.instrument_id WHERE i.underlying_id = ? AND i.source = ?', [$uid, 'kotak']);
        $to = $covered ? (new \DateTimeImmutable((string) $covered))->modify('-1 day')->format('Y-m-d') : $today;
        $flags = ['MONTH'];
        if (!empty($u['weekly_expiry_dow'])) {
            $flags[] = 'WEEK';
        }
        $created = 0;
        foreach (array_reverse($this->chunks($from, $to, 30)) as $i => [$a, $b]) {
            foreach ($flags as $flag) {
                foreach (['CALL', 'PUT'] as $side) {
                    for ($o = -$band; $o <= $band; $o++) {
                        $strike = $o === 0 ? 'ATM' : ($o > 0 ? "ATM+{$o}" : "ATM{$o}");
                        $created += (int) JobQueue::enqueue('dhan_backfill', $uid, null, $a, $b, 60 + $i, 'dhan', "{$flag}|{$strike}|{$side}");
                    }
                }
            }
        }
        return $created;
    }

    /**
     * Inclusive [from,to] windows of at most $maxDays calendar days, aligned to fixed boundaries counted
     * from a constant epoch, so re-planning on a later date reproduces the same windows (and therefore the
     * same idempotent job keys) and only the newest, still-open window is fetched again.
     * @return array<int,array{0:string,1:string}>
     */
    public function chunks(string $from, string $to, int $maxDays): array
    {
        $out = [];
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        if ($start > $end) {
            return [];
        }
        $epoch = new \DateTimeImmutable(self::CHUNK_EPOCH);
        $offset = (int) $epoch->diff($start)->days;
        $cursor = $epoch->modify('+' . (intdiv($offset, $maxDays) * $maxDays) . ' days');
        while ($cursor <= $end) {
            $chunkEnd = $cursor->modify('+' . ($maxDays - 1) . ' days');
            $a = $cursor < $start ? $start : $cursor;
            $b = $chunkEnd > $end ? $end : $chunkEnd;
            $out[] = [$a->format('Y-m-d'), $b->format('Y-m-d')];
            $cursor = $chunkEnd->modify('+1 day');
        }
        return $out;
    }

    private function pause(): void
    {
        usleep(Settings::int('fetch_delay_ms') * 1000);
    }
}
