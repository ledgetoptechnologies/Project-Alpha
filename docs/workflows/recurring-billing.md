---
title: Recurring Billing
description: Monthly, yearly, and other long-term billing behavior in Project Alpha.
---

# Recurring Billing

Recurring billing is driven by long-term contracts.

## Billing Schedule

A per-invoice long-term contract contains one or more recurring service schedules. Each service stores its own amount, interval, effective dates, approval state, and next invoice date. This supports combinations such as annual website hosting and monthly advertising management under one agreement.

Services due on the same date are combined into one invoice with separate line items. Services on different dates generate independently. Editing a service changes only future invoices; generated invoices remain historical records.

## Amendments and Add-ons

Use **Recurring Services & Amendments** on the long-term contract details page to add or change a service.

- Unapproved services remain pending and are excluded from invoice generation.
- Approval may be recorded directly or evidenced by an uploaded signed addendum PDF.
- Every addition, edit, approval, pause, resume, end, and proration is recorded in amendment history.
- An optional explicit prorated subtotal can generate a one-time long-term invoice without changing the full recurring amount.

## First Invoice

When a long-term contract becomes active and the first invoice is already due, PA attempts to generate it immediately. This avoids waiting until the next scheduled cron run or the next yearly date.

Activation and **Start Billing** preserve an explicitly scheduled first invoice date, including a date retained after recording historical payments. A future date does not generate an invoice early. With manual billing start selected, uploading a signed PDF leaves the agreement pending until staff explicitly starts billing. An agreement with no selected billing date starts today, unless its service start date is in the future.

When `invoice_auto_email_on_generate` is enabled, each generated direct-collection invoice is finalized and emailed once with a public payment link. Delivery uses an idempotent `on_generate` notification record, so a cron retry does not send the same invoice twice.

## Catch-Up Behavior

The cron job can generate missed invoices when PA has been offline. It uses an idempotency guard and a maximum catch-up pass count to avoid duplicate or runaway generation.

## Contract and Invoice Status

The contract lifecycle and each invoice lifecycle are independent:

- Paying a monthly or yearly invoice marks that invoice paid and leaves the long-term contract active.
- Pausing a contract stops scheduled generation while preserving existing invoice history.
- A long-term contract completes only when its configured end or fixed-total schedule is reached, or when an operator explicitly terminates it.
- Open-ended per-invoice contracts continue until explicitly terminated.

The **Recurring Billing** page shows both the active schedules and a filterable history of every generated recurring invoice, including paid invoices.

Selecting **View history** on a billing schedule filters that history to the selected long-term contract and scrolls directly to the matching invoices. An unpaid recurring invoice created by mistake can be opened from **View / Actions** and voided without pausing, completing, or advancing the underlying contract schedule.

Invoice display numbers are type-specific while preserving the existing numeric sequence: regular invoices use `I-#`, long-term recurring invoices use `LTI-#`, and on-demand invoices use `ODI-#`. Existing records are not renumbered; the prefix is derived from `invoice_type` everywhere the invoice is displayed, emailed, downloaded, or sent to Stripe.

## Manual Recovery

### Record a previously received check for monthly periods

For an approved monthly service, open **Record paid monthly periods** in the agreement's services panel. Enter the first covered period, number of months, date the check was received, check reference, and the full amount. Use this only when the payment has not already been recorded elsewhere.

Preview the coverage dates, invoice totals, and next billing date before confirming. PA creates one paid invoice per month and allocates the check across those invoices using the same reference. The sum must match the check exactly, including any configured tax and discount. The current month's coverage may extend into the future, but every selected period must already have started.

Recording history does not activate the agreement, collect money, or send invoices or receipts. It preserves the signed agreement and advances the service's next invoice date past the paid coverage. Start billing separately after reviewing the remaining schedule. Retries of the same request return the existing entries; overlapping invoiced periods are rejected. All invoices and payment allocations are saved together or rolled back together.

This workflow supports monthly direct-collection services and checks. It rejects queued service changes, ambiguous older invoices without coverage records, project statement collection, and unsupported inherited pricing. Existing invoices should receive payments through their normal payment actions. Annual conversions, partial-year settlement, and automatic withdrawals are separate workflows.

If a long-term contract is active but an invoice was not generated when expected, use the long-term contract details page to review the schedule and generate or send the needed invoice.

If a temporary one-off invoice was paid before the delayed recurring invoice appeared, do not record a refund unless money is actually being returned to the client. Open **Payments**, choose **Correct allocation** on the real payment, select the recurring invoice, and select any duplicate manual cash/check entry to reverse. PA will:

- keep the original Stripe transaction and processor identifiers;
- move that payment to the selected recurring invoice;
- mark the duplicate manual entry as reversed rather than refunded or deleted;
- refresh the balances and paid dates on both invoices;
- optionally retain the accidental source invoice as void; and
- leave the long-term contract active.

Processor-backed refunds must be initiated in Stripe and are synchronized into PA by Stripe webhook events. The local **Record refund** action is limited to payments whose money was returned outside a connected processor.

