# Directory inventory reader key

`bin/provision-directory-inventory-read-key.php` is a dedicated local-root
provisioner with exactly two fixed scopes:

- `api.capabilities.read`
- `directory.inventory.read`

It cannot select extra scopes, create applications, change an existing key,
grant project access or issue write scopes. It binds one newly created key to an
**existing** API-v2 application. The original capabilities-only and instance-scoped
snapshot provisioners, their scopes and all endpoint authentication remain
unchanged. This key does not import records, activate source registration, sync
data or expand any machine identity's grants.

## Target and authority

Use the actual API-v2 source instance, history epoch and selected existing
application UUID from independently verified staging metadata. All three must be
canonical lowercase UUIDv4, matching `api_v2_identity_is_valid`; a new request ID
must also be UUIDv4. Do not substitute the separate legacy sync source identity
(which may be UUIDv1), application names or invented identifiers. The tool locks
and compares the current identity and application before any insertion. Root-local
execution with no PHP session is mandatory; no fake admin session is accepted.

The operator owns verification that the installation and identities are the
approved staging resources. UUID comparisons do not independently attest
Cloudflare account ownership or confer production authorization. This pilot's
operational target remains the separately reviewed Stage One installation. The
tool's source contains no operational identities or credentials.

Required existing schema includes migrations 0088, 0089 and 0102, API-v2 identity
and application rows, both directory and project authorization states, `api_keys`
and `system_audit`. MySQL requires the mutated/locked tables to be InnoDB. The tool
never installs migrations, repairs incomplete schema or creates missing states.

## Encryption and compatibility

The key is saved only in an immutable AES-256-GCM encrypted receipt. The
`EncryptedDirectoryInventoryKeyReceipt` wrapper has a distinct authenticated
format (`pa.directory-inventory-key.encrypted`) and transient environment key
`PA_DIRECTORY_INVENTORY_RECEIPT_KEY_B64`. The wrapping key must be canonical base64
of 32 raw bytes, provided through the approved private inherited environment.
Never put its value in arguments, shell history, Docker `-e NAME=VALUE`, files,
images, logs, Git or chat. The operator owns fresh Windows CurrentUser DPAPI
custody and transient transport before apply. No new vault, backup or custody
service is introduced here.

The shared abstract `EncryptedLocalOperatorKeyReceipt` preserves the exact
snapshot format, key environment, canonical plan/path/version AAD and methods from
commit 926493. The snapshot wrapper retains its original service dependency as
well. Existing snapshot receipt bytes are never migrated, rewrapped or replayed
by this tool. Inventory and snapshot receipts cannot authenticate across purposes,
even with the same wrapping key, plan and path or a forged format header. Plaintext
receipts are unsupported and held without overwrite or replacement issuance.

Use an existing approved durable private root-owned directory outside webroots,
mode 0700, and mode-0600 receipt files. Canonical absolute paths and reviewed plans
are authenticated, so retain the same plan, key custody and receipt path for
reconciliation. The existing filesystem rules refuse symlinks, hard links and
unsafe ancestors. Metadata-only journals contain no plaintext credential or
wrapping key. Memory cleanup is best effort and does not claim to wipe every PHP
or environment copy.

## Prepare and apply

Dry run requires no wrapping key, generates no secret and writes no database rows:

```sh
php bin/provision-directory-inventory-read-key.php --dry-run \
  --request-id=<new-request-uuid-v4> \
  --source-instance-id=<verified-api-v2-source-uuid> \
  --history-epoch=<verified-api-v2-history-uuid> \
  --application-id=<verified-existing-application-uuid> \
  --plan-file=/existing-private-location/directory-plan.json \
  --secret-file=/existing-private-location/directory-key.json
```

Review the actual target, exact scopes, private paths, both captured authorization
generations and printed plan SHA-256. Supply the purpose-specific wrapping key
through approved transient transport before apply:

```sh
php bin/provision-directory-inventory-read-key.php --apply \
  --plan-file=/existing-private-location/directory-plan.json \
  --reviewed-plan-sha256=<reviewed-plan-sha256> \
  --confirm-create-directory-inventory-key
```

The plan and wrapping key are validated before secret generation. The encrypted
receipt is exclusively created, fsynced and authenticated from durable bytes
before database work. Source/application locks serialize issuance, including
different receipt paths. In one transaction the tool rechecks identity and both
generations, creates the fixed-scope application-bound key, advances directory
**and project** authorization generations once, and inserts/verifies a mandatory
local-operator audit. It then verifies the stored key's exact scope and binding.
An audit or key verification failure rolls everything back.

Advancing the existing application's generations invalidates stale pending
command fences; it does not change existing key scopes or grant project access.
Coordinate pending commands and their normal reconciliation before operational
apply, and refresh consumers' current generation observations afterward. Other
applications' states and existing key rows remain unchanged.

## Interrupted or ambiguous outcomes

Keep the original plan, encrypted key and journals. Reconcile with the same
wrapping key, plan hash and path:

```sh
php bin/provision-directory-inventory-read-key.php --reconcile \
  --plan-file=/existing-private-location/directory-plan.json \
  --reviewed-plan-sha256=<same-reviewed-plan-sha256>
```

- `already_committed` (exit 0): exact key, binding and audit exist; no writes or
  second generation advance, even if later authorized work advanced generations.
- `not_committed` (exit 3): original grant is absent; no insertion or repair.
  After reviewing current identity and generations, the original apply command
  plus `--resume-prepared` can use the same encrypted material.
- Held (exit 1): changed identity/epoch/application/generations, duplicate request
  with different material, missing or altered audit, revoked or tampered key,
  wrong/missing wrapping key, unsafe path, unsupported receipt or uncertain result
  requires operator review. Nothing is repaired, reactivated or silently replaced.

An existing receipt defaults to read-only reconciliation unless explicit resume
is supplied. A receipt/journal failure after commit may hold the command; database
reconciliation, rather than another request/key, resolves the outcome. Secrets
are never printed, and error output omits SQL diagnostics and stack arguments.

## Tests and limits

The standalone synthetic SQLite/crypto suite needs disposable Linux root PHP with
PDO SQLite and OpenSSL, but no Composer dependencies or network:

```sh
php tests/standalone/directory-inventory-key.php
php tests/standalone/ops-snapshot-read-key.php
```

The MySQL harness requires an already-created disposable
`pa_snapshot_proof_<lowercase-alphanumeric-suffix>` database and explicit
`PA_TEST_DISPOSABLE_DIRECTORY_KEYS=1`. It checks `SELECT DATABASE()` and **drops
and recreates its own synthetic fixture tables**. Never target an installation.
Use `DB_HOST`, optional `DB_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`:

```sh
PA_TEST_DISPOSABLE_DIRECTORY_KEYS=1 php tests/standalone/directory-inventory-key-mysql.php
PA_TEST_DISPOSABLE_SNAPSHOT_KEYS=1 php tests/standalone/ops-snapshot-read-key-mysql.php
```

Tests cover exact scopes/application binding, generation atomicity, existing-key
preservation, identity/epoch/plan substitutions, audit and key mutation rollback,
lost acknowledgements, revoked keys, read-only reconciliation, encrypted explicit
resume, secret-free CLI output and concurrent different-receipt attempts. Crypto
tests prove snapshot-926 compatibility in both directions without modifying fixture
bytes, and same-key/plan/path cross-purpose rejection. Snapshot regression suites
remain unchanged. Fixture schema tests do not prove full installation migration,
actual API acceptance, staging source eligibility or data/pilot readiness; those
remain separate owner acceptance steps.
