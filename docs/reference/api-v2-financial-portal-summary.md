# API v2 portal financial summary

`GET /api/v2/financial/summary` is a bounded, read-only portal integration
surface. It is disabled by default. Enable it only after provisioning a
dedicated API-v2-application-bound key with exactly
`api.capabilities.read,financial.portal_summary.read` (and any separately
required capability-discovery key policy). Legacy `full` access is rejected.

## Request

Supply exactly one of `clientExternalId`, `organizationExternalId`,
`projectExternalId`, or `projectPublicId`, which is
resolved only through that application's active API v2 directory binding.
`cursor` is an optional positive signed invoice row cursor (1 through
`2147483647`); `limit` is 1 through 100
(default 50). Unknown parameters, both selectors, and malformed bounds return
`400`. The source-instance, application, and history-epoch headers are all
required and must match the bound key's API-v2 application identity.

The organization selector includes only invoices whose organization and linked
customer both belong to that organization. The client selector includes only
invoices directly linked to that bound customer. Void and cancelled invoices
are not included.

`projectPublicId` is the portal-consumer selector: it is PA's canonical
32-character Project public ID, not the distinct Project-v2 external ID. It
resolves only through one binding for the authenticated application. The
response retains both `resource.publicId` and the resolved
`resource.externalId`, so consumers never need to assume they are equal.

Either Project selector returns an invoice only when its Project, customer, and
organization all exactly match the resolved Project's canonical relationships.
It additionally requires PA's `project_clients.can_view_invoice_links=1` for
that Project's canonical customer. This API has no per-person principal, so it
fails closed (404) for the entire Project selector when that grant is absent;
amounts, statuses, and public URLs are all withheld. Use the Project selector
for a portal member's per-Project view; it cannot enumerate another Project's
invoices or action URLs.

## Response and safety boundary

The response contains `returnedPageTotals` (totals of **only the invoices in
this cursor page**, not the whole customer, organization, or Project) and a
bounded invoice projection: document number, lifecycle status, total/paid/
balance values, due/document dates, plus `invoicePublicUrl` and
`paymentPublicUrl`. These URLs are returned only from an already-existing,
unrevoked, unexpired public-link record for that exact invoice. They are null
when no such client link is eligible; this GET never creates, refreshes,
revokes, or otherwise changes a link. The origin is derived solely from the
the same validated global `app_config.app_host` configuration used by PA's
existing public-link and invoice-email lifecycle, never from `APP_HOST`, a
request header, or portal input. It deliberately omits
invoice lines, notes, scope/custom fields, contract/project content, customer
details, payment records, processor identifiers, public link tokens, and all
payment secrets.

This is a GET-only read. It creates no command receipt and makes no invoice,
payment, binding, revision, public-link, or audit mutation; API-key admission
accounting remains the common authentication middleware's responsibility.
`invoicePublicUrl` opens PA's existing public invoice route; `paymentPublicUrl`
uses that same existing token at PA's existing public checkout route. Neither
field is a staff-only UI URL.

## Consumer schema

The stable response shape is:

```json
{
  "resource": {"type": "project", "externalId": "application-project-id", "publicId": "canonical-pa-project-id"},
  "returnedPageTotals": {"invoiceTotal": "150.00", "amountPaid": "30.00", "balanceDue": "120.00"},
  "invoices": [{
    "documentNumber": 101,
    "status": "partial",
    "total": "150.00",
    "amountPaid": "30.00",
    "balanceDue": "120.00",
    "dueDate": "2026-10-01",
    "documentDate": "2026-09-01",
    "invoicePublicUrl": "https://project-alpha.example/?page=public-doc&type=invoice&token=...",
    "paymentPublicUrl": "https://project-alpha.example/?page=stripe-checkout&token=..."
  }],
  "nextCursor": null
}
```

Amounts are decimal strings; clients must not assume a currency conversion or
sum them across pages. `invoicePublicUrl` and `paymentPublicUrl` are nullable.
For Project responses, all three field groups (the returned invoice summary,
public URLs, and page totals) require both the API capability and the PA
`project_clients.can_view_invoice_links` grant for the Project's canonical
client. `send_project_invoices` controls PA notification delivery and does not
grant this API read surface.

The route returns `404` while disabled and for an absent/tombstoned application
binding, `409` for identity/canonical consistency conflicts, `405` for a
non-GET request, and `503` when the required database state is unavailable.
