---
title: Workforce Modules
description: Configure Project Alpha's built-in workforce, timekeeping, approvals, and employee-pay modules.
---

# Workforce Modules

Workforce, Timekeeping, Approvals, and Employee Pay run directly inside Project
Alpha. They share PA's database, login, projects, UI, deployment, and backup.
These modules do not require a second application, connection, or synchronization
service. Optional API integrations do not change PA's internal financial authority.

## Module ownership

- PA users and TOTP are the only login identities.
- Employees are PA users with the `employee` role and strict default ACLs.
- PA projects are authoritative; assignments control which projects employees select.
- Workforce owns timers, breaks, manual time, revisions, approval snapshots,
  and employee-pay accruals.
- PA billing consumes immutable approved snapshots internally. Corrections and
  voids append billing reversals instead of rewriting approved time.

## Initial setup

1. Sign in as an administrator. PA recommends TOTP, but the reminder may be dismissed.
2. Open **Settings > Work, Jobs & Pay > Workflow defaults**. Set the Workforce
   currency, fallback pay/billing rates, and worker time requirements in the
   **Time & Workforce** section. The business name and timezone remain under
   **Business & Branding**.
3. Open **Accounts**, create or edit a PA user, and choose the **Employee** role.
   The Employee role loads the strict self-service ACL defaults automatically.
4. Complete the employee profile, pay visibility, and assigned projects on the
   same Account form. Optional assignment and service-component compensation
   rules take precedence over broader defaults.
5. Employees use **Workforce > Time**; approvers use **Approvals**; authorized
   users use **Employee Pay**. The Workforce Overview summarizes time and pay.

Timekeeping managers can enter time for any active PA account. Client, project,
and mutable draft-invoice context is optional for managers. Employees never see
direct client or invoice rates and select only the services and Work Activities available to them.

Client billing and worker compensation resolve separately. For hourly client
billing, PA uses project override, client override, Service Activity rate, Work
Activity default, then the business fallback billing rate. Compensation uses
the most specific valid assignment or service-activity rule before Work Activity and worker/business
fallbacks. Missing required rates block the affected payable or billable step.

See [Service Library and Work Activities](../workflows/service-catalog-and-work-types.html)
for catalog and activity setup, and [Workforce, Time, Billing, and Pay](../workflows/workforce-time-billing-and-pay.html)
for time corrections, billing decisions, statements, payment records, deadlines, and payroll exports.

## Security boundaries

The employee role grants personal time, assigned projects, profile access, and
permitted personal pay visibility. It does not grant billing, payments,
financial administration, approvals, workforce administration, user management,
settings, or API-key access.

Privileged users receive a dismissible TOTP recommendation. Sessions use hashed server-side identifiers,
expire after 15 idle minutes, and have a seven-day absolute maximum.

## Corrections and audit

Confirmation snapshots worker, Job, activity, duration, rates, amount, and
currency. A worker can edit their own draft or returned entry. For a submitted
entry, the worker uses **Withdraw and edit**; this marks the submitted revision withdrawn,
retains its review history, and returns the live entry to draft for resubmission.
Authorized workforce managers can perform the corresponding pre-approval action
for workers in their scope.

Confirmed work is never overwritten in place. Workers use **Request correction**,
while an authorized administrator uses **Edit** and supplies a
reason. Approval retains the original revision and immutable snapshots, creates
a corrected revision, and records worker-pay and client-billing effects
separately. Draft statements and invoice lines can be rebuilt from the correction;
closed statements and finalized invoices remain locked and receive explicit
adjustments. Worker Payment Records preserve the independent fact of what an
administrator actually paid. All module mutations write to PA's system audit trail.

Verified-Owner self-confirmation is a worker-relationship rule. Marking a Worker
Profile as a verified business Owner permits self-confirmation, but does not
choose its compensation policy. An explicit `rules` policy can create an eligible
earning; `nonpayable` and `owner_no_pay` do not. Built-in PA `admin` and `owner`
account roles may also self-confirm their own completed time without changing
the Worker Profile's compensation policy. Neither self-confirmation path itself
approves an earning or records payment. Ordinary time-management and review
permission grants do not provide this bypass. Historical entries in
closed periods remain unchanged and require the normal audited exception process.

Confirmed, billable time may be attached to a matching mutable draft invoice.
When exactly one matching draft exists, PA can attach it automatically; otherwise
an administrator chooses the destination. The invoice line and totals are created
only after confirmation. Later corrections refresh a draft line, while finalized
invoice changes use an explicit charge or credit workflow.

## Operations

The Compose stack is `db`, `migrate`, `web`, `worker`, and `cron`. Backups cover
the one PA database and shared configuration/upload volumes. No module-specific
recovery step is required.
