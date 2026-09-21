#!/usr/bin/env bash
#
# Throwaway FMS instance for agents: a SQLite file under writable/run-skill/,
# served on its own port. Never touches the MySQL database in
# .env, and never touches a server you already have running on app.baseURL.
#
#   serve.sh up [--fresh]   build the DB if missing (or rebuild with --fresh), start the server
#   serve.sh down           stop the server
#   serve.sh reset          down + delete the DB + up
#   serve.sh status         is it up, which DB, which port
#   serve.sh spark <args>   run `php spark <args>` against the SQLite DB (migrate gets -g tests added)
#
# DATA=demo (default) seeds the ELOG demonstration organisation, port 8095.
# DATA=blank is a brand-new instance: BaselineSeeder + `spark install` with
#   install.json (Coast Community Trust, a.salim@cct.or.ke), port 8096.
# PORT and PHP_BIN (default /opt/homebrew/bin/php, else php) override.

set -euo pipefail

cd "$(dirname "$0")/../../.." # project root

DATA="${DATA:-demo}"
case "$DATA" in
  demo) PORT="${PORT:-8095}" ;;
  blank) PORT="${PORT:-8096}" ;;
  *) echo "DATA must be demo or blank, got '$DATA'" >&2; exit 1 ;;
esac
if [ -z "${PHP_BIN:-}" ]; then
  if [ -x /opt/homebrew/bin/php ]; then PHP_BIN=/opt/homebrew/bin/php; else PHP_BIN=php; fi
fi
DIR="$PWD/writable/run-skill"
DB="$DIR/$DATA.sqlite"
PIDFILE="$DIR/server-$DATA.pid"
PORTFILE="$DIR/server-$DATA.port" # the port it was started on, whatever PORT says now
LOG="$DIR/server-$DATA.log"
mkdir -p "$DIR"
[ -f "$DIR/.gitignore" ] || echo "*" >"$DIR/.gitignore" # DBs, logs, screenshots stay out of git

# .env's database.default.* always wins over the process environment (PHP drops
# dotted names from $_ENV, so .env fills them in first). .env does not set the
# default group or the `tests` group, so point the app at the SQLite `tests`
# group instead. Tables get its `db_` prefix.
export database_defaultGroup=tests
export database_tests_database="$DB"
# Sign-in needs only the password here, so the driver and curl can sign in
# without an authenticator. Config\Auth::$mfaRequired defaults to 'all'.
export auth_mfaRequired="${auth_mfaRequired:-optional}" # auth_mfaRequired=all serve.sh up, to try the second step

spark() {
  # `spark migrate` ignores defaultGroup and uses `default` (MySQL) unless told.
  case "${1:-}" in
    migrate|migrate:*) "$PHP_BIN" spark "$@" -g tests ;;
    *) "$PHP_BIN" spark "$@" ;;
  esac
}

build_db() {
  rm -f "$DB"
  echo "Building $DB ($DATA)..."
  spark migrate >/dev/null
  if [ "$DATA" = demo ]; then
    spark db:seed DatabaseSeeder >/dev/null
  else
    spark db:seed BaselineSeeder >/dev/null
    spark install --config=.claude/skills/run-fms/install.json >/dev/null
  fi
  echo "Built."
}

running() { [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; }
url() { echo "http://localhost:$(cat "$PORTFILE" 2>/dev/null || echo "$PORT")"; }

up() {
  if [ "${1:-}" = "--fresh" ] || [ ! -s "$DB" ]; then
    running && down
    build_db
  fi
  if running; then echo "Already up: $(url) (pid $(cat "$PIDFILE"))"; return; fi
  if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Port $PORT is taken by something else. Use PORT=<n> $0 up" >&2; exit 1
  fi
  PHP_CLI_SERVER_WORKERS=4 nohup "$PHP_BIN" -S "localhost:$PORT" -t public/ \
    vendor/codeigniter4/framework/system/rewrite.php >"$LOG" 2>&1 &
  echo $! >"$PIDFILE"
  echo "$PORT" >"$PORTFILE"
  for _ in $(seq 1 50); do
    curl -sf -o /dev/null "http://localhost:$PORT/api/auth" && break
    sleep 0.2
  done
  curl -sf -o /dev/null "http://localhost:$PORT/api/auth" || { echo "Server did not come up; see $LOG" >&2; exit 1; }
  echo "Up: $(url) (pid $(cat "$PIDFILE"), db $DB)"
}

down() {
  if running; then
    pid=$(cat "$PIDFILE")
    pkill -P "$pid" 2>/dev/null || true # the worker processes
    kill "$pid" 2>/dev/null || true
    echo "Stopped pid $pid"
  else
    echo "Not running"
  fi
  rm -f "$PIDFILE" "$PORTFILE"
}

case "${1:-}" in
  up) shift; up "$@" ;;
  down) down ;;
  reset) down; rm -f "$DB"; up ;;
  status) if running; then echo "Up: $(url) (pid $(cat "$PIDFILE"), db $DB)"; else echo "Down (db $DB $( [ -s "$DB" ] && echo exists || echo missing))"; fi ;;
  spark) shift; spark "$@" ;;
  *) sed -n '3,17p' "$0"; exit 1 ;;
esac
