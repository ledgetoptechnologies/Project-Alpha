# AutoPay disable controls handoff

Clients and authorized staff can turn off future automatic collection for an entire client account. Existing production AutoPay restrictions remain in place; this implementation is available only in the existing bound staging and test context.

## Client workflow

The public invoice or agreement link opens AutoPay management. Email verification is required before viewing or changing authorizations. Clients can confirm **Turn off all AutoPay**, covering both recurring agreements and broader future-invoice authorization. They can alternatively turn off a particular billing scope, with a warning that another authorization may still cover invoices.

Management remains available when new enrollment is paused. Enrollment-confirmation emails already contain a separate management link that remains available after the original invoice link expires or is paid. The invoice link also continues to expose management while enrollment is paused, within the existing test context.

After disabling, the page shows that AutoPay is off. Payments already submitted may still complete. Disabling does not cancel the agreement, settle invoices, delete payment history, or issue refunds.

## Staff workflow

Client details list the bound active and pending test authorizations and provide **Turn off AutoPay for this client**. Staff need both client-edit and invoice-send permissions plus access to the exact client. The action requires POST, CSRF validation and explicit confirmation, and records an audit event.

The all-scope action prevents an overlapping business authorization from continuing to cover invoices after only an agreement authorization is removed. Revoked rows retain their history; repeating the action does not move the original revocation timestamp. Other clients, installations, processor accounts and live-mode authorizations are excluded.

## Setup and collection safeguards

New setup reserves pending consent before calling Stripe. After each external call, the server locks and rechecks the client and pending authorization before binding the customer or returning a Checkout URL. If AutoPay was disabled during customer or Checkout creation, the request fails and its late setup callback cannot restore consent. Database locks are not held during external calls. Failed setup revokes its new pending reservation while preserving older active consent.

Revocation does not fabricate cancellation of a reserved or processing payment. The collection worker independently rechecks authorization before collecting; existing processing, held or uncertain attempts retain their reconciliation requirements. Re-enrollment still requires explicit verified consent.

## Validation and integration

Local validation covers real MySQL revocation, both setup races, late callbacks, test/live separation, overlapping scopes, client proof, staff permissions, CSRF and confirmation. Workflow checks cover permission boundaries and the paused-enrollment management UI. Synthetic transports are used; these cancellation tests do not submit real Stripe payments or send client email.

- Combined local MySQL and workflow regressions: **60 tests, 382 assertions passed**, with no warnings, failures, errors or skips.
- Additional local workflow checks: **12 tests, 74 assertions passed**.
- Final isolated staging run on `192.168.50.90`: **18 tests, 135 assertions passed**, with no warnings, deprecations or skips. The tested source hashes matched the local manifest; all six shared PA container fingerprints were unchanged.

The isolated staging run uses a fresh synthetic MySQL database with the normal baseline and contiguous migrations through 0116. It preserves shared PA containers and configuration. The initial combined run exposed a missing `created_by` column in the SQLite test fixture; the fixture was corrected to reflect the canonical schema before final validation.

The local branch is `codex/autopay-disable-controls`, based on email-presentation commit `7a9fbb4f70ee1f7769c696301d3aaddbc2f4201d`. This change adds no migration. Preserve the prior mailed-check migration ordering gate and main-only Actions policy when integrating. No shared deployment or production AutoPay setting is changed by this branch.

Local receipts are kept under `C:/Users/fstor/.codex/tmp/`, including `pa-autopay-disable-workflows-20261029.log` and the staging receipt directory supplied with the integration handoff.
