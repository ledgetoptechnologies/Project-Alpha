#!/usr/bin/env bash
# Optional execution profiles for the existing cron daemon. No key or secret handling.

cron_execution_profile() {
  if [ "$#" -gt 1 ]; then
    echo '[cron-entrypoint] ERROR: Expected at most one execution profile.' >&2
    return 1
  fi
  case "${1-full}" in
    full|portal-only) printf '%s\n' "${1-full}" ;;
    *) echo '[cron-entrypoint] ERROR: Unknown execution profile; use full or portal-only.' >&2; return 1 ;;
  esac
}

cron_install_execution_profile() {
  local profile source_file target="$3"
  profile="$(cron_execution_profile "$1")" || return 1
  source_file="${2}/crontab.${profile}"
  if [ ! -f "$source_file" ] || [ -L "$source_file" ] || [ -L "$target" ] || { [ -e "$target" ] && [ ! -f "$target" ]; }; then
    echo '[cron-entrypoint] ERROR: Cron schedule paths must be regular, non-symlink files.' >&2
    return 1
  fi
  install -m 0644 -- "$source_file" "$target"
}

cron_run_startup_jobs() {
  local profile
  profile="$(cron_execution_profile "$1")" || return 1
  if [ "$profile" = portal-only ]; then
    echo '[cron-entrypoint] Portal-only profile: startup backup and Stripe jobs skipped.'
    return 0
  fi
  echo '[cron-entrypoint] Running startup scheduled backup check...'
  php "$2/backup_database.php" --scheduled || echo '[cron-entrypoint] Startup scheduled backup check failed; cron will retry at the next hourly check.'
  echo '[cron-entrypoint] Running startup Stripe reconciliation...'
  php "$2/stripe_reconciliation.php" --startup || echo '[cron-entrypoint] Startup Stripe reconciliation failed; cron will continue.'
}
