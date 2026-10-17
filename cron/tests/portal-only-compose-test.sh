#!/usr/bin/env bash
# Real-container CI acceptance, after the default full-cron startup check.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
compose=(docker compose -f docker-compose.yml -f docker-compose.portal-only.yml)
"${compose[@]}" up -d --no-deps --force-recreate --pull never cron

ready=0
for attempt in $(seq 1 30); do
  logs="$("${compose[@]}" logs --no-color cron)"
  if grep -qF 'DB is ready' <<< "$logs" \
    && grep -qF 'Loaded and verified APP_ENCRYPTION_KEY' <<< "$logs" \
    && grep -qF 'Starting cron...' <<< "$logs" \
    && "${compose[@]}" exec -T cron bash -c 'test "$(cat /proc/1/comm)" = cron' >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
[ "$ready" = 1 ] || { echo 'Portal-only cron did not become ready within 60 seconds'; exit 1; }
"${compose[@]}" exec -T cron cmp - /etc/cron.d/project-alpha < cron/crontab.portal-only
grep -qF 'Portal-only profile: startup backup and Stripe jobs skipped.' <<< "$logs"
if grep -qE 'Running startup scheduled backup check|Running startup Stripe reconciliation' <<< "$logs"; then
  echo 'Portal-only cron ran a forbidden startup job'
  exit 1
fi
# Compare inside the container; neither key value is printed or placed in argv.
"${compose[@]}" exec -T cron bash -c '
  set -euo pipefail
  . /etc/environment
  test -n "${APP_ENCRYPTION_KEY:-}"
  test -f /var/www/config/.encryption_key
  test ! -L /var/www/config/.encryption_key
  test "$APP_ENCRYPTION_KEY" = "$(cat /var/www/config/.encryption_key)"
'
echo 'PASS: real portal-only cron starts with DB and shared key, installs the exact schedule, and skips startup financial jobs'
# Remaining CI checks use web or independent stacks; the existing final Compose
# teardown removes this cron container. Do not rerun full startup jobs here.
