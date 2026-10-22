#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Data fetcher. Safe to run from cron every minute; it exits when the time budget is spent.
 *
 *   php bin/fetch.php --plan                 queue initial backfill jobs for all enabled underlyings
 *   php bin/fetch.php --refresh              queue today's top-up jobs
 *   php bin/fetch.php --run [--budget=50]    process queued jobs for up to N seconds
 *   php bin/fetch.php --loop                 keep running until the queue is empty (local use)
 *   php bin/fetch.php --status               show queue counts
 *   Options: --underlying=NIFTY  --today=YYYY-MM-DD
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Settings;
use App\Data\JobQueue;
use App\Data\Planner;
use App\Data\Runner;
use App\Data\Sources\KotakClient;

$opts = getopt('', ['plan', 'plan-dhan', 'refresh', 'run', 'loop', 'status', 'budget::', 'underlying::', 'today::', 'quiet']);
$today = $opts['today'] ?? date('Y-m-d');
$quiet = isset($opts['quiet']);
$say = static function (string $m) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('H:i:s') . "] {$m}\n";
    }
};

$source = new KotakClient(Env::require('CONSUMER_KEY'));
$sql = 'SELECT * FROM underlyings WHERE enabled = 1' . (isset($opts['underlying']) ? ' AND symbol = ?' : '') . ' ORDER BY sort_order';
$underlyings = Database::all($sql, isset($opts['underlying']) ? [strtoupper($opts['underlying'])] : []);

if (isset($opts['status'])) {
    foreach (JobQueue::counts() as $k => $v) {
        echo str_pad($k, 10) . $v . "\n";
    }
    exit(0);
}

if (isset($opts['plan'])) {
    $planner = new Planner($source);
    $n = 0;
    foreach ($underlyings as $u) {
        $n += $planner->planUnderlying($u, $today);
    }
    $say("Queued {$n} new job(s) for " . count($underlyings) . ' underlying(s)');
}

if (isset($opts['plan-dhan'])) {
    if (!\App\Data\Sources\DhanClient::configured()) {
        fwrite(STDERR, "Set DHAN_CLIENT_ID and DHAN_ACCESS_TOKEN in .env first\n");
        exit(1);
    }
    $planner = new Planner($source);
    $n = 0;
    foreach ($underlyings as $u) {
        $n += $planner->planDhan($u, $today);
    }
    $say("Queued {$n} Dhan backfill job(s)");
}

if (isset($opts['refresh'])) {
    $planner = new Planner($source);
    $n = 0;
    foreach ($underlyings as $u) {
        $n += $planner->planDailyRefresh($u, $today);
        $n += (int) JobQueue::enqueue('discover', (int) $u['id'], null, $today, $today, 20);
    }
    $say("Queued {$n} refresh job(s)");
}

if (isset($opts['run']) || isset($opts['loop'])) {
    $lock = Runner::lock(APP_ROOT . '/storage/fetch.lock');
    if ($lock === null) {
        $say('Another fetcher is running; exiting');
        exit(0);
    }
    $budget = (int) ($opts['budget'] ?? Settings::int('fetch_time_budget_sec'));
    Database::run("INSERT INTO fetch_runs (trigger_type) VALUES (?)", [PHP_SAPI === 'cli' ? 'cli' : 'web']);
    $runId = Database::lastId();
    $runner = new Runner($source, $say);
    do {
        $processed = $runner->run(isset($opts['loop']) ? 3600 : $budget, $today);
        $pending = JobQueue::counts()['pending'];
    } while (isset($opts['loop']) && $pending > 0 && $processed > 0);
    Database::run('UPDATE fetch_runs SET finished_at = NOW(), jobs_done = ?, jobs_failed = ?, requests = ?, rows_written = ? WHERE id = ?',
        [$runner->jobsDone, $runner->jobsFailed, $source->requestCount, $runner->rowsWritten, $runId]);
    $say("Run finished: {$runner->jobsDone} ok, {$runner->jobsFailed} failed, {$source->requestCount} requests, {$runner->rowsWritten} rows. Pending: {$pending}");
}

if (!array_intersect(array_keys($opts), ['plan', 'plan-dhan', 'refresh', 'run', 'loop', 'status'])) {
    echo "Usage: php bin/fetch.php --plan | --plan-dhan | --refresh | --run [--budget=N] | --loop | --status [--underlying=SYM] [--today=YYYY-MM-DD]\n";
    exit(1);
}
