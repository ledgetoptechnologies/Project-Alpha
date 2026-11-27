# Project Alpha Documentation

This directory contains current operating guides and historical engineering records for Project Alpha.

## Start Here

| Document | Audience | Purpose |
|---|---|---|
| [Project README](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/README.md) | Everyone | Product overview, quick start, architecture, and project status |
| [GitHub Pages Setup](GITHUB_PAGES_SETUP.md) | Maintainers | Publishing source, custom domain, Cloudflare DNS, and HTTPS checklist |
| [Document Workflow](DOCUMENT_WORKFLOW.md) | Operators and contributors | Quote, contract, invoice, payment, and public-link behavior |
| [TrueNAS Scale Deployment](truenas-scale-deployment.md) | Operators | Production and staging deployment guidance |
| [Stripe Webhook Setup](stripe-webhook-setup.md) | Operators | Stripe events, endpoint configuration, and verification |
| [Recurring Invoices](RECURRING_INVOICES_SETUP.md) | Operators | Long-term contract scheduling and invoice generation |
| [Migration Safety](MIGRATION_SAFETY.md) | Operators and developers | Safe schema updates, backups, and recovery |
| [Backup and Recovery](BACKUP_RECOVERY.md) | Operators | Database/full backups, encryption, restore testing, and key custody |
| [Security Policy](SECURITY.md) | Everyone | Private vulnerability reporting |
| [Developer and Agent Guidance](AGENTS.md) | Contributors and coding agents | Repository conventions, commands, and high-risk areas |

## Technical References

- [Encryption at Rest](ENCRYPTION_AT_REST.md)
- [On-Demand Contracts](ON_DEMAND_CONTRACTS_README.md)
- [Templating Strategy](TEMPLATING_STRATEGY.md)
- [Webhook Routing](webhook-routing.md)
- [Security Checklist](SECURITY_CHECKLIST.md)
- [AutoPay Beta Foundation](AUTOPAY_BETA.md)
- [Deferred Client Onboarding Portal](CLIENT_ONBOARDING_PORTAL_PLAN.md)
- [Database Migrations](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/database/migrations/README.md)
- [Cron Service](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/cron/README.md)
- [Twig Templates](https://github.com/ledgetoptechnologies/Project-Alpha/blob/main/src/views/templates/README.md)

## Historical Records

The following files document earlier implementation passes, audits, or plans. They are useful context but are not current operating instructions:

- [Implementation Summary - May 6, 2026](IMPLEMENTATION_SUMMARY.md)
- [Supplemental Audit Findings - June 16, 2026](supplemental_audit_findings.md)
- [Comprehensive Plan - June 21, 2026](pa-comprehensive-plan.md)
- [Production Readiness Assessment - June 21, 2026](pa-production-readiness-assessment.md)
- [CI/CD Next Steps - June 23, 2026](CI-CD-NEXT-STEPS.md)

When a historical record conflicts with the application, current source code, `database/baseline.sql`, active migrations, Docker files, and the current guides above take precedence.

## Documentation Rules

- Do not commit passwords, API keys, tokens, customer information, private IP addresses, or production-only operational details.
- Prefer links to one authoritative guide instead of copying the same instructions into several files.
- Mark point-in-time audits and plans as historical once their implementation window closes.
- Update documentation in the same pull request as behavior changes.
