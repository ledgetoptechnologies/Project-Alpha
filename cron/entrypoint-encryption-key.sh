#!/usr/bin/env bash
# Shared by the cron entrypoint and its focused shell test. This intentionally
# never generates or persists a key: the web service owns first-run creation.

cron_load_app_encryption_key() {
  local config_dir="$1" retries="${2:-60}" wait_seconds="${3:-1}"
  local key_file="${config_dir}/.encryption_key" loaded_key attempt

  if [ -n "${APP_ENCRYPTION_KEY:-}" ]; then
    return 0
  fi

  for ((attempt = 0; attempt <= retries; attempt += 1)); do
    if [ -f "$key_file" ] && [ ! -L "$key_file" ]; then
      if loaded_key="$(cat -- "$key_file" 2>/dev/null)" && [ -n "$loaded_key" ]; then
        export APP_ENCRYPTION_KEY="$loaded_key"
        echo "[cron-entrypoint] Loaded APP_ENCRYPTION_KEY from the shared config volume."
        return 0
      fi
    fi

    if [ "$attempt" -lt "$retries" ]; then
      sleep "$wait_seconds"
    fi
  done

  echo "[cron-entrypoint] ERROR: APP_ENCRYPTION_KEY is unset and the shared encryption key was unavailable or empty after $((retries * wait_seconds)) seconds. Cron will not start with a different key. Start or restart the web service so it persists ${key_file}, then restart cron." >&2
  return 1
}
