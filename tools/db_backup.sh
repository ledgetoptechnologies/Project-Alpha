#!/usr/bin/env bash
# tools/db_backup.sh — dump the Project Alpha database to a gzipped SQL file.
#
# Usage:
#   ./tools/db_backup.sh                  # writes backups/project_alpha_YYYYmmdd_HHMMSS.sql.gz[.gpg]
#   ./tools/db_backup.sh /path/out.sql.gz # explicit output path
#
# Reads credentials from .env (same file docker compose uses).
# Execs mysqldump INSIDE the db container, so it works with the
# no-host-port hardened compose setup.
#
# Encryption: if BACKUP_ENCRYPTION_KEY is set in .env, the dump streams
# directly into AES-256 GPG without writing a plaintext archive. Use a strong
# passphrase (e.g. openssl rand -hex 32).
#
# Retention: keeps the most recent $KEEP backups (default 14) in backups/.

set -euo pipefail
umask 077

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$REPO_DIR"

if [[ ! -f .env ]]; then
  echo "ERROR: .env not found in $REPO_DIR" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

DB_NAME="${MYSQL_DATABASE:-project_alpha}"
KEEP="${KEEP:-14}"
if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "ERROR: MYSQL_DATABASE contains unsupported characters." >&2
  exit 1
fi

STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="${1:-backups/${DB_NAME}_${STAMP}.sql.gz}"
mkdir -p -m 700 "$(dirname "$OUT")"
if [[ "$OUT" == backups/* ]]; then
  chmod 700 backups
fi

dump_database() {
  docker compose exec -T db sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot \
      --single-transaction --routines --triggers --events --no-tablespaces \
      "$MYSQL_DATABASE"
  '
}

echo "Dumping ${DB_NAME} -> ${OUT}"
ENCRYPTED=""
if [[ -n "${BACKUP_ENCRYPTION_KEY:-}" ]]; then
  OUT="${OUT}.gpg"
  ENCRYPTED=".gpg"
  if ! dump_database | gzip | gpg --batch --yes --pinentry-mode loopback \
    --passphrase-fd 3 --symmetric --cipher-algo AES256 --output "$OUT" \
    3<<<"$BACKUP_ENCRYPTION_KEY"; then
    rm -f -- "$OUT"
    echo "ERROR: encrypted database dump failed." >&2
    exit 1
  fi
  if ! gpg --batch --pinentry-mode loopback --passphrase-fd 3 --decrypt "$OUT" \
    3<<<"$BACKUP_ENCRYPTION_KEY" 2>/dev/null | gzip -cd | tail -1 | grep -q "Dump completed"; then
    rm -f -- "$OUT"
    echo "ERROR: encrypted backup verification failed." >&2
    exit 1
  fi
else
  if ! dump_database | gzip > "$OUT"; then
    rm -f -- "$OUT"
    echo "ERROR: database dump failed." >&2
    exit 1
  fi
  if ! gzip -cd "$OUT" | tail -1 | grep -q "Dump completed"; then
    rm -f -- "$OUT"
    echo "ERROR: database dump verification failed." >&2
    exit 1
  fi
fi

SIZE="$(du -h "$OUT" | cut -f1)"
echo "OK: ${OUT} (${SIZE})${ENCRYPTED:+ [encrypted]}"

# Retention: prune old backups beyond $KEEP (only in the default backups/ dir)
if [[ "$OUT" == backups/* ]]; then
  ls -1t backups/${DB_NAME}_*.sql.gz* 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    echo "Pruning old backup: $old"
    rm -f "$old"
  done
fi
