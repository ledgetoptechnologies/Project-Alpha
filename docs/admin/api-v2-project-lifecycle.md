---
layout: default
title: API v2 Project lifecycle
---

# API v2 Project lifecycle

Project Alpha keeps the authoritative Project record. The optional API exposes
exact reads, reversible lifecycle commands, and application-scoped Project
synchronization to explicitly provisioned API v2 applications. Every route is
generic and disabled by default. Nothing selects a Project, client, or
organization by name or email, creates portal membership, publishes documents,
enables a public link, activates delivery, or inherits access from a legacy
`full` key.

## Lifecycle model

Persisted workflow status is one of `not_started`, `active`, `completed`, or
`cancelled`. "Overdue" is a read-time warning when an unarchived active or
not-started Project has an estimated end date before today; it is not a status
that a caller can set. Archive is reversible and independent of workflow
status. It removes the Project from ordinary selection, managed delivery,
portal projection, and public-link resolution while retaining the Project,
documents, files, financial relationships, public ID, and revision history.
Archive also disables both public-link and portal-publication state, stops
undelivered managed presentation, and queues pinned-contract revocations for
accepted project-scoped deliveries. Tokens and receipts remain audit evidence.
Restore changes lifecycle visibility only and leaves presentation disabled.

The historical browser delete route now archives. A foreign key from permanent
change history to the Project also makes an accidental physical delete fail
closed. Archived Projects can be restored from the browser's Archived filter or
through the separately authorized restore command.
After restore, a user must deliberately enable the existing public Project link
control in the ownership- and CSRF-guarded Project edit workflow before the
retained token can resolve or the Project can re-enter portal projection.

## Routes and identity

All routes require a dedicated API v2 key, `api.capabilities.read`, the exact
route capability, and matching `X-PA-Source-Instance-ID`,
`X-PA-Application-ID`, and `X-PA-History-Epoch` headers.

- `GET /api/v2/projects/{publicId}` requires `projects.v2.read`.
- `POST /api/v2/projects/{publicId}/complete/commands` requires `projects.lifecycle.complete`.
- `POST /api/v2/projects/{publicId}/cancel/commands` requires `projects.lifecycle.cancel`.
- `POST /api/v2/projects/{publicId}/archive/commands` requires `projects.lifecycle.archive`.
- `POST /api/v2/projects/{publicId}/restore/commands` requires `projects.lifecycle.restore`.
- `POST /api/v2/projects/commands` requires `projects.create`.
- `POST /api/v2/projects/profile/commands` requires `projects.write`.
- `POST /api/v2/projects/bindings/commands` requires `projects.bind`.
- `POST /api/v2/projects/bindings/revisions/commands` requires `projects.binding.revision.refresh`.
- `GET /api/v2/projects/bindings/status/{base64urlExternalId}` requires `projects.binding_status.read`.
- `GET /api/v2/projects/inventory?limit=100&cursor=...` requires `projects.inventory.read`.

Lifecycle command bodies have exactly two fields:

```json
{"commandId":"423e4567-e89b-42d3-a456-426614174000","expectedRevision":"7"}
```

Project synchronization commands use the separately documented strict shapes
for create, update, bind, and binding-revision refresh; they are not represented
by the lifecycle example above. Command IDs are UUIDv4 values. Revisions are
canonical positive decimal strings. Receipts are isolated by application and
history epoch. An exact
replay returns the recorded result; reuse of a command ID with another target,
action, revision, or body fails with conflict. Closeout contract guards,
receivable auditing, schedule synchronization, portal reconciliation, revision
history, and the receipt share one database transaction.
Lifecycle results and exact-replay receipts include `portalPublished` and
`publicLinkEnabled`; archive and restore record both values as false.

## Project synchronization contract

Each application has a separate authorization generation and a permanent
one-to-one mapping between its external Project ID and one PA Project public
ID. An external ID and PA public ID cannot be reused or remapped. Existing PA
Projects are bound only by a deliberate command carrying the exact public ID,
revision, canonical projection SHA-256, and generation. A Project created by
the API is bound atomically and must cite an active, same-application
organization directory binding; an optional client binding must belong to that
organization. This keeps the Project eligible for ordinary PA ownership rules
without guessing from names or email addresses.

