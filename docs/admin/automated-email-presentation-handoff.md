# Automated email presentation handoff

Project Alpha emails now identify automated delivery in the inbox and at the top of the message. The shared layout uses a centered card, company identity, prominent automation label, subject heading, and reply guidance before the existing content.

## Behavior

- Subjects receive one `[Automated]` prefix. The existing optional no-reply setting adds `NO-REPLY |` to the effective sender name and displays the configured notice prominently.
- Without the no-reply setting, the sender shows `AUTOMATED |`. Automation identification remains visible even when the optional notice is off.
- Documents with an issuer Reply-To retain that address and show `AUTOMATED |` with accurate reply guidance. A no-reply warning would contradict the working contact address, so the custom no-reply notice is suppressed for those documents.
- HTML and plain-text messages are supported. Existing content, links, recipients, transport addresses, attachments, message IDs, and delivery keys are retained. SMTP now explicitly uses UTF-8, matching Gmail MIME generation.
- Presentation runs after provider and document issuer selection, before delivery reservation. The delivery log stores the presented subject, limited to its existing 500-character column; the actual outgoing subject remains complete.

All messages delivered through the shared service receive the label, including manually initiated document emails. The label describes automated delivery, not who authored or finalized a document. No billing, payment, recipient selection, or scheduling behavior changes.

## Validation

| Check | Result |
| --- | --- |
| Local presentation, receipt, notification relay, issuer, public-link and integration foundation regressions | 41 tests, 225 assertions passed; one existing deprecation |
| Actual staging SMTP pipeline | 1 test, 33 assertions passed; no failures, errors, or skips; one existing PHPMailer Timelimit deprecation |
| Actual MySQL subject boundary regression | 2 tests, 24 assertions passed; old code reproduced both length failures |
| Browser preview | Synthetic invoice card visually checked at the normal desktop viewport |

The staging run on `192.168.50.90` exercised EmailService, provider selection, presentation, delivery reservation and PHPMailer into a loopback SMTP capture. It verified HTML and plain text, provider and issuer sender names, Reply-To, original PDF bytes, delivered subject logging, and one delivery for a repeated stable message key. The trusted image had a matching Composer lock and networking disabled. Synthetic SQLite tables supplied provider, issuer, and delivery state. Separately, real MySQL verified ASCII and multibyte subject limits.

No external inbox received test email. This validates SMTP and MIME, not real Gmail or Outlook rendering or external inbox deliverability. All six shared staging PA containers retained identical fingerprints. Shared configuration and deployments were unchanged; owned test containers, children, temporary remote sources and preview server were removed.

## Integration

The branch is `codex/automated-email-presentation`, based on the locally committed mailed-check branch at `165b748d701f26b94a05030c400a64f80ddb283e`. The email commit introduces no migration and can be reviewed independently of the mailed-check feature.

Publication remains local pending the previously identified main-only Actions coordination. Do not run PR or feature-branch Actions. Preserve the main-only workflow changes when integrating, coordinate other active branch owners, and keep the separate mailed-check migration numbering gate in its handoff. These email changes do not enable production AutoPay.

For a repeat isolated staging run, use `scripts/run-automated-email-staging.sh` on a staging Docker host with `AUTOMATED_EMAIL_TEST_IMAGE` set to a trusted matching-lock PHP image. Use a source-only checkout without credentials or real configuration. The script guards ownership, disables networking, enforces resource limits, and removes its test container.

Receipts are retained locally under `C:/Users/fstor/.codex/tmp/pa-automated-email-staging-receipts-20261029/`, `pa-email-local-regression-20261029.log`, and `pa-email-subject-boundary-{red,green}.log`. The synthetic desktop preview is `pa-email-preview-20261029/invoice-desktop.png` in that temporary evidence directory's parent.
