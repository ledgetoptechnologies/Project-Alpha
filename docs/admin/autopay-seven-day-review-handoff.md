# AutoPay seven day invoice review

AutoPay applies to all future invoice types on a newly enrolled client account within one business instance. A normal invoice email contains the AutoPay notice; there is no extra charge-notification email. Collection waits at least seven local calendar days after the outgoing email service accepts that notice, or until a later invoice due date.

Production AutoPay remains unavailable under the existing guard. This change does not enable live keys or live charging.

## Client experience

New enrollment explicitly authorizes account-wide coverage for regular, on-demand, recurring and eligible project invoices. Existing agreement-only consent is preserved until the client explicitly enrolls for broader coverage. Historical invoices and work performed before authorization are not swept into collection.

The invoice email prominently shows the amount, earliest charge date and saved method's last four digits when available. It explains that no action is needed if the invoice is correct and offers links to review or skip that invoice, question it, or turn off all AutoPay. The notice is inside the shared email layout, including when a caller supplies a complete HTML document.

The review link requires email verification before showing or changing the client's payment controls. GET requests never change collection state. The server derives the document and client from the bound review token; posted document IDs are ignored. Replaced review tokens cannot change a newer invoice notice.

- **Skip this invoice:** stops only its automatic collection and permits manual payment or a mailed-check report. The skip cannot be overridden by a staff restart or silently re-enqueued. Other invoices retain their AutoPay setting.
- **Question this invoice:** holds collection and records the client's question for staff. It is an internal invoice review, separate from a bank or processor dispute.
- **Turn off all AutoPay:** revokes the client's existing authorizations. Payments already submitted may still complete and require reconciliation rather than a local declaration of cancellation.

## Staff experience

Invoice details show whether AutoPay is awaiting email delivery, scheduled, processing, held, skipped or canceled. Finalizing reserves the invoice; it does not charge immediately. The existing finalization choice and button now describe the review window.

The payments page lists current client invoice questions with permission-filtered links. Invoice details preserve the question and expose an explicit resolution action for an unsubmitted review. Staff need invoice-send permission, access to the exact document, CSRF validation, confirmation and a resolution note. Resolution creates a fresh review generation; sending the updated invoice starts another complete review window. The original question and staff resolution are retained separately.

If a question requires correcting the invoice, staff can cancel a genuinely unsubmitted held collection and handle billing manually. Attempts with processor submission, leases or financial holds cannot be released this way. Canceled or client-skipped collections do not automatically restart during later scans.

## Collection and email safeguards

Migration `0117_autopay_invoice_review.sql` introduces durable review generations. Each binds the collection to stable document and client identities, revision, outstanding amount, currency, due date, timezone and recipient. Existing queued collections receive no automatic delivery evidence and cannot charge immediately after migration.

The first successful notice acknowledgment starts the timer. Failed delivery does not start it. Resending the same current notice preserves its original deadline; a recorded successful email delivery can recover a lost schedule update without restarting the clock. Only invoice delivery is eligible: receipts, failure notices and other email cannot arm a collection.

Before submitting, the worker checks the current notice and deadline, original active authorization, client and processor bindings, invoice basis, payment activity and check holds. Changed evidence requires review rather than a guessed debit. Invoice changes during email delivery invalidate the old acknowledgment; staff resolution and a new notice are required.

An invoice notice must reach the current authorized client email to start collection. Sending only to another accounting recipient does not silently authorize collection; the invoice remains awaiting appropriate notice. Mixed-client project statements do not inherit one client's authority over other clients' balances.

Email success means SMTP/API acceptance recorded by PA, not proof of inbox delivery or reading. The seven-day arithmetic uses the configured timezone and calendar days, including daylight-saving changes. SMTP, Stripe calls and other network requests run outside database locks. Existing idempotency, reconciliation, receipts and refund holds remain in effect.

## Validation and integration

Testing uses synthetic clients, normal database migrations, fake Stripe transport and an actual loopback SMTP capture inside isolated staging containers. It includes all invoice types, calendar boundaries, failed delivery, resend recovery, stale amounts and identities, opt-out, question resolution, account revocation, OTP/CSRF and staff permission checks, full HTML MIME and PDF preservation, and processing/financial-hold guards.

The branch is `codex/autopay-seven-day-review`, based on local commit `b72eb6a157bb6f2cee753c076b65e344f0c6e49d`. Earlier mailed-check, email-presentation and disable-control commits are prerequisites in this branch; no production or shared staging deployment is performed by the tests.

Resolve the existing mailed-check versus organization-billing migration `0116` collision and the resulting `0117` ordering before merging these branches. Do not alter an applied migration, introduce a sequence gap, or overwrite another agent's branch. Preserve PA's existing main protection and main-only Actions policy; validation is local or isolated staging, never feature-branch Actions.

The final test receipt and exact source hashes are retained under `C:/Users/fstor/.codex/tmp/pa-autopay-review-staging-receipts-20261102-final/`. Preserve the separate first-run evidence, which records the issues corrected before acceptance.

Final November 2 validation on isolated staging:

| Check | Result |
| --- | --- |
| New review-window tests | 21 tests, 280 assertions passed |
| Full PHP regression suite | 1587 tests, 12796 assertions; no failures or errors; 83 environment-gated skips, one existing warning and eight deprecations |
| AutoPay-disable suite on its separately guarded synthetic database | 14 tests, 114 assertions passed |
| Main-only Actions policy test | 2 tests, 32 assertions passed |
| Frontend authorization preview | 3 tests passed |
| Database upgrades | Normal baseline, backup, contiguous migrations through 117 and schema validation passed on both synthetic databases |

The full run preserves intentional guards for other Stripe/database/CLI-proof environments; those skips are not acceptance of unexecuted integrations. The AutoPay-disable cases skipped under the primary database guard were executed separately. Existing PHP 8.5 notices include PHPMailer's dynamic Timelimit property.

All ten shared staging container fingerprints were unchanged. Tests used one capped PHP driver and one capped MySQL container sequentially on an internal network. Owned containers, database storage, network, PHP children and temporary remote source packets were removed. No external Stripe or client email calls were made; the email capture was loopback SMTP. No local browser or heavy local test runner was started.
