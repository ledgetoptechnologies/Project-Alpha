# Project Alpha Cron Service

The cron image runs Project Alpha's scheduled PHP jobs independently from Apache. The current schedule is installed from `cron/crontab` and uses the PA system timezone loaded from `app_config.timezone` when the cron container starts.

On container startup, the entrypoint runs a scheduled backup catch-up check and `stripe_reconciliation.php --startup` before starting cron. The backup check creates today's missing backup when the configured backup time has already passed. Stripe reconciliation catches recent payments after downtime, while the normal six-hour schedule continues to reconcile missed webhooks.

## Portal-only staging profile

Use `command: ["entrypoint.sh", "portal-only"]` to schedule only
`reconcile_client_portal.php` and `send_portal_projection_outbox.php`, once per
minute. This profile skips the startup backup and Stripe reconciliation and
installs no financial, email, backup, or other jobs. It uses the existing cron
daemon, database settings, shared configuration volume, and non-generating
encryption key loader. A missing or mismatched persisted key stops startup.
The portal producer and delivery settings must still be enabled and configured;
the profile does not change application permissions or configuration.

With the published Compose installation (after setting its normal inline
database credentials), the included optional override selects this profile:

```sh
docker compose -f docker-compose.yml -f docker-compose.portal-only.yml up -d cron
```

The override keeps the normal `latest` and `cron-latest` tags and needs no new
environment file. An existing staging stack with only `db` and `web` must first
add the published `cron` service with the same database settings and shared
configuration volume. No argument, or explicit `full`, retains the full
production schedule and startup jobs. Unknown profiles or extra arguments fail
before key loading or job execution. Pull a cron image containing this profile
before selecting it.

## Installed Schedule

AutoPay collection is disabled in production. Staging acceptance requires migrations
112–115, `APP_ENV=staging`, `AUTOPAY_BETA_ENABLED=true`, a unique persistent
`AUTOPAY_INSTALLATION_ID`, the matching `AUTOPAY_STRIPE_ACCOUNT_ID`, Stripe test
credentials, and verified webhook forwarding. Use synthetic clients and a mail
catcher. Never copy production payment authorizations into staging.

The worker only processes reservations created when an authorized invoice is first
finalized; it never scans old unpaid invoices or imports legacy AutoPay flags.
Drafts, historical paid periods, and refunds do not authorize another debit.
Pending bank debits remain unpaid until Stripe confirms success. Unknown outcomes
remain reserved for reconciliation; do not clear them to retry payment manually.
The legacy `auto_charge_recurring.php` entry point delegates to this worker and
cannot run its previous unpaid-invoice scanning logic.

| Local schedule | Script | Purpose |
|---|---|---|
| Every minute | `process_notification_relay.php` | Process the disabled-by-default internal notification relay queue |
| Every minute | `send_portal_projection_outbox.php` | Deliver enabled, signed portal projection outbox records with bounded retries |
| Every minute | `reconcile_client_portal.php` | Resume the bounded historical client-portal provisioning backfill after producer preflight is ready |
| Daily 02:00 | `generate_recurring_invoices.php` | Generate due long-term invoices and catch up missed periods |
| Every minute | `process_autopay_collections.php` | Process explicit AutoPay reservations, only in configured staging/test mode |
| Daily 02:15 | `generate_recurring_expenses.php` | Generate due recurring expenses once per scheduled occurrence |
| Daily 02:30 | `purge_mileage_tracking_points.php` | Delete finalized GPS route points after 90 days and discarded points immediately |
| Hourly at :30 | `backup_database.php --scheduled` | Creates rotating backups when the configured local backup hour matches |
| Daily 03:00 | `auto_terminate_contracts.php` | Complete contracts whose configured end date has passed |
| Daily 04:00 | `link_expiration_checker.php` | Expire public document links |
| Daily 04:15 | `daily_link_resolver.php` | Scan enabled providers for organization, department, and standalone-client folders when Daily folder scan is enabled |
| Every 6 hours | `stripe_reconciliation.php` | Recover Stripe payments missed by webhooks or downtime |
| Daily 05:00 | `sync_merchant_rate.php` | Calculate the observed Stripe processing rate |
| Daily 06:00 | `process_audit_schedules.php` | Generate and deliver scheduled audit exports |
| Daily 08:00 | `send_invoice_reminders.php` | Send enabled due and overdue reminders |
| Every 5 minutes | `process_invoice_notifications.php` | Deliver pending invoice and project invoice emails and due retries, up to 100 rows per queue |
| Paused in code | `process_workforce_deadlines.php` | Workforce reminders and automatic confirmation are shelved; the crontab entry is commented out and direct invocation exits without database access |

