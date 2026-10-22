#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Data fetcher. Safe to run from cron every minute; it exits when the time budget is spent.
 * The same commands are available from Admin > Console.
 *
 *   php bin/fetch.php --plan                 queue initial backfill jobs for all enabled underlyings
 *   php bin/fetch.php --plan-dhan            queue expired-options backfill via Dhan (needs DHAN_* in .env)
 *   php bin/fetch.php --refresh              queue today's top-up jobs
 *   php bin/fetch.php --run [--budget=50]    process queued jobs for up to N seconds
 *   php bin/fetch.php --loop                 keep running until the queue is empty (local use)
 *   php bin/fetch.php --status               show queue counts
 *   Options: --underlying=NIFTY  --today=YYYY-MM-DD  --quiet
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Data\FetchCommands;

$opts = getopt('', ['plan', 'plan-dhan', 'refresh', 'run', 'loop', 'status', 'budget::', 'underlying::', 'today::', 'quiet']);
if (!array_intersect(array_keys($opts), ['plan', 'plan-dhan', 'refresh', 'run', 'loop', 'status'])) {
    echo "Usage: php bin/fetch.php --plan | --plan-dhan | --refresh | --run [--budget=N] | --loop | --status [--underlying=SYM] [--today=YYYY-MM-DD] [--quiet]\n";
    exit(1);
}
$quiet = isset($opts['quiet']);
$say = static function (string $m) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('H:i:s') . "] {$m}\n";
    }
};
$cmd = new FetchCommands(FetchCommands::source(), $say, $opts['today'] ?? date('Y-m-d'));
$symbol = $opts['underlying'] ?? null;

if (isset($opts['status'])) {
    $cmd->status();
}
if (isset($opts['plan'])) {
    $cmd->plan($symbol);
}
if (isset($opts['plan-dhan'])) {
    $cmd->planDhan($symbol);
}
if (isset($opts['refresh'])) {
    $cmd->refresh($symbol);
}
if (isset($opts['run']) || isset($opts['loop'])) {
    $cmd->run((int) ($opts['budget'] ?? FetchCommands::defaultBudget()), isset($opts['loop']), 'cli');
}
