---
title: External Operations Integration
description: Assignment-driven synchronization from Project Alpha to a deployment-specific operations application.
---

# External Operations Integration

This optional module projects operational records into a separate, authenticated, read-only application. Project Alpha remains the only editor for Projects, Operations, Tasks, teams, and assignments.

## Company structure and work planning

A **Business Unit** represents a division, branch, region, department, or crew. Manage Units under **Settings > Business > Business units & divisions**. Add existing PA users as Members or Heads and choose one primary Unit per user. These are organizational labels: they do not grant PA permissions, workforce review scope, or external-application access.

Each Project can have one Unit, and its Operations and Tasks inherit that Unit. Manage work in the Project's **Team & Work** section:

- Select one primary Project Manager. The manager is kept on the Project Team and can supply the default Business Unit for a new Project.
- A worker must be an active Team member before being assigned to an Operation or Task.
- A Task may have several assignees.
- Open Operations or Tasks must be reassigned, completed, or cancelled before a Team membership can end.
- The current Project Manager must be replaced or cleared before their Team membership can end.

## Access model

External access is an explicit user allowlist managed either under **Custom integrations** or on the PA account. Project Team and Business Unit membership never grants sign-in access by itself.

Use **Grant external operations access** (or the configured application name shown by the deployment) as the authoritative provisioning action. It activates a merely inactive PA account, enables the configured application entitlement, derives the external role, and queues one signed change event in a single transaction. Deleted or terminated users must be restored separately. **Revoke external operations access** disables only the external entitlement; it does not disable the PA account or remove Project assignments.

The access panel distinguishes the PA account state, effective external access, derived role, and latest delivery status. A legacy `manual_access` value by itself is never treated as effective access: the explicit selection, enabled entitlement, and active account must all agree. Repeating an already-completed grant or revoke is a safe no-op and does not queue a duplicate event.

| Access action or state | Result |
|---|---|
| Grant an active account | Enables the configured entitlement and queues one `application_entitlement.changed` event |
| Grant a merely inactive account | Reactivates the PA account and worker records, enables the entitlement, and queues one consistent changed event |
| Grant a deleted or terminated account | Refused until the account or worker is restored through its normal administrative workflow |
| Repeat an effective grant | Successful no-op; no contradictory or duplicate event is queued |
| Revoke access | Disables the external entitlement and queues `application_entitlement.revoked`; the PA account remains active |
| Repeat a completed revoke | Successful no-op; no duplicate revocation is queued |

Effective sign-in access requires all of the following: the PA account is active,
the configured application entitlement is enabled, and the latest projection is
not a revocation. Account authentication, external role, and work visibility are
separate concerns: granting access permits sign-in but never expands Project,
Operation, or Task assignments.

A selected user whose exact PA role is `admin` becomes a **Global external administrator** (`role-admin`). Every other selected role, including PA `owner`, becomes an **Assigned-work operator** (`role-operator`). Project Team membership or Project Manager status provides Project context; direct Operation and Task assignments provide those records. A selected operator with no assignments sees an empty operational workspace. Business Units remain synchronized as Project metadata and are not authorization scopes.

## Configure a deployment

API-key pull synchronization and signed outbound delivery are separate features.
An existing API key (including one with `ops.sync.read`) does not configure or
enable the outbound outbox sender. The pull snapshot remains available when
outbound delivery is disabled or paused, provided its normal API key and stable
application key requirements are met.

Open **Settings > System & Integrations > Custom integrations**. This single surface contains the deployment-specific display label, signed-event URL, service authentication credentials, HMAC secret, outbound signing controls, explicit Project Alpha account access, and synchronization health. Set a stable application key such as `external_application`; use the same key in the provisioning receiver and snapshot importer. No application key or display label is fixed by Project Alpha. The open-source display-name fallback is **External operations**; a deployment may replace it with its own product name.

Portal projection profiles, workspace/principal records, scoped allowlists,
runtime gates, recovery, and signing remain backend compatibility contracts and
are not editable in normal Settings navigation. The External Operations card
shows only client-portal health and one idempotent reconcile/repair action. The
ordinary organization and client pages contain the relevant portal-login
revoke/restore action.

Historical clients do not require that repair button. Once the complete signed
producer preflight is ready, `reconcile_client_portal.php` automatically discovers
unprocessed organization and standalone roots every minute, at most 25 roots per
run. Each root, its eligibility, relationships, projection outbox records, and
completion marker commit atomically. Restarts resume unfinished roots; repeated
runs do not duplicate workspaces, principals, or published snapshots. Producer
application-key/receiver changes invalidate the old completion fingerprint.
This job uses the portal producer gates, like the outbox sender, rather than the
unrelated automatic-invoice `cron_enabled` preference. It sends no email.

An existing deployment also does not require a one-time settings save after an
upgrade. When the stored External Operations connection is enabled, complete,
and decryptable, the reconciliation job idempotently binds that same connection
as the portal producer before processing historical roots. It does not invent a
second endpoint, activate an unconfigured instance, or bypass the established
retire-and-drain rules for a changed receiver. Scheduled system activation is
recorded without falsely attributing it to an administrator.

