# API v2 financial document reads

This read-only projection lets an application display eligible financial documents for one explicitly bound customer or project. It does not create documents, send notifications, accept payments, sign contracts, or create public links.

## Deployment gate

- Apply normal database migrations, including `0107_financial_document_public_ids.sql`, before enabling this endpoint.
- `APP_API_V2_FINANCIAL_DOCUMENTS_ENABLED` defaults to `false`. Disabled requests return 404.
- Use a bound application API key explicitly granted both `api.capabilities.read` and `financial.documents.read`. Legacy `full` access is not accepted.
- Verify application/source/history identity, current binding revision and projection, and recipient authorization in the consuming application. A successful application API read does not itself authorize a portal recipient.

## Request

`GET /api/v2/financial-documents?selectorType=client&externalId=<application-external-id>&limit=50`

- `selectorType`: `client`, `organization`, or `project`.
- `externalId`: the exact application binding's external ID, not a name or internal numeric database ID.
- `limit`: 1–100; defaults to 50.
- `cursor`: the opaque continuation returned by the previous page. Keep the selector unchanged.
- Send the bearer key and `X-PA-Source-Instance-ID`, `X-PA-Application-ID`, and `X-PA-History-Epoch` headers.
- Unknown query fields, array-valued query parameters, and malformed cursors are rejected.

The response identifies the source, application and history epoch; repeats the exact selector with revision, projection hash and authorization generation; and returns a snapshot hash, document count, documents and optional next cursor. Document IDs are stable non-enumerable public IDs. Monetary values are decimal strings, never floating-point calculations in the client. Responses are JSON, `Cache-Control: no-store`, and carry `X-Request-ID`; the route does not open an interactive session.

## Visibility and links

- Draft documents are excluded. Eligible invoice statuses are sent, unpaid, partial, paid and overdue; eligible contract statuses are pending, active, paused and completed.
- Standalone invoice projection excludes invoices collected through a project invoice, preventing duplicate presentation of the same charge.
- An action/document URL is returned only when an eligible, existing, unrevoked, unexpired public link exists and the document's lifecycle permits it. Reads never generate a missing link.
- Links use the configured application host, not a caller-supplied host. Treat returned URLs as private bearer information: do not log them or expose them to unauthorized recipients.
- Project selectors require exact customer ownership. Organization selectors validate the document owner's current organization relationship.
- Financial changes can invalidate pagination. A stale snapshot or stale binding returns 409; restart from the first page after resolving the current state rather than merging inconsistent pages.

The projection refuses more than 10,000 eligible documents and the controller caps the serialized response at 128 KiB. Consumers should also enforce their own response bounds and validate the complete schema and selector before display.

## Migration and validation

Migration 0107 adds immutable public identities and recorded currencies to invoices, contracts and project invoices. Backfill preserves existing document timestamps, totals, balances and public-link rows. Current-revision pricing snapshots supply currency where available; legacy rows otherwise record the application's historical USD payment behavior. New pricing snapshots update the recorded invoice/contract currency. The migration grants no API scope and enables no route.

Local checks:

- `php vendor/bin/phpunit tests/Workflows/ApiV2FinancialProjectionFoundationTest.php`
- `node scripts/run-api-v2-financial-http-mysql.mjs` starts a new disposable loopback-only MySQL fixture and a local PHP server, tests the real HTTP/authentication path, and removes only that generated fixture afterward. Requires Docker, PHP and locked Composer dependencies.
- The minimal HTTP fixture does not substitute for a full database bootstrap/upgrade rehearsal or live application/recipient revocation acceptance.

Stage the migration and scoped read first. Verify positive and negative recipient access, existing-link preservation, same-session revocation, and notification ownership before any production enablement.
