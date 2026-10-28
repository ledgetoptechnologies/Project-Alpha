# Recoverable client archive with protected contact identity

## Failure and fix

Physical client archive could fail with foreign-key error 1451 on
`fk_portal_contact_identity_client`. Migration 0109 deliberately protects the
contact/login policy with `ON DELETE RESTRICT`; the archive service had not
preserved that newer dependency before deleting the live client. The existing
transaction rolls back the failed attempt, so a failure is not successful cleanup.

`ClientArchivePortalStateService` now snapshots that exact policy into the
existing `archived_entities` store inside the archive transaction, keyed by
archive row ID and original client ID. It then removes the live policy as part
of the supported lifecycle. The restrictive FK remains in place. There is no
schema migration, direct live database repair, or deletion of integration mappings.

Restore reinstates the same policy before provisioning hooks can infer identity
from an edited contact email. It preserves the saved principal/login, application,
history epoch, command ID, request hash and creation time. If the original numeric
client ID has been reused, only the restored policy's client FK is remapped to
the restored client's new numeric ID; the saved public ID is retained. The
unrelated occupant is untouched.

A protected NONE policy stays NONE. A protected existing login is recovered only
under the archive service's existing principal version, manual-state and
entitlement fences, using the frozen login rather than the contact email.
Intervening revocation or mismatched identity fails closed. Missing policy schema,
malformed or ambiguous snapshots reject restore and retain the archive through
rollback. No new portal identity or entitlement is created by this fix.

Independent review of the first patch (`ac75fd5f`) found that a missing snapshot
could be mistaken for a legacy archive. The successor records policy presence
independently in the archive row: `portal_identity_binding_ids_json` now holds
`{"version":2,"binding_ids":[],"contact_policy_expected":true}` (or `false`
when no policy existed). A required snapshot or its table being missing rejects
restore before provisioning, as does a malformed envelope. Existing bare ID
lists remain readable for legitimate legacy archives. No schema migration is
needed; this format must ship with its updated reader. The initial patch was
not deployed and must not be released independently.

## Validation

- Focused archive/restore suite: 23 tests, 294 assertions, all passing, including
  real SQLite restrictive FKs, protected NONE, frozen bound login, version
  revocation, archive/restore rollback, numeric ID collision, and missing,
  malformed or duplicate snapshots, required snapshot loss, malformed state
  envelopes and nested JSON objects, and legacy list compatibility.
- Full PHP suite: 1,514 tests, 12,197 assertions, no failures/errors. One existing
  oversized-float warning, seven existing deprecations and 67 skips remain.
- Disposable MySQL HTTP regression: 1 test, 32 assertions, all passing. Use
  `scripts/run-client-archive-policy-http-mysql.mjs`. It creates a random fresh
  database/container, applies the baseline plus 115 migrations, proves that a
  direct client delete is blocked by the real FK, then exercises the actual
  authenticated archive and restore controllers. It verifies the archived policy,
  source directory tombstone/restoration, stable public ID, numeric ID collision
  and continued protected NONE without creating a portal principal. Removing the
  exact required snapshot and posting restore must refuse and retain the archive,
  leave directory state tombstoned, and leave principal, binding and entitlement
  rows unchanged.

```powershell
$env:ARCHIVE_HTTP_TEST_IMAGE = 'your-local-pa-test-image'
node scripts/run-client-archive-policy-http-mysql.mjs
```

The runner requires a trusted local test image with the same Composer lock and
uses current checkout source. It never accepts an existing production DSN.

## Staging and production cleanup handoff

This hotfix is independent of the organization-billing branch and its migration
0116. Use the exact commit communicated with the handoff; apply through the normal
reviewed PA image/release workflow. Do not hot-edit production source, drop the
FK, purge the client, delete its policy manually, or suppress the card by name.

On staging, create synthetic protected NONE and bound-login contacts. Archive and
restore through the actual UI. Verify directory present/tombstone state, portal
eligibility and workspace events, and the receiver's exact source-qualified card.
Restore must not regrant authority revoked after archive. Exercise a reused local
integer ID and prove the unrelated client's profile and policy are unchanged.
Repeat with both PA sources, keeping their identities and ledgers distinct.
On isolated staging only, remove an exact policy snapshot after archive, then
attempt restore through the canonical HTTP/UI path. Verify refusal and rollback
without principal or entitlement changes. Never run this fault injection in
production. Also verify legitimate legacy archives without policies still restore.

The owner explicitly authorized recoverable cleanup of the two previously
identified production acceptance fixtures. Their exact source/public identities
are in the private PA-Ops coordination, not this public repository. There was one
failed, rolled-back LTDS archive before this fix. Do not count it as cleanup or
repeat an uncertain request. The integration owner released the fixtures from
acceptance use and committed not to re-create or re-grant them.

After the supported fix is deployed and staging accepted, archive only those
exact fixtures, one source at a time. Record each actual result once, verify the
archived client and retained policy snapshot, then verify directory tombstones,
portal ineligibility and eventual Operations catalog disappearance. Preserve
audit history, source aliases and all unrelated clients. No permanent purge or
new invitation/grant is part of this cleanup. The real newly approved client was
independently verified correct and must remain untouched.
