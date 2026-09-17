---
layout: default
title: Sync Contract v2
description: Provider-neutral read-only bootstrap and event contract for external operations systems.
---

# Sync Contract v2

Sync Contract v2 is an opt-in, provider-neutral interface for synchronizing a
least-privilege Project Alpha projection. Project Alpha remains authoritative.
Existing version 1 keys and pagination remain compatible.

The route is disabled unless `APP_SYNC_CONTRACT_V2_ENABLED=true`. Keep that
setting false until every item in the production gate has been completed.

> **Foundation status:** the v2 snapshot and event primitives are suitable for
> consumer contract development and mutation-adapter work. Do not enable a
> production consumer until every covered mutation writes its resource version
> and event in the same transaction and webhook delivery/reconciliation has
> passed the production gate described below.

## Authentication and route

Use a dedicated API key with only `ops.sync.read`.

```http
GET /api/v2/ops/snapshot?limit=100
Authorization: Bearer <api-key>
```

The route is parallel to `/api/v1/ops/snapshot`; callers opt in by selecting the
v2 URL. Existing API keys, v1 response fields, and v1 pagination do not change.

## Identity

`source_instance_id` is the stable UUID of this Project Alpha installation. It
is seeded from Project Alpha's existing installation identity when present and
otherwise generated once by the migration.

Consumers identify a resource by the tuple:

```text
(source_instance_id, resource.type, resource.id)
```

Local numeric IDs are serialized as strings. Email addresses, names, codes, and
other editable attributes are never identities.

The existing **v1** snapshot exports the stored `public_id` of organizations,
clients, and projects as an additive field on each resource row. The v2
snapshot and upsert projection export that same value in `data.public_id`. This is
the same source-issued, 32-character lowercase hexadecimal identifier used by
the portal projection, **not** a hyphenated installation/session UUID. Numeric
`id` and relationship fields such as `organization_id` and
`client_id` retain their original values and types. Build an explicit,
source-scoped mapping between these identifiers; never infer a link from equal
names, email addresses, or unqualified IDs from another installation.
Client `email` and `phone` remain scalar fields; this addition does not introduce
a contacts array or change their existing null handling.

Migration `0062_client_portal_foundation.sql` backfilled these IDs once, makes
them non-null and unique within each entity table, and assigns IDs when new rows
are inserted. Snapshot reads do not create or repair IDs. A missing or malformed
stored ID fails either export rather than publishing a fabricated mapping. Older
producers without this additive field remain unmapped until upgraded and synced.

Adding this field changes the v2 content fingerprint of an already-observed
resource. The contract deliberately reports `sync_state_out_of_date` rather than
silently replacing that fingerprint; such a deployment requires an explicit
versioned compatibility transition. Before enabling v2 in production, protect
global event commit ordering: an
auto-increment sequence can be allocated before another transaction commits a
higher sequence, so a consumer checkpointing that higher value could miss the
earlier transaction's late event. Per-resource locks and a plain `MAX(sequence)`
high-water read do not prevent this. A transaction-held global sequencing lock
with consistent lock ordering, or an equivalent proven mechanism, must precede
the upgrade. Validate it with a real MySQL three-connection test (two writers and
a reader), including delayed commits, rollbacks, checkpoint advancement, and
replay convergence. Do not enable v2 in production or synthesize mappings while
these prerequisites remain incomplete.

## Snapshot bootstrap

The first response creates an API-key-bound snapshot session:

```json
{
  "contract_version": "2.0",
  "source_instance_id": "11111111-1111-4111-8111-111111111111",
  "snapshot": {
    "id": "22222222-2222-4222-8222-222222222222",
    "generated_at": "2026-07-30T18:00:00.000000Z",
    "expires_at": "2026-07-30T18:30:00.000000Z",
    "high_water_sequence": "41"
  },
  "items": [],
  "next_cursor": null,
  "request_id": "request-example-0001"
}
```

When `next_cursor` is non-null, request the next page with both values returned
by the server:

```http
GET /api/v2/ops/snapshot?limit=100&snapshot_id=<uuid>&cursor=<opaque>
```

