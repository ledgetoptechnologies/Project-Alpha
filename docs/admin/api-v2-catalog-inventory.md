# API v2 catalog inventory

`GET /api/v2/catalog/inventory?limit=100&cursor=...` is a generic, read-only
full-snapshot catalog feed. It does not use portal integration profiles,
workspaces, routes, outboxes, or projection state. The API key must be bound to
an API v2 application and have the explicit `api.capabilities.read` and
`catalog.inventory.read` grants needed by the handshake and route. The legacy
`full` scope is rejected. Requests carry the matching `X-PA-Source-Instance-ID`,
`X-PA-Application-ID`, and `X-PA-History-Epoch`.

The endpoint returns current active, externally requestable items in stable
`publicId` order. `limit` is 1 through 200; there is no installation-size
truncation or hidden total-count ceiling. Each item's `version` is the SHA-256 of its emitted canonical
content. The top-level `snapshotId` is the SHA-256 of the ordered `publicId` and
`version` pairs, and `totalCount` is pinned in the opaque cursor.

Before enabling the route, audit every active externally requestable item for a
unique 32-character lowercase hexadecimal `publicId` and valid client-safe
catalog fields. Existing rows without that identity are not synthesized or
skipped: the endpoint fails closed with `503`, so the operator must repair the
canonical catalog record before cutover.
Run a staging inventory dry run over the complete production-shaped catalog and
finish every page with one unchanged fingerprint before enabling consumers.

Every page recomputes the complete aggregate inside its database read
transaction. If an insert, edit, deactivation, or deletion changes the catalog
between pages, the next request returns `409` with
`error.code=catalog_snapshot_changed`. The consumer discards that scan and
restarts without a cursor. Only after all pages have matching `snapshotId` and
`totalCount` may it atomically replace its prior snapshot; IDs absent from that
completed snapshot are removals. This is not an incremental event feed and does
not claim that an insertion-only watermark protects in-place edits or deletes.

The envelope contains `apiVersion`, the three API-v2 identity values,
`requestId`, `snapshotId`, `totalCount`, `items`, and `nextCursor`. Items contain
`publicId`, `version`, `name`, nullable `summary`, `category`, `displayOrder`,
`geometryRequirement`, and `questions`. Consumers must not send this response
to a legacy portal projection parser. The route is default-off and is exposed
only when `APP_API_V2_CATALOG_INVENTORY_ENABLED=true` is set for an approved
cutover.
