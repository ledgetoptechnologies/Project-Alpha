# Organization billing recipients: staging verification handoff

Status: implemented and locally tested; **live staging and production release acceptance remain pending**.

## Source and ownership

- Branch: `codex/organization-billing-policy` in `ledgetoptechnologies/Project-Alpha`.
- The owner's accompanying handoff identifies the immutable final commit. Fetch the branch, verify that commit, and build/deploy that exact tree to staging through its normal approved workflow.
- This branch includes the unmerged modular refactor commit `9eea9956434f18b432b7915ac3d9db0dd1e1047f` (the invoice queue and Stripe settlement refactor). Use the complete branch; do not cherry-pick only its billing commit without that dependency. Main at implementation start was `e48b1013092a2d7fdcbfc799fc9efae9fe54a43c`.
- Beau requested PA implementation/testing on a new branch and a handoff to PA-Ops Integration for live staging verification before production. This document is not production deployment approval.
- PA-Ops Integration confirmed the ownership boundary in Engineering Hub: `ledge-top-ops / reviews/pa-organization-billing-compatibility-20261027.md`, version 1, SHA-256 `d00b1abe495d1c3a465e740768c0fa813bfeddfaeeff5c95220af727d24c28db`.
- PA owns financial email routing. Ops continues to own the shared operational profile experience and source-specific identity bindings. This implementation changes no directory profile payload, directory revision hash, API scopes, portal membership, or AutoPay authorization.

## Change overview

Organizations can opt into accounting-only email delivery using multiple saved contacts. Including the working contact is a separate, explicit checkbox that starts off. Draft invoices can inherit the project/organization policy or explicitly choose recipients for that invoice. Invoice create/edit and staff details show the working contact, effective billing organization, routing source and resolved destinations.

The central policy is `src/utils/billing_recipients.php`. Existing organization context wins in the same order as document workflows: project organization, saved document organization, then the working contact's organization for legacy records. Custom invoice recipients take precedence. Once organization billing is configured, existing saved project recipients remain a project override; a project with no saved recipients inherits the organization. Unconfigured organizations preserve ordinary invoice client-email routing and existing project routing.

Ordinary invoice generation, manual/finalized sends, both on-demand branches, reminders and receipts use this policy. Queued emails keep their original destination and suppress removed recipients rather than retargeting them. Missing/archived/moved/invalid configured recipients do not silently fall back to the signer. Contract signatures, invoice client ownership and accounting amounts are unchanged.

One financial receipt remains associated with each payment. Configured receipts snapshot their initial recipient list before sending, track successful deliveries separately, and retry only failed eligible destinations. Later policy changes cannot add recipients to an old receipt. Already completed legacy and configured receipts are not resent. AutoPay also preserves the verified payer's receipt; only that payer receives its email-verified management link. Accounting email delivery grants no payment-method or portal authority.

When the resolver is off, Automatic Link Strategy is hidden while ordinary manual/shared links remain visible. Strategy saves, first-department transition, cleanup, and expiration changes are guarded. Disabling it preserves saved strategy and resolver links. The first-department strategy mutation now runs inside the existing ownership-guarded transaction.

## Migration and compatibility

Apply forward migration `0116_organization_billing_recipients.sql` through the canonical migration runner **before** starting the new web/worker/cron code. Do not edit the immutable baseline or an installed migration checksum.

The migration adds five empty tables:

- `organization_billing_preferences`
- `organization_billing_recipients`
- `invoice_billing_preferences`
- `invoice_billing_recipients`
- `payment_receipt_deliveries`

It seeds no policies and rewrites no existing invoices, contracts, payments, directory records or delivery history. Organization opt-in applies to future sends/reminders for existing inheriting invoices too; the UI states this explicitly. Changing an organization's policy does not redistribute completed receipts. An explicit project recipient list remains an override; review those lists during staging so a previously selected working contact is not mistaken for organization inheritance.

Keep each PA instance's contact IDs and preferences separate. Do not map by name or email across instances. Existing strict Ops profile updates must continue updating only their shared-profile whitelist and preserve PA-only policy rows. No Ops deployment is required for PA-only billing settings. A future Ops view/editor of this policy needs its own reviewed source-qualified contract.

## Local acceptance evidence

The implementation was checked with isolated disposable MySQL 8.4 databases, synthetic records, and the existing PHP 8.5.12 test runtime. The image's Composer lock matched this checkout; current source, tests and frontend were used. Local results are not live staging acceptance.

- Fresh baseline plus all 116 forward migrations passed canonical schema validation.
- Seeded 115-to-116 upgrade preserved canonical row hashes of all 281 existing tables, all prior migration ledger rows, all three contract/invoice types, a historical check payment and receipt, and legacy recipient routing. Five new tables were empty. A second runner pass applied zero migrations.
- Guarded MySQL payment selection: 294 tests, 1,455 assertions, no failures/errors/skips. Included AutoPay, webhook/Checkout/reconciliation, refunds and receipt retries.
- Front-controller billing HTTP/MySQL rehearsal: 1 test, 30 assertions. Covered authentication, CSRF, finance permission and organization-scope denial, same-company non-primary-contact project preview, multi-recipient save/preview, rejection of another organization's contact, profile-field preservation and no payment/notification side effects.
- Browser component verification used the actual rendered component and JS with synthetic responses at desktop and 390px width. Selection, separate contact/organization/delivery summary, default-off working-copy and wrapping passed. This is not authenticated full-page acceptance.
- All 51 frontend regression tests passed. New tests include stale response rejection, general-recipient privacy, explicit selection, failed-preview send blocking and soft-navigation idempotency.
- Full PHP suite: 1,546 tests, 12,181 assertions, no failures/errors. The existing oversized-float warning, seven deprecations and 77 skips remain. Payment-specific opt-in cases were exercised separately above; other guarded suites remain skipped unless expressly stated.
- Independent review found and prompted fixes for cross-organization project recipient revalidation, same-company project preview and historical receipt redistribution. Focused tests cover each.

