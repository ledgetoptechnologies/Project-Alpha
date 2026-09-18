# API v2 directory lifecycle and relationships

This surface is generic, stateless, default-off, and available only to an
application-bound API key with each route's explicit scope. The legacy `full`
scope never authorizes these routes. Every request also carries the provisioned
source-instance, application, and history-epoch headers.

Lifecycle commands use:

- `POST /api/v2/directory/clients/{publicId}/archive/commands`
- `POST /api/v2/directory/clients/{publicId}/restore/commands`
- `POST /api/v2/directory/organizations/{publicId}/archive/commands`
- `POST /api/v2/directory/organizations/{publicId}/restore/commands`

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
`POST /api/v2/directory/{clients|organizations}/bindings/revoke/commands`.
Revoked external IDs remain tombstoned and are not silently reused.

`GET /api/v2/directory/inventory` returns a bounded, snapshot-consistent list of
current resource revisions, retained tombstones, projection hashes, and only the
calling application's binding state. Use `type`, `limit`, and the returned
`nextCursor` for reconciliation. It returns no profile fields, credentials, or
provider secrets.

All flags in `config/.env.example` remain `false` by default. Complete and retain
the migration/backfill attestation, enable every required route, and provision
exactly one non-`full` key with all required scopes before activating external
directory ownership.
