# Project Alpha Document Workflow

This guide explains how quotes, contracts, invoices, payments, projects, public links, and scheduled jobs fit together in the current application.

## Workflow at a Glance

```text
Client
  |
  +-> Quote --approved--> Contract --signed/activated--> Work
                                      |
                                      +-> Regular invoice
                                      +-> Scheduled long-term invoices
                                      +-> Manually generated on-demand invoices
                                                    |
                                                    v
                                                  Payment
```

Documents may also be created directly. A quote is helpful for traceability, but it is not required before creating a contract or invoice.

## Shared Concepts

### Clients

Every quote, contract, and invoice belongs to a client. Clients may also belong to an organization.

### Projects and job codes

- A **Project** is a manually managed parent record for grouping work.
- A **job code** is the `project_code` copied across related quotes, contracts, and invoices.

When a quote does not already have a job code, approval generates one and carries it into derived documents.

### Document families

All document families use the same three tables: `quotes`, `contracts`, and `invoices`. Type columns distinguish the workflow:

| Family | Quote value | Contract value | Invoice value |
|---|---|---|---|
| Regular | `regular` | `regular` | `regular` |
| Long-term | `long_term` | `long_term` | `long_term` |
| On-demand | `on_demand` | `on_demand` | `on_demand` |

## Quotes

Quotes propose a scope and price before work is placed under contract.

Primary statuses:

```text
draft -> pending -> approved
                 -> rejected
                 -> denied
                 -> expired
```

- Only a `pending` quote can be approved or rejected by the normal actions.
- Approved and rejected quotes become historical records rather than editable working drafts.
- A rejected quote can be re-enabled, returning it to `pending` and refreshing its document date.

### Approval from the authenticated application

The **Documents > Quotes** settings control automatic creation:

- `quote_auto_create_contract` creates a pending contract.
- `quote_auto_create_invoice` creates a private draft regular invoice.

Both settings default to enabled. Long-term and on-demand quote approval creates a contract but does not create the first invoice; those invoice workflows begin after contract activation.

### Approval from a public link

A valid quote link allows the client to approve or deny a pending quote. Approval creates a pending contract. A regular quote also creates a private draft invoice; long-term and on-demand quotes wait for their contract-specific invoice workflow.

## Regular Contracts

A regular contract represents one-time work.

Typical flow:

```text
pending --signed PDF--> active --complete--> completed
   |                       |
   +------deny------------> denied
   +------void------------> cancelled
```

- Creating a regular contract directly creates a related draft invoice.
- Uploading a signed PDF through the authenticated application activates a regular contract.
- A client may upload a signed document through a valid public link.
- Completing the contract finalizes the newest related draft invoice, applies configured net terms, and emails it when workflow delivery is enabled.
- Voiding a contract sets it to `cancelled`, voids related invoices, unlinks billed time entries, and revokes invoice links.
- Re-enabling a cancelled, denied, or void contract returns it to `pending` and restores voided invoices to `unpaid`.

## Long-Term Contracts

Long-term contracts model recurring services.

Typical flow:

```text
pending --signed document + Activate--> active <--> paused
                                             |
                                             +--> recurring invoices
                                             +--> completed/cancelled
```

Activation requires a signed document. Uploading the document from the authenticated application records it, after which the operator explicitly activates the contract. A signed upload accepted through the current public-link route activates the contract as part of that client action. Activation sets `next_invoice_date` from the existing value or the contract start date.

If the contract is already due when activated, Project Alpha attempts to generate the first invoice immediately. The daily cron job then generates later invoices from:

- `next_invoice_date`
- `billing_interval_count`
- `billing_interval_unit`
- pricing and invoice-generation settings on the contract

The generator is idempotent and performs up to 36 catch-up passes so a deployment returning after downtime can create missed billing periods without running forever.

An eligible long-term contract must be `active`, have a signed document, and have a due `next_invoice_date`.

## On-Demand Contracts

On-demand contracts cover work billed only when requested.

Typical flow:

```text
pending --signed document + Activate--> active
                                             |
                                             +--Generate invoice--> on-demand invoice
                                             +--Generate invoice--> on-demand invoice
```

- Activation requires a signed document. Internal upload and activation are separate actions; the current public signing route activates the contract when it accepts the signed upload.
- No scheduled invoices are created.
- An operator generates each invoice from the contract when billable work exists.
- Invoice generation may use set amounts, itemized content, or a general write-up depending on contract configuration.
- **Generate Only** creates a private editable draft. **Generate and Send** finalizes the invoice before creating its public link and notification.

## Invoices and Payments

Primary invoice statuses:

```text
draft -> sent/unpaid -> partial -> paid
                  |        |
                  +------> overdue
                  +------> void
```

- Recording a payment creates a payment record and recalculates the invoice balance.
- A partially covered invoice becomes `partial`; a fully covered invoice becomes `paid`.
- Stripe Checkout and Payment Intent webhooks perform the same reconciliation for online payments.
- Fully paying an invoice revokes its public payment links and may complete its linked contract.
- The **Mark Paid** action opens the payment-entry screen with the remaining balance; it does not bypass payment recording.
- A void invoice can be re-enabled as `unpaid`.
- Draft invoices cannot be emailed, shared, reminded, paid, or included in public payment links.
- A finalized unpaid invoice must be explicitly reopened before editing; reopening revokes its public links.
- Online payments are one-time Stripe Checkout payments. AutoPay is unavailable.

## Public Links

Public links are random, expiring tokens that allow a client to interact without a persistent account.

| Document | Available interaction |
|---|---|
| Quote | View, download, approve, or deny while pending |
| Contract | View, download, and submit a signed document while eligible |
| Invoice | View, download, and start Stripe Checkout while payable |
| Project invoice | View, download, and pay the aggregate balance; payment is allocated to child invoices |

Links can expire or be revoked. Paying an invoice or voiding related documents may revoke active links. Configure the public application URL and document-link lifetime before emailing links.

## Scheduled Follow-Up

The cron service handles:

- Long-term invoice generation
- Seven-day due reminders and weekly overdue reminders when enabled
- Stripe reconciliation after missed webhooks or downtime
- Contract and public-link expiration
- Scheduled financial audits
- Rotating database-only or full backups; full backups require environment-key encryption

See [Cron Service](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/cron/README.md) for the installed schedule, which uses the configured Project Alpha timezone.

## Operational Checklist

Before using document automation:

1. Configure company identity, sender profile, timezone, and public application URL.
2. Configure SMTP and send a test email.
3. Configure Stripe in test mode and verify webhook delivery.
4. Review quote auto-creation and invoice-notification settings.
5. Create one regular, one long-term, and one on-demand test workflow.
6. Confirm public links work through the production hostname.
7. Confirm cron logs show successful invoice, reminder, reconciliation, and backup jobs.

Report behavior that differs from this guide through a public GitHub issue, using sanitized example data.
