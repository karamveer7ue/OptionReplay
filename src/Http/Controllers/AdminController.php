<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\Settings;
use App\Data\JobQueue;
use App\Data\Planner;
use App\Data\Runner;
use App\Data\Sources\KotakClient;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;

final class AdminController
{
    public function loginForm(): Response
    {
        if (Auth::user()) {
            return Response::redirect('/admin');
        }
        return Response::html(View::render('admin/login', ['error' => null, 'siteName' => Settings::get('site_name')]));
    }

    public function login(): Response
    {
        if (Auth::attempt((string) Request::post('email'), (string) Request::post('password'))) {
            return Response::redirect('/admin');
        }
        return Response::html(View::render('admin/login', ['error' => 'Invalid email or password', 'siteName' => Settings::get('site_name')]), 401);
    }

    public function logout(): Response
    {
        Auth::logout();
        return Response::redirect('/admin/login');
    }

    public function dashboard(): Response
    {
        $this->guard();
        $underlyings = Database::all('SELECT * FROM underlyings ORDER BY sort_order, symbol');
        foreach ($underlyings as &$u) {
            $uid = (int) $u['id'];
            $u['spot_range'] = Database::one('SELECT MIN(ts) mn, MAX(ts) mx, COUNT(*) n FROM spot_candles_1m WHERE underlying_id = ?', [$uid]);
            $u['contracts'] = (int) Database::scalar('SELECT COUNT(*) FROM instruments WHERE underlying_id = ?', [$uid]);
            $u['live_contracts'] = (int) Database::scalar('SELECT COUNT(*) FROM instruments WHERE underlying_id = ? AND expiry >= CURDATE()', [$uid]);
            $u['option_rows'] = (int) Database::scalar('SELECT COUNT(*) FROM candles_1m c JOIN instruments i ON i.id = c.instrument_id WHERE i.underlying_id = ?', [$uid]);
            $u['option_range'] = Database::one('SELECT MIN(ts) mn, MAX(ts) mx FROM candles_1m c JOIN instruments i ON i.id = c.instrument_id WHERE i.underlying_id = ?', [$uid]);
            $u['jobs'] = JobQueue::counts($uid);
        }
        unset($u);
        return $this->page('admin/dashboard', [
            'underlyings' => $underlyings,
            'jobs' => JobQueue::counts(),
            'runs' => Database::all('SELECT * FROM fetch_runs ORDER BY id DESC LIMIT 10'),
            'dbSize' => Database::one('SELECT ROUND(SUM(data_length + index_length)/1024/1024, 1) mb FROM information_schema.tables WHERE table_schema = ?', [Env::require('DB_NAME')]),
            'dhan' => \App\Data\Sources\DhanClient::configured(),
        ]);
    }

    public function underlyings(): Response
    {
        $this->guard();
        return $this->page('admin/underlyings', ['rows' => Database::all('SELECT * FROM underlyings ORDER BY sort_order, symbol'), 'edit' => null]);
    }

