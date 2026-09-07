# Project Alpha Database Migrations

Project Alpha 0.5.0 starts from the immutable `database/baseline.sql` schema. The baseline inserts version `0` into `schema_migrations`; this directory contains only later, forward-only changes.

`0001_schema_compatibility_for_dev_release.sql` backfills schema pieces that were added to the 0.5.0 dev baseline after early migration-test databases had already been reset. It is safe for current fresh installs and repairs existing baseline-ledger databases before the web image serves newer dev code.

## File Contract

- Name files `0001_description.sql`, `0002_description.sql`, and so on.
- Versions must begin at `0001` and remain contiguous and unique.
- Never edit or remove a migration after it ships. Stored SHA-256 checksums are enforced; CRLF/LF-only differences are tolerated so Windows and Linux builds validate the same SQL content.
- Rollback files, `DELIMITER` blocks, gaps, malformed names, and empty files are rejected.
- Do not copy historical pre-0.5.0 SQL back into this directory.
- Before `0.5.0` ships, fold schema work into `database/baseline.sql`; after it ships, add the next immutable migration instead.

## Execution Contract

The one-shot Compose `migrate` service:

1. Refuses to modify a non-empty database without the 0.5.0 baseline marker.
2. Loads `database/baseline.sql` only into an empty database.
3. Validates the migration sequence and applied checksums. An empty post-baseline migration directory is valid.
4. Requires a successful compressed backup before applying pending post-baseline migrations.
5. Applies pending migrations, if any, and validates critical schema invariants.
6. Leaves administrator creation to the web first-time setup when the users table is empty.

Web and cron depend on successful completion of this service.

## Validation

```bash
php src/migrations/run_migrations.php --validate-files

docker compose run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

Fix forward after a migration ships. MySQL DDL can auto-commit, so a failed change may require restoring the required pre-migration backup before deploying a corrected migration.

Migration `0068_portal_contract_completeness.sql` is additive and leaves every
portal capability disabled. It adds durable command correlation/outcomes,
incremental projection checkpoints, per-scope manager recovery state, and
optional private pricing-range policy fields. It also permits the distinct
`viewer.share.create` entitlement value without creating or enabling any grant.

Migration `0079_client_portal_provisioning.sql` adds durable organization-root
and standalone-client portal access controls plus fail-closed per-client login
eligibility. It does not create identity-provider bindings or delivery grants;
the post-migration reconciliation publishes only explicit invitation intent.

Migration `0080_portal_service_assignment_projection.sql` adds the explicit,
default-off service-assignment producer to the same External Operations profile,
signed delivery credentials, and durable projection outbox. It does not infer
assignments from catalog visibility, billing records, portal eligibility, or
workspace membership. Migration `0081_portal_service_assignment_management_permission.sql`
adds the separate staff permission used to administer those assignments.

Migration `0082_portal_contact_assignment_projection.sql` adds the schema-v4
contact-assignment producer capability to the same External Operations profile.
It defaults off, requires both portal and relation projection, and publishes
only explicit department-contact and project-client assignments. It does not
create identities, login eligibility, memberships, entitlements, delivery
grants, billing recipients, or notification recipients.

Migration `0083_portal_client_provisioning_backfill.sql` adds durable,
profile-scoped progress for the automatic historical client-portal
reconciliation. The cron job remains inert until the unified portal producer
preflight is ready, processes a bounded batch, retries transient failures, and
does not send invitations or override administrator revocations.

Migration `0084_unify_managed_delivery_external_ops.sql` routes new managed
delivery intents through the one existing External Operations connection and
removes the obsolete URL/profile settings. Existing unresolved rows are paused
with an explicit manual-remediation status so the retired direct sender cannot
run. Historical accepted receipts remain intact, but legacy receipts and
revocations are never rebound to the External Operations contract; an
administrator must resolve them in the original delivery system.

Migration `0085_stable_client_archive_identity.sql` preserves a client's stable
public ID, client classification, portal principal reference, explicit
portal-login choice, and the exact active identity bindings in the legacy
archive table. Historical archive rows remain
compatible; newly archived clients restore into the same Operations/portal
identity instead of creating a second source record or an orphan-principal
conflict. A principal authorization-version fence prevents restore from
undoing a security change made while the client was archived, and shared
principals retain access only for their other active client associations.

Migration `0086_external_operations_ed25519_signing.sql` marks version 2 of
the encrypted External Operations credential envelope. It preserves HMAC-SHA256
delivery unchanged and does not enable Ed25519, create another receiver, or
store private key material outside the encrypted credentials value. Ed25519 is
staged and explicitly activated by an administrator only after the existing
receiver has registered the displayed public key.
