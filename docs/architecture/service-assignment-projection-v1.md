# Service-assignment projection v1

Project Alpha is the authority for explicit service assignments. The optional
producer publishes those facts to a registered client-portal receiver without
turning a catalog item, quote, contract, invoice, workspace membership, portal
entitlement, or matching name/email into an assignment.

## Activation and connection boundary

The producer is disabled by default. Migration 0080 adds the per-profile
`service_assignment_projection_enabled` capability with a default of `0`.
Outbound delivery also requires the existing profile, profile enable flag,
delivery enable flag, HMAC credentials and installation-wide outbound switch.
The receiving connector must independently grant the exact capability
`portal.service-assignments.publish`.

The single External Operations form owns the explicit
**Publish assigned services to the client portal** toggle. Enabling it queues a
complete snapshot after the existing signed-delivery contract is ready.
Disabling it queues an authoritative empty snapshot marked as profile-control
revocation work before changing the capability flag. Those records remain
deliverable while the normal producer is off. Stable connection saves preserve
the stored choice and do not queue duplicate snapshots.

There is no second connection or credential set. The producer derives the
service-assignment endpoint only from an existing registered portal-v2 route:

```text
/api/internal/project-alpha/sources/{sourceId}/portal-v2
    -> /api/internal/project-alpha/sources/{sourceId}/service-assignments-v1
```

The source ID in that registered URL is only a candidate until the receiver
validates its Access assertion and pinned HMAC authority. Project Alpha never
puts a source ID in the payload and never accepts one from a browser. The
receiver stores `(sourceId, assignmentPublicId)` as the identity, so separate
Project Alpha instances may use the same opaque public IDs without colliding.

## Wire and durability contract

`PortalServiceAssignmentProjectionService` emits the exact schema-v1
`snapshot.page`, `snapshot.activate`, and ordered `event` envelopes accepted by
the Operations receiver. Pages contain at most 100 normalized items, a snapshot
contains at most 5,000 items and each serialized body is capped at 256 KiB.

Items are ordered by `assignmentPublicId`. A page hash is SHA-256 of the UTF-8
JSON bytes for `{schemaVersion:1,items}`. The snapshot hash is SHA-256 of the
UTF-8 JSON bytes for `{schemaVersion:1,pageCount,itemCount,pages}`, where pages
are ordered and each entry is `{pageNumber,itemCount,pageHash}`. A complete
snapshot and activation share one generation and sequence. Subsequent upserts
and tombstones increment the sequence without changing the generation.

All deliveries use the existing `portal_projection_outbox`. The exact payload,
destination, application key, signing key ID and delivery ID are pinned when
queued. Network retries therefore resend the same bytes and delivery identity.
Producer command receipts are immutable and keyed by profile plus a hash of the
caller-supplied idempotency key. Reusing a key with the same normalized intent
returns the original result; reusing it with different content fails closed.

Assignment-removal tombstones use the last published source version. They are
normal ordered data events, not profile-control revocations, and cannot bypass
a disabled profile or supersede an earlier undelivered event. The distinct
empty snapshot used to disable or retire the producer is profile-control
revocation work and may drain after the capability is off. A missing complete
snapshot prevents incremental delivery. Disabled, unconfigured, malformed,
oversized, missing-subject and missing-catalog-service states enqueue nothing.

## Authority and operations

Assignments live only in `portal_service_assignments`. Projection state and
last-published records are qualified by integration profile. The producer
first resolves the profile's active workspace allowlist, then includes only
subjects contained by those workspace roots. Organization roots include their
departments, active clients and non-cancelled organization projects. Standalone
client roots include the client under both the `standalone_client` and `client`
subject types plus that client's non-cancelled projects. Valid assignments from
other roots are ignored, while a missing root for an active allowed workspace
fails closed. Direct upsert events use the same containment check, and direct
tombstones may reference only records previously published for that profile.

After containment, the producer revalidates the referenced subject and the
current requestable catalog service before publishing; its source version
covers the assignment fields and exact catalog service version. Catalog changes
therefore produce a new assignment version instead of reusing a version for
different content.

## Staff administration and lifecycle reconciliation

Migration 0081 adds the separate `portal_service_assignments.manage` staff
permission. The owner system role receives it; administrators retain wildcard
authority, and other roles remain denied until explicitly delegated. A write
also requires the entity's normal edit permission, record ownership and a valid
CSRF token. The browser submits only internal subject, assignment and catalog
IDs. Project Alpha resolves public wire identities, workspace roots and
integration profiles inside the write transaction.

Assignments are managed on organization, department, client and project detail
pages. Creation, edits, activation changes and removal preserve the assignment's
opaque public identity and queue the affected projections atomically. A service
may be assigned only once to the same non-deleted subject. Effective timestamps
are normalized to UTC, and an end must be later than its start.

Ownership changes reconcile the ordered union of locked old roots and current
roots in the same transaction: former profiles receive tombstones before new
profiles receive upserts. Catalog unpublishing keeps the authoritative
assignment as history but omits it from delivery, causing an exact tombstone;
one unavailable service therefore cannot block unrelated assignment changes.
The UI identifies that unavailable service explicitly and requires an intentional
replacement rather than silently selecting another catalog entry.

The administration surface does not enable any existing integration profile,
publish contact roles, or grant portal access. Before activation, operators must:

1. apply migrations 0080 and 0081 and verify the existing External Operations connection;
2. register `portal.service-assignments.publish` for the exact receiver source;
3. delegate `portal_service_assignments.manage` only to approved staff and
   create explicit assignment records from the owning entity page;
4. enable **Publish assigned services to the client portal**, deliver its
   automatically queued complete snapshot, then verify receiver activation;
5. enable mutation callers only after their assignment write and outbox enqueue
   share one transaction; and
6. test two independent sources with intentionally colliding public IDs; and
7. test disjoint profile workspace allowlists before enabling delivery.

Do not enable this producer merely because the catalog or portal projection is
enabled. A receiver that is missing, suspended, incomplete, or unable to prove
its source remains unavailable rather than becoming an authoritative empty set.
