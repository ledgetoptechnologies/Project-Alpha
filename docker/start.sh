#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-rootpass}"
APP_ENV_NORMALIZED="$(printf '%s' "${APP_ENV:-production}" | tr '[:upper:]' '[:lower:]')"
APP_ENCRYPTION_KEY_WAS_SET=1
if [ -z "${APP_ENCRYPTION_KEY:-}" ]; then
  APP_ENCRYPTION_KEY_WAS_SET=0
fi

CONFIG_DIR="/var/www/config"
source /usr/local/lib/project-alpha/app-encryption-key.sh
app_encryption_key_prepare_web "$CONFIG_DIR"
# Do not follow a volume-provided link while adjusting the preferred owner.
# Some volume drivers do not allow chown, in which case the already validated
# owner-only file remains usable by the root entrypoint and Apache inherits the
# loaded value through its environment.
chown -h www-data:www-data "${CONFIG_DIR}/.encryption_key" 2>/dev/null || true
app_encryption_key_validate_file "${CONFIG_DIR}/.encryption_key"

# Also write the key to a .env file in the config volume so PHP can read it
# (app.php reads .env from /var/www/config/.env)
if [ ! -f "${CONFIG_DIR}/.env" ] || ! grep -q "APP_ENCRYPTION_KEY" "${CONFIG_DIR}/.env" 2>/dev/null; then
  echo "APP_ENCRYPTION_KEY=\"${APP_ENCRYPTION_KEY}\"" >> "${CONFIG_DIR}/.env"
fi

if [ "$APP_ENV_NORMALIZED" = "production" ] || [ "$APP_ENV_NORMALIZED" = "prod" ]; then
  echo "Production readiness checks:"
  if [ -z "${APP_HOST:-}" ]; then
    echo "WARNING: APP_HOST is not set. Configure the canonical HTTPS/proxy hostname before exposing Project Alpha."
  fi
  if [ -z "${WEBAUTHN_ORIGIN:-}" ]; then
    echo "WARNING: WEBAUTHN_ORIGIN is not set. Password and TOTP sign-in work, but passkeys are disabled."
  fi
  if [ "$APP_ENCRYPTION_KEY_WAS_SET" = "0" ]; then
    echo "WARNING: APP_ENCRYPTION_KEY was not explicitly supplied. A persisted key was used/generated; back it up securely."
  fi
  if [ "${MYSQL_ROOT_PASSWORD:-}" = "changeme_root_pass" ] || [ "${MYSQL_ROOT_PASSWORD:-}" = "rootpass" ]; then
    echo "WARNING: MYSQL_ROOT_PASSWORD appears to use a default/example value."
  fi
  if [ -z "${BACKUP_ENCRYPTION_KEY:-}" ]; then
    echo "WARNING: BACKUP_ENCRYPTION_KEY is not set; backup archives will not be encrypted."
  fi
  if command -v mysql >/dev/null 2>&1 && [ -n "${MYSQL_USER:-}" ] && [ -n "${MYSQL_PASSWORD:-}" ]; then
    stripe_webhook_count="$(MYSQL_PWD="${MYSQL_PASSWORD}" mysql --skip-ssl -h "$DB_HOST" -P "$DB_PORT" -u"${MYSQL_USER}" -N -s -e "SELECT COUNT(*) FROM app_config WHERE config_key = 'stripe_webhook_secret_enc' AND COALESCE(config_value, '') <> ''" "${MYSQL_DATABASE:-project_alpha}" 2>/dev/null || echo "0")"
    if [ "${stripe_webhook_count:-0}" = "0" ]; then
      echo "WARNING: Stripe webhook secret is not configured in app settings; Stripe webhooks will fail closed in production."
    fi
  else
    echo "WARNING: Could not verify Stripe webhook secret readiness because mysql client or database credentials are unavailable."
  fi
  if [ "$(printf '%s' "${AUTH_DISABLED:-${APP_AUTH_DISABLED:-}}" | tr '[:upper:]' '[:lower:]')" = "true" ]; then
    echo "WARNING: AUTH_DISABLED/APP_AUTH_DISABLED is set but ignored in production."
  fi
fi

# Database initialization, migrations, schema validation, and administrator
# reconciliation are completed by docker/migrate.sh before this container is
# allowed to start. The web process never performs fail-open schema work.

# Seed config directory with defaults if mounted and empty
CONFIG_DIR="/var/www/config"
if [ ! -d "${CONFIG_DIR}" ]; then
  mkdir -p "${CONFIG_DIR}" || true
