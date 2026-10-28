# Project Alpha

Project Alpha is a self-hosted business operations application for managing clients, quotes, contracts, invoices, payments, expenses, receipts, mileage, and supporting documents.

It is developed by [Ledge Top Technologies LLC](https://ledgetoptechnologies.com/) and is currently used in production by Ledge Top Technologies and Ledge Top Drone Services. The project is stable enough for day-to-day internal use, but it is still evolving; bugs and feature requests are tracked publicly in [GitHub Issues](https://github.com/ledgetoptechnologies/Project-Alpha/issues).

> Project Alpha is business software, not accounting, tax, or legal advice. Review your own operational and regulatory requirements before relying on it for third-party hosting.

## Highlights

- Regular, long-term, and on-demand quotes and contracts
- Quote-to-contract and contract-to-invoice workflows
- Scheduled recurring invoices with downtime catch-up
- Manual on-demand invoice generation
- Fixed-price and hourly billing
- Stripe Checkout, Payment Intents, webhooks, and reconciliation
- Offline payment recording and partial-payment tracking
- Tokenized public links for document review, signing, and payment
- Clients, organizations, projects, and automatically generated job codes
- Item library, discounts, deposits, taxes, and custom document fields
- Expenses, receipts, vendors, mileage, forms, and financial reporting
- Role-based permissions, per-user overrides, optional TOTP with dismissible reminders, and audit logs
- Built-in workforce, server-authoritative timekeeping, approvals, and employee-pay modules
- Docker Compose deployment with migrate, web, worker, cron, and MySQL services
- Prebuilt images published through GitHub Container Registry

## Document Workflow

Project Alpha supports three primary document families:

| Family | Intended use | Invoice behavior |
|---|---|---|
| Regular | One-time work | A regular invoice is normally created with the contract |
| Long-term | Recurring services or retainers | Invoices are generated from the active contract schedule |
| On-demand | Work billed only when needed | Invoices are generated manually from the active contract |

The usual regular workflow is:

```text
Quote -> Contract -> Invoice -> Payment
```

Long-term and on-demand workflows branch after contract activation. See [Document Workflow](docs/DOCUMENT_WORKFLOW.md) for statuses, automatic actions, public-link behavior, and examples.

### Service rates and renewal changes

Quote and contract creation separates **Agreement** (one-time work or an ongoing
service agreement) from **Invoicing** (recurring or as needed). These choices use
the existing document families; upgrading does not convert saved documents.

For recurring services, **Monthly service rate** calculates one month or twelve
months per invoice. For example, a $100.01 monthly rate produces a $1,200.12 annual
subtotal. **Amount per invoice** retains the existing behavior for custom billing
periods. Tax and discount rules remain separate from the rate calculation.

On an ongoing recurring agreement, use **Services & billing schedule** to propose
a change for the next renewal. The current service continues until the change is
approved and its renewal arrives. A client billed annually who requests monthly
billing halfway through the year keeps that annual coverage; the first monthly
charge starts after it ends. This boundary protects already-invoiced periods even
when payment collection is delayed. It does not automatically refund or prorate an
existing invoice. Outstanding overdue billing must be resolved before changing
the renewal schedule.

Rate-only changes preserve calendar anniversaries, including month-end and leap-day
renewals. A new monthly-rate service starts its full billing period on its first
invoice date. A full monthly or annual charge must fit within the service end date;
choose a shorter frequency or revise the agreed end date when it does not.
If a later final period is too short, billing stops before charging it. Resolve
that period before renewal; once overdue, end the service and add the remaining
period separately rather than rewriting its previously billed coverage.

Signed agreement terms and previous invoices remain unchanged. New recurring
invoices show their service coverage and monthly-rate calculation. Pending and
approved changes can be cancelled before they take effect. Draft initial terms
are edited on the draft agreement; ended services require a new service period.
Fixed-total installments and existing on-demand generation retain their separate
behavior. Bank AutoPay is not enabled by this feature.

Migration `0111_recurring_service_schedule_versions.sql` is additive. Existing
rates default to **Amount per invoice**, with no inferred monthly rate, historical
coverage backfill, or queued change. Apply migrations before updating web and cron,
and retain each deployment's existing cron execution profile.

## Current Scope

Project Alpha currently targets a self-hosted deployment operated for one business. The schema includes organizations and organization-aware permissions, but the application is not yet offered as a fully isolated, hosted multi-tenant service.

Current boundaries include:

- Public tokenized document links instead of persistent client accounts
- Responsive web UI, but no native mobile application or installable PWA
- Operational income and expense reporting, but not a complete general ledger
- Stripe is implemented; other payment processors are not yet available
- Deployment, backups, email, and payment credentials remain the operator's responsibility

## Quick Start

### Requirements

- Docker Engine with Docker Compose v2
- A host that can pull public images from `ghcr.io`
- Ports and reverse-proxy configuration appropriate for your environment

### Start the published images

1. Clone the repository:

   ```bash
   git clone https://github.com/ledgetoptechnologies/Project-Alpha.git
   cd Project-Alpha
   ```

2. In `docker-compose.yml`, replace both database `changeme` passwords:

   - `MYSQL_PASSWORD`
   - `MYSQL_ROOT_PASSWORD`

   The canonical Compose file is self-contained; no separate `.env` file is
   required. Application startup initializes its persistent runtime configuration
   in the named config volume. `config/.env.example` is an optional reference for
   custom overrides, not an installation step. Published `latest`, `cron-latest`,
   and `db-latest` image tags remain defined in Compose.

3. Pull and start the stack:

   ```bash
   docker compose pull
   docker compose up -d
   ```

4. Open <http://localhost:1627>. On a clean database, PA displays its first-time setup screen. Create the initial administrator with your own email, username, and password; PA has no default administrator credential.

5. Complete the application settings:

   - **System**: organization identity, application URL, timezone, and sender profile
   - **Billing**: Stripe keys, net terms, payment methods, and surcharge preferences
   - **Email**: SMTP connection and sender information
   - **Documents**: terms, custom fields, automatic document creation, and link lifetime
   - **Notifications**: cron and invoice-email options

The one-shot `migrate` service initializes and validates the database before web or cron can start. Project Alpha 0.5.0 is a destructive database reset and cannot upgrade a 0.4.x database in place; follow the [0.5.0 reset runbook](docs/0.5.0-database-reset.md).

### Administrator recovery

If an active administrator loses their password and email reset is unavailable, run this from the Compose project directory:

```bash
docker compose exec web php bin/admin-recovery.php admin@example.com
```

The identifier may be the administrator username or email. PA prints one temporary password, revokes existing sessions and reset codes, and forces a password change at the next login. It refuses non-admin, inactive, ambiguous, or unknown accounts.

Reset TOTP only when it is also lost:

```bash
docker compose exec web php bin/admin-recovery.php admin@example.com --reset-totp
```

That explicit option removes the existing TOTP enrollment and requires a new enrollment immediately after the temporary password is changed. Both recovery operations are audited.

### Staging

Staging definitions are intentionally host-owned rather than tracked. When a
staging stack is needed, copy `docker-compose.yml` to the staging host and edit
that copy to use the `:dev` and `:cron` images, port `1628`, `APP_ENV: staging`,
and staging-only passwords. Deploy it from a separate directory so its named
volumes cannot collide with production.

## Local Development

Build the current checkout using the same tags referenced by the canonical
Compose file, then start it normally:

```bash
docker build --target test -t ghcr.io/ledgetoptechnologies/project-alpha:latest .
docker build --target cron -t ghcr.io/ledgetoptechnologies/project-alpha:cron-latest .
docker compose up -d
```

Running `docker compose pull` restores the published images afterward.

Useful checks:

```bash
composer install
composer test
docker compose run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

The production images use PHP 8.5. Composer currently targets PHP 8.3.31 for dependency resolution and permits PHP 8.1 or newer.

## Architecture

| Layer | Implementation |
|---|---|
| Web runtime | PHP 8.5 with Apache |
| Application | Custom PHP routing and controllers; no full-stack framework |
| Database | MySQL 8 with PDO |
| Templates | PHP views plus Twig components |
| Frontend | Server-rendered HTML, CSS, and vanilla JavaScript |
| PDFs | Dompdf |
| Payments | Stripe Checkout, Payment Intents, webhooks, and reconciliation |
| Background work | Dedicated worker and cron containers |
| Deployment | Multi-stage Docker image and Docker Compose |

All HTTP requests enter through `public/index.php`. Application source is under `src/`, the immutable fresh-install schema is `database/baseline.sql`, and sequential forward migrations live under `database/migrations/`.

### Payment and invoice notification boundaries

| Module | Responsibility |
|---|---|
| `src/utils/invoice_notification_queue.php` | Shared queue claims, stale-claim recovery, retries, delivery state, and reminder stages for ordinary and project invoices |
| `src/utils/invoice_notifications.php` and `project_invoice_notifications.php` | Document-specific eligibility, recipients, rendering, and delivery orchestration |
| `src/utils/stripe_payment_settlement.php` | PaymentIntent accounting used by verified webhook and AutoPay recovery paths |
| `src/utils/stripe_payment_notifications.php` | Post-commit administrator notices and payer receipts, with stable delivery keys and retry behavior |
| `src/controllers/webhook/stripe_payment_succeeded.php` | Compatibility adapter retaining the existing webhook handler entry point |

Keep provider verification at the entry points and payment transactions in the settlement layer. Invoke payment email delivery after the accounting commit. Queue helpers accept only the two supported invoice queue types; recipient policy stays in the document-specific workers. AutoPay outboxes retain their separate authorization, lease-token, and receipt rules.

Checkout and reconciliation retain their distinct invoice lookup, repair, and transaction rules. Share common operations through these modules rather than copying them or routing background work through an HTTP controller. Existing adapters preserve legacy configuration and function signatures; changing those contracts requires separate behavioral tests.

## Scheduled Operations

The cron image runs recurring invoice generation, backups, contract expiration, public-link expiration, audit schedules, invoice reminders, Stripe reconciliation, and merchant-rate synchronization. AutoPay is an unavailable beta foundation and is not scheduled or exposed.

See [Cron Service](cron/README.md) for the installed schedule and [Recurring Invoices](docs/RECURRING_INVOICES_SETUP.md) for billing behavior.

## Security and Operations

Project Alpha includes application-level controls such as CSRF validation, prepared database access, rate limiting, optional TOTP with stronger recommendations for privileged users, role-based authorization, encrypted storage for configured secrets, audit logging, security headers, and webhook signature validation when a Stripe webhook secret is configured.

Operators remain responsible for:

- HTTPS and reverse-proxy configuration
- Unique passwords and credential rotation
- Protecting the persistent encryption key and backup volume
- Restricting network access to MySQL
- Testing migrations and restoring backups
- Keeping container images and dependencies current
- Reviewing application logs without committing credentials or customer data

Report security vulnerabilities privately as described in [Security Policy](docs/SECURITY.md).

## Repository Workflow

- Report reproducible bugs through [GitHub Issues](https://github.com/ledgetoptechnologies/Project-Alpha/issues).
- Never include credentials, payment data, or identifiable customer information in an issue.
- `main` is protected and requires a pull request with passing checks.
- `dev` publishes the staging images; `main` publishes the production images.
- Testing should be proportional to the change, with browser verification for user-facing workflows.

See [Contributing](CONTRIBUTING.md) for issue and pull-request guidance.

## Documentation

Start with the [Documentation Index](docs/README.md).

Key guides:

- [Document Workflow](docs/DOCUMENT_WORKFLOW.md)
- [Deployment](docs/admin/deployment.md)
- [Stripe Webhook Setup](docs/stripe-webhook-setup.md)
- [Workforce Modules](docs/admin/workforce-modules.md)
- [Migration Safety](docs/MIGRATION_SAFETY.md)
- [Cron Service](cron/README.md)
- [Database Migrations](database/migrations/README.md)
- [Developer and Agent Guidance](docs/AGENTS.md)

## License

The repository currently contains a legacy Project Alpha license in [LICENSE.md](LICENSE.md). A transition to GNU AGPLv3 is planned after contributor relicensing permission is documented. Until the license file is formally replaced, its current terms control use of the project.

Copyright Ledge Top Technologies LLC.
