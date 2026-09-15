---
layout: default
title: API v2 Project lifecycle
---

# API v2 Project lifecycle

Project Alpha keeps the authoritative Project record. This foundation exposes
exact Project reads and reversible lifecycle commands to explicitly provisioned
API v2 applications. It is generic, disabled by default, and does not select a
Project by name, create portal membership, publish documents, enable a public
link, or inherit access from a legacy `full` key.

## Lifecycle model

Persisted workflow status is one of `not_started`, `active`, `completed`, or
`cancelled`. "Overdue" is a read-time warning when an unarchived active or
not-started Project has an estimated end date before today; it is not a status
that a caller can set. Archive is reversible and independent of workflow
status. It removes the Project from ordinary selection, managed delivery,
portal projection, and public-link resolution while retaining the Project,
documents, files, financial relationships, public ID, and revision history.

The historical browser delete route now archives. A foreign key from permanent
change history to the Project also makes an accidental physical delete fail
closed. Archived Projects can be restored from the browser's Archived filter or
through the separately authorized restore command.

## Routes and identity

All routes require a dedicated API v2 key, `api.capabilities.read`, the exact
route capability, and matching `X-PA-Source-Instance-ID`,
`X-PA-Application-ID`, and `X-PA-History-Epoch` headers.

- `GET /api/v2/projects/{publicId}` requires `projects.v2.read`.
- `POST /api/v2/projects/{publicId}/complete/commands` requires `projects.lifecycle.complete`.
- `POST /api/v2/projects/{publicId}/cancel/commands` requires `projects.lifecycle.cancel`.
- `POST /api/v2/projects/{publicId}/archive/commands` requires `projects.lifecycle.archive`.
- `POST /api/v2/projects/{publicId}/restore/commands` requires `projects.lifecycle.restore`.

Every command body has exactly two fields:

```json
{"commandId":"423e4567-e89b-42d3-a456-426614174000","expectedRevision":"7"}
```

Command IDs are UUIDv4 values. Revisions are canonical positive decimal
strings. Receipts are isolated by application and history epoch. An exact
replay returns the recorded result; reuse of a command ID with another target,
action, revision, or body fails with conflict. Closeout contract guards,
receivable auditing, schedule synchronization, portal reconciliation, revision
history, and the receipt share one database transaction.

## Release gate

Keep every `APP_API_V2_PROJECTS_*_ENABLED` flag false while preparing a release.
After migration 0100, run bounded dry runs and then apply during a confirmed
maintenance window:

```text
php bin/backfill-api-v2-projects.php --limit=100 --dry-run
php bin/backfill-api-v2-projects.php --limit=100 --apply --confirm-api-v2-project-backfill --maintenance-window-confirmed
```

Continue from the emitted cursor until no cursor remains. Then run focused and
full tests plus disposable MySQL concurrency/rollback tests. Provision only the
needed per-route scopes and enable only the reviewed routes. A missing or
drifted canonical history row causes reads and commands to fail closed.

Run the isolated MySQL 8.4 coverage with:

```powershell
tools/run-api-v2-project-lifecycle-mysql-integration.ps1
```

## Deliberate boundary

This release does not implement external Project create, profile update,
external-ID binding, inventory, binding revocation, or application generation
fences. Those need a separately reviewed one-to-one ownership contract. Project
selection remains the caller's responsibility; this API accepts only the exact
permanent Project public ID.
