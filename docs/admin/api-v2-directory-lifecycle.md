# API v2 directory lifecycle and relationships

This surface is generic and stateless. Read-only directory, binding-status, and
inventory routes are enabled by default; commands are default-off. Every route
is available only to an application-bound API key with its explicit scope. The legacy `full`
scope never authorizes these routes. Every request also carries the provisioned
source-instance, application, and history-epoch headers.

External ownership covers shared directory identity and topology: client,
organization, and customer-unit creation, profile changes, lifecycle changes,
and relationships
are blocked in the browser while active.
PA-internal organization notes and organization document uploads remain local
metadata; they are intentionally not API v2 directory projections and remain
editable. A `unit` is backed by `organization_departments`; it is not PA's
internal `business_units` model. Its canonical projection is its name, parent
`organizationPublicId`, and ordered contacts (`clientPublicId`, `role`, and
`primary`). Operations owns those fields. Department notes, folder names,
resolver modes, aliases, generated links, and organization `link_strategy`
remain PA-local and are never returned by this contract. Local unit/contact and
link-strategy forms are nevertheless disabled while managed ownership is
active so there is one operational writer during cutover.

For this first release, `projects.department_id` remains a PA/project-domain
field. Assigning a Project to a unit does not mutate the unit projection and is
not an Operations directory command. Link resolution configuration likewise
remains PA-owned; Operations owns unit records and contact assignments, not PA's
resolver configuration.

Lifecycle commands use:

- `POST /api/v2/directory/clients/{publicId}/archive/commands`
- `POST /api/v2/directory/clients/{publicId}/restore/commands`
- `POST /api/v2/directory/organizations/{publicId}/archive/commands`
- `POST /api/v2/directory/organizations/{publicId}/restore/commands`
- `POST /api/v2/directory/units/{publicId}/archive/commands`
- `POST /api/v2/directory/units/{publicId}/restore/commands`

The strict JSON body contains `commandId`, `expectedRevision`, and
`expectedAuthorizationGeneration`, all as strings. Archive is a soft lifecycle
transition: Project Alpha keeps the source row and every linked financial and
historical row, publishes a directory tombstone, revokes active external
bindings, and advances affected authorization generations. Restore publishes a
new live revision but deliberately does not revive an old binding.

The neutral projection path cannot revoke portal principals, entitlements, or
active workspaces. An archive therefore returns a reconciliation conflict while
any such authority is active. Resolve that access through the governed portal
workflow first; the command will not publish a directory tombstone while leaving
another authority plane live.

There is no API v2 hard-delete command. Existing local purge/delete paths can
physically remove clients, organizations, projects, documents, or billing
history through foreign-key behavior. Reproducing that behavior would violate
the external-directory safety contract, so managed ownership blocks those local
paths and the API fails closed by exposing only archive/restore. Do not add a
delete flag, scope, or advertised endpoint without first replacing the domain
deletion semantics with a separately reviewed retention-safe design.

Client relationships use distinct assign, remove, and move command routes below
`/api/v2/directory/clients/{publicId}/organization/`. A command names the exact
current organization public ID (or `null` for assign), the expected client
revision and authorization generation, and, when adding a destination, an exact
active application-scoped organization binding plus its public ID and revision.
Names and email addresses are never matching keys.

Binding authority is explicitly revoked through
`POST /api/v2/directory/{clients|organizations|units}/bindings/revoke/commands`.
Revoked external IDs remain tombstoned and are not silently reused.

`GET /api/v2/directory/inventory` returns a bounded, snapshot-consistent list of
current resource revisions, retained tombstones, projection hashes, and only the
calling application's binding state. Use `type`, `limit`, and the returned
`nextCursor` for reconciliation. It returns no profile fields, credentials, or
provider secrets.

Read-only routes do not require deployment configuration. An
installation can set their documented environment variables to `false` as an
emergency override. Complete and retain the migration/backfill attestation,
enable every required command route, and provision exactly one non-`full` key
with all required scopes before activating external directory ownership.
Configuration and activation recompute the current backfill attestation and
activation requires its current receipt digest to equal the release
attestation's `backfillDigest`. Once active, normal synchronized directory
changes may advance the projection beyond that activation snapshot; active
health instead requires an intact release proof and a complete current backfill
projection. A durable application-configuration sentinel keeps browser
directory writers blocked if the managed-directory policy schema or health read
is unavailable; only an explicit administrator takeover clears it. The
activation proof is prepared before its policy-lock transaction and the locked
policy snapshot is rechecked before ownership changes; this does not eliminate
the separate local source-write cutover race.

Unit contact changes use dedicated `assign`, `remove`, and `set-primary`
commands below `/api/v2/directory/units/{publicId}/contacts/`. Every command is
idempotent and requires the expected unit revision and authorization generation.
It also supplies an exact client external ID, public ID, and revision. The unit,
its parent organization, and the client must all have current active bindings
for the calling application, and the client must be active in the same
organization. Assignment accepts a non-empty role; set-primary atomically
clears any previous primary. Contact topology changes advance both the unit
revision and application authorization generation.
