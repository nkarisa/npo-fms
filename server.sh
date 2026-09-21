#!/usr/bin/env bash
#
# Local development server.
#
# Use this instead of `php spark serve`, which runs PHP's built-in server with a
# single worker and, whenever that server exits abnormally, silently starts it
# again on the NEXT port (8076, 8077, ...), so the app moves out from under open
# browser tabs. This keeps the port fixed, runs several workers so one slow
# request — an assistant question — does not freeze every other page, and
# restarts the server if it dies, logging the exit status and time.
#
# Usage:
#   ./server.sh                 # http://localhost:8075
#   ./server.sh --port 8080
#   ./server.sh --port=8080
#   ./server.sh --no-localstack # skip starting LocalStack
#   PHP_BIN=/opt/homebrew/opt/php@8.3/bin/php ./server.sh   # when php is not on PATH
#
# When .env keeps documents in S3 (documents.disk = s3) at a local endpoint
# (documents.s3Endpoint = http://localhost:4566), LocalStack is started and the
# bucket set up first (localstack.sh), so uploads work without an AWS account.
#
# Stop with Ctrl+C.

set -u

port=8075
workers=4
php_bin="${PHP_BIN:-php}"
localstack=1

usage() {
  echo "Usage: $0 [--port <1-65535>] [--no-localstack]" >&2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --port)
      [ $# -ge 2 ] || { echo "--port needs a value" >&2; usage; exit 1; }
      port="$2"
      shift 2
      ;;
    --port=*)
      port="${1#--port=}"
      shift
      ;;
    --no-localstack)
      localstack=0
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

case "$port" in
  ''|*[!0-9]*) echo "--port must be a number, got '$port'" >&2; exit 1 ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
  echo "--port must be between 1 and 65535, got $port" >&2
  exit 1
fi

if ! command -v "$php_bin" >/dev/null 2>&1; then
  echo "'$php_bin' not found. Put php on PATH, or set PHP_BIN=/path/to/php." >&2
  exit 1
fi

# Paths below are relative to the project root, wherever this is run from.
cd "$(dirname "$0")" || exit 1

if command -v lsof >/dev/null 2>&1 && lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Port $port is already in use. Stop whatever is on it, or pass --port." >&2
  exit 1
fi

# Documents in S3 at a local endpoint: bring up LocalStack and its bucket first.
documents_setting() {
  [ -f .env ] || return 0
  sed -n -E "s/^[[:space:]]*documents\.$1[[:space:]]*=[[:space:]]*(.*)$/\1/p" .env | tail -n 1 \
    | sed -E "s/[[:space:]]+#.*$//; s/[[:space:]]+$//; s/^['\"](.*)['\"]$/\1/"
}
if [ "$localstack" -eq 1 ] && [ "$(documents_setting disk)" = "s3" ]; then
  case "$(documents_setting s3Endpoint)" in
    http://localhost:*|http://127.0.0.1:*)
      PHP_BIN="$php_bin" ./localstack.sh || {
        echo "LocalStack is not ready, so documents cannot be uploaded. Fix the above, set documents.disk = local, or run with --no-localstack." >&2
        exit 1
      }
      ;;
  esac
fi

trap 'exit 0' INT TERM

echo "Serving on http://localhost:$port with $workers workers. Ctrl+C to stop."

quick_failures=0
while true; do
  started=$(date +%s)
  PHP_CLI_SERVER_WORKERS=$workers "$php_bin" -S "localhost:$port" -t public/ vendor/codeigniter4/framework/system/rewrite.php
  status=$?
  echo "$(date '+%F %T') server exited with status $status — restarting on $port" >&2

  # A server that dies within seconds of starting is not going to recover —
  # the port was taken, or PHP cannot start — so stop rather than spin.
  if [ $(( $(date +%s) - started )) -lt 5 ]; then
    quick_failures=$((quick_failures + 1))
    if [ "$quick_failures" -ge 3 ]; then
      echo "Server failed to stay up three times in a row. Giving up." >&2
      exit 1
    fi
  else
    quick_failures=0
  fi

  sleep 1
done