Create accepts one shared Project name, description, and estimated dates. It
starts `not_started`, uses neutral per-invoice billing with automatic invoice
email disabled, and starts with portal publication and public links disabled.
Update changes only those profile fields, rejects archived Projects, preserves
existing presentation state, advances the canonical Project revision only for
a content change, and maintains PA's internal schedule entry. The API path
deliberately does not reconcile workspaces, advance portal projection
generations, or enqueue portal or External Operations delivery. Those remain
separate PA/browser or deployment-governed actions. Lifecycle state changes
remain the separate lifecycle commands.
Terms such as client proposal or approval are deliberately outside this PA
contract.

Every sync command uses canonical strict JSON, UUIDv4 command IDs, a request
hash, authorization generation, and revision/projection-hash CAS where a
Project already exists. Exact retries return the immutable receipt; a changed
body using the same command ID conflicts. After a legitimate PA/browser edit,
the separately scoped refresh command requires the pinned prior revision plus
the current live public ID, revision, projection hash, and generation. It can
only advance the existing mapping and never changes Project content or
presentation.

Binding status verifies the pinned revision and hash against live canonical
history. A synchronized binding returns `200`. After a PA/browser edit makes a
binding stale, the route preserves `409` conflict semantics but returns a
no-store recovery envelope with `error.code` set to `binding_stale` for that
exact application binding. The top-level
`authorizationGeneration` is the current Project authorization generation;
`binding.publicId` and `binding.revision` are the pinned evidence; and
`resource.revision` and `resource.projectionSha256` are the verified live
evidence. Those values map directly to the binding-revision
refresh command's `expectedPublicId`, `expectedPriorRevision`,
`expectedRevision`, `expectedProjectionSha256`, and
`expectedAuthorizationGeneration` fields. A missing binding returns `404`, and
an identity mismatch or missing/corrupt canonical history remains a bare `409`.
Recovery evidence is returned only when the pinned revision is strictly older
than verified live canonical history and its pinned hash matches the immutable
`project_changes` hash for that exact historical revision. Equal-revision hash
drift, an older revision with a mismatched historical hash, or a binding
revision ahead of live history is treated as corruption and remains a bare
`409`; the refresh command's prior-revision fence is not sufficient to repair
those cases. The established synchronized `200` response shape is unchanged.

Inventory is capped at 200 entries and lists only the calling application's
bindings; unbound browser-created Projects are invisible. Inventory remains an
all-or-nothing current snapshot. If a binding in the requested page is stale,
it returns `409` with only the identity envelope and
`error: {"code":"binding_stale","externalId":"..."}` instead of returning a
partial `projects` array. The inventory capability does not disclose live
revision, hash, public ID, or authorization generation on conflict. A fresh
client uses the discovered external ID with the separately scoped binding-status
route, refreshes that binding, retries the same page, and repeats until the page
is current. At most one external ID is returned per request. Missing or corrupt
canonical history still produces a bare `409`. These responses contain no
billing, budget, invoice, payment, document, portal URL, public action link, or
other financial/publishing content.

## Release gate

Keep every `APP_API_V2_PROJECTS_*_ENABLED` flag false while preparing a release.
After migrations 0100 through 0102, run bounded dry runs and then apply during a confirmed
maintenance window:

```text
php bin/backfill-api-v2-projects.php --limit=100 --dry-run
php bin/backfill-api-v2-projects.php --limit=100 --apply --confirm-api-v2-project-backfill --maintenance-window-confirmed
php bin/backfill-api-v2-projects.php --limit=100 --dry-run --attest
php bin/check-api-v2-project-release.php --attestation-sha256=<digest emitted above>
```

Continue from the emitted cursor until no cursor remains. The staging release
must run the check command with the reviewed digest immediately before enabling
any Project synchronization route; it exits nonzero for a missing/stale receipt,
partial/wrong 0102 schema (including binding/receipt keys and foreign keys), or
code/schema/coverage drift. Then run focused and
full tests plus disposable MySQL concurrency/rollback tests. Provision only the
needed per-route scopes and enable only the reviewed routes. A missing or
drifted canonical history row causes reads and commands to fail closed.

Run the isolated MySQL 8.4 coverage with:

```powershell
tools/run-api-v2-project-lifecycle-mysql-integration.ps1
```

CI runs this disposable-MySQL runner explicitly because `tests/Integration` is
excluded from the normal PHPUnit suite; a green normal PHPUnit step alone is
not release evidence for this slice.

This repository evidence establishes code and test eligibility only. It does
not claim that a deployment has applied the migration, completed the backfill,
provisioned a key, enabled a route, or cut over an external application.
Project binding revocation, remapping, and hard deletion are not supported.
