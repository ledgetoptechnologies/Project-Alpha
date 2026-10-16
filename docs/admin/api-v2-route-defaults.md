# Tested Ops API v2 route defaults

The following tested routes are enabled when their process-environment flag is unset or empty. The dispatcher and capability advertisement use the same `api_v2_enabled()` policy. An explicit `false` or `0` disables an individual gate; malformed nonempty boolean values also disable it.

```text
APP_API_V2_DIRECTORY_CLIENTS_CREATE_ENABLED
APP_API_V2_DIRECTORY_ORGANIZATIONS_CREATE_ENABLED
APP_API_V2_DIRECTORY_CLIENTS_WRITE_ENABLED
APP_API_V2_DIRECTORY_ORGANIZATIONS_WRITE_ENABLED
APP_API_V2_DIRECTORY_RELATIONSHIPS_WRITE_ENABLED
APP_API_V2_DIRECTORY_BINDING_REFRESH_ENABLED
APP_API_V2_DIRECTORY_BINDING_REFRESH_RECONCILIATION_ENABLED
APP_API_V2_PROJECTS_CREATE_ENABLED
APP_API_V2_PROJECTS_WRITE_ENABLED
APP_API_V2_PROJECTS_COMPLETE_ENABLED
APP_API_V2_PROJECTS_CANCEL_ENABLED
APP_API_V2_PROJECTS_ARCHIVE_ENABLED
APP_API_V2_PROJECTS_RESTORE_ENABLED
APP_API_V2_PROJECTS_BINDING_REFRESH_ENABLED
APP_API_V2_DIRECTORY_BINDING_ENABLED
APP_API_V2_FINANCIAL_DOCUMENTS_ENABLED
APP_API_V2_DIRECTORY_CLIENTS_ARCHIVE_ENABLED
APP_API_V2_DIRECTORY_CLIENTS_RESTORE_ENABLED
APP_API_V2_DIRECTORY_ORGANIZATIONS_ARCHIVE_ENABLED
APP_API_V2_DIRECTORY_ORGANIZATIONS_RESTORE_ENABLED
APP_API_V2_DIRECTORY_BINDING_REVOKE_ENABLED
APP_API_V2_PROJECTS_BINDING_ENABLED
```

The existing six directory/project profile, binding-status and inventory read gates remain enabled by default. Other gates remain disabled by default, including separate unit profile/create/archive/restore/contact gates, catalog inventory and project adoption candidates. Shared directory binding/read/refresh/revoke routes retain their existing supported resource types and explicit per-type scopes.

Route availability grants no authority. Commands still require application-bound keys with explicit scopes, exact source/application/history identities, current revisions and authorization generations, and the existing conflict/idempotency checks. The financial-document endpoint remains read-only and requires `financial.documents.read`; it enables no payments, financial mutations, emails or client grants.

## Updating an existing installation

Normal database migrations and the existing scoped application keys are still required. The canonical Compose file omits API v2 gate overrides, and `.env.example` shows only commented optional `false` values for these defaults. The standard Compose installation needs no separate `.env` file; its service settings and named volumes are defined directly in `docker-compose.yml`.

Update the application image and restart its web service. If your existing deployment explicitly sets one of these flags to `false`, that deliberate override is preserved: remove that individual override or set it to `true` if you want the enabled default. A newer image cannot override an explicit deployment setting. Preserve all unrelated environment values and credentials.

API v2 dispatch runs before the interactive application's `.env` loader. Optional overrides must reach the web process environment; editing only `/var/www/config/.env` is not evidence that a stateless API gate changed. Do not introduce interactive sessions or load application database configuration just to resolve route defaults.

Verify `/api/v2/capabilities` with a scoped application key after an update. `implementedEndpoints` advertises enabled routes independently of that key's scopes; `grantedCapabilities` reports only its explicit grants. A capability response never replaces request authorization.

Command metadata also reports enforced existing-resource preconditions. Directory and Project binding/revoke routes that accept `expectedPublicId` advertise `requiresExpectedPublicId`; profile, lifecycle, relationship and refresh routes advertise their enforced revision preconditions as `requiresExpectedRevision`. Creation does not require an existing-resource revision. Directory refresh uses prior/live revision fields, and relationship/contact commands use their typed client/unit revision fields. Project commands retain their required `expectedProjectionSha256` checks; this is not advertised as the different `expectedProfileSha256` field. No `requiresUpdatePublicId` claim is made for commands that identify their target through the URL or an application binding.

Migration `0108_api_v2_directory_binding_generation_fence.sql` adds durable generation evidence for modern directory binding commands. The optional fifth request field, `expectedAuthorizationGeneration`, compares the current application generation atomically with binding creation and records the exact expected/result pair. A successful acknowledgement returns `result.authorizationGeneration`; replay returns the original stored result while preserving current resource, binding, key and identity checks. Historical four-field commands keep their original request/response contract, and historical receipts retain NULL generation evidence. They cannot be reused as modern five-field receipts.

Earlier migration and cutover documents describe the initial default-off release gates. This page defines the current runtime defaults; their identity, backfill and authorization prerequisites still apply.