    public function saveUnderlying(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $id = (int) (Request::post('id') ?? 0);
        $symbol = strtoupper((string) Request::post('symbol'));
        $name = (string) Request::post('name');
        $fo = (string) Request::post('fo_exchange');
        $spot = (string) Request::post('spot_neosymbol');
        if (!preg_match('/^[A-Z0-9&\-]{2,32}$/', $symbol) || $name === '' || !in_array($fo, ['nse_fo', 'bse_fo'], true) || !str_contains($spot, '|')) {
            throw new HttpException('Please fill all fields correctly (symbol, name, F&O exchange, spot neosymbol like nse_cm|Nifty 50).', 422);
        }
        $data = [
            $symbol, $name, Request::post('kind') === 'stock' ? 'stock' : 'index', $fo, $spot,
            (int) (Request::post('lot_size') ?: 0), (float) (Request::post('strike_step') ?: 50), Request::post('enabled') ? 1 : 0, (int) (Request::post('sort_order') ?: 0),
            Request::post('lookback_days') !== '' ? (int) Request::post('lookback_days') : null,
            Request::post('strike_band') !== '' ? (int) Request::post('strike_band') : null,
            Request::post('expiry_window_days') !== '' ? (int) Request::post('expiry_window_days') : null,
            Request::post('dhan_segment') ?: null,
            Request::post('dhan_security_id') !== '' ? (int) Request::post('dhan_security_id') : null,
            Request::post('weekly_expiry_dow') !== '' ? (int) Request::post('weekly_expiry_dow') : null,
            (int) (Request::post('monthly_expiry_dow') ?: 4),
        ];
        if ($id > 0) {
            Database::run('UPDATE underlyings SET symbol=?, name=?, kind=?, fo_exchange=?, spot_neosymbol=?, lot_size=?, strike_step=?, enabled=?, sort_order=?, lookback_days=?, strike_band=?, expiry_window_days=?, dhan_segment=?, dhan_security_id=?, weekly_expiry_dow=?, monthly_expiry_dow=? WHERE id=?', [...$data, $id]);
        } else {
            Database::run('INSERT INTO underlyings (symbol, name, kind, fo_exchange, spot_neosymbol, lot_size, strike_step, enabled, sort_order, lookback_days, strike_band, expiry_window_days, dhan_segment, dhan_security_id, weekly_expiry_dow, monthly_expiry_dow) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $data);
        }
        return Response::redirect('/admin/underlyings?saved=1');
    }

    public function deleteUnderlying(int $id): Response
    {
        $this->guard();
        Auth::checkCsrf();
        Database::transaction(function () use ($id): void {
            Database::run('DELETE FROM fetch_jobs WHERE underlying_id = ?', [$id]);
            Database::run('DELETE c FROM candles_1m c JOIN instruments i ON i.id = c.instrument_id WHERE i.underlying_id = ?', [$id]);
            Database::run('DELETE c FROM candles_1d c JOIN instruments i ON i.id = c.instrument_id WHERE i.underlying_id = ?', [$id]);
            Database::run('DELETE FROM spot_candles_1m WHERE underlying_id = ?', [$id]);
            Database::run('DELETE FROM spot_candles_1d WHERE underlying_id = ?', [$id]);
            Database::run('DELETE FROM underlyings WHERE id = ?', [$id]);
        });
        return Response::redirect('/admin/underlyings?deleted=1');
    }

    public function settings(): Response
    {
        $this->guard();
        return $this->page('admin/settings', ['values' => Settings::all(), 'saved' => Request::query('saved') !== null]);
    }

    public function saveSettings(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $numeric = ['lookback_days', 'strike_band', 'expiry_window_days', 'fetch_delay_ms', 'fetch_time_budget_sec', 'risk_free_rate', 'brokerage_per_order', 'stt_sell_pct', 'exchange_txn_pct', 'sebi_fee_pct', 'stamp_duty_buy_pct', 'gst_pct'];
        foreach ($numeric as $key) {
            $v = Request::post($key);
            if ($v === null || !is_numeric($v) || (float) $v < 0) {
                throw new HttpException("Invalid value for {$key}", 422);
            }
            Settings::set($key, $v);
        }
        Settings::set('fetch_delay_ms', (string) max(500, (int) Request::post('fetch_delay_ms')));
        foreach (['site_name', 'default_underlying'] as $key) {
            $v = Request::post($key);
            if ($v !== null && $v !== '') {
                Settings::set($key, mb_substr($v, 0, 64));
            }
        }
        return Response::redirect('/admin/settings?saved=1');
    }

    public function jobs(): Response
    {
        $this->guard();
        $status = Request::query('status');
        $where = $status && in_array($status, ['pending', 'running', 'done', 'empty', 'failed'], true) ? 'WHERE j.status = ' . Database::pdo()->quote($status) : '';
        $rows = Database::all("SELECT j.*, u.symbol, i.trading_symbol FROM fetch_jobs j JOIN underlyings u ON u.id = j.underlying_id LEFT JOIN instruments i ON i.id = j.instrument_id {$where} ORDER BY FIELD(j.status,'running','failed','pending','done','empty'), j.priority, j.id DESC LIMIT 200");
        return $this->page('admin/jobs', ['rows' => $rows, 'counts' => JobQueue::counts(), 'status' => $status, 'flash' => Request::query('flash'), 'lockHeld' => $this->lockHeld(), 'dhan' => \App\Data\Sources\DhanClient::configured()]);
    }

    public function planJobs(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $planner = new Planner($this->source());
        $n = 0;
        foreach (Database::all('SELECT * FROM underlyings WHERE enabled = 1') as $u) {
            $n += $planner->planUnderlying($u, date('Y-m-d'));
        }
        return Response::redirect('/admin/jobs?flash=' . urlencode("Queued {$n} backfill job(s)"));
    }

    public function planDhanJobs(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        if (!\App\Data\Sources\DhanClient::configured()) {
            return Response::redirect('/admin/jobs?flash=' . urlencode('Add DHAN_CLIENT_ID and DHAN_ACCESS_TOKEN to .env first'));
        }
        $planner = new Planner($this->source());
        $n = 0;
        foreach (Database::all('SELECT * FROM underlyings WHERE enabled = 1') as $u) {
            $n += $planner->planDhan($u, date('Y-m-d'));
        }
        return Response::redirect('/admin/jobs?flash=' . urlencode("Queued {$n} Dhan backfill job(s)"));
    }

    public function refreshJobs(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $planner = new Planner($this->source());
        $n = 0;
        foreach (Database::all('SELECT * FROM underlyings WHERE enabled = 1') as $u) {
            $n += $planner->planDailyRefresh($u, date('Y-m-d'));
            $n += (int) JobQueue::enqueue('discover', (int) $u['id'], null, date('Y-m-d'), date('Y-m-d'), 20);
        }
        return Response::redirect('/admin/jobs?flash=' . urlencode("Queued {$n} refresh job(s)"));
    }

    /** Process jobs for a short budget from the browser (handy on shared hosting without CLI access). */
    public function runJobs(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $lock = Runner::lock(APP_ROOT . '/storage/fetch.lock');
        if ($lock === null) {
            return Response::redirect('/admin/jobs?flash=' . urlencode('A fetcher is already running'));
        }
        set_time_limit(90);
        ignore_user_abort(true);
        $source = $this->source();
        $runner = new Runner($source);
        $runner->run(min(60, Settings::int('fetch_time_budget_sec')), date('Y-m-d'));
        Database::run('INSERT INTO fetch_runs (trigger_type, finished_at, jobs_done, jobs_failed, requests, rows_written) VALUES (?, NOW(), ?, ?, ?, ?)', ['manual', $runner->jobsDone, $runner->jobsFailed, $source->requestCount, $runner->rowsWritten]);
        return Response::redirect('/admin/jobs?flash=' . urlencode("Processed {$runner->jobsDone} job(s), {$runner->jobsFailed} failed, {$runner->rowsWritten} rows"));
    }

    public function retryFailed(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $n = Database::run("UPDATE fetch_jobs SET status='pending', attempts=0, not_before=NULL WHERE status='failed'")->rowCount();
        return Response::redirect('/admin/jobs?flash=' . urlencode("Re-queued {$n} failed job(s)"));
    }

    public function clearJobs(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $n = Database::run("DELETE FROM fetch_jobs WHERE status IN ('done','empty')")->rowCount();
        return Response::redirect('/admin/jobs?flash=' . urlencode("Removed {$n} finished job(s)"));
    }

    public function console(): Response
    {
        $this->guard();
        return $this->page('admin/console', [
            'commands' => \App\Data\FetchCommands::COMMANDS,
            'budget' => \App\Data\FetchCommands::defaultBudget(),
            'underlyings' => Database::all('SELECT symbol FROM underlyings WHERE enabled = 1 ORDER BY sort_order'),
            'counts' => JobQueue::counts(),
            'lockHeld' => $this->lockHeld(),
            'dhan' => \App\Data\Sources\DhanClient::configured(),
        ]);
    }

    /** Runs one fetcher command in-process and streams its output into the page as it happens. */
    public function consoleRun(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $command = (string) Request::post('command');
        if (!isset(\App\Data\FetchCommands::COMMANDS[$command])) {
            throw new HttpException('Unknown command', 400);
        }
        $budget = max(5, min(600, (int) (Request::post('budget') ?: \App\Data\FetchCommands::defaultBudget())));
        $symbol = Request::post('underlying') ?: null;
        $today = Request::post('today') ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            throw new HttpException('Invalid date', 422);
        }
        session_write_close();
        set_time_limit($budget + 60);
        ignore_user_abort(true);
        // Stream: headers and page head first, then one line per message.
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        $label = $command . ($command === 'run' ? " (budget {$budget}s)" : '') . ($symbol ? " for {$symbol}" : '');
        echo View::render('admin/console-run', ['label' => $label, 'siteName' => Settings::get('site_name'), 'head' => true]);
        echo str_repeat(' ', 1024);
        flush();
        $say = static function (string $m): void {
            echo '<div class="line"><span class="t">' . date('H:i:s') . '</span>' . View::e($m) . "</div>\n";
            flush();
        };
        $started = microtime(true);
        try {
            $cmd = new \App\Data\FetchCommands(\App\Data\FetchCommands::source(), $say, $today);
            match ($command) {
                'status' => $cmd->status(),
                'plan' => $cmd->plan($symbol),
                'refresh' => $cmd->refresh($symbol),
                'plan-dhan' => $cmd->planDhan($symbol),
                'run' => $cmd->run($budget, false, 'manual'),
            };
            $say(sprintf('Done in %.1fs', microtime(true) - $started));
        } catch (\Throwable $e) {
            echo '<div class="line err"><span class="t">' . date('H:i:s') . '</span>' . View::e('Error: ' . $e->getMessage()) . "</div>\n";
        }
        echo View::render('admin/console-run', ['label' => $label, 'siteName' => Settings::get('site_name'), 'head' => false]);
        flush();
        exit;
    }

