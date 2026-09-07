#!/usr/bin/env bash
set -euo pipefail

CONFIG_DIR="/var/www/config"
# The web service owns initial key generation in the shared config volume.
# Wait briefly for a delayed shared-volume write, but never invent a key that
# would make cron unable to decrypt the web service's encrypted configuration.
source /usr/local/lib/project-alpha/cron-encryption-key.sh
cron_load_app_encryption_key "$CONFIG_DIR" 60 1

# ── Export environment variables so cron jobs can access them ──
# Cron does NOT inherit the container's env vars, so we dump them
# to /etc/environment which each cron job sources before running.
ENV_FILE="/etc/environment"
: > "$ENV_FILE"
while IFS='=' read -r name value; do
  case "$name" in
    MYSQL_*|DB_*|APP_*|STRIPE_*|SMTP_*|BACKUP_*|NOTIFICATION_RELAY_*)
      printf 'export %s=%q\n' "$name" "$value" >> "$ENV_FILE"
      ;;
  esac
done < <(printenv)

# ── Create log directory if it doesn't exist ──
LOG_ROOT="/var/www/config/logs"
if [ -L "$LOG_ROOT" ] || { [ -e "$LOG_ROOT" ] && [ ! -d "$LOG_ROOT" ]; }; then
    echo "[cron-entrypoint] ERROR: $LOG_ROOT must be a real directory."
    exit 1
fi
if [ ! -d "$LOG_ROOT" ]; then
    mkdir "$LOG_ROOT"
fi
chown -h www-data:www-data "$LOG_ROOT"
runuser -u www-data -- chmod 775 "$LOG_ROOT"

LOG_DIR="$LOG_ROOT/cron"
if [ -L "$LOG_DIR" ] || { [ -e "$LOG_DIR" ] && [ ! -d "$LOG_DIR" ]; }; then
    echo "[cron-entrypoint] ERROR: $LOG_DIR must be a real directory."
    exit 1
fi
if [ ! -d "$LOG_DIR" ]; then
    runuser -u www-data -- mkdir "$LOG_DIR"
    echo "[cron-entrypoint] Created log directory: $LOG_DIR"
fi
chown -h www-data:www-data "$LOG_DIR"
runuser -u www-data -- chmod 775 "$LOG_DIR"

CRON_LOG="$LOG_DIR/cron.log"
if [ -L "$CRON_LOG" ] || { [ -e "$CRON_LOG" ] && [ ! -f "$CRON_LOG" ]; }; then
    echo "[cron-entrypoint] ERROR: $CRON_LOG must be a regular file."
    exit 1
fi
if ! runuser -u www-data -- php /var/www/src/cron/prepare_log_file.php "$CRON_LOG"; then
    echo "[cron-entrypoint] ERROR: $CRON_LOG could not be safely prepared for www-data."
    exit 1
fi

# ── Wait for the database to become available ──
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
RETRIES=60
WAIT=2

echo "[cron-entrypoint] Waiting for DB at ${DB_HOST}:${DB_PORT}..."

counter=0
while [ $counter -lt $RETRIES ]; do
  if command -v mysqladmin > /dev/null 2>&1; then
    mysqladmin --skip-ssl ping \
      -h "${DB_HOST}" -P "${DB_PORT}" \
      -u"${MYSQL_USER:-root}" \
      --password="${MYSQL_PASSWORD:-${MYSQL_ROOT_PASSWORD:-rootpass}}" \
      --silent > /dev/null 2>&1 && {
      echo "[cron-entrypoint] DB is ready."
      break
    }
  else
    (echo > /dev/tcp/${DB_HOST}/${DB_PORT}) > /dev/null 2>&1 && {
      echo "[cron-entrypoint] DB TCP port is open."
      break
    }
  fi

  counter=$((counter + 1))
  echo "[cron-entrypoint] Still waiting for DB... (${counter}/${RETRIES})"
  sleep ${WAIT}
done

if [ $counter -ge $RETRIES ]; then
  echo "[cron-entrypoint] ERROR: DB did not become available after $((RETRIES * WAIT)) seconds."
  exit 1
fi

# ── Start cron in the foreground ──
APP_TIMEZONE="${APP_TIMEZONE:-}"
if command -v mysql > /dev/null 2>&1; then
  APP_TIMEZONE="$(mysql --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" \
    -u"${MYSQL_USER:-root}" \
    --password="${MYSQL_PASSWORD:-${MYSQL_ROOT_PASSWORD:-rootpass}}" \
    -D "${MYSQL_DATABASE:-project_alpha}" \
    --batch --skip-column-names \
    -e "SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='timezone' LIMIT 1" 2>/dev/null || true)"
fi

if [ -z "$APP_TIMEZONE" ]; then
  APP_TIMEZONE="${TZ:-UTC}"
fi

if [ -f "/usr/share/zoneinfo/${APP_TIMEZONE}" ]; then
  ln -snf "/usr/share/zoneinfo/${APP_TIMEZONE}" /etc/localtime
  echo "${APP_TIMEZONE}" > /etc/timezone
  export TZ="${APP_TIMEZONE}"
  echo "[cron-entrypoint] Using PA timezone: ${APP_TIMEZONE}"
else
  export TZ="UTC"
  echo "[cron-entrypoint] Timezone '${APP_TIMEZONE}' is unavailable; using UTC."
fi

grep -vE '^(export[[:space:]]+)?TZ=' "$ENV_FILE" > /tmp/project-alpha-environment || true
printf 'export TZ=%q\n' "$TZ" >> /tmp/project-alpha-environment
mv /tmp/project-alpha-environment "$ENV_FILE"

echo "[cron-entrypoint] Running startup scheduled backup check..."
php /var/www/src/cron/backup_database.php --scheduled || echo "[cron-entrypoint] Startup scheduled backup check failed; cron will retry at the next hourly check."

echo "[cron-entrypoint] Running startup Stripe reconciliation..."
php /var/www/src/cron/stripe_reconciliation.php --startup || echo "[cron-entrypoint] Startup Stripe reconciliation failed; cron will continue."

echo "[cron-entrypoint] Starting cron..."
exec cron -f
