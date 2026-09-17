---
layout: default
title: API v2 cutover and rollback evidence template
---

# API v2 cutover and rollback evidence template

Use one private, instance-specific copy of this template for a proposed API v2
cutover. It is an evidence record and release gate, not an instruction to enable
an endpoint or deploy a configuration. Keep all API v2 flags `false` until the
responsible operators approve the completed record. Do not put credentials,
request bodies containing personal data, external IDs, public IDs, hostnames,
or key material in this template or in source control.

This contract is generic. Each instance has its own source-instance identity,
history epoch, application identity, database evidence, and dedicated API key.
Never reuse any of those values across instances. A legacy `full` key never
authorizes an API v2 route.

## Record identity and approval

| Field | Evidence to retain outside the repository |
| --- | --- |
| Instance label | `[non-secret installation label]` |
| Change/review reference | `[approved change reference]` |
| Maintenance window | `[start, end, and time zone]` |
| Accountable operator and reviewer | `[roles or approved identities]` |
| Application identity verification | `[redacted verification that source-instance ID, application ID, and history epoch match the instance]` |
| Dedicated key verification | `[redacted key record: one active, application-bound, non-full key]` |
| Decision | `[not started / accepted / aborted / rolled back]` |

Record immutable checksums, aggregate counts, command exit status, and approved
test references. Record secrets only in the instance's approved secret store;
neither a key value nor an authorization header is evidence for this document.

## Required migration evidence

Before a combined directory and Project cutover, retain the migration runner's
dry-run and applied-version evidence for every migration below. A partial slice
is allowed only when its approved route/flag/scope subset is recorded and all
other flags remain false.

| Surface | Required migrations |
| --- | --- |
| Directory identity, revisions, bindings, commands, and management evidence | `0088_api_v2_application_identity.sql` through `0099_api_v2_directory_lifecycle_relationships.sql` |
| Project lifecycle, presentation revocation, and synchronization | `0100_project_lifecycle_api_foundation.sql`, `0101_project_archive_presentation_revocation.sql`, `0102_api_v2_project_synchronization.sql` |

Required evidence:

- [ ] A backup and restore/takeover contact are recorded under the instance's
  normal operational controls.
- [ ] Migration-file validation and a migration dry run completed successfully.
- [ ] The applied migration ledger names every required migration exactly and
  its checksums are accepted by the runner.
- [ ] The instance's API v2 source identity and history epoch passed preflight;
  neither was copied from another instance or silently reset.
- [ ] No flag was changed as part of gathering this evidence.

## Exact route flags and least-privilege scopes

List the exact planned flags and scopes in the approval record. Set every
unlisted flag to `false`; do not use a broad or inherited scope. The following
is the complete current directory/Project API v2 flag inventory.