Do not decode, synthesize, or edit cursors. Sessions expire after 30 minutes and
are bound to the API key that created them. Restart from the first request after
expiry.

Expired sessions are removed when a new snapshot begins. Each API key may hold
at most ten active sessions, preventing abandoned pagination sessions from
growing without bound.

`high_water_sequence` is the last committed v2 event when the session began.
After reading all pages, a consumer begins event reconciliation strictly after
that sequence. Sequence and version values are decimal strings and must be
handled as arbitrary-precision integers.

Pages do not hold a database transaction open across HTTP requests. Instead,
resource versions and the event high-water mark provide convergence: if a
resource changes during pagination, its event carries the same or a later
version. Consumers must ignore an event version they have already applied.

## Snapshot resources

The foundation allowlist contains:

- `organization`: name, postal address, created and updated timestamps
- `client`: contact/postal fields, organization reference, client type,
  archive state, created and updated timestamps
- `project`: client/parent/organization/business-unit/manager references,
  name, description, Project Alpha status and dates
- `service_location`: organization/client/project references, name, postal
  address, archive state and timestamps
- `job`: client/organization/project/location references, job code and origin,
  Project Alpha status, completion/archive state and timestamps

The allowlist excludes financial amounts and terms, payment-provider IDs,
authentication data, public/private tokens, private storage paths, uploaded
files, and arbitrary configuration/custom-field blobs.

Project Alpha lifecycle values remain canonical. Consumers must preserve
unknown future values rather than rejecting or silently remapping them.

## Resource and event envelopes

Snapshot items use:

```json
{
  "resource": {
    "type": "job",
    "id": "40",
    "version": "4"
  },
  "data": {}
}
```

The provider-neutral event envelope is:

```json
{
  "contract_version": "2.0",
  "source_instance_id": "11111111-1111-4111-8111-111111111111",
  "sequence": "42",
  "event_id": "33333333-3333-4333-8333-333333333333",
  "occurred_at": "2026-07-30T18:05:00.000000Z",
  "resource": {
    "type": "job",
    "id": "40",
    "version": "4"
  },
  "action": "upsert",
  "data": {}
}
```

`delete` events carry `data: null`. Event IDs are stable UUIDs. Delivery is
at-least-once, so consumers deduplicate by `event_id` and apply per-resource
versions monotonically. Gaps in the global sequence are allowed.

The machine-readable schema is
[`sync-contract-v2.schema.json`](sync-contract-v2.schema.json). Golden consumer
fixtures are under `tests/fixtures/sync-contract-v2`.

## Transaction consistency

The v2 event service rejects calls made outside an active database transaction.
A covered mutation must:

1. update the authoritative Project Alpha row;
2. build the exact allowlisted projection used by the snapshot;
3. update `sync_resource_state`; and
4. append `sync_event_log`;

all before committing the same transaction.

The snapshot seeds unseen resource states at version `1`. Once seeded, it fails
closed with `sync_state_out_of_date` if a resource differs from its recorded
state, exposing an uninstrumented mutation instead of silently serving an
unreconcilable snapshot.

## Errors

V2 errors use:

```json
{
  "success": false,
  "code": "invalid_request",
  "message": "Human-readable summary.",
  "request_id": "request-example-0001"
}
```

Important codes are `invalid_limit`, `invalid_request`, `snapshot_invalid`,
`sync_state_out_of_date`, `schema_out_of_date`, and `snapshot_failed`.

## Production gate

Before an external application consumes v2 in production:

- route all covered create/update/status/archive/delete paths through the
  transaction contract and emit explicit deletion tombstones;
- expose signed event delivery after `high_water_sequence`, with retry,
  deduplication, leasing, dead-letter, and replay behavior;
- protect global event commit order and prove checkpoint/replay convergence with
  two concurrent writers and a reader before any snapshot-driven public-ID
  fingerprint upgrade;
- add tenant isolation if an installation hosts more than one security tenant;
- run a full snapshot plus concurrent-mutation convergence test;
- document rate-limit headers and operational cursor-expiry/restart behavior;
- compare a canary consumer's final state with Project Alpha before promotion.

Inbound write APIs, conflict resolution, file transfer, and provider-specific
fields are outside this foundation.
