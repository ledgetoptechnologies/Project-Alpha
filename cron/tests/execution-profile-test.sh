#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${ROOT_DIR}/cron/execution-profile.sh"
TEST_PARENT="$(cd "${TMPDIR:-/tmp}" && pwd)"
TEST_DIR="$(mktemp -d "${TEST_PARENT}/pa-cron-profile.XXXXXX")"
cleanup() {
  case "$TEST_DIR" in "$TEST_PARENT"/pa-cron-profile.*) rm -rf -- "$TEST_DIR" ;; *) exit 1 ;; esac
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

[ "$(cron_execution_profile)" = full ] || fail 'default profile changed'
[ "$(cron_execution_profile full)" = full ] || fail 'explicit full rejected'
[ "$(cron_execution_profile portal-only)" = portal-only ] || fail 'portal profile rejected'
for profile in '' portal all FULL; do
  if cron_execution_profile "$profile" >/dev/null 2>&1; then fail 'invalid profile accepted'; fi
done
if cron_execution_profile full portal-only >/dev/null 2>&1; then fail 'extra arguments accepted'; fi

mkdir "$TEST_DIR/schedules"
cp "$ROOT_DIR/cron/crontab" "$TEST_DIR/schedules/crontab.full"
cp "$ROOT_DIR/cron/crontab.portal-only" "$TEST_DIR/schedules/crontab.portal-only"
target="$TEST_DIR/project-alpha"
cron_install_execution_profile portal-only "$TEST_DIR/schedules" "$target"
mapfile -t jobs < <(sed -n 's/.*php \/var\/www\/src\/cron\/\([^ ]*\).*/\1/p' "$target")
[ "${#jobs[@]}" = 2 ] || fail 'portal profile has extra or missing jobs'
[ "${jobs[0]}" = reconcile_client_portal.php ] || fail 'reconcile missing'
[ "${jobs[1]}" = send_portal_projection_outbox.php ] || fail 'sender missing'
[ "$(grep -c '^\* \* \* \* \* root \. /etc/environment && php ' "$target")" = 2 ] || fail 'jobs do not inherit the verified runtime environment'
cron_install_execution_profile full "$TEST_DIR/schedules" "$target"
cmp "$ROOT_DIR/cron/crontab" "$target" || fail 'default schedule changed or portal schedule persisted into full mode'
if cron_install_execution_profile invalid "$TEST_DIR/schedules" "$target" >/dev/null 2>&1; then fail 'invalid install succeeded'; fi
cmp "$ROOT_DIR/cron/crontab" "$target" || fail 'invalid install modified schedule'
ln -s "$target" "$TEST_DIR/linked-target"
if [ -L "$TEST_DIR/linked-target" ]; then
  if cron_install_execution_profile portal-only "$TEST_DIR/schedules" "$TEST_DIR/linked-target" >/dev/null 2>&1; then fail 'symlink schedule target accepted'; fi
else
  echo 'SKIP: this shell emulates symbolic links with regular files'
fi

php() { printf '%s\n' "$*" >> "$TEST_DIR/php-calls"; }
cron_run_startup_jobs portal-only /var/www/src/cron >/dev/null
[ ! -e "$TEST_DIR/php-calls" ] || fail 'portal profile ran a startup PHP job'
if cron_run_startup_jobs invalid /var/www/src/cron >/dev/null 2>&1; then fail 'invalid startup profile accepted'; fi
[ ! -e "$TEST_DIR/php-calls" ] || fail 'invalid profile ran a PHP job'
cron_run_startup_jobs full /var/www/src/cron >/dev/null
printf '%s\n' '/var/www/src/cron/backup_database.php --scheduled' '/var/www/src/cron/stripe_reconciliation.php --startup' > "$TEST_DIR/expected-calls"
cmp "$TEST_DIR/expected-calls" "$TEST_DIR/php-calls" || fail 'default startup behavior changed'
echo 'PASS: execution profile validation, exact schedules, mode switching, symlink rejection, and startup job isolation'
