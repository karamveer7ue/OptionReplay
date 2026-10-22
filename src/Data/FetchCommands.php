<?php
declare(strict_types=1);

namespace App\Data;

use App\Core\Database;
use App\Core\Env;
use App\Core\Settings;
use App\Data\Sources\DhanClient;
use App\Data\Sources\KotakClient;

/**
 * The fetcher's commands, shared by bin/fetch.php and the admin console so both run exactly the same code.
 * Every command reports progress through the $say callback (one line per message).
 */
final class FetchCommands
{
    public const COMMANDS = [
        'status' => 'Show queue counts',
        'plan' => 'Queue the initial backfill (spot history, contract discovery, candles)',
        'refresh' => 'Queue today\'s candles for all live contracts and re-run discovery',
        'plan-dhan' => 'Queue the expired-options backfill via Dhan',
        'run' => 'Process queued jobs for the time budget',
    ];

    /** @var callable(string):void */
    private $say;

    public function __construct(private readonly KotakClient $source, callable $say, private readonly string $today)
    {
        $this->say = $say;
    }

    public static function source(): KotakClient
    {
        return new KotakClient(Env::require('CONSUMER_KEY'));
    }

    /** @return array<int,array<string,mixed>> */
    public function underlyings(?string $symbol = null): array
    {
        $sql = 'SELECT * FROM underlyings WHERE enabled = 1' . ($symbol ? ' AND symbol = ?' : '') . ' ORDER BY sort_order';
        return Database::all($sql, $symbol ? [strtoupper($symbol)] : []);
    }

    public function status(): void
    {
        foreach (JobQueue::counts() as $k => $v) {
            ($this->say)(str_pad($k, 10) . $v);
        }
    }

    public function plan(?string $symbol = null): int
    {
        $planner = new Planner($this->source);
        $n = 0;
        foreach ($this->underlyings($symbol) as $u) {
            $created = $planner->planUnderlying($u, $this->today);
            ($this->say)("{$u['symbol']}: {$created} new job(s)");
            $n += $created;
        }
        ($this->say)("Queued {$n} new job(s)");
        return $n;
    }

    public function refresh(?string $symbol = null): int
    {
        $planner = new Planner($this->source);
        $n = 0;
        foreach ($this->underlyings($symbol) as $u) {
            $created = $planner->planDailyRefresh($u, $this->today);
            $created += (int) JobQueue::enqueue('discover', (int) $u['id'], null, $this->today, $this->today, 20);
            ($this->say)("{$u['symbol']}: {$created} new job(s)");
            $n += $created;
        }
        ($this->say)("Queued {$n} refresh job(s)");
        return $n;
    }

    public function planDhan(?string $symbol = null): int
    {
        if (!DhanClient::configured()) {
            ($this->say)('Dhan is not configured: add DHAN_CLIENT_ID and DHAN_ACCESS_TOKEN to .env');
            return 0;
        }
        $planner = new Planner($this->source);
        $n = 0;
        foreach ($this->underlyings($symbol) as $u) {
            $created = $planner->planDhan($u, $this->today);
            ($this->say)("{$u['symbol']}: {$created} Dhan job(s)");
            $n += $created;
        }
        ($this->say)("Queued {$n} Dhan backfill job(s)");
        return $n;
    }

    /** Process jobs for $budget seconds (or until empty with $loop). Returns jobs processed. */
    public function run(int $budget, bool $loop, string $trigger): int
    {
        $lock = Runner::lock(APP_ROOT . '/storage/fetch.lock');
        if ($lock === null) {
            ($this->say)('Another fetcher is running; nothing started');
            return 0;
        }
        Database::run('INSERT INTO fetch_runs (trigger_type) VALUES (?)', [$trigger]);
        $runId = Database::lastId();
        $runner = new Runner($this->source, $this->say);
        $total = 0;
        do {
            $processed = $runner->run($loop ? 3600 : $budget, $this->today);
            $total += $processed;
            $pending = JobQueue::counts()['pending'];
        } while ($loop && $pending > 0 && $processed > 0);
        Database::run('UPDATE fetch_runs SET finished_at = NOW(), jobs_done = ?, jobs_failed = ?, requests = ?, rows_written = ? WHERE id = ?',
            [$runner->jobsDone, $runner->jobsFailed, $this->source->requestCount, $runner->rowsWritten, $runId]);
        ($this->say)("Run finished: {$runner->jobsDone} ok, {$runner->jobsFailed} failed, {$this->source->requestCount} request(s), {$runner->rowsWritten} rows written. Pending: {$pending}");
        flock($lock, LOCK_UN);
        fclose($lock);
        return $total;
    }

    public static function defaultBudget(): int
    {
        return max(10, Settings::int('fetch_time_budget_sec'));
    }
}
