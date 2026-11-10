# Automated email clarity handoff

## Current change (November 10, 2026)

This branch separates automated email presentation from PR #237 and its AutoPay/migration dependencies. Base: `9972dbdf` on `origin/main`. No database migration or workflow change is included.

All outgoing messages through the shared email provider now receive:
- One `[Automated]` subject prefix.
- `NO-REPLY | Company` when the no-reply setting is enabled and there is no document Reply-To; otherwise `AUTOMATED | Company`.
- A centered, bounded HTML card with a prominent yellow notice above the message title and content.
- Explicit "Automated notification — please do not reply" wording for no-reply mail. Configured notice text is escaped and preserved.
- Accurate reply guidance for documents with an issuer Reply-To. These preserve their reply address and do not claim replies are prohibited.
- Leading automation guidance for plain-text messages.

Provider addresses, recipients, attachments, document links, message IDs, delivery keys and scheduling are unchanged. Presentation is applied after provider and issuer identity resolution, exactly once. SMTP uses UTF-8; the delivery log truncates only its stored subject to the existing column limit, never the outgoing subject.

## Current validation

- Focused presentation, receipt delivery, notification relay, issuer policy/snapshot and public-link regressions: 45 tests, 226 assertions, no failures/errors/skips.
- Shared vendor dependencies were reused read-only, with a temporary bootstrap pointing application autoloading at this checkout. An initial run without this override hit missing issuer classes in the unrelated vendor owner's checkout; this was corrected and the entire selected suite rerun.
- A test-only MIME decoder was corrected to decode RFC 2047 Q-encoded spaces on this Windows PHP runtime.
- PHP lint and whitespace checks passed.
- Synthetic preview: `C:/Users/fstor/.codex/tmp/pa-email-clarity-20261110/receipt.html`.
- Browser rendering and external inbox delivery are not claimed: the browser blocked local file URLs. Current SMTP integration and MySQL tests require their isolated environments and have not been rerun for this revision.

## Prior evidence, not a fresh acceptance run

The earlier presentation commit `7a9fbb4f` passed isolated loopback SMTP (1 test / 33 assertions), MySQL subject-boundary tests (2 / 24) and local regressions. This branch cherry-picks that independent commit and updates its notice styling and associated assertions. Those historical results do not validate the new styling in real Gmail/Outlook.

## Release

Review and merge through a PR, with local/staging verification before production deployment. Keep Actions main-only. This branch does not enable AutoPay, change receipt accounting, or modify production settings. When later integrating PR #237, retain this presentation version and reconcile duplicate email changes deliberately.
