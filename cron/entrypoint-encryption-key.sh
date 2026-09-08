#!/usr/bin/env bash
# Establish one application-encryption key for every process sharing the
# Project Alpha configuration volume. Secret values are never written to logs.

app_encryption_key_error() {
  printf '%s\n' "[app-encryption-key] ERROR: $1" >&2
}

app_encryption_key_warning() {
  printf '%s\n' "[app-encryption-key] WARNING: $1" >&2
}

app_encryption_key_validate_file() {
  local key_file="$1" mode
  if [ -L "$key_file" ] || [ ! -f "$key_file" ]; then
    app_encryption_key_error 'The shared key path must be a regular, non-symlink file.'
    return 1
  fi
  mode="$(stat -c '%a' -- "$key_file" 2>/dev/null || true)"
  if [[ ! "$mode" =~ ^[0-7]{3,4}$ ]]; then
    app_encryption_key_error 'Could not determine the shared key file permissions.'
    return 1
  fi

  # Existing named volumes can retain a key created with the host's default
  # umask. A regular file which this container can control may be tightened in
  # place; never substitute, follow, or otherwise recover from an unsafe path.
  if (( (8#$mode & 077) != 0 )); then
    if ! chmod 0600 -- "$key_file"; then
      app_encryption_key_error 'The shared key file has unsafe permissions and could not be restricted to owner-only access.'
      return 1
    fi

    # TrueNAS SCALE application datasets can report host-ACL-derived mode bits
    # even after a successful chmod from inside the container. Revalidate the
    # object type unconditionally. If only the mode remains broad, preserve the
    # prior compatible behavior and warn: this file lives in the private shared
    # application config volume and is also guarded by the key-match contract.
    if [ -L "$key_file" ] || [ ! -f "$key_file" ]; then
      app_encryption_key_error 'The shared key path must remain a regular, non-symlink file.'
      return 1
    fi
    mode="$(stat -c '%a' -- "$key_file" 2>/dev/null || true)"
    if [[ ! "$mode" =~ ^[0-7]{3,4}$ ]]; then
      app_encryption_key_error 'Could not determine the shared key file permissions after attempting to restrict them.'
      return 1
    fi
    if (( (8#$mode & 077) != 0 )); then
      app_encryption_key_warning 'The storage driver retained group/other mode bits after chmod; continuing with the existing regular key in the private application config volume.'
    fi
  fi
}

app_encryption_key_read_file() {
  local key_file="$1" loaded_key
  app_encryption_key_validate_file "$key_file" || return 1
  loaded_key="$(cat -- "$key_file" 2>/dev/null || true)"
  if [ -z "$loaded_key" ]; then
    app_encryption_key_error 'The shared key file is empty or unreadable.'
    return 1
  fi
  printf '%s' "$loaded_key"
}

# Publish a complete file without replacing a key another process may already
# have established. The temporary file and hard link remain on the same volume,
# and link(2) fails rather than overwriting an existing contract.
app_encryption_key_persist_absent() {
  local key_file="$1" key_value="$2" temp_file
  umask 077
  temp_file="$(mktemp "${key_file}.tmp.XXXXXX")" || {
    app_encryption_key_error 'Could not prepare the shared key file.'
    return 1
  }
  if ! printf '%s\n' "$key_value" > "$temp_file" || ! chmod 600 "$temp_file"; then
    rm -f -- "$temp_file"
    app_encryption_key_error 'Could not prepare the shared key file.'
    return 1
  fi
  if ln -- "$temp_file" "$key_file" 2>/dev/null; then
    rm -f -- "$temp_file"
    return 0
  fi
  rm -f -- "$temp_file"
  return 2
}

app_encryption_key_require_match() {
  local key_file="$1" expected="$2" persisted
  persisted="$(app_encryption_key_read_file "$key_file")" || return 1
  if [ "$persisted" != "$expected" ]; then
    app_encryption_key_error 'The explicit application key does not match the shared configuration key. Startup is stopped to prevent encrypted settings from becoming unreadable.'
    return 1
  fi
}

app_encryption_key_prepare_web() {
  local config_dir="$1" key_file="${1}/.encryption_key"
  local generated_key persisted persist_status

  if [ -L "$config_dir" ] || { [ -e "$config_dir" ] && [ ! -d "$config_dir" ]; }; then
    app_encryption_key_error 'The shared configuration path must be a real directory.'
    return 1
  fi
  mkdir -p -- "$config_dir"

  if [ -n "${APP_ENCRYPTION_KEY:-}" ]; then
    if [ -e "$key_file" ] || [ -L "$key_file" ]; then
      app_encryption_key_require_match "$key_file" "$APP_ENCRYPTION_KEY" || return 1
    elif app_encryption_key_persist_absent "$key_file" "$APP_ENCRYPTION_KEY"; then
      :
    else
      persist_status=$?
      if [ "$persist_status" -ne 2 ]; then return 1; fi
      app_encryption_key_require_match "$key_file" "$APP_ENCRYPTION_KEY" || return 1
    fi
    echo '[app-encryption-key] Persisted explicit APP_ENCRYPTION_KEY contract is ready.'
    return 0
  fi

  if [ -e "$key_file" ] || [ -L "$key_file" ]; then
    persisted="$(app_encryption_key_read_file "$key_file")" || return 1
    export APP_ENCRYPTION_KEY="$persisted"
    echo '[app-encryption-key] Loaded APP_ENCRYPTION_KEY from the shared configuration volume.'
    return 0
  fi

  generated_key="$(php -r 'echo base64_encode(random_bytes(32));')" || {
    app_encryption_key_error 'Could not generate the application key.'
    return 1
  }
  if app_encryption_key_persist_absent "$key_file" "$generated_key"; then
    export APP_ENCRYPTION_KEY="$generated_key"
  else
    persist_status=$?
    if [ "$persist_status" -ne 2 ]; then return 1; fi
    persisted="$(app_encryption_key_read_file "$key_file")" || return 1
    export APP_ENCRYPTION_KEY="$persisted"
  fi
  echo '[app-encryption-key] Generated and persisted APP_ENCRYPTION_KEY in the shared configuration volume.'
}

# Cron never generates or persists a key. It waits for the web-owned shared
# contract and verifies any explicit runtime key against it before proceeding.
app_encryption_key_prepare_cron() {
  local config_dir="$1" retries="${2:-60}" wait_seconds="${3:-1}"
  local key_file="${1}/.encryption_key" explicit_key="${APP_ENCRYPTION_KEY:-}"
  local persisted attempt

  for ((attempt = 0; attempt <= retries; attempt += 1)); do
    if [ -e "$key_file" ] || [ -L "$key_file" ]; then
      persisted="$(app_encryption_key_read_file "$key_file")" || return 1
      if [ -n "$explicit_key" ] && [ "$persisted" != "$explicit_key" ]; then
        app_encryption_key_error 'The cron application key does not match the shared configuration key. Cron will not start with a different key.'
        return 1
      fi
      export APP_ENCRYPTION_KEY="${explicit_key:-$persisted}"
      echo '[cron-entrypoint] Loaded and verified APP_ENCRYPTION_KEY from the shared configuration volume.'
      return 0
    fi
    if [ "$attempt" -lt "$retries" ]; then sleep "$wait_seconds"; fi
  done

  app_encryption_key_error "The shared application key was unavailable after $((retries * wait_seconds)) seconds. Cron will not start with a different key. Start or restart the web service, then restart cron."
  return 1
}

# Backward-compatible name used by the cron entrypoint and existing tests.
cron_load_app_encryption_key() {
  app_encryption_key_prepare_cron "$@"
}
