<?php
declare(strict_types=1);

namespace App\Data;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Data\Repository\CandleStore;
use App\Data\Sources\MarketDataSource;
use App\Data\Sources\RateLimitedException;

/** Executes queued fetch jobs with pacing, a time budget and a process lock. */
final class Runner
{
    public int $requests = 0;
    public int $rowsWritten = 0;
    public int $jobsDone = 0;
    public int $jobsFailed = 0;

    public function __construct(private readonly MarketDataSource $source, private readonly ?\Closure $onProgress = null)
    {
    }

    /** Run until the queue is empty or the time budget (seconds) is spent. Returns number of jobs processed. */
    public function run(int $budgetSeconds, string $today): int
    {
        $deadline = microtime(true) + $budgetSeconds;
        $delayMs = max(250, Settings::int('fetch_delay_ms'));
        JobQueue::releaseStale();
        $processed = 0;

        while (microtime(true) < $deadline) {
            $job = JobQueue::claimNext();
            if ($job === null) {
                break;
            }
            $processed++;
            try {
                $rows = $this->execute($job, $today);
                JobQueue::complete((int) $job['id'], $rows);
                $this->jobsDone++;
                $this->rowsWritten += $rows;
                $this->report($job, "ok {$rows} rows");
            } catch (RateLimitedException $e) {
                $wait = max(30, $e->retryAfterSeconds);
                JobQueue::defer((int) $job['id'], $wait, 'rate limited');
                Logger::info('fetch', "Rate limited, pausing {$wait}s");
                $this->report($job, "rate limited, waiting {$wait}s");
                if (microtime(true) + $wait > $deadline) {
                    break;
                }
                sleep($wait);
                continue;
            } catch (\Throwable $e) {
                $this->jobsFailed++;
                JobQueue::fail((int) $job['id'], (int) $job['attempts'], substr($e->getMessage(), 0, 1000));
                Logger::error('fetch', 'Job failed', ['id' => $job['id'], 'kind' => $job['kind'], 'error' => $e->getMessage()]);
                $this->report($job, 'FAILED ' . $e->getMessage());
            }
            usleep($delayMs * 1000);
        }
        if ($processed > 0) {
            \App\Core\Cache::forget('range_');
        }
        return $processed;
    }

    /** @param array<string,mixed> $job */
    private function execute(array $job, string $today): int
    {
        $u = Database::one('SELECT * FROM underlyings WHERE id = ?', [$job['underlying_id']]);
        if ($u === null) {
            throw new \RuntimeException('Underlying missing');
        }
        $uid = (int) $u['id'];
        switch ($job['kind']) {
            case 'discover':
                $planner = new Planner($this->source);
                $n = $planner->discover($u, $today);
                $this->requests += 1;
                return $n;
            case 'spot_1m':
                $this->requests++;
                $c = $this->source->candles($u['spot_neosymbol'], '1min', $job['from_date'], $job['to_date']);
                return CandleStore::saveSpot1m($uid, $c);
            case 'spot_1d':
                $this->requests++;
                $c = $this->source->candles($u['spot_neosymbol'], 'D', $job['from_date'], $job['to_date']);
                return CandleStore::saveSpot1d($uid, $c);
            case 'dhan_backfill':
                if (!\App\Data\Sources\DhanClient::configured()) {
                    throw new \RuntimeException('Dhan credentials not configured');
                }
                $this->requests++;
                $dhan = new \App\Data\Sources\DhanClient(\App\Core\Env::require('DHAN_CLIENT_ID'), \App\Core\Env::require('DHAN_ACCESS_TOKEN'));
                return (new DhanBackfill($dhan))->run($u, $job);
            case 'inst_5m':
            case 'inst_1d':
                $inst = Database::one('SELECT * FROM instruments WHERE id = ?', [$job['instrument_id']]);
                if ($inst === null) {
                    throw new \RuntimeException('Instrument missing');
                }
                $this->requests++;
                $neo = $inst['exchange_seg'] . '|' . $inst['token'];
                if ($job['kind'] === 'inst_5m') {
                    return CandleStore::saveInstrument5m((int) $inst['id'], $this->source->candles($neo, '5min', $job['from_date'], $job['to_date']));
                }
                return CandleStore::saveInstrument1d((int) $inst['id'], $this->source->candles($neo, 'D', $job['from_date'], $job['to_date']));
        }
        throw new \RuntimeException('Unknown job kind ' . $job['kind']);
    }

    /** @param array<string,mixed> $job */
    private function report(array $job, string $msg): void
    {
        if ($this->onProgress) {
            ($this->onProgress)(sprintf('#%d %s u=%d i=%s %s..%s: %s', $job['id'], $job['kind'], $job['underlying_id'], $job['instrument_id'] ?? '-', $job['from_date'] ?? '', $job['to_date'] ?? '', $msg));
        }
    }

    /** Acquire an exclusive lock so cron overlaps do not double-run. Returns the handle or null. */
    public static function lock(string $path): mixed
    {
        $fh = fopen($path, 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            return null;
        }
        return $fh;
    }
}