Reproducible focused commands from the repository root:

```powershell
node --test tests/frontend/*.test.js
# Set to a trusted local --target test image built from this exact tree.
$env:BILLING_HTTP_TEST_IMAGE = 'your-local-pa-test-image'
node scripts/run-billing-recipients-http-mysql.mjs
```

The HTTP runner creates a random disposable MySQL container/database, copies this checkout into a disposable test workspace, verifies the Composer lock, runs real front-controller requests and removes its own container. Without the test-image variable it requires a local PHP/MySQL-compatible test runtime. Do not substitute a production DSN.

Focused PHPUnit classes: `OrganizationBillingRecipientsTest`, `OrganizationLinkStrategyPolicyTest`, `PaymentEmailDeliveryTest`, `ApiV2DirectoryReleaseSafetyTest`, `ProjectWorkflowUiTest`. Use normal isolated database settings for the full suite and the existing guarded opt-in settings for payment integration tests.

## Required live staging acceptance

Use both existing staging PA instances with synthetic customers only and safe mail capture. Verify instance identity and current ownership first. Record the actual code/image commit, migration checksum/version, test timestamps and sanitized mail/queue outcomes. Disable or isolate outgoing customer mail, scheduled catch-up and live payment-provider actions before fixture work. Keep production AutoPay and all production data untouched.

1. **Upgrade and historical records.** Back up staging through its normal workflow. Apply the migration, confirm its checksum and health, and rerun it idempotently. Confirm pre-existing regular/long-term/on-demand documents retain their types, signer PDFs, totals, dates and payment histories. An unconfigured organization must retain prior routing.
2. **Accounting default.** Create synthetic working contact Alex, accounting contacts Pat and Morgan, and a contact in a different organization. Configure only Pat/Morgan. Leave working-copy off. Sign a contract as Alex; its derived ordinary/recurring/on-demand invoice must retain Alex as client while preview and captured invoice email target Pat/Morgan only. Check manual send, Finalize & Send, recurring generation and on-demand replay.
3. **Explicit inclusion and override.** Enable organization working-copy and verify Alex is included once. Disable it again. Save a draft override selecting Morgan only; reload and compare create/edit/details/send summary with captured destinations. Reopen before editing a finalized invoice. A forged POST must not change a finalized invoice or accept another organization's recipient. General-recipient invoices remain manually shared with no automatic mail.
4. **Project precedence.** Test a same-company project whose primary client differs from the working contact. Test existing explicit project recipients and a project inheriting the new organization policy. Check project invoice send/reminder/receipt, with no accidental child-invoice duplicate notices. Verify project effective organization takes precedence over a moved working contact.
5. **Retries and history.** Force one captured recipient send to fail. Retry and prove successful recipients are not sent twice. Remove/move/archive the failed recipient; confirm pending delivery is suppressed and no fallback/retarget occurs. Add another recipient and replay a completed receipt: no old receipt may be newly emailed. Repeat for a partially failed receipt: only initial, still-eligible destinations may be retried.
6. **Reminders.** Run due-seven-day and overdue scheduling/processing against the synthetic invoices. Check recipients, per-recipient queue/message keys, idempotent replay, changed due dates and removed contacts. No real customer mail.
7. **Payment/AutoPay boundaries.** Use Stripe sandbox only if exercising a payment. Confirm one payment/receipt identity, multi-recipient accounting receipts, verified payer receipt, payer-only management link, duplicate callbacks and refunds without duplicate accounting or mail. Billing contact selection must not create enrollments, portal access or grants.
8. **Permissions and Ops sync.** An administrator/authorized finance user can save preferences; a creator-only user cannot enumerate all organization contacts. Through the existing exact-source Ops staging profile workflow, change a shared organization profile field, then read back that both PA billing policy and invoice override are preserved. Do not add recipient fields to the existing strict profile payload. Keep source bindings separate between PA instances.
9. **Resolver off/on.** With resolver off, verify the Automatic Link Strategy panel is absent while manual/shared links remain. A direct strategy POST is rejected. Create the first synthetic department and verify saved strategy, resolver links and expiration dates are unchanged. With resolver enabled and an approved synthetic provider fixture, verify the existing enabled transition still works. Test a toggle change before cleanup where practical.
10. **Complete pages.** Verify actual organization, invoice create/edit/details and send flows at desktop and narrow widths, including soft navigation, client/project changes, empty/invalid recipient states and failed preview. Local component screenshots do not replace these full-page checks.

Save a staging acceptance note with observed results and any blockers. Do not infer acceptance from green local tests or a missing projection. Production remains gated on the owner's review of that evidence and the normal release process. A schema rollback by deleting these tables would destroy new policy/delivery history; retain the additive schema and use a reviewed recovery plan instead.

## Cleanup already performed

Only the three owned branches whose tips exactly matched merged PRs were deleted locally and remotely: `codex/fix-invoice-email-automation`, `codex/unified-service-billing`, and `codex/staging-autopay`. Remote deletion used expected-head leases. The open `codex/modular-consolidation` branch and all unrelated/active worktrees were preserved. Pre-existing untracked `config/logs/` was not included in commits.