Progress is recorded in `portal_client_provisioning_backfill`,
`portal_integration_audit`, and the `portal_client_provisioning_backfill` entry in
`cron_job_runs`. A failed root rolls back without blocking the rest. It retries
after 1, 2, 4, and 8 minutes, stopping after five attempts. Logs include counts;
root records include only the stable `storage_failure`/`projection_failure`
category and a diagnostic hash, not raw customer data or credentials. Terminal
failures keep cron health failed until repaired. After correcting the cause,
the existing audited reconcile/repair action also clears its backfill failure
by marking successfully reconciled roots complete. Never remove access-control
or eligibility rows to retry: those preserve manual revocations.

Project Alpha automatically publishes login eligibility for an active human
contact only when it has one valid canonical email that is unique among active
client records. Missing, invalid, or duplicate email, an unclassified standalone
record, and a conflict with a principal outside this managed workflow all fail
closed as **review required**. Organization client rows are contact records;
standalone records must explicitly be consumer records. An administrator revoke
is durable and is not undone by reconciliation. Archiving or deleting a client,
or revoking its organization root, publishes the corresponding disabled state
and tombstones.

Eligibility is not identity proof and is not content access. Project Alpha never
writes an identity-provider issuer/subject binding. The consuming portal must
bind the principal only after an exact verified sign-in assertion, and Operations
must still grant a folder or delivery explicitly. The automatic capabilities are
limited to opening the workspace, reading its directory, and viewing deliveries;
they cannot create shares or infer access to any stored file.

Project Alpha does not accept or infer an identity-provider subject from email.
The consuming portal verifies a live assertion and owns the issuer/subject
binding. Matching names, addresses, CRM contacts, primary contacts, and public
links are never identity bindings or grants.

Outbound delivery is ready only when the administrator has requested it and the
application key, signed event URL, Access service-token ID, Access service-token
secret, and a ready signing method are available. The compatible default signing
method is HMAC-SHA256 with its secret; an activated Ed25519 key may replace it on
the same connection. The encrypted credential payload must also be readable with
the deployment's persisted application encryption key. Timeout and maximum-attempt settings are bounded
but are not readiness predicates. Keep outbound delivery disabled until the
receiver contract is deployed. If an older or partial configuration has the
enable flag set but is incomplete, Project Alpha pauses outbound delivery while
continuing to record authoritative events for later retry. It shows the missing
non-secret setting categories without altering stored values, access
administration, or API-key pull sync. A full snapshot remains the reconciliation
authority after any historical capture gap.

Use a dedicated Project Alpha API key with only the stable `ops.sync.read` scope. The UI describes this as external operations synchronization, but the scope identifier remains stable for compatibility.

Secrets are encrypted with Project Alpha's persisted application encryption key. Passwords, pay rates, financial details, API secrets, private tokens, and integration secrets are never included in the operational projection.

### Ed25519 signing upgrade

HMAC-SHA256 remains the compatible default. Migration `0086_external_operations_ed25519_signing.sql` adds only a non-secret envelope marker; it does not enable a connection or change an existing HMAC sender. An installation may instead use Ed25519 on the same External Operations connection when its PHP runtime provides `ext-sodium`.

1. Generate a **staged Ed25519 key** in Custom integrations.
2. Register the displayed key ID and public key with the existing receiver. Project Alpha never displays or exports the private key.
3. Confirm that registration in the UI and activate the staged key. Activation is refused while ordinary, portal, or managed-delivery rows remain unresolved, so an accepted retry cannot be re-signed with a replacement key.
4. Keep HMAC available only as a deliberate fallback. Returning to it requires explicit confirmation and the same empty-outbox safety check.
5. Retire an unused staged key when it is no longer needed; retirement permanently removes its encrypted private material while retaining the public audit record.

Ed25519 uses the unchanged canonical input `timestamp + "." + raw_request_body`. It sends `X-PA-Signature-Ed25519: ed25519=<base64url-without-padding signature>`; the receiver selects its active or overlap public key from its registry. HMAC retains the existing byte-for-byte `X-PA-Signature: sha256=<hex>` header. A receiver must register the public key before activation; no second URL or portal signer is created.

## Delivery contract

Project Alpha writes signed, idempotent change events for Business Units, Projects, Team membership, Operations, Operation assignments, Tasks, Task assignments, and entitlements. Events include an event ID, source timestamp, schema version, configured application key, and the selected HMAC or Ed25519 signature. The receiver ignores duplicates and out-of-order changes.

The minute-scheduled outbox sender delivers queued events with the configured
Cloudflare Access service-token headers and Project Alpha event headers. HMAC is
SHA-256 over `timestamp + "." + raw_request_body`; Ed25519 signs the same input.
Failed deliveries
remain in the outbox for the existing retry schedule; a successful retry keeps the
same event identity so receiver-side idempotency remains effective.

The external application also performs a daily reconciliation using:

```text
GET /api/v1/ops/snapshot?page=1&limit=500
```

Follow `next_page` while `has_more` is true. The snapshot includes Project Managers, Business Unit-aware Projects, and the multi-worker `task_assignments` collection. It is the recovery authority if an incremental event is delayed or missed.