| Flags | Required scopes when the corresponding route is selected |
| --- | --- |
| `APP_API_V2_DIRECTORY_READ_ENABLED` | `api.capabilities.read` plus `directory.clients.read` and/or `directory.organizations.read` |
| `APP_API_V2_BINDING_STATUS_ENABLED` | `api.capabilities.read` plus `directory.clients.binding_status.read` and/or `directory.organizations.binding_status.read` |
| `APP_API_V2_DIRECTORY_BINDING_ENABLED` | `api.capabilities.read` plus `directory.clients.bind` and/or `directory.organizations.bind` |
| `APP_API_V2_DIRECTORY_BINDING_REFRESH_ENABLED` | `api.capabilities.read` plus `directory.clients.binding.revision.refresh` and/or `directory.organizations.binding.revision.refresh` |
| `APP_API_V2_DIRECTORY_ORGANIZATIONS_WRITE_ENABLED` | `api.capabilities.read`, `directory.organizations.write` |
| `APP_API_V2_DIRECTORY_CLIENTS_WRITE_ENABLED` | `api.capabilities.read`, `directory.clients.write` |
| `APP_API_V2_DIRECTORY_ORGANIZATIONS_CREATE_ENABLED` | `api.capabilities.read`, `directory.organizations.create` |
| `APP_API_V2_DIRECTORY_CLIENTS_CREATE_ENABLED` | `api.capabilities.read`, `directory.clients.create`; an organization assignment additionally requires `directory.clients.organization.assign` |
| `APP_API_V2_DIRECTORY_CLIENTS_ARCHIVE_ENABLED` | `api.capabilities.read`, `directory.clients.archive` |
| `APP_API_V2_DIRECTORY_CLIENTS_RESTORE_ENABLED` | `api.capabilities.read`, `directory.clients.restore` |
| `APP_API_V2_DIRECTORY_ORGANIZATIONS_ARCHIVE_ENABLED` | `api.capabilities.read`, `directory.organizations.archive` |
| `APP_API_V2_DIRECTORY_ORGANIZATIONS_RESTORE_ENABLED` | `api.capabilities.read`, `directory.organizations.restore` |
| `APP_API_V2_DIRECTORY_RELATIONSHIPS_WRITE_ENABLED` | `api.capabilities.read` plus the exact one of `directory.clients.organization.assign`, `directory.clients.organization.remove`, or `directory.clients.organization.move` |
| `APP_API_V2_DIRECTORY_BINDING_REVOKE_ENABLED` | `api.capabilities.read` plus `directory.clients.unbind` and/or `directory.organizations.unbind` |
| `APP_API_V2_DIRECTORY_INVENTORY_ENABLED` | `api.capabilities.read`, `directory.inventory.read` |
| `APP_API_V2_PROJECTS_READ_ENABLED` | `api.capabilities.read`, `projects.v2.read` |
| `APP_API_V2_PROJECTS_COMPLETE_ENABLED` | `api.capabilities.read`, `projects.lifecycle.complete` |
| `APP_API_V2_PROJECTS_CANCEL_ENABLED` | `api.capabilities.read`, `projects.lifecycle.cancel` |
| `APP_API_V2_PROJECTS_ARCHIVE_ENABLED` | `api.capabilities.read`, `projects.lifecycle.archive` |
| `APP_API_V2_PROJECTS_RESTORE_ENABLED` | `api.capabilities.read`, `projects.lifecycle.restore` |
| `APP_API_V2_PROJECTS_CREATE_ENABLED` | `api.capabilities.read`, `projects.create` |
| `APP_API_V2_PROJECTS_WRITE_ENABLED` | `api.capabilities.read`, `projects.write` |
| `APP_API_V2_PROJECTS_BINDING_ENABLED` | `api.capabilities.read`, `projects.bind` |
| `APP_API_V2_PROJECTS_BINDING_REFRESH_ENABLED` | `api.capabilities.read`, `projects.binding.revision.refresh` |
| `APP_API_V2_PROJECTS_BINDING_STATUS_ENABLED` | `api.capabilities.read`, `projects.binding_status.read` |
| `APP_API_V2_PROJECTS_INVENTORY_ENABLED` | `api.capabilities.read`, `projects.inventory.read` |

For the planned subset, retain:

- [ ] An exact `flag=false/true` review list signed by the approver. It starts
  with all flags false and changes only the approved subset through the
  instance's normal configuration process.
- [ ] A scope-to-route matrix showing that each key has only the listed scopes,
  is bound to the intended application, and is not a `full` key.
- [ ] A negative-scope result for each adjacent unapproved route.
- [ ] A capability response captured without sensitive values, confirming only
  the planned features are advertised.

## Backfill and attestation

Directory routes require a complete, reviewed directory backfill. Run bounded
dry runs, retain only aggregate output and local resume cursors, then apply in
the approved maintenance window with ordinary source mutations paused:

```text
php bin/backfill-api-v2-directory.php --type=all --limit=100 --dry-run
php bin/backfill-api-v2-directory.php --type=all --limit=100 --apply --confirm-api-v2-directory-backfill --maintenance-window-confirmed
php bin/backfill-api-v2-directory.php --type=all --limit=100 --dry-run --attest
```

Continue from the returned cursor until exhausted. Retain the coverage audit:
every valid existing client and organization has present directory state and a
matching `upsert` change; every refusal is resolved or the cutover is aborted.
Retain the safe `Attestation: <64 lowercase hex digest>.` line from the final
staging command as the directory backfill receipt. The command refuses to
persist an incomplete or drifted state; a changed source or schema state
requires a fresh command and review. Retain the directory-management release
attestation digest generated by the approved administration workflow, where
directory management is in scope.

Project routes require the same bounded, dry-run-first approach and a current
attestation immediately before the approved route is enabled:

```text
php bin/backfill-api-v2-projects.php --limit=100 --dry-run
php bin/backfill-api-v2-projects.php --limit=100 --apply --confirm-api-v2-project-backfill --maintenance-window-confirmed
php bin/backfill-api-v2-projects.php --limit=100 --dry-run --attest
php bin/check-api-v2-project-release.php --attestation-sha256=<approved-digest>
```

Continue with each returned cursor until none remains. The digest is evidence,
not a secret; it must be current for the same instance and code/schema state.

- [ ] Directory backfill coverage, lifecycle/relationship writer inventory, and
  any directory-management attestation are accepted.
- [ ] Project backfill and release attestation are complete and current.
- [ ] Disposable real-MySQL concurrency, replay, stale-state, and rollback
  tests for every selected command family are accepted in addition to normal
  unit/workflow tests.

## API smoke and public-link parity acceptance

Use an approved, non-production test fixture or redacted test record. Capture
route, status class, feature name, command correlation ID, revision/generation
comparison, and test result only. Do not retain response bodies containing
personal or financial data.

- [ ] With all flags false, selected routes return the documented unavailable
  result and capabilities do not advertise them.
- [ ] With the reviewed subset available, the capabilities handshake validates
  source-instance, application, and history-epoch matching; wrong identities,
  stale revisions/generations, missing scopes, and changed idempotency bodies
  fail closed.
- [ ] Each selected read, status, inventory, binding, profile, create,
  lifecycle, relationship, and revoke route has a successful and a
  least-privilege-negative smoke result as applicable.
- [ ] Exact idempotent command replay returns the first outcome; a changed
  body with the same command ID conflicts without changing source state.
- [ ] A sampled Project read/command contains no billing, document, portal URL,
  or public-action-link content beyond its documented contract.
- [ ] Public-link parity is verified before and after each selected Project
  lifecycle action: archive disables public-link resolution and portal
  presentation; restore leaves both disabled until the ordinary authorized
  browser publish action deliberately re-enables them. Existing tokens and
  delivery receipts remain audit records.
- [ ] Project API v2 stays dual-editor: this cutover does not create a Project
  ownership lock or block the ordinary authorized browser edit workflow.

## Cutover, legacy quiescence, and retirement acceptance

Enable only the reviewed subset through the instance's approved configuration
and release process. This repository does not perform that action.

- [ ] A bounded observation period, alert owner, and rollback decision point
  are recorded.
- [ ] The legacy custom integration is quiesced before overlapping writes:
  schedulers, workers, webhooks, and retry queues are stopped or made read-only;
  no new credential or route grant is issued to it.
- [ ] In-flight legacy commands are drained or explicitly reconciled using
  aggregate, non-secret evidence. Duplicate write ownership is not accepted.
- [ ] API v2 reconciliation inventory and legacy aggregate counts agree for the
  approved resource set, with exceptions resolved or the cutover aborted.
- [ ] Retirement is accepted only after the observation period: legacy keys,
  callbacks, schedules, and delivery configuration are revoked or removed by
  the instance operator; retained audit records and rollback evidence remain
  available under normal retention controls.

## Rollback and local takeover

On a failed smoke check, unexpected parity result, stale attestation, or
duplicate-writer signal, stop further cutover activity and record the trigger.

1. Set every directory and Project API v2 flag to `false` through the approved
   instance configuration process; retain the configuration revision evidence.
2. Quiesce the external caller and preserve command, receipt, audit, and
   migration evidence. Do not delete receipts, bindings, history, or public
   tokens to make a rollback appear clean.
3. If directory management was active, use the explicit administrator takeover
   path to return local directory control and retain its audit result. Do not
   bypass the policy or reinterpret a degraded state as external authority.
4. Verify local authorized browser workflows and public-link/portal behavior,
   then compare aggregate directory and Project state with the pre-cutover
   evidence. Restore from the approved backup only when the incident plan calls
   for it; migration rollback files are not part of the forward migration path.
5. Revoke or suspend the dedicated external key as appropriate, keep the
   legacy integration quiesced until ownership is decided, and obtain a new
   approval before any later attempt.

Final acceptance:

- [ ] Reviewers accepted migration, flag/scope, backfill/attestation, smoke,
  public-link parity, quiescence, retirement, and rollback/takeover evidence.
- [ ] The record names the active approved subset, or confirms that all flags
  remain false.
- [ ] No credential, personal data, deployment address, or business-specific
  integration detail was added to this repository.
