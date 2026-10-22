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
- **Travel through time.** Step by ±5 minutes, ±15 minutes, ±1 hour, start of day, end of day, or ±1 trading day.
  Weekends and exchange holidays are skipped.
  Arrow keys step, space toggles autoplay.
- **Autoplay** advances automatically at a chosen step and speed and stops at the edge of available data.
- **Payoff chart** with the expiry line, the T+0 curve (Black-Scholes valued with each leg's implied volatility), profit and loss shading, breakevens, max profit and max loss, and an optional charges toggle (brokerage, STT, exchange fees, SEBI fee, stamp duty, GST).
- **P&L over time** chart, marked-to-market from the first leg's entry to the current time.
- **Spot chart** (candlesticks at 1m, 5m, 15m, 1h, 1D) with leg entries marked.
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
   composer serve
   ```

   Open http://localhost:8080 for the simulator and http://localhost:8080/admin for the admin panel.

6. Collect data (see the next section).
   The simulator shows "No historical data collected yet" until the first candles arrive.

## Collecting market data

Queue the initial backfill and process it:

```bash
php bin/fetch.php --plan      # queue spot history, contract discovery and per-contract candles
php bin/fetch.php --loop      # process the queue until empty (hours; safe to stop and restart)
```

Other commands:

```bash
php bin/fetch.php --status                 # queue counts
php bin/fetch.php --run --budget=50        # process jobs for 50 seconds then exit (cron friendly)
php bin/fetch.php --refresh                # queue today's candles for every live contract and re-run discovery
php bin/fetch.php --plan-dhan              # queue the expired-options backfill via Dhan
php bin/fetch.php --plan --underlying=NIFTY
```

What gets fetched, with defaults editable in Admin → Settings:

| Setting | Default | Meaning |
|---|---|---|
| Lookback period | 365 days | How far back spot and backfill data should go |
| Strike band | ±25 strikes | Strikes around ATM to track per expiry |
| Expiry window | 60 days | Expiries ahead of today to track |
| Delay between requests | 2500 ms | Kotak throttles fast clients |
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

`/admin`, email and password login, session cookie, CSRF-protected forms.

- **Dashboard**: data coverage per underlying, job counts, recent fetch runs, database size, cron snippet.
- **Console**: run any fetcher command once from the browser (`status`, `plan`, `refresh`, `plan-dhan`, `run` with a time budget) and watch its output stream into the page.
  Handy for testing locally and on hosts without shell access.
- **Data jobs**: queue a backfill, today's refresh or the Dhan backfill; run the queue for 60 seconds from the browser (useful on hosts without shell access); retry failed jobs; clear finished ones; filter by status; see errors.
- **Underlyings**: add, edit, disable or delete underlyings.
  A new underlying needs its Kotak symbol (`pSymbolName` in the scrip master), F&O exchange segment, and the spot `neosymbol` (for example `nse_cm|Nifty 50`, or `nse_cm|<token>` for a stock).
  Lot size and strike step refresh automatically from the option chain.
  Per-underlying overrides for lookback, band and expiry window are optional.
- **Holidays**: the exchange holiday list used by the time stepper and expiry rules.
  Weekdays not listed are treated as trading days.
- **Settings**: collection parameters, risk-free rate, charge rates, site name, default underlying.
- **Account**: change password.

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
