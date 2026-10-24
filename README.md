# OptionReplay

OptionReplay is an options strategy simulator for Indian index options.
You build a strategy from a real historical option chain, then replay it minute by minute against the prices that actually traded, watching the payoff, Greeks and running profit or loss change as time moves.

It starts with NIFTY, BANKNIFTY and SENSEX.
More indices and stocks can be added from the admin panel.

Built with plain PHP 8 and MySQL/MariaDB.
No framework, no Node build step.
It runs on a laptop with the PHP built-in server and deploys to ordinary shared hosting (cPanel) as a file upload.

## Contents

1. [What the simulator does](#what-the-simulator-does)
2. [How it works](#how-it-works)
3. [Requirements](#requirements)
4. [Install and run locally](#install-and-run-locally)
5. [Collecting market data](#collecting-market-data)
6. [Admin panel](#admin-panel)
7. [Deploying to shared hosting](#deploying-to-shared-hosting)
8. [Project layout](#project-layout)
9. [API reference](#api-reference)
10. [Testing](#testing)
11. [Data notes and known limitations](#data-notes-and-known-limitations)
12. [Troubleshooting](#troubleshooting)

## What the simulator does

- **Option chain at any past minute.** Pick an underlying, an expiry and a date and time.
  The chain shows the last traded premium, implied volatility, delta, theta and traded volume for every strike, with in-the-money shading and the at-the-money row highlighted.
- **Build a strategy.** Click a premium (or its B/S buttons) to add a buy or sell leg, or pick one of 29 ready-made strategies grouped as Bullish, Bearish, Neutral and Other.
  Legs can be edited: side, lots, enabled or disabled, removed.
- **Square off legs as you go.** Close any leg at the current replay time at an editable price (prefilled with the last traded price). The booked profit or loss stays in the strategy totals and the payoff, the leg can be reopened, and new legs can be added at any later time, including on the same contract.
- **Resizable layout.** Drag the divider between the option chain and the strategy panel to give either side more room; double-click it to reset.
- **Travel through time.** Step by ±5 minutes, ±15 minutes, ±1 hour, start of day, end of day, or ±1 trading day.
  Weekends and exchange holidays are skipped.
  Arrow keys step, space toggles autoplay.
- **Autoplay** advances automatically at a chosen step and speed and stops at the edge of available data.
- **Payoff chart** with the expiry line, the T+0 curve (Black-Scholes valued with each leg's implied volatility), profit and loss shading, breakevens, max profit and max loss, and an optional charges toggle (brokerage, STT, exchange fees, SEBI fee, stamp duty, GST).
- **P&L over time** chart, marked-to-market from the first leg's entry to the current time.
- **Spot chart** (candlesticks at 1m, 5m, 15m, 1h, 1D) with leg entries marked.
- **Expected-move bands**: ±1σ and ±2σ to the weekly and to the monthly expiry, from the at-the-money implied volatility at the replay time, shown on the payoff chart, as price lines on the spot chart, and as numbers in the market strip. Each can be toggled.
- **Greeks** per leg and for the whole strategy: delta, gamma, theta per day, vega.
- **Save and share** strategies: saved in the browser, or shared as a URL that encodes every leg and the simulation time.
- **Light and dark themes.**

No login is needed to use the simulator.

## How it works

```
Kotak NEO market-data API ──┐
                            ├──► bin/fetch.php (job queue) ──► MySQL ──► PHP JSON API ──► browser (vanilla JS)
Dhan expired-options API ───┘
```

**Data collection** is a job queue.
`bin/fetch.php --plan` reads the admin configuration (lookback period, strike band, expiry window) and creates small, idempotent jobs: index spot candles in 30-day windows, a daily "discover" job per underlying that registers live contracts, and per-contract candle jobs.
`bin/fetch.php --run` claims jobs one at a time, calls the broker with a configurable delay between requests, backs off on HTTP 429, and exits when its time budget is spent.
Running it every minute from cron gives continuous collection; running it with `--loop` processes the whole queue in one go.

**Storage** is minute candles in InnoDB, with prices stored as integer paise to keep the tables compact.
Spot and option candles live in separate tables keyed by `(instrument_id, ts)`.

**The simulator** never touches the broker.
At a given minute it looks up the last candle at or before that minute for every contract of the chosen expiry (falling back to the previous daily close before the first trade of the day), computes implied volatility and Greeks with Black-Scholes, and returns JSON.
The browser does the payoff maths, charting and strategy editing.

**Two data sources** are needed because Kotak NEO serves candles only for contracts that have not yet expired.
Kotak is used for continuous forward collection (it needs only a consumer key, no OTP or TOTP).
Dhan's rolling expired-options endpoint backfills the past year; its rows come as strikes relative to ATM per minute, and OptionReplay resolves each row to a concrete contract using the expiry rules configured per underlying.

## Requirements

- PHP 8.2 or newer with `pdo_mysql`, `curl`, `mbstring`, `json`
- MySQL 8 or MariaDB 10.6+
- Composer
- A Kotak NEO trading account with a Trade API consumer key (NEO app → More → Trade API → Generate application)
- Optional: a Dhan account with API access, for the one-year backfill of expired contracts
- Optional, for the browser tests: Python 3 with Playwright and Google Chrome

## Install and run locally

1. Clone and install dependencies:

   ```bash
   git clone https://github.com/karamveer7ue/OptionReplay.git
   cd OptionReplay
   composer install
   ```

2. Create the environment file:

   ```bash
   cp .env.example .env
   ```

   Fill in `CONSUMER_KEY` (Kotak) and the `DB_*` values.
   `MOBILE_NUMBER`, `UCC` and `MPIN` are not used by the market-data endpoints and can stay empty.

3. Create the database user (as MySQL root), unless you use root locally:

   ```sql
   CREATE DATABASE options_simulator CHARACTER SET utf8mb4;
   CREATE USER 'optsim'@'localhost' IDENTIFIED BY 'change-me';
   GRANT ALL ON options_simulator.* TO 'optsim'@'localhost';
   ```

4. Run migrations and create the admin account:

   ```bash
   php bin/migrate.php
   php bin/seed-admin.php you@example.com
   ```

   The generated password is printed once.
   Change it later under Admin → Account.

5. Start the web server:

   ```bash
   ./start.sh        # or: composer serve
   ```

   Open http://localhost:8080 for the simulator and http://localhost:8080/admin for the admin panel.
   The script starts PHP's built-in server with six worker processes, so a long Console run does not block the simulator.

6. Collect data (see the next section).
   The simulator shows "No historical data collected yet" until the first candles arrive.

## Collecting market data

### Quick start scripts

Two helper scripts cover the everyday local workflow:

```bash
./start.sh            # start the web server on port 8080, or restart it if it is already running
./start.sh 9000       # another port
./start.sh --stop     # stop it

./fetch.sh            # queue everything missing up to now, then process the queue until it is empty
./fetch.sh --watch    # same, then keep checking for new work every 15 minutes
./fetch.sh --status   # queue counts
```

`fetch.sh` does the work of both cron jobs in one go: it runs `--plan` and `--refresh` to queue anything missing (including today's candles), then `--loop` until the queue is empty.
It refuses to start if another fetcher is running and clears a stale lock left by a killed run.

### Running the collector from a local terminal

Everything cron does can also be run by hand from the project folder.

```bash
cd /path/to/OptionReplay
php bin/fetch.php --plan      # queue everything that is missing (safe to repeat)
php bin/fetch.php --loop      # process the whole queue until it is empty
```

`--loop` runs for hours and prints one line per job.
Stop it any time with Ctrl+C and start it again later; it resumes where it left off.
To keep it running after closing the terminal:

```bash
nohup php bin/fetch.php --loop > storage/logs/fetch-loop.out 2>&1 &
tail -f storage/logs/fetch-loop.out      # watch progress
php bin/fetch.php --status               # queue counts at any time
```

To do exactly what the two cron entries do, run them by hand:

```bash
php bin/fetch.php --run --budget=50      # one cron tick: 50 seconds of work, then exit
php bin/fetch.php --refresh              # the after-close job: today's candles plus contract discovery
```

Things to know:

- Only one fetcher runs at a time.
  While `--loop` is running, the admin Console's `run` button reports "Another fetcher is running".
- The first backfill of three indices is roughly 12,000 jobs, around 4 hours at the default pacing of one request per second.
- Run `--refresh` after each market close so new days arrive.
  Kotak publishes option candles with a lag, so a day may only become complete the following evening.
- The same commands are available in Admin → Console with live output in the browser.

### All commands

```bash
php bin/fetch.php --status                 # queue counts
php bin/fetch.php --plan                   # queue spot history, contract discovery and per-contract candles
php bin/fetch.php --run --budget=50        # process jobs for 50 seconds then exit (cron friendly)
php bin/fetch.php --loop                   # process until the queue is empty
php bin/fetch.php --refresh                # queue today's candles for every live contract and re-run discovery
php bin/fetch.php --plan-dhan              # queue the expired-options backfill via Dhan
php bin/fetch.php --plan --underlying=NIFTY   # any command can be limited to one underlying
php bin/fetch.php --refresh --today=2026-10-21 # plan as of a given date
php bin/fetch.php --run --quiet            # no output (for cron)
```

What gets fetched, with defaults editable in Admin → Settings:

| Setting | Default | Meaning |
|---|---|---|
| Lookback period | 365 days | How far back spot and backfill data should go |
| Strike band | ±25 strikes | Strikes around ATM to track per expiry |
| Expiry window | 60 days | Expiries ahead of today to track |
| Delay between requests | 1000 ms | Kotak allows about one request per second; 500 ms gets throttled (HTTP 429), and the runner backs off automatically |
| Time budget per run | 50 s | How long one `--run` processes jobs |

Sizing: with the defaults, three indices produce roughly 3,000 live contracts and about 12,000 jobs for the first backfill, around 10 hours at the default pacing.
Each contract-day is about 375 rows, so a full year of three indices at 1-minute resolution is on the order of 200 million rows (roughly 10 to 15 GB).
Raise the delay or narrow the band if your host is small.

Daily operation after the first backfill:

```
* * * * *      cd /path/to/app && php bin/fetch.php --run --quiet
30 16 * * 1-5  cd /path/to/app && php bin/fetch.php --refresh --quiet
```

The runner holds a lock file, so overlapping cron ticks are harmless, and a run started from the admin Console waits its turn the same way.
Fetch windows are aligned to fixed 30-day boundaries, so re-running `--plan` on a later day only adds the newest window instead of re-fetching history.

### Backfilling expired contracts with Dhan

1. Add `DHAN_CLIENT_ID` and `DHAN_ACCESS_TOKEN` to `.env`.
2. Check each underlying in Admin → Underlyings has a Dhan segment, Dhan security id and expiry weekdays.
   NIFTY (13, NSE_FNO), BANKNIFTY (25, NSE_FNO) and SENSEX (51, BSE_FNO) are preset.
3. Run `php bin/fetch.php --plan-dhan` or click "Queue Dhan backfill" in Admin → Data jobs, then run the queue.

Dhan jobs cover only the period before Kotak's own coverage begins and never overwrite Kotak rows.
Each job fetches one strike offset (ATM ±10) for one side and one expiry type over a 30-day window.

## Admin panel

The admin backend lives at `/admin`.
It is where you decide what data is collected, watch it arrive, run the collector by hand, and tune the numbers the simulator uses.
Public users never see it.

### Signing in

- Create the first admin with `php bin/seed-admin.php you@example.com` (prints a generated password) or `php bin/seed-admin.php you@example.com 'your-password'`.
- Running the same command again for an existing email resets that admin's password.
- Sign in at `/admin/login`.
  Sessions use an HTTP-only cookie named `optsim_admin`; every form carries a CSRF token.
- Change your password under **Account** (minimum 10 characters, current password required).
- Use `localhost` or `127.0.0.1` locally, not `0.0.0.0`: browsers refuse cookies on that address and the login appears to fail.

### Dashboard

A read-only overview of the system.

| Block | What it shows |
|---|---|
| Job counters | Pending, running, done, empty and failed jobs. Each counter links to the filtered job list. |
| Data coverage | Per underlying: lot size and strike step, number of 1-minute spot rows and their date range, contracts known (and how many are still live), 1-minute option rows and their date range, pending and failed jobs. |
| Recent fetch runs | The last 10 runs with trigger (cli, cron, manual), jobs ok and failed, requests made and rows written. |
| Cron setup | The two cron lines to install on the server. |
| Header | Database size and whether the Dhan backfill is configured. |

Use it to answer "how far back can the simulator go for SENSEX?" or "did last night's refresh run?".

### Console

Runs any collector command once, from the browser, and streams its output into the page while it works.
This is the easiest way to test the pipeline locally or on a host without shell access.

| Button | Equivalent CLI | What it does |
|---|---|---|
| `status` | `php bin/fetch.php --status` | Prints the queue counters. |
| `plan` | `--plan` | Queues the initial backfill: spot history for the lookback period, a discovery job per underlying, and per-contract candle jobs. Idempotent. |
| `refresh` | `--refresh` | Queues today's candles for every live contract plus a fresh discovery. Run after market close. |
| `plan-dhan` | `--plan-dhan` | Queues the expired-options backfill. Disabled until `DHAN_CLIENT_ID` and `DHAN_ACCESS_TOKEN` are in `.env`. |
| `run (process jobs)` | `--run --budget=N` | Processes queued jobs for N seconds, then stops. |

Options above the buttons:

- **Underlying**: restrict the command to one symbol, or leave as "all enabled".
- **Run budget (seconds)**: how long `run` keeps going (5 to 600). The page keeps streaming until the budget is spent.
- **As of date**: the planning date, normally today. Useful to re-plan as of an earlier date.

Only one collector can run at a time.
If a cron run or a `--loop` process holds the lock, the Console reports "Another fetcher is running; nothing started" instead of starting a second one.

### Data jobs

The job queue itself.

Actions at the top:

- **Queue backfill**: same as the Console's `plan`.
- **Queue Dhan backfill**: same as `plan-dhan`.
- **Queue today's refresh**: same as `refresh`.
- **Run for 60s now**: processes jobs for one minute (disabled while another fetcher is running).
- **Retry failed**: resets all failed jobs to pending with their attempt counter cleared.
- **Clear finished**: deletes job records in done or empty state to keep the table small. Candle data is not touched.

The table lists up to 200 jobs, running and failed first.
Filter tabs select a status.
Each row shows the job kind, underlying, contract, date range, priority (lower runs first), status, attempts, rows written, the last error message, and when it was last updated.
A pending job with an "after" time is waiting out a back-off after a rate limit or an error.

Job kinds:

| Kind | Meaning |
|---|---|
| `spot_1m`, `spot_1d` | Index spot candles, one job per window. |
| `discover` | Calls the broker for expiries and option chains, registers contracts, queues their candle jobs. |
| `inst_1m`, `inst_1d` | Candles for one contract and one window. |
| `dhan_backfill` | One Dhan request: one strike offset, one side, one expiry type, one 30-day window. |

A job fails permanently after 4 attempts; use **Retry failed** once the cause is fixed.
"Empty" means the broker answered with no candles, which is normal for illiquid far strikes.

### Underlyings

Add, edit, disable or delete the indices and stocks that are collected and offered in the simulator.

| Field | Meaning |
|---|---|
| Symbol | Kotak's `pSymbolName`, e.g. `NIFTY`, `BANKNIFTY`, `SENSEX`, `RELIANCE`. |
| Display name | Shown in the admin. |
| Kind | Index or stock. |
| F&O exchange | `nse_fo` or `bse_fo`. |
| Spot neosymbol | Kotak `segment|token` for the spot series, e.g. `nse_cm|Nifty 50`, `nse_cm|Nifty Bank`, `bse_cm|SENSEX`, or `nse_cm|2885` for a stock token from the scrip master. |
| Lot size, strike step | Refreshed automatically by discovery; the initial values are only a starting point. |
| Sort order | Order of the switcher in the simulator. |
| Lookback / strike band / expiry window overrides | Per-underlying values; blank means use the global settings. |
| Weekly expiry weekday, monthly expiry weekday | Used to resolve expiries for past dates during the Dhan backfill (e.g. NIFTY: Tuesday and Tuesday; SENSEX: Thursday and Thursday; BANKNIFTY: none and Tuesday). |
| Dhan segment, Dhan security id | The underlying's id in Dhan's instrument list (`NSE_FNO` 13 for NIFTY, 25 for BANKNIFTY; `BSE_FNO` 51 for SENSEX). |
| Enabled | Disabled underlyings are neither collected nor shown. |

Adding an underlying does not fetch anything by itself: queue a backfill afterwards from the Console or Data jobs.
**Delete** removes the underlying together with all its contracts, candles and jobs, after a confirmation.

### Holidays

The exchange holiday calendar.
Weekdays not listed here are treated as trading days by the time stepper, the expiry rules and the data-range clamps.
Add a date with an optional description, or remove one.
NSE holidays for 2025 and 2026 are seeded; add the next year's list when the exchange publishes it.

### Settings

Global numbers, all editable:

| Group | Setting | Effect |
|---|---|---|
| Data collection | Lookback period (days) | How far back spot history and the Dhan backfill go. |
| | Strike band (± strikes) | Strikes around ATM tracked per expiry. The Dhan backfill uses at most ±10. |
| | Expiry window (days ahead) | Expiries tracked beyond today. |
| | Delay between requests (ms) | Pause between broker calls; about 1000 ms is the measured limit for Kotak, faster gets throttled. |
| | Time budget per run (s) | How long one cron tick or Console run processes jobs. |
| Pricing | Risk-free rate | Used for implied volatility, Greeks and the synthetic futures price. |
| Charges | Brokerage per order, STT, exchange transaction, SEBI fee, stamp duty, GST | Rates used by the simulator's "Include charges" toggle. |
| Site | Site name, default underlying | Branding and the underlying selected on first visit. |

Changes apply immediately to new simulator requests; collection settings apply to jobs queued after the change.

### Account

Shows the signed-in email and lets the admin change their password.
To add another admin, run `php bin/seed-admin.php other@example.com` on the server.

### Typical workflows

- **First setup**: Underlyings (check the three presets) → Settings (lookback, band, delay) → Console `plan` → Console `run` a few times or let cron work → Dashboard to watch coverage grow.
- **Daily**: cron runs `--run` every minute and `--refresh` after the close. Check Dashboard for failed jobs; use Data jobs → Retry failed if the broker had an outage.
- **Add a stock**: Underlyings → fill symbol, `nse_fo`, spot neosymbol from the scrip master → Save → Console `plan` restricted to that symbol.
- **Backfill a year of expired options**: add Dhan credentials to `.env` → check Dhan ids and expiry weekdays per underlying → Console `plan-dhan` → run the queue.

## Deploying to shared hosting

1. Upload everything except `.git` and keep `public/` as the document root.
   If the host forces `public_html`, put the contents of `public/` there and adjust the two `require` paths at the top of `public/index.php` and `public/router.php`.
2. Run `composer install --no-dev` locally and upload `vendor/` if the host has no Composer.
3. Create `.env` on the server with production database credentials and `APP_ENV=production`.
4. Run `php bin/migrate.php` and `php bin/seed-admin.php` through SSH, or via the host's PHP CLI from cron once.
5. Add the two cron lines above.
   If cron is not available, the "Run for 60s now" button in Admin → Data jobs processes the queue manually.
6. `public/.htaccess` already routes requests to `index.php` and sets cache headers for assets.

## Project layout

```
bin/                 CLI entry points: migrate, seed-admin, fetch
database/migrations  Numbered SQL migrations (applied once each, tracked in `migrations`)
public/              Document root: index.php router, .htaccess, assets (CSS, JS, vendored lightweight-charts)
src/Core             Env loader, PDO wrapper, settings, logger
src/Data             Job queue, planner, runner, Dhan backfill, repositories, broker clients
src/Market           Trading calendar and time stepping, expiry rules, market read model
src/Pricing          Black-Scholes pricing, implied volatility, Greeks
src/Http             Router, request/response helpers, controllers, PHP views
src/Admin            Admin session authentication
tests/Unit           PHPUnit tests
tests/e2e            Playwright browser tests (simulator and admin)
storage/             Logs, lock file, cache (ignored by git)
```

Key tables: `underlyings`, `instruments` (one row per contract), `spot_candles_1m`, `spot_candles_1d`, `candles_1m`, `candles_1d`, `holidays`, `expiry_calendar`, `fetch_jobs`, `fetch_runs`, `settings`, `admins`.

## API reference

All endpoints are `GET` and return JSON.
Times are IST in `YYYY-MM-DDTHH:MM` form.

| Endpoint | Parameters | Returns |
|---|---|---|
| `/api/underlyings` | | enabled underlyings with data range, pricing and charge settings |
| `/api/snapshot` | `u`, `at` | normalised time, spot with change, futures price (real or synthetic), expiries, lot size |
| `/api/chain` | `u`, `at`, `expiry` | per-strike CE/PE quotes with IV and Greeks, ATM strike |
| `/api/quotes` | `u`, `at`, `ids` | quotes and Greeks for specific contracts; expired contracts settle at intrinsic value |
| `/api/series` | `u`, `from`, `to`, `ids` | close series for contracts and spot, sampled for the P&L chart |
| `/api/spot-series` | `u`, `from`, `to`, `interval` | OHLC candles for the spot chart |
| `/api/step` | `at`, `step` | the time after applying a step (`+5m`, `-1h`, `sod`, `eod`, `+1d`, ...) |

## Testing

```bash
composer test                                     # PHPUnit: pricing, calendar stepping, planner, expiry rules, Dhan mapping
python3 tests/e2e/simulator.py                    # browser test of the simulator (needs data and a running server)
python3 tests/e2e/admin.py you@example.com pass   # browser test of the admin panel
```

The browser tests use Playwright with the installed Google Chrome (`pip install playwright`).

## Data notes and known limitations

- Kotak NEO returns no candles for contracts that have already expired, which is why the Dhan backfill exists.
  Without Dhan, coverage starts from the first day you ran the collector (plus the listing history of contracts that were live then) and grows daily.
- Kotak publishes option candles with a lag of up to one trading day, and includes post-close prints up to 15:39.
  The simulator clamps its time range to where option data exists and ignores prints after 15:30.
- Kotak candles do not carry open interest yet.
  The chain shows traded volume in its place until OI appears.
- Greeks are European Black-Scholes without dividends, using the risk-free rate from settings.
  Deep in-the-money premiums below intrinsic value have no implied volatility and show a delta of ±1.
- The futures price is the stored contract where available, otherwise a synthetic spot-plus-carry value marked as such.
- Expiry rules for past dates are weekday based (for example NIFTY weekly on Tuesday) and holiday shifted.
  Exchanges have changed expiry weekdays over the years; adjust per underlying in the admin panel for older backfills.
- Mobile layout is basic.
  The app is designed for desktop and tablet first.

## Troubleshooting

- **"Access denied" on migrate**: the `DB_*` values in `.env` are wrong or the user lacks rights to create the database.
- **Admin "Run for 60s now" does nothing**: another fetcher holds the lock (`storage/fetch.lock`), or the queue is empty.
- **HTTP 429 in job errors**: lower the request rate in Settings; the runner already backs off using the `Retry-After` header.
- **Chain shows dashes for most strikes**: the backfill is still running; dashes mean no candle yet for that contract at that minute.
- **Time jumps to the next day on +5m from 15:25**: intended.
  The session is a 375-minute grid; stepping past 15:29 continues into the next trading day.
