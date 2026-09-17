# Custom integration administration

Project Alpha is provider-neutral open-source software. Deployment product names must not appear in its runtime settings UI or defaults.

The normal administrator experience intentionally has one surface:

- **Custom integrations** contains the external application connection, explicit Project Alpha account access, and synchronization health.
- **Links & Storage → Managed Delivery Service** delegates delivery intents through the same External Operations signed endpoint. That service owns object storage and client-facing links. Project Alpha never receives its object-storage credentials, and there is no second receiver profile or signer to configure.
- Direct Dropbox, Google Drive, S3, and R2 adapters remain available to standalone installations.

Portal projection profiles, workspace/principal administration, allowlists, runtime gates, recovery actions, and viewer-sharing authority are not rendered as normal Settings controls. They remain internal compatibility contracts for connected deployments and automation. Existing database contracts, handler action names, stable capability identifiers, explicit profile/workspace allowlists, deny precedence, immutable signing epochs, retries, tenant isolation, and default-off gates remain compatibility requirements.

Hiding those technical controls does not collapse their authorization boundaries into the visible account-access directory. The directory manages only the configured application's explicit Project Alpha account entitlement. Client eligibility, verified external identity binding, scoped portal authority, and public-link authority remain separate backend decisions.

New runtime UI must pass the repository branding and hidden-surface guards in `tests/frontend/portal-authority-responsive.test.js`. Deployment-specific names belong in administrator-supplied display labels or deployment documentation, never Project Alpha defaults.