fi
if [ -d "${CONFIG_DIR}" ]; then
  if [ ! -f "${CONFIG_DIR}/settings.json" ]; then
    echo "Seeding default settings.json into ${CONFIG_DIR}..."
    cat > "${CONFIG_DIR}/settings.json" <<'JSON'
{
  "brand_name": "Project Alpha",
  "logo_path": null,
  "from_name": null,
  "from_address_line1": null,
  "from_address_line2": null,
  "from_city": null,
  "from_state": null,
  "from_postal": null,
  "from_country": null,
  "from_email": null,
  "from_phone": null,
  "terms": null,
  "net_terms_days": 30,
  "payment_methods": ["card","cash","bank_transfer"]
}
JSON
    chown www-data:www-data "${CONFIG_DIR}/settings.json" || true
    chmod 664 "${CONFIG_DIR}/settings.json" || true
  fi

  # Establish persistent log paths without following administrator-writable
  # volume symlinks while privileged. All file operations run as www-data.
  LOG_ROOT="${CONFIG_DIR}/logs"
  if [ -L "$LOG_ROOT" ] || { [ -e "$LOG_ROOT" ] && [ ! -d "$LOG_ROOT" ]; }; then
    echo "ERROR: ${LOG_ROOT} must be a real directory, not a symlink or special file."
    exit 1
  fi
  if [ ! -d "$LOG_ROOT" ]; then
    mkdir "$LOG_ROOT"
  fi
  chown -h www-data:www-data "$LOG_ROOT"
  runuser -u www-data -- chmod 775 "$LOG_ROOT"

  for log_subdir in system cron; do
    full_dir="${LOG_ROOT}/${log_subdir}"
    if [ -L "$full_dir" ] || { [ -e "$full_dir" ] && [ ! -d "$full_dir" ]; }; then
      echo "ERROR: ${full_dir} must be a real directory, not a symlink or special file."
      exit 1
    fi
    if [ ! -d "$full_dir" ]; then
      runuser -u www-data -- mkdir "$full_dir"
    fi
    chown -h www-data:www-data "$full_dir"
    runuser -u www-data -- chmod 775 "$full_dir"
  done

  ERROR_LOG="${LOG_ROOT}/system/error_log.txt"
  if [ -L "$ERROR_LOG" ] || { [ -e "$ERROR_LOG" ] && [ ! -f "$ERROR_LOG" ]; }; then
    echo "ERROR: ${ERROR_LOG} must be a regular file, not a symlink or special file."
    exit 1
  fi
  if ! runuser -u www-data -- php /var/www/src/cron/prepare_log_file.php "$ERROR_LOG"; then
    echo "ERROR: ${ERROR_LOG} could not be safely prepared for www-data."
    exit 1
  fi

  if [ ! -d "${CONFIG_DIR}/uploads" ]; then
    mkdir -p "${CONFIG_DIR}/uploads" || true
    chown -R www-data:www-data "${CONFIG_DIR}/uploads" || true
    chmod 775 "${CONFIG_DIR}/uploads" || true
  fi
fi

# Ensure application-level .htaccess routing is active even when APP_HOST is not
# set and the default Apache virtual host is used.
cat > /etc/apache2/conf-available/project-alpha-routing.conf <<'EOF'
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
EOF
a2enmod rewrite >/dev/null
a2enconf project-alpha-routing >/dev/null

# 5) Ensure uploads directories exist with correct permissions
UPLOADS_DIR="/var/www/src/uploads"
if [ ! -d "${UPLOADS_DIR}/receipts" ]; then
  echo "Creating receipts upload directory..."
  mkdir -p "${UPLOADS_DIR}/receipts" || true
fi
if [ ! -d "${UPLOADS_DIR}/forms" ]; then
  echo "Creating forms upload directory..."
  mkdir -p "${UPLOADS_DIR}/forms" || true
fi
chown -R www-data:www-data "${UPLOADS_DIR}" || true
chmod -R 775 "${UPLOADS_DIR}" || true

# 5b) Ensure backup directories exist with correct permissions
echo "Creating backup directories..."
BACKUP_DIR="/var/www/backups"
for subdir in daily weekly monthly; do
    if [ ! -d "$BACKUP_DIR/$subdir" ]; then
        mkdir -p "$BACKUP_DIR/$subdir"
    fi
done
chown -R www-data:www-data "$BACKUP_DIR" 2>/dev/null || true
chmod -R 775 "$BACKUP_DIR" 2>/dev/null || true

# 6) Database and config setup complete
# Note: Cron jobs are now handled by the separate 'cron' service in docker-compose.yml
# This web service no longer manages scheduled tasks.

# 7) Optional: harden Apache virtual host when APP_HOST is set
APP_HOST="${APP_HOST:-}"
if [ -n "$APP_HOST" ]; then
  echo "🔒 Hardening Apache for domain: ${APP_HOST}"
  cat > /etc/apache2/conf-available/security-hardening.conf <<EOF
# Deny access by IP, require hostname match
<VirtualHost *:80>
    ServerName ${APP_HOST}
    DocumentRoot /var/www/html
    
    # Security headers
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options SAMEORIGIN
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy no-referrer-when-downgrade
    # Project Alpha emits its canonical CSP from security_headers.php. Do not
    # add a second policy here: browsers enforce both policies, and the more
    # restrictive Apache copy breaks legacy controls still being migrated away
    # from inline handlers.
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
    
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# Default: deny direct IP access (optional)
<VirtualHost _default_:80>
    DocumentRoot /var/www/html
    <Location />
        Require all denied
    </Location>
</VirtualHost>
EOF
  a2enmod headers
  a2enconf security-hardening
  echo "✅ Apache hardened for production domain."
fi

echo "✅ Setup complete."
exec apache2-foreground
