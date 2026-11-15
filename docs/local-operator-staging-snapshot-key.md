# Staging legacy snapshot-read key

`bin/provision-ops-snapshot-read-key.php` is a dedicated local-root operator for
the existing `ops.sync.read` API scope. It is separate from, and does not widen,
the capabilities-key provisioner. It creates no application, modifies no existing
key, and changes no API-v2 binding or authorization generation. Its mandatory audit
records `local_operator`/UID 0, with no impersonated human user.

The credential is **instance-scoped**. Existing snapshot authentication checks
the key's hash, revocation, allowed IPs and scope. It does not enforce a binding to
an API-v2 application. The same scope can authorize **both** the legacy snapshot
and the API-v2 snapshot endpoint when those endpoints are enabled. Null API-v2
binding does not restrict this credential to the legacy URL. Snapshot entitlements use the installation's configured
`external_ops_application_key`. This tool creates an unbound key, as the existing
admin-web key-creation workflow does. The application check below is an issuance
guard, not a new endpoint-level authorization guarantee. Keep this credential in
the server's approved secret keeper; never embed it in an iPad build.

## Independently verify the staging target

The operator must independently verify the actual installation, source ownership
and intended staging account before preparing a plan. Use the **legacy**
`sync_source_identity.source_instance_id`, not the separate API-v2 history source
or application UUID. The tool compares that existing identity and the configured
Ops application key, locks them during issuance, and binds the reviewed public
staging origin to the plan/audit. Only the two established Stage One/Stage Two
origins are accepted. A supplied origin is an operator assertion; it does not
attest that a local PDO connection belongs to that hostname or Cloudflare account.
Do not supply production identities or run this operator against production.

The existing `api_keys`, `system_audit`, `sync_source_identity` and `app_config`
schema must already be present. MySQL requires all four tables to use InnoDB.
The tool never migrates or repairs schema. It reuses the installed
`LocalOperatorKeyReceipt` and `ApiV2CapabilitiesKeyProvisioner::operator()` for
the existing root-CLI/private-path contract, without invoking capabilities-key
issuance. Their existing utility dependencies must be available in the reviewed
installation. No new environment marker, backup or vault prerequisite is added.

Choose the destination's existing approved durable private receipt location:
root-owned, parent mode 0700, files mode 0600, outside every webroot, with no
symlink/hard-link or writable-ancestor ambiguity. Do not use ephemeral container
storage for an operational credential. A disposable `/tmp` location is used only
by synthetic tests. No secret is printed to stdout, stderr or the audit.

## Dry run and reviewed apply

```sh
php bin/provision-ops-snapshot-read-key.php --dry-run \
  --request-id=<new-request-uuid-v4> \
  --source-instance-id=<verified-legacy-source-uuid> \
  --application-key=<verified-configured-ops-application-key> \
  --staging-origin=https://pa-stage-one.ledgetoptechnologies.com \
  --plan-file=/existing-private-location/snapshot-plan.json \
  --secret-file=/existing-private-location/snapshot-key.json
```

Dry run generates no key and writes no database rows. It exclusively creates a
private plan containing the expected identity, instance-scoped contract, fixed
scope, request hash and a fingerprint of the public app-key/enabled/webhook
configuration. No encrypted delivery credential or API-key hash is read into the
plan. Review the actual target, scope, private paths and printed plan SHA-256.

```sh
php bin/provision-ops-snapshot-read-key.php --apply \
  --plan-file=/existing-private-location/snapshot-plan.json \
  --reviewed-plan-sha256=<reviewed-sha256> \
  --confirm-create-staging-snapshot-key
```

After metadata validation, a new immutable secret receipt is exclusively created,
flushed and fsynced before any database mutation. An attempt journal is also
durably created. A single database transaction locks the legacy source and public
configuration rows, rechecks the reviewed configuration, inserts the fixed-scope
unbound key and verifies its exact audit and stored scope/binding. Audit failure
or trigger-induced scope changes roll back the key. Existing keys, staff/grants,
business records, source registration, event configuration and generations remain
unchanged. The tool does not fetch a snapshot, perform sync or deploy anything.

## Reconcile interruption or lost acknowledgement

Preserve the original private plan, key and journals after any ambiguous result.
Do not delete them or generate a new request to work around a failed response.

```sh
php bin/provision-ops-snapshot-read-key.php --reconcile \
  --plan-file=/existing-private-location/snapshot-plan.json \
  --reviewed-plan-sha256=<same-reviewed-sha256>
```

- `already_committed` (exit 0) means the exact original key and mandatory audit
  are present. Reconciliation makes no database changes. Later enabled/webhook
  changes do not create another key; changed source/application identity is held.
- `not_committed` (exit 3) means that original key/audit is absent. After reviewing
  the target and original plan again, use the original apply command plus
  `--resume-prepared` to use the same secret. Without this flag, an existing
  receipt only reconciles and never inserts again.
- Held (exit 1) means review is required: mismatched plan, source, app/config,
  duplicate request, missing/altered audit, changed scope/binding, revoked key,
  unsafe path, schema problem or uncertain outcome. No repair, replacement or
  reactivation is attempted. A journal failure after commit may also produce a
  held result, which is why reconciliation uses database evidence.

Source-row locking serializes requests on MySQL even when their receipt paths
differ. The deterministic request name and audit request UUID detect replay with
different material. The local receipt lock rejects overlapping use of one file.
Plaintext is available only from the original private receipt for the approved
destination's secret mechanism; do not paste it into chat, logs or source control.

## Validation

Run the standalone synthetic suite in a disposable Linux-root PHP CLI runtime
with PDO SQLite. It requires no Composer/vendor dependencies or network:

```sh
php tests/standalone/ops-snapshot-read-key.php
```

It covers legacy/v2 identity distinction, fixed scope/null binding, unchanged
existing keys/generations, source/app/config/plan substitution, revoked and
tampered keys, audit failure, trigger-induced scope mutation, duplicate requests,
lost commit acknowledgement, read-only reconciliation, private immutable receipts
and path/locking refusal. Synthetic files are created only under its private test
directory and removed at test completion.

The additional standalone MySQL harness requires an already-created disposable
database named `pa_snapshot_proof_<lowercase-alphanumeric-suffix>` and explicit
`PA_TEST_DISPOSABLE_SNAPSHOT_KEYS=1`. It verifies `SELECT DATABASE()` and **drops
and recreates its synthetic fixture tables**. Never point it at an installation.
It uses `DB_HOST`, optional `DB_PORT`, `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_PASSWORD`, matching the CLI's existing migration connection loader.

```sh
PA_TEST_DISPOSABLE_SNAPSHOT_KEYS=1 php tests/standalone/ops-snapshot-read-key-mysql.php
```

This exercises MySQL JSON/audit and transaction behavior, lost commit
acknowledgement, nontransactional-table refusal, complete CLI dry-run/reviewed
apply/replay, prepared-receipt reconciliation/resume, secret-free output and
concurrent independent CLI processes with different receipt paths for one request.
It tests a minimal realistic key/audit/source/config schema, not a full installation
migration or actual API authentication. Operator target verification, current
installed dependency compatibility and owner staging acceptance remain necessary
before the operational credential is used.
