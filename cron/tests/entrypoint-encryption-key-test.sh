#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${ROOT_DIR}/cron/entrypoint-encryption-key.sh"

TEST_DIR="$(mktemp -d)"
cleanup() { rm -rf "$TEST_DIR"; }
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
assert_equal() { [ "$1" = "$2" ] || fail "expected '$1' to equal '$2'"; }

explicit_key_is_persisted_atomically_when_absent() {
  local config_dir="${TEST_DIR}/explicit-absent"
  mkdir -p "$config_dir"
  export APP_ENCRYPTION_KEY='explicit-key-is-canonical'
  app_encryption_key_prepare_web "$config_dir" >/dev/null
  assert_equal 'explicit-key-is-canonical' "$(cat "${config_dir}/.encryption_key")"
  assert_equal '600' "$(stat -c '%a' "${config_dir}/.encryption_key")"
  if compgen -G "${config_dir}/.encryption_key.tmp.*" >/dev/null; then
    fail 'atomic persistence left a temporary key file behind'
  fi
}

matching_explicit_key_is_accepted() {
  local config_dir="${TEST_DIR}/explicit-match"
  mkdir -p "$config_dir"
  printf '%s\n' 'matching-explicit-key' > "${config_dir}/.encryption_key"
  chmod 600 "${config_dir}/.encryption_key"
  export APP_ENCRYPTION_KEY='matching-explicit-key'
  app_encryption_key_prepare_web "$config_dir" >/dev/null
  cron_load_app_encryption_key "$config_dir" 0 0 >/dev/null
  assert_equal 'matching-explicit-key' "$APP_ENCRYPTION_KEY"
}

broad_persisted_key_is_restricted_for_web_and_cron() {
  local config_dir="${TEST_DIR}/permission-repair"
  mkdir -p "$config_dir"
  printf '%s\n' 'repairable-persisted-key' > "${config_dir}/.encryption_key"
  chmod 644 "${config_dir}/.encryption_key"
  if [ "$(stat -c '%a' "${config_dir}/.encryption_key")" = '644' ]; then
    export APP_ENCRYPTION_KEY='repairable-persisted-key'
    app_encryption_key_prepare_web "$config_dir" >/dev/null
    assert_equal '600' "$(stat -c '%a' "${config_dir}/.encryption_key")"

    chmod 640 "${config_dir}/.encryption_key"
    unset APP_ENCRYPTION_KEY
    cron_load_app_encryption_key "$config_dir" 0 0 >/dev/null
    assert_equal 'repairable-persisted-key' "$APP_ENCRYPTION_KEY"
    assert_equal '600' "$(stat -c '%a' "${config_dir}/.encryption_key")"
  fi
}

unrepairable_permissions_fail_closed() {
  local config_dir="${TEST_DIR}/permission-repair-failure" output
  mkdir -p "$config_dir"
  printf '%s\n' 'permission-repair-sentinel-secret' > "${config_dir}/.encryption_key"
  chmod 644 "${config_dir}/.encryption_key"
  if [ "$(stat -c '%a' "${config_dir}/.encryption_key")" = '644' ]; then
    unset APP_ENCRYPTION_KEY
    if output="$(
      chmod() { return 1; }
      cron_load_app_encryption_key "$config_dir" 0 0 2>&1
    )"; then
      fail 'cron accepted a broad key when chmod could not repair it'
    fi
    [[ "$output" == *'could not be restricted'* ]] || fail 'chmod failure diagnostic was unclear'
    [[ "$output" != *'permission-repair-sentinel-secret'* ]] || fail 'chmod failure leaked the key'
  fi
}

ineffective_permissions_repair_fails_closed() {
  local config_dir="${TEST_DIR}/permission-repair-ineffective" output
  mkdir -p "$config_dir"
  printf '%s\n' 'ineffective-repair-sentinel-secret' > "${config_dir}/.encryption_key"
  chmod 644 "${config_dir}/.encryption_key"
  if [ "$(stat -c '%a' "${config_dir}/.encryption_key")" = '644' ]; then
    unset APP_ENCRYPTION_KEY
    if output="$(
      chmod() { return 0; }
      cron_load_app_encryption_key "$config_dir" 0 0 2>&1
    )"; then
      fail 'cron accepted a broad key when chmod reported success without changing it'
    fi
    [[ "$output" == *'unsafe permissions'* ]] || fail 'ineffective chmod diagnostic was unclear'
    [[ "$output" != *'ineffective-repair-sentinel-secret'* ]] || fail 'ineffective chmod leaked the key'
  fi
}

mismatched_explicit_key_fails_without_leaking_either_key() {
  local config_dir="${TEST_DIR}/explicit-mismatch" output
  mkdir -p "$config_dir"
  printf '%s\n' 'persisted-sentinel-secret' > "${config_dir}/.encryption_key"
  chmod 600 "${config_dir}/.encryption_key"
  export APP_ENCRYPTION_KEY='explicit-sentinel-secret'
  if output="$(app_encryption_key_prepare_web "$config_dir" 2>&1)"; then
    fail 'web accepted a conflicting explicit key'
  fi
  [[ "$output" == *'does not match'* ]] || fail 'web mismatch diagnostic was unclear'
  [[ "$output" != *'persisted-sentinel-secret'* ]] || fail 'web mismatch leaked the persisted key'
  [[ "$output" != *'explicit-sentinel-secret'* ]] || fail 'web mismatch leaked the explicit key'
  if output="$(cron_load_app_encryption_key "$config_dir" 0 0 2>&1)"; then
    fail 'cron accepted a conflicting explicit key'
  fi
  [[ "$output" == *'does not match'* ]] || fail 'cron mismatch diagnostic was unclear'
  [[ "$output" != *'sentinel-secret'* ]] || fail 'cron mismatch leaked a key'
}