The integration status card reports queued ordinary and portal deliveries,
retry errors, client roots, eligible contacts, records requiring review, and
the honest count of historical roots that remain. After deployment or a
configuration change, use **Sync now** or **Reconcile next client portal
batch**. Both actions use the same External Operations connection, reconcile a
bounded and restart-safe batch (25 and 100 roots respectively), and then send
due ordinary and portal events within the request deadline. Repeat either
action while historical roots remain; scheduled jobs continue the same work.

The status card reports the one **External Operations connection** and whether
client portal events are ready on that connection. API-key pull reconciliation
is a receiver-driven recovery path, not another outbound destination.

Before reconciliation, the portal preflight checks the enabled External
Operations connection, its exact signed event URL, service authentication,
ready signing method, saved producer state, delivery switch, outbound runtime, and
authoritative hooks. The page reports only fixed prerequisite names and boolean
state; it never displays a URL, token, or secret value. There is no separate
Project Alpha-to-portal connection or signing capability.

Each portal outbox record retains its ordering, retry, revocation, and
dead-letter state. At delivery time it is wrapped as an External Operations
event with event type `portal.projection`, a strict `projection_kind`, and the
unchanged inner projection. The complete outer body is signed using the same
`timestamp + "." + raw_request_body` contract as other events and posted to the
exact saved signed event URL. Operations authenticates it once and routes the
inner record to the client portal internally.

To rotate the receiver contract, first disable the visible connection without
changing its URL, application key, or credentials. That retires the bound portal
state and queues its workspace tombstones. Re-enable the unchanged connection
long enough to drain those records to the original receiver. Project Alpha
blocks changes to the signed-event URL, application key, Access service token,
or active signing contract while any deliverable portal outbox row remains unresolved,
including a dead-lettered revocation. Dead-lettered normal events are resolved
through the existing retirement audit step and are never replayed against the
replacement contract. Only after the queue reaches zero may an administrator
save the replacement contract and reconcile its complete snapshot. This staged
rotation prevents old client data or revocations from being signed for, or sent
to, a replacement receiver.
After every revocation is acknowledged, any older dead-lettered non-revocation
events are administratively resolved before the replacement is activated. The
original dead-letter timestamp and error remain available for audit; revocation
events are never resolved this way.
Any catalog, pricing-preview, draft-quote, and relation-projection settings on
the bound profile are retained through this disabled drain state and restored
when the replacement portal contract becomes active.
The authority service also rejects any legacy or direct settings action that
would activate a second portal producer, so the single-producer rule is not
limited to the simplified External Operations page.

The optional service-assignment producer uses this same External Operations
profile, receiver origin, Access headers, active signing method, workspace allowlist, and
durable outbox. It is disabled by default and requires the receiver to grant
`portal.service-assignments.publish` before activation. Catalog visibility,
billing records, portal eligibility, workspace membership, and portal
entitlements never create an assignment. See
[Service-assignment projection v1](../architecture/service-assignment-projection-v1.md).
Administrators opt in with **Publish assigned services to the client portal**
on this connection form; there is no second endpoint or credential form. The
first enable queues a complete snapshot. Clearing the option queues an
authoritative empty snapshot as revocation work before the capability is
disabled, so the receiver cannot retain stale assignments. Re-enabling after a
connection rotation queues a new complete snapshot against the replacement
contract. Leave the option clear until migrations 0080 and 0081 are applied and
the receiver capability is verified.

The optional contact-assignment producer also uses this same profile, route,
workspace allowlist, credentials, and portal outbox. **Publish scoped contact
roles to the client portal** is disabled by default and requires portal and
relation projection. It publishes only explicit department and project contact
assignments; it never creates login or content access. Enabling or disabling it
queues a complete replacement portal generation. Primary billing ownership and
invoice-email delivery remain independent. Leave it clear until migration 0082
and the receiver's schema-v4 capability are deployed. See
[Portal contact-assignment projection v4](../architecture/portal-contact-assignment-projection-v4.md).

If a revocation exhausts its delivery attempts, the simplified synchronization
status exposes an audited retry action. It resets only failed revocations and
keeps their original receiver/key contract; it never suppresses a tombstone or
allows the replacement connection to activate early.
Re-enabling the same connection waits for those revocations; ordinary
saves of an already-active unchanged connection never administratively resolve
historical dead-lettered events.

The daily snapshot is reconciliation and recovery, not a replacement for the
event path. Account email, display name, PA role, active state, and explicit-access
changes refresh or revoke the entitlement projection through the same outbox.

## Sync Contract v2 foundation

The provider-neutral v2 bootstrap foundation is implemented in parallel at `GET /api/v2/ops/snapshot` for API keys with `ops.sync.read`, but it returns 404 unless `APP_SYNC_CONTRACT_V2_ENABLED=true`. It does not change the v1 route or its event delivery. See [Sync Contract v2](../reference/sync-contract-v2.html) for its identity, cursor, resource-version, fixture, and production-gate rules. Keep the foundation disabled until all covered mutations use the documented atomic event contract and the remaining production gates pass.
