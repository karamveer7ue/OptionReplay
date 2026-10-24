#!/usr/bin/env bash
# Fetch and update all market data up to now, then keep processing until the queue is empty.
# Does the same work as the two cron jobs (--run every minute, --refresh after close), in one go.
#   ./fetch.sh              plan + refresh + loop until the queue is empty
#   ./fetch.sh --watch      same, then keep re-checking for new work every 15 minutes
#   ./fetch.sh --status     show queue counts only
set -euo pipefail
cd "$(dirname "$0")"
[[ -f .env ]] || { echo "Missing .env (copy .env.example and fill it in)"; exit 1; }
[[ -d vendor ]] || composer install --no-interaction

if [[ "${1:-}" == "--status" ]]; then php bin/fetch.php --status; exit 0; fi

# Clear a lock left behind by a killed run (only when no fetcher process exists).
if [[ -f storage/fetch.lock ]] && ! pgrep -f "bin/fetch.php" >/dev/null; then
  rm -f storage/fetch.lock
fi
if pgrep -f "bin/fetch.php --(run|loop)" >/dev/null; then
  echo "A fetcher is already running (pid $(pgrep -f 'bin/fetch.php --(run|loop)' | head -1)). Stop it first or wait for it to finish."
  exit 1
fi

cycle() {
  echo "== $(date '+%Y-%m-%d %H:%M:%S') queueing work"
  php bin/fetch.php --plan --quiet      # anything missing from the lookback window (idempotent)
  php bin/fetch.php --refresh --quiet   # today's candles for every live contract + fresh discovery
  php bin/fetch.php --status
  echo "== processing queue (Ctrl+C to stop; progress is saved per job)"
  php bin/fetch.php --loop
}

cycle
if [[ "${1:-}" == "--watch" ]]; then
  while true; do
    echo "== queue empty, sleeping 15 minutes"
    sleep 900
    cycle
  done
fi
echo "== all queued data fetched. Run ./fetch.sh again after the next market close to pick up new candles."
