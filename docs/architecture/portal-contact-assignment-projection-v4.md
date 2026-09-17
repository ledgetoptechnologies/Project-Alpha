# Portal contact-assignment projection v4

Schema v4 extends the existing portal snapshot/event stream with one
`contactAssignments` family. It is an informational directory projection. It
does not create or imply a login identity, principal, identity-provider binding,
workspace membership, entitlement, content grant, invoice recipient, or
notification recipient.

## Activation

The producer is controlled by the persistent External Operations profile field
`contact_assignment_projection_enabled`. Migration 0082 creates the field with
a database default of `0`. It may be enabled only when both portal projection
and schema-v3 relation projection are active. Enabling or disabling it queues a
complete replacement generation for every active profile/workspace link; an
incremental event is never used to cross schema versions.

Keep the option off until the receiver's schema-v4 migrations and capability
gate are deployed and verified. Existing profiles therefore continue to emit
schema v2 or v3 without a payload-shape change after migration.

## Authoritative sources

Only two explicit Project Alpha associations are projected:

- `organization_department_contacts` becomes a department-scoped assignment.
  `role` and `is_primary` are preserved. Project-billing flags are always false.
- `project_clients` becomes a project-scoped assignment. `role`,
  `is_primary_billing`, `send_project_invoices`, and
  `can_view_invoice_links` are preserved. It is not inferred from the project's
  primary client field. Primary billing ownership and invoice-email delivery
  are independent: `primaryBilling: true` with `sendProjectInvoices: false` is
  valid and must be preserved exactly.

Organization ownership, a matching name/email, portal eligibility, public
links, identities, entitlements, manual invoice recipients, and general billing
contacts are not assignment sources. Organization workspaces fail closed if an
explicit association crosses the workspace organization. Standalone-client
workspaces publish only that client's explicit assignment to projects whose
lineage belongs to the standalone root.

Contacts remain ordinary schema-v2/v3 `entities` with `type: contact` and use
the durable `portal_v2_contacts.public_id`. No parallel top-level `contacts`
family exists. Assignment IDs and their companion relation IDs are opaque
SHA-256-derived identifiers over the exact scope type, scope public ID, and
contact public ID. Source versions hash the complete client-visible record, so
role, primary, billing, invoice-delivery, invoice-link, active, or display-name
changes cannot reuse an old version.

## Delivery and recovery

Each schema-v4 page contains exactly the schema-v3 families plus
`contactAssignments`. The existing 100-record page, 100-page, and 2,000-record
generation bounds include every family. The canonical snapshot hash and
`recordCount` also include contact assignments. The activation record repeats
the same hash, page count, and record count.

The first assignment for a contact/scope and every assignment removal changes
receiver topology. Those mutations publish a complete replacement generation,
never a partial sequence of endpoint, relation, and assignment events. Updates
to an already-established assignment (for example its role or billing flags)
remain incremental events because all referenced endpoints already belong to
the active generation. A complete snapshot is the recovery authority after any
gap, rejected generation, topology mutation, capability transition, or receiver
rebuild.

When schema v4 is disabled, Project Alpha first supersedes every unclaimed,
normal v4 portal delivery—including a receiver-rejected row—then queues the v3
replacement generation. A currently claimed row and every revocation remain
untouched; their existing ordering barrier completes before the replacement is
eligible for delivery.

The golden wire examples are in
`tests/fixtures/project-alpha-portal-contact-assignments-v4.json`.

## Mutation hooks

The existing authoritative project create/update and department-contact
controllers already call `PortalProjectionMutationService` inside their database
transaction. Schema v4 consumes those same hooks. A projection, checkpoint, or
outbox failure rolls back the source mutation; no controller or onboarding flow
is replaced. Tests preserve this transaction-boundary contract so later client
onboarding, approval, project, and document work cannot silently bypass it.
