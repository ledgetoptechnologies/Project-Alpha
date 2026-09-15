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

Migration `0088_api_v2_application_identity.sql` adds the stable API v2 history
epoch, generic application identities, and nullable key-to-application binding.
It grants no existing key access. An operator must create an application row
with a generated UUID and explicitly bind a scoped key carrying
`api.capabilities.read` and only the other capabilities that application
actually needs to its numeric `api_v2_applications.id`. The capabilities
probe and subsequent resource calls use the same key. Do not attach the
scope to legacy `full` keys. For example, after creating a scoped key
through the existing administrator API-key screen, use a reviewed database
change to insert `api_v2_applications(application_id, name)` with a freshly
generated lowercase UUID-v4 from a cryptographic random generator
and update that specific `api_keys.id` to reference the new application row.
The key must remain unrevoked. By default `/api/v2/capabilities` reports only
itself; optional directory and binding-status reads are advertised only when
their installation flags are enabled, and are granted only to keys with their
explicit scopes. It does not claim snapshot or change-feed support. Its
`sourceInstanceId` and `historyEpoch` are independent, persisted UUID-v4
values. The history epoch changes only with an explicit operator history reset.
MySQL `UUID()` alone is version 1 and will fail API v2 preflight validation.

Migration `0089_api_v2_directory_revision_foundation.sql` adds
application-independent directory revision state and per-resource change rows,
plus an application-specific authorization generation. Covered client and
organization create/update, onboarding, import, relationship, archive, restore,
purge and organization-delete writers record changes in their transactions,
suppressing unchanged profile hashes. The migration does not backfill existing
resources or initialize or advance authorization generations. The optional
directory reads remain disabled by default and fail closed when revision state
or authorization generation is absent. The legacy Sync Contract v2 source identity is
not used; its UUID-v1 value is incompatible with the new v2 handshake. Before
enabling a directory read, cover every client, organization, address,
relationship, deletion/restoration, and access-grant mutation in the same
transaction as its revision/change and authorization-generation updates.
Prove concurrency, rollback, and exact application binding with real MySQL
tests. Do not infer a globally commit-ordered feed from auto-increment IDs.

Migration `0090_api_v2_directory_binding_status_foundation.sql` adds exact,
application-scoped external-ID bindings to client or organization public IDs.
It creates no binding and performs no backfill. The optional status routes
remain disabled by default via `APP_API_V2_BINDING_STATUS_ENABLED`; the
directory reads use `APP_API_V2_DIRECTORY_READ_ENABLED`.

Migration `0091_api_v2_directory_binding_command_receipts.sql` adds durable,
application-scoped idempotency receipts for binding an existing resource.
The optional POST commands use `APP_API_V2_DIRECTORY_BINDING_ENABLED` and
require explicit, separate `directory.clients.bind` or
`directory.organizations.bind` grants. A successful new binding advances the
application authorization generation in the same transaction. These tables
and handlers do not initialize applications, keys, authorization generations,
or old-resource revision state; no flag should be enabled in production until
complete backfill, all writer/lifecycle coverage, current-profile verification,
and real-MySQL concurrency and rollback acceptance are demonstrated.

Migration `0092_api_v2_directory_binding_revision_refresh_receipts.sql` adds
durable receipts for advancing the revision of an existing client or
organization binding without changing its public ID or external ID. The
refresh POST is independently disabled by default through
`APP_API_V2_DIRECTORY_BINDING_REFRESH_ENABLED` and requires an explicit
`directory.clients.binding.revision.refresh` or
`directory.organizations.binding.revision.refresh` scope. It is not a
replacement for a fresh status read, and real-MySQL concurrency and replay
tests remain required before enabling it.

Migration `0093_api_v2_directory_organization_profile_command_receipts.sql`
adds application-scoped idempotency receipts for conditional organization
profile updates. The generic command route is independently disabled by
default through `APP_API_V2_DIRECTORY_ORGANIZATIONS_WRITE_ENABLED` and
requires the same bound policy-v2 key to hold both `api.capabilities.read` and
the explicit `directory.organizations.write` scope; legacy `full` access does
not inherit it. Commands preserve private notes and address metadata, use the
shared browser/API organization mutation transaction, reject stale resource or
authorization generations, record no-op success without revision churn, and
recover the immutable first result on exact retry. Do not enable the route
until the existing-row backfill, complete writer inventory, real-MySQL
concurrency/rollback checks, and external-management handoff are accepted.

Migration `0094_api_v2_directory_client_profile_command_receipts.sql` adds
application-scoped idempotency receipts for conditional client profile
updates. The generic command route is independently disabled by default
through `APP_API_V2_DIRECTORY_CLIENTS_WRITE_ENABLED` and requires the same
bound API v2 application key to hold both `api.capabilities.read` and the
explicit `directory.clients.write` scope; legacy `full` access does not
inherit it. Commands change only shared client-profile fields, preserve
organization membership, portal access, billing identifiers, notes, and
address-provider metadata, use the shared browser/API transaction, reject
stale resource or authorization generations, record no-op success without
revision churn, and recover the immutable first result on exact retry. Do not
enable the route until the existing-row backfill, complete writer inventory,
real-MySQL concurrency/rollback checks, and external-management handoff are
accepted.

For an operator-approved dedicated API key, the generic provisioning CLI can
bind its numeric key ID to a new API v2 application and initialize that
application's authorization generation at zero. It requires migrations 0088
and 0089, a valid persisted source/history identity, an active explicitly
scoped key, and an exact dry-run before apply. The key secret is never an
argument or output. Take a database backup and review the selected key first:

```bash
php bin/provision-api-v2-application.php --api-key-id=123 --name='External application' --dry-run
php bin/provision-api-v2-application.php --api-key-id=123 --name='External application' --apply --confirm-bind-api-v2-application
```

The same-key/same-name rerun is a no-op. This command does **not** backfill
directory revisions, authorize portal access, or enable any endpoint. Use it
separately for each installation; never copy an application's identity or
secret between installations.

Existing directory resources require a separate, maintenance-window backfill
before any directory route can be enabled. The local-only bounded tool and its
conflict/coverage requirements are documented in
[`docs/admin/api-v2-directory-backfill.md`](../../docs/admin/api-v2-directory-backfill.md).

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

Migration `0087_portal_projection_recovery.sql` records bounded, audited
replacement-snapshot recovery for terminal workspace projection deliveries.
Failed payloads remain immutable; a recovery becomes complete only after the
receiver acknowledges the replacement generation's activation record.
