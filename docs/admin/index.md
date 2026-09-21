---
title: Admin Overview
description: Administrative setup areas for Project Alpha.
---

# Admin Overview

PA administration is mostly handled from Settings plus the deployment environment that hosts the app.

## Admin Areas

| Area | Purpose |
|---|---|
| System | Business identity, public domain, timezone, logo, and SMTP |
| Billing | Payment methods, Stripe, surcharges, net terms, and receipt behavior |
| Documents | Terms, document settings, custom fields, and public-link behavior |
| Links | File and external-link resolver settings |
| Taxes | Manual tax rates and imported jurisdiction data |
| Notifications | Cron, admin email notifications, reminders, and alert timing |
| Backup | Backup status, retention, and recovery controls |
| Permissions | Roles, permissions, and user access |
| Workforce | Employee PA accounts, employment state, pay rates, and project assignments |
| Timekeeping and Approvals | Timers, breaks, review, correction revisions, and immutable approval snapshots |
| Custom integrations | Optional, deployment-specific entitlements, operations, tasks, and read-only synchronization |
| Project lifecycle API | Default-on exact Project reads; revision-checked complete, cancel, archive, and restore commands remain default-off |

## Recommended Order

1. Configure [settings](settings.html).
2. Confirm [backups](backups.html).
3. Configure [security](security.html) and verify [database encryption](database-encryption.html).
4. Add [Stripe](payments-stripe.html), if needed.
5. Import [tax rates](tax-rates.html), if needed.
6. Verify deployment and cron behavior.
7. Configure [Workforce modules](workforce-modules.html), employees, assignments, and rates.
8. If this deployment has a separate operations dashboard, configure [External Operations](external-operations.html).
9. Before enabling Project API v2 lifecycle routes, complete the [Project lifecycle API](api-v2-project-lifecycle.html) backfill and release gate.

