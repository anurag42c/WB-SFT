#!/usr/bin/env bash
# Quick start (Linux/macOS): checks the PC, then runs the scale readers and the web UI.
# For a permanent installation use the systemd units in deploy/ instead (README step 8).
set -e
cd "$(dirname "$0")"
PHP="${PHP:-php}"; PORT="${PORT:-8080}"
"$PHP" bin/check.php || { echo "Fix the FAIL items above first."; exit 1; }
"$PHP" bin/scale_supervisor.php > data/supervisor.log 2>&1 &
SUP=$!
trap 'kill $SUP 2>/dev/null; wait $SUP 2>/dev/null' EXIT INT TERM
echo "Open  http://<this-pc>:$PORT/   (Ctrl+C stops everything)"
"$PHP" -S "0.0.0.0:$PORT" -t public
