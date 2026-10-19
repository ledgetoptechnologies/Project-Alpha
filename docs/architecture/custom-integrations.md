# Custom integration administration

Project Alpha is provider-neutral open-source software. Deployment product names must not appear in its runtime settings UI or defaults.

The normal administrator experience intentionally has one surface:

- **Custom integrations** contains the external application connection, explicit Project Alpha account access, and synchronization health.
- **Links & Storage → Managed Delivery Service** delegates delivery intents through the same External Operations signed endpoint. That service owns object storage and client-facing links. Project Alpha never receives its object-storage credentials, and there is no second receiver profile or signer to configure.
- Direct Dropbox, Google Drive, S3, and R2 adapters remain available to standalone installations.

Portal projection profiles, workspace/principal administration, allowlists, runtime gates, recovery actions, and viewer-sharing authority are not rendered as normal Settings controls. They remain internal compatibility contracts for connected deployments and automation. Existing database contracts, handler action names, stable capability identifiers, explicit profile/workspace allowlists, deny precedence, immutable signing epochs, retries, tenant isolation, and default-off gates remain compatibility requirements.

Hiding those technical controls does not collapse their authorization boundaries into the visible account-access directory. The directory manages only the configured application's explicit Project Alpha account entitlement. Client eligibility, verified external identity binding, scoped portal authority, and public-link authority remain separate backend decisions.

New runtime UI must pass the repository branding and hidden-surface guards in `tests/frontend/portal-authority-responsive.test.js`. Deployment-specific names belong in administrator-supplied display labels or deployment documentation, never Project Alpha defaults.


## Internal division retirement: compatibility transition

The internal division setup is retired from the normal project workflow. New
projects carry no internal division assignment; editing existing projects retains
their historical ID. Customer `organization_departments` and directory unit APIs
are customer structures and are not internal divisions. They remain supported.
PA's existing installation roles and document ownership continue to govern its
interactive records. Owner/staff company access and member creator-only scoping
are unchanged. Selecting a project manager still records explicit project team
membership; it never grants every staff member access through a shared division.

The following external-contract transition is a reviewed implementation plan,
not an enabled new authorization mode:

1. Consumers must accept null/deprecated `business_unit_id` on newly created
   project/work planning projections, without dropping the record or treating
   null as a wildcard grant.
2. Keep application entitlement enablement, exact application identity, role,
   source binding, and explicit project/team authority. New company-level
   authority must be represented by an explicit installation-role or application
   entitlement decision. It must never be inferred from missing division IDs,
   directory presence, matching company names, or existing client portal access.
3. Before removing old fields from a contract, version the projection and prove
   the replacement preserves both positive and negative access outcomes for
   legacy scoped users, newly unscoped projects, revoked users, and assignments
   from another source. Existing public document links must remain unchanged.
4. Retain historical IDs and projection fields until every deployed consumer
   has acknowledged the replacement contract. Do not clear memberships, remove
   scope rows, broaden roles, or mass-reassign projects as a compatibility fix.

This source change removes the user-facing project selector/default dependency.
It does not claim that every historical external authority contract has already
been removed. Physical table/field deletion is a later migration after the
consumer acceptance evidence exists.
