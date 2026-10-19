# Workforce temporarily shelved

Workforce is paused in code while client onboarding, shared profiles and document
workflows receive attention. Its source, migrations, permission records, approved
time, assignments and compensation history remain intact. No schema downgrade or
record deletion is required.

`src/utils/workforce_availability.php` is deliberately fixed off. There is no new
Compose setting, environment variable, or saved setting that can resume it.
Resuming requires a reviewed code release and acceptance tests.

The front controller rejects the experimental time, review, pay, Workforce API,
catalog planning and project-work mutation entry points. Workforce navigation,
worker/pay account panels, project work planning and related settings are hidden.
Ordinary account updates no longer provision or edit worker/pay profiles.
Invoice-paid callbacks and document-to-work materialization do nothing. The
deadline cron line is commented out; its script also exits before opening a
database so an older installed schedule cannot send reminders or confirm time.

Quotes, contracts, invoices, payments, customer departments and ordinary service
catalog reads remain available. The historical approved-time read used by invoice
editors remains available under its existing authorization checks; no invoice or
payment history is rewritten. Internal division settings are hidden, but retained
historical IDs and existing authorization constraints are not erased or widened.

Operations currently has inactive Workforce migration foundations only, with no
released Workforce runtime or UI to disable. Preserve those migration records.

Preexisting running timers are retained, not stopped or rewritten by this release.
They must be reconciled before Workforce can be enabled again. Integration grants
still honor inactive and terminated worker records: a grant cannot silently resume
an inactive payroll profile while Workforce is paused. Normal active-staff grants
and explicit account reactivation continue to use their existing authorization.
