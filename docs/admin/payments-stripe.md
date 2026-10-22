---
title: Stripe Payments
description: Stripe setup, webhook behavior, and testing.
---

# Stripe Payments

PA can use Stripe for invoice and project invoice payments.

## Setup

1. Configure the publishable key, secret key, and webhook secret in **Settings > Billing**.
2. Create a Stripe webhook endpoint:

   ```text
   https://YOUR_PROJECT_ALPHA_HOST/stripe-webhook
   ```

3. Select at least `checkout.session.completed` and `payment_intent.succeeded`.
4. Use test mode until the full payment workflow has been verified.

## What PA Records

PA reconciles successful payments, records refunds and disputes where supported, updates invoice status, and can issue receipts.

## Project Invoice Payments

Project statement payments are allocated to child invoices. The project statement itself is not counted as additional revenue.

## Troubleshooting

Check the Stripe endpoint URL, HTTPS certificate, test/live mode, webhook secret, PA logs, and Stripe delivery attempts.


## Verify one existing declined Stripe attempt

An administrator or owner with Settings management and invoice-payment permission
can use **Payments > Preview Stripe settlement** on an existing failed, zero-dollar
Stripe invoice attempt. PA reads the configured Stripe account and that exact
PaymentIntent again and previews captured amounts, currency, invoice/contact
identity, charge and actual processor fee evidence. After confirmation it settles
the same payment row. It updates the invoice balance and historical settlement
date in the same transaction as the audit record. It sends no charge, refund, receipt or email.

This bounded recovery supports finalized, named-recipient direct invoices whose
single captured payment equals the full invoice principal plus any explicitly
declared customer surcharge. Nonzero fees require explicit, consistent principal
and surcharge metadata. Only principal credits the invoice; the customer fee and
actual processor fee/net are recorded separately. Refund/dispute history, another
settled payment, project-statement
assignments, incomplete or unrelated Checkout sessions and ambiguous references
are held for review. It does not scan or import unrelated Stripe payments.
Repeated verification of the same correctly settled row is a no-op. Apply normal
database migrations before using the action; it does not repair schema.


The preview is read-only and shows Stripe principal, customer surcharge, captured
gross, processor fee/net and reported refund/dispute evidence. A held preview
shows a stable safety-check code and operator message without returning raw
provider errors, credentials or payment metadata. It does not change invoices,
payments, audit records, public links or schema.

Confirmation requires the same administrator's per-payment preview token, valid
for five minutes, plus normal CSRF protection. The POST fetches Stripe again and
rechecks authoritative locked rows; it never trusts amounts from the page.
Success and CSRF failures return to the canonical
`/?page=payments/payments-list` route.
