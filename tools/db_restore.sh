#!/usr/bin/env bash
# tools/db_restore.sh — restore a Project Alpha database backup.
#
# Usage:
#   ./tools/db_restore.sh backups/project_alpha_20260611_120000.sql.gz
#   ./tools/db_restore.sh dump.sql            # plain .sql also accepted
#
# SAFETY:
#   - Takes a pre-restore safety dump automatically (encrypted when configured)
#   - Asks for confirmation (set FORCE=1 to skip, e.g. in scripts)
#
# Reads credentials from .env. Execs mysql INSIDE the db container.

set -euo pipefail
umask 077

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$REPO_DIR"

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <backup.sql[.gz]>" >&2
  exit 1
fi
IN="$1"
if [[ ! -f "$IN" ]]; then
  echo "ERROR: file not found: $IN" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a
DB_NAME="${MYSQL_DATABASE:-project_alpha}"
if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "ERROR: MYSQL_DATABASE contains unsupported characters." >&2
  exit 1
fi
if [[ "$IN" == *.gpg && -z "${BACKUP_ENCRYPTION_KEY:-}" ]]; then
  echo "ERROR: BACKUP_ENCRYPTION_KEY not set in .env — cannot decrypt $IN" >&2
  exit 1
fi

dump_database() {
  docker compose exec -T db sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot \
      --single-transaction --routines --triggers --events --no-tablespaces \
      "$MYSQL_DATABASE"
  '
}

restore_database() {
  docker compose exec -T db sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"
  '
}

echo "About to RESTORE '${DB_NAME}' from: $IN"
echo "This OVERWRITES current data in ${DB_NAME}."
if [[ "${FORCE:-0}" != "1" ]]; then
  read -r -p "Type 'yes' to continue: " ans
  [[ "$ans" == "yes" ]] || { echo "Aborted."; exit 1; }
fi

# Pre-restore safety dump
STAMP="$(date +%Y%m%d_%H%M%S)"
SAFETY="backups/pre_restore_${DB_NAME}_${STAMP}.sql.gz"
mkdir -p -m 700 backups
chmod 700 backups
echo "Taking pre-restore safety dump -> ${SAFETY}"
if [[ -n "${BACKUP_ENCRYPTION_KEY:-}" ]]; then
  SAFETY="${SAFETY}.gpg"
  if ! dump_database | gzip | gpg --batch --yes --pinentry-mode loopback \
    --passphrase-fd 3 --symmetric --cipher-algo AES256 --output "$SAFETY" \
    3<<<"$BACKUP_ENCRYPTION_KEY"; then
    rm -f -- "$SAFETY"
    echo "ERROR: encrypted safety dump failed; restore was not started." >&2
    exit 1
  fi
  if ! gpg --batch --pinentry-mode loopback --passphrase-fd 3 --decrypt "$SAFETY" \
    3<<<"$BACKUP_ENCRYPTION_KEY" 2>/dev/null | gzip -cd | tail -1 | grep -q "Dump completed"; then
    rm -f -- "$SAFETY"
    echo "ERROR: encrypted safety dump verification failed; restore was not started." >&2
    exit 1
  fi
else
  if ! dump_database | gzip > "$SAFETY"; then
    rm -f -- "$SAFETY"
    echo "ERROR: safety dump failed; restore was not started." >&2
    exit 1
  fi
  if ! gzip -cd "$SAFETY" | tail -1 | grep -q "Dump completed"; then
    rm -f -- "$SAFETY"
    echo "ERROR: safety dump verification failed; restore was not started." >&2
    exit 1
  fi
fi

echo "Restoring..."
if [[ "$IN" == *.gpg ]]; then
  # Encrypted backup — decrypt first
  gpg --batch --pinentry-mode loopback --passphrase-fd 3 --decrypt "$IN" \
    3<<<"$BACKUP_ENCRYPTION_KEY" 2>/dev/null | \
    { [[ "$IN" == *.gz.gpg ]] && gzip -cd || cat; } | \
    restore_database
elif [[ "$IN" == *.gz ]]; then
  gzip -cd "$IN" | restore_database
else
  restore_database < "$IN"
fi

# Quick verification
TABLES=$(docker compose exec -T db sh -c '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE" -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"
')
echo "OK: restore complete — ${DB_NAME} now has ${TABLES} tables."
echo "Safety dump retained at: ${SAFETY}"