    public function holidays(): Response
    {
        $this->guard();
        return $this->page('admin/holidays', ['rows' => Database::all('SELECT * FROM holidays ORDER BY day DESC')]);
    }

    public function saveHoliday(): Response
    {
        $this->guard();
        Auth::checkCsrf();
        $day = (string) Request::post('day');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new HttpException('Invalid date', 422);
        }
        Database::run('INSERT INTO holidays (day, description, source) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [$day, mb_substr((string) Request::post('description'), 0, 120), 'manual']);
        return Response::redirect('/admin/holidays');
    }

    public function deleteHoliday(string $day): Response
    {
        $this->guard();
        Auth::checkCsrf();
        Database::run('DELETE FROM holidays WHERE day = ?', [$day]);
        return Response::redirect('/admin/holidays');
    }

    public function account(): Response
    {
        $this->guard();
        return $this->page('admin/account', ['saved' => Request::query('saved') !== null, 'error' => null]);
    }

    public function saveAccount(): Response
    {
        $user = $this->guard();
        Auth::checkCsrf();
        $current = (string) Request::post('current_password');
        $new = (string) Request::post('new_password');
        $row = Database::one('SELECT password_hash FROM admins WHERE id = ?', [$user['id']]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            return $this->page('admin/account', ['saved' => false, 'error' => 'Current password is incorrect']);
        }
        if (strlen($new) < 10) {
            return $this->page('admin/account', ['saved' => false, 'error' => 'New password must be at least 10 characters']);
        }
        Database::run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_ARGON2ID), $user['id']]);
        return Response::redirect('/admin/account?saved=1');
    }

    /** @return array<string,mixed> */
    private function guard(): array
    {
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/admin/login')->send();
            exit;
        }
        return $user;
    }

    /** @param array<string,mixed> $vars */
    private function page(string $view, array $vars): Response
    {
        $vars += ['user' => Auth::user(), 'csrf' => Auth::csrf(), 'siteName' => Settings::get('site_name'), 'active' => $view];
        return Response::html(View::render('admin/layout', ['content' => View::render($view, $vars), ...$vars]));
    }

    private function source(): KotakClient
    {
        return new KotakClient(Env::require('CONSUMER_KEY'));
    }

    private function lockHeld(): bool
    {
        $fh = @fopen(APP_ROOT . '/storage/fetch.lock', 'c');
        if ($fh === false) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }
}
