---
title: Documentation Source
description: Source index for the Project Alpha documentation site.
---

# Documentation Source

The published documentation site starts at [Project Alpha Documentation](./).

This folder is organized for GitHub Pages:

Important operator references:

| Document | Audience | Purpose |
|---|---|---|
| [Project README](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/README.md) | Everyone | Product overview, quick start, architecture, and project status |
| [GitHub Pages Setup](GITHUB_PAGES_SETUP.md) | Maintainers | Publishing source, custom domain, Cloudflare DNS, and HTTPS checklist |
| [Document Workflow](DOCUMENT_WORKFLOW.md) | Operators and contributors | Quote, contract, invoice, payment, and public-link behavior |
| [TrueNAS Scale Deployment](truenas-scale-deployment.md) | Operators | Production and staging deployment guidance |
| [Stripe Webhook Setup](stripe-webhook-setup.md) | Operators | Stripe events, endpoint configuration, and verification |
| [Recurring Invoices](RECURRING_INVOICES_SETUP.md) | Operators | Long-term contract scheduling and invoice generation |
| [Migration Safety](MIGRATION_SAFETY.md) | Operators and developers | Safe schema updates, backups, and recovery |
| [Internal Notification Relay](NOTIFICATION_RELAY.md) | Operators and integrators | Disabled-by-default transactional notification relay setup and security controls |
| [API v2 directory backfill and command release gate](admin/api-v2-directory-backfill.md) | Operators and integrators | Local directory backfill and disabled-by-default generic API v2 command gate |
| [API v2 application key binding](admin/api-v2-application-key-binding.md) | Operators and integrators | Safely add an explicit-scope key to an existing API v2 application |
| [API v2 cutover and rollback evidence template](admin/api-v2-cutover-evidence-template.md) | Operators and integrators | Per-instance, non-secret migration, cutover, retirement, and rollback evidence record |
| [Backup and Recovery](BACKUP_RECOVERY.md) | Operators | Database/full backups, encryption, restore testing, and key custody |
| [Security Policy](SECURITY.md) | Everyone | Private vulnerability reporting |
| [Developer and Agent Guidance](AGENTS.md) | Contributors and coding agents | Repository conventions, commands, and high-risk areas |

| Folder | Purpose |
|---|---|
| `getting-started/` | First-time orientation and setup path |
| `workflows/` | Day-to-day PA workflows |
| `admin/` | Deployment, settings, security, backups, Stripe, and tax rates |
| `reference/` | Focused reference pages |
| `archive/` | Historical plans, audits, implementation notes, and older runbooks |

For invoice automation rollout, migrations 0058/0059, staging validation, observability, and incident backfill, see the [Invoice Automation Staging Runbook](INVOICE_AUTOMATION_STAGING_RUNBOOK.md).

For the isolated general-recipient invoice release, migration 0060, privacy checks, Stripe test-mode acceptance, and the seven-day paid receipt gate, see the [General-Recipient Invoice Release Runbook](GENERAL_RECIPIENT_INVOICE_RELEASE.md).

The site uses Jekyll, so do not add `.nojekyll` unless the publishing strategy changes.