existing_shared_key_is_loaded() {
  local config_dir="${TEST_DIR}/existing"
  mkdir -p "$config_dir"
  printf '%s\n' 'persisted-shared-key' > "${config_dir}/.encryption_key"
  chmod 600 "${config_dir}/.encryption_key"
  unset APP_ENCRYPTION_KEY
  cron_load_app_encryption_key "$config_dir" 0 0 >/dev/null
  assert_equal 'persisted-shared-key' "$APP_ENCRYPTION_KEY"
}

delayed_shared_key_is_loaded() {
  local config_dir="${TEST_DIR}/delayed"
  mkdir -p "$config_dir"
  unset APP_ENCRYPTION_KEY
  (sleep .1; umask 077; printf '%s\n' 'delayed-shared-key' > "${config_dir}/.encryption_key") &
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
  chmod 600 "${config_dir}/.encryption_key"
  if output="$(cron_load_app_encryption_key "$config_dir" 1 0 2>&1)"; then
    fail 'empty shared key unexpectedly succeeded'
  fi
  [[ "$output" == *'empty or unreadable'* ]] || fail 'empty-key diagnostic was unclear'
  [[ "$output" != *'sentinel-secret'* ]] || fail 'empty-key diagnostic leaked a key'

  local symlink_dir="${TEST_DIR}/symlink" hidden_key="${TEST_DIR}/sentinel-key"
  mkdir -p "$symlink_dir"
  printf '%s\n' 'sentinel-secret' > "$hidden_key"
  chmod 644 "$hidden_key"
  ln -s "$hidden_key" "${symlink_dir}/.encryption_key"
  # Git for Windows may emulate a symlink by copying when developer mode is
  # unavailable. Linux CI exercises the real symlink rejection path.
  if [ -L "${symlink_dir}/.encryption_key" ]; then
    if output="$(app_encryption_key_prepare_web "$symlink_dir" 2>&1)"; then
      fail 'web accepted a symlinked shared key'
    fi
    assert_equal '644' "$(stat -c '%a' "$hidden_key")"
    if output="$(cron_load_app_encryption_key "$symlink_dir" 1 0 2>&1)"; then
      fail 'symlinked shared key unexpectedly succeeded'
    fi
    [[ "$output" != *'sentinel-secret'* ]] || fail 'symlink-key diagnostic leaked a key'
  fi

  local permissions_dir="${TEST_DIR}/permissions"
  mkdir -p "$permissions_dir"
  printf '%s\n' 'permissions-sentinel-secret' > "${permissions_dir}/.encryption_key"
  chmod 644 "${permissions_dir}/.encryption_key"
  # Git for Windows does not expose POSIX chmod bits; Linux CI does.
  if [ "$(stat -c '%a' "${permissions_dir}/.encryption_key")" = '644' ]; then
    cron_load_app_encryption_key "$permissions_dir" 0 0 >/dev/null
    assert_equal 'permissions-sentinel-secret' "$APP_ENCRYPTION_KEY"
    assert_equal '600' "$(stat -c '%a' "${permissions_dir}/.encryption_key")"
  fi
}

generated_and_persisted_key_is_reused() {
  local config_dir="${TEST_DIR}/generated" generated
  mkdir -p "$config_dir"
  unset APP_ENCRYPTION_KEY
  app_encryption_key_prepare_web "$config_dir" >/dev/null
  generated="$APP_ENCRYPTION_KEY"
  [ -n "$generated" ] || fail 'web did not generate an application key'
  unset APP_ENCRYPTION_KEY
  app_encryption_key_prepare_web "$config_dir" >/dev/null
  assert_equal "$generated" "$APP_ENCRYPTION_KEY"
}

web_and_cron_share_one_volume_contract() {
  local config_dir="${TEST_DIR}/shared-volume"
  mkdir -p "$config_dir"
  export APP_ENCRYPTION_KEY='shared-volume-sentinel'
  app_encryption_key_prepare_web "$config_dir" >/dev/null
  unset APP_ENCRYPTION_KEY
  cron_load_app_encryption_key "$config_dir" 0 0 >/dev/null
  assert_equal 'shared-volume-sentinel' "$APP_ENCRYPTION_KEY"
}

explicit_key_is_persisted_atomically_when_absent
matching_explicit_key_is_accepted
broad_persisted_key_is_restricted_for_web_and_cron
unrepairable_permissions_fail_closed
ineffective_permissions_repair_fails_closed
mismatched_explicit_key_fails_without_leaking_either_key
existing_shared_key_is_loaded
delayed_shared_key_is_loaded
missing_or_empty_key_fails_without_leaking_a_key
generated_and_persisted_key_is_reused
web_and_cron_share_one_volume_contract
echo 'PASS: cron encryption-key resolution'