All output is appended to `/var/www/config/logs/cron/cron.log` in the cron container. A repository-owned, non-root sweep runs once per minute, rotating `.log` and `.txt` files at 10 MiB. Rotation uses rename-and-recreate so open writers finish safely; quiescent archives are integrity-checked, compressed, and retained according to the deployment policy.

If the PA system timezone is changed in Settings, restart or recreate the cron container so the daemon reloads `/etc/localtime`. Individual PHP jobs also load `config/app.php`, so their date math uses the configured timezone.

## Runtime

Invoice reminder occurrences are created by the daily 08:00 job. The five-minute delivery worker drains existing queues without creating reminders, so queued generation emails, manual project emails, and failed deliveries can retry even when reminder scheduling is disabled. It honors `cron_enabled`; each delivery also rechecks the recipient and relevant email setting before sending. Retry counts are recorded in the job result, and queue-processing errors mark the run failed while allowing the other queue to run. This worker is included only in the full execution profile.

The multi-stage root `Dockerfile` builds the cron image and copies the current `src/`, Composer dependencies, migrations, crontab, and entrypoint into the image. Application source is not bind-mounted by the published Compose configuration; rebuild or pull a new cron image after PHP cron changes.

Persistent volumes provide:

- `/var/www/config`: settings, encryption key, and logs
- `/var/www/backups`: generated backups
- `/var/www/src/uploads`: shared user uploads

## Commands

```bash
# Check service state
docker compose ps cron

# Inspect the installed schedule
docker compose exec cron cat /etc/cron.d/project-alpha

# Follow cron output
docker compose exec cron tail -f /var/www/config/logs/cron/cron.log

# Run a job manually
docker compose exec cron php /var/www/src/cron/generate_recurring_invoices.php
docker compose exec cron php /var/www/src/cron/generate_recurring_expenses.php
docker compose exec cron php /var/www/src/cron/daily_link_resolver.php
docker compose exec cron php /var/www/src/cron/send_portal_projection_outbox.php
docker compose exec cron php /var/www/src/cron/reconcile_client_portal.php
```

## Application Settings

The container schedule always starts with the service. Individual scripts also honor application settings where applicable, including:

- `cron_enabled`
- link resolver, daily folder scan, and provider enablement toggles
- invoice due and overdue reminder toggles
- invoice email-on-generation toggle
- Stripe and SMTP configuration
- internal notification relay enablement and policy (disabled by default)
- portal authoritative mutation and outbound delivery gates, plus each profile's delivery switch (all disabled by default)

Historical portal provisioning is automatic after all of those producer
prerequisites are ready. It is profile-scoped, idempotent, and bounded to 25
roots per run. Future client mutations continue through the authoritative
mutation hook. Explicit root or client revocations remain authoritative and no
invitation email is sent.

On upgrades, the same job also adopts an already-enabled and complete External
Operations connection as the portal producer. Administrators do not need to
re-enter or re-save its credentials. Disabled or incomplete connections remain
inert, and a changed receiver must finish the existing retirement/revocation
drain before the replacement contract can activate.

Database backups are infrastructure protection and do not depend on the automatic-invoice `cron_enabled` setting.

## Troubleshooting

1. Confirm the cron service is running and connected to MySQL.
2. Review `/var/www/config/logs/cron/cron.log`.
3. Check `cron_job_runs` for the last success or failure.
4. Run the affected PHP script manually in the cron container.
5. Confirm the configuration and encryption-key volumes are mounted.
6. Confirm the deployed cron tag matches the intended branch.
7. Confirm `/etc/cron.d/project-alpha` includes `/usr/local/bin` in `PATH`; otherwise the official PHP image's executable is not available to cron jobs.
8. For portal delivery, inspect only the bounded error code and outbox counters in Settings; do not copy encrypted credentials, signed payloads, or receiver authorization headers into tickets.
9. Confirm migration 0083 is applied. Schema health now treats a missing `portal_client_provisioning_backfill` table as an incomplete upgrade.

Do not paste production logs into a public issue without removing credentials and customer information.

## AutoPay missed-webhook recovery

The Stripe reconciliation job also checks up to 100 stored AutoPay PaymentIntent IDs per run, independently of Stripe's creation-date listing window. This covers delayed bank settlement and repairs collection status after payment accounting already succeeded. Verified failures, cancellations and action-required outcomes are recovered only if the local snapshot is still current; terminal outcomes never regress. Checked rows rotate by their last update time. Recovery uses authenticated provider reads and existing accounting; it never creates or captures a payment and never treats provider reads as signed webhooks.

Recovery remains restricted to the configured staging/test/development installation and Stripe test account. It can settle existing collections while the new-collection feature flag is off. Financial holds stay held for review; observed refunds/disputes also cause a hold. Unknown outcomes without a stored PaymentIntent ID are not retried by reconciliation. The existing invoice-notification job subsequently creates and retries the durable receipt work for recovered successes.
