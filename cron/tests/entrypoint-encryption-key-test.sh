#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${ROOT_DIR}/cron/entrypoint-encryption-key.sh"

TEST_DIR="$(mktemp -d)"
cleanup() { rm -rf "$TEST_DIR"; }
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
assert_equal() { [ "$1" = "$2" ] || fail "expected '$1' to equal '$2'"; }

explicit_key_takes_precedence() {
  local config_dir="${TEST_DIR}/explicit"
  mkdir -p "$config_dir"
  printf '%s\n' 'shared-key-should-not-win' > "${config_dir}/.encryption_key"
  export APP_ENCRYPTION_KEY='explicit-key-wins'
  cron_load_app_encryption_key "$config_dir" 0 0 >/dev/null
  assert_equal 'explicit-key-wins' "$APP_ENCRYPTION_KEY"
}

existing_shared_key_is_loaded() {
  local config_dir="${TEST_DIR}/existing"
  mkdir -p "$config_dir"
  printf '%s\n' 'persisted-shared-key' > "${config_dir}/.encryption_key"
  unset APP_ENCRYPTION_KEY
  cron_load_app_encryption_key "$config_dir" 0 0 >/dev/null
  assert_equal 'persisted-shared-key' "$APP_ENCRYPTION_KEY"
}

delayed_shared_key_is_loaded() {
  local config_dir="${TEST_DIR}/delayed"
  mkdir -p "$config_dir"
  unset APP_ENCRYPTION_KEY
  (sleep .1; printf '%s\n' 'delayed-shared-key' > "${config_dir}/.encryption_key") &
  local writer=$!
  cron_load_app_encryption_key "$config_dir" 2 1 >/dev/null
  wait "$writer"
  assert_equal 'delayed-shared-key' "$APP_ENCRYPTION_KEY"
}

missing_or_empty_key_fails_without_leaking_a_key() {
  local config_dir="${TEST_DIR}/missing" output
  mkdir -p "$config_dir"
  unset APP_ENCRYPTION_KEY
  if output="$(cron_load_app_encryption_key "$config_dir" 1 0 2>&1)"; then
    fail 'missing shared key unexpectedly succeeded'
  fi
  [[ "$output" == *'Cron will not start with a different key'* ]] || fail 'missing-key diagnostic was unclear'
  [[ "$output" == *'restart the web service'* ]] || fail 'missing-key diagnostic omitted restart action'
  [[ "$output" != *'sentinel-secret'* ]] || fail 'missing-key diagnostic leaked a key'

  : > "${config_dir}/.encryption_key"
  if output="$(cron_load_app_encryption_key "$config_dir" 1 0 2>&1)"; then
    fail 'empty shared key unexpectedly succeeded'
  fi
  [[ "$output" == *'unavailable or empty'* ]] || fail 'empty-key diagnostic was unclear'
  [[ "$output" != *'sentinel-secret'* ]] || fail 'empty-key diagnostic leaked a key'

  local symlink_dir="${TEST_DIR}/symlink" hidden_key="${TEST_DIR}/sentinel-key"
  mkdir -p "$symlink_dir"
  printf '%s\n' 'sentinel-secret' > "$hidden_key"
  ln -s "$hidden_key" "${symlink_dir}/.encryption_key"
  if output="$(cron_load_app_encryption_key "$symlink_dir" 1 0 2>&1)"; then
    fail 'symlinked shared key unexpectedly succeeded'
  fi
  [[ "$output" != *'sentinel-secret'* ]] || fail 'symlink-key diagnostic leaked a key'
}

explicit_key_takes_precedence
existing_shared_key_is_loaded
delayed_shared_key_is_loaded
missing_or_empty_key_fails_without_leaking_a_key
echo 'PASS: cron encryption-key resolution'
