#!/usr/bin/env bash
# Start (or restart) the local OptionReplay web server.
#   ./start.sh            start on port 8080 (restarts if already running)
#   ./start.sh 9000       use another port
#   ./start.sh --stop     stop the server
set -euo pipefail
cd "$(dirname "$0")"

PORT="${1:-8080}"
LOG="storage/logs/server.out"
mkdir -p storage/logs storage/cache

if [[ "${1:-}" == "--stop" ]]; then
  pkill -f "php -S 0.0.0.0:[0-9]+ -t public" 2>/dev/null && echo "Server stopped." || echo "No server was running."
  exit 0
fi

[[ -f .env ]] || { echo "Missing .env (copy .env.example and fill it in)"; exit 1; }
[[ -d vendor ]] || composer install --no-interaction

# Restart if already running
if pgrep -f "php -S 0.0.0.0:${PORT} -t public" >/dev/null; then
  echo "Server already running on port ${PORT}, restarting..."
  pkill -f "php -S 0.0.0.0:${PORT} -t public" || true
  sleep 1
fi

php bin/migrate.php >/dev/null 2>&1 || { echo "Database migration failed. Check DB_* in .env and that MySQL/MariaDB is running."; exit 1; }

PHP_CLI_SERVER_WORKERS=6 nohup php -S "0.0.0.0:${PORT}" -t public public/router.php >"$LOG" 2>&1 &
sleep 1
if curl -sf -o /dev/null "http://127.0.0.1:${PORT}/"; then
  echo "OptionReplay is running."
  echo "  Simulator: http://localhost:${PORT}"
  echo "  Admin:     http://localhost:${PORT}/admin"
  echo "  Log:       ${LOG}"
  echo "Stop with: ./start.sh --stop"
else
  echo "Server did not come up. Last log lines:"; tail -5 "$LOG"; exit 1
fi
