# Mailed check payments staging handoff

Clients can report a mailed check from a finalized invoice or project invoice. A report holds collection and shows staff that receipt is pending; it does not mark the invoice paid or record income. This feature does not enable production AutoPay.

## Client and staff workflow

- Enable the existing check payment method. The client follows the invoice link, chooses to report a mailed check, verifies their email, and enters the check number.
- The server captures the current outstanding balance and currency. The arrival deadline is ten calendar days from the original submission, stored in UTC. Repeating the report does not extend the deadline.
- Staff see pending reports on invoice details and the payments page. Confirm receipt only after physically receiving the check. Confirmation records the payment through the existing accounting path; repeating confirmation does not record another payment or resend a receipt.
- A missing check becomes overdue for staff review. It does not automatically trigger a debit on day eleven. Staff can reject the report and arrange follow-up; the invoice is not automatically re-enqueued for AutoPay.
- A report is refused if an electronic collection is already reserved or processing. Client-entered document IDs, amounts, or client IDs cannot redirect the report.

## Integration requirements

The local branch is `codex/pending-check-payments`, based on main commit `f2225384d4951291e9b075dc6f1d1024575252c2`. No production or shared staging database was changed.

The migration is `0116_mailed_check_reports.sql`. The unmerged organization billing branch at `60042cc9a68435730066b7340c41e17261bf00bc` also proposes migration 0116. Coordinate the merge order and determine whether either migration has been applied on shared staging before rebasing or renumbering. Do not modify an applied migration, introduce a sequence gap, or merge both as 0116.

Workflow changes remove PR triggers and guard jobs to main, preserving main release and security checks. Historical remote branches still contain legacy triggers and need coordination with their owners; this branch does not establish an account-wide restriction. Publication is held until the migration and Actions integration gates are resolved. Pre-merge validation must remain local under the October 29 main-only Actions policy.

## Completed local validation

Tests used disposable MySQL 8.4 databases and the matching-lock PHP test image. No real client payment or message was sent.

| Validation | Result |
| --- | --- |
| Full PHP suite | 1536 tests, 12398 assertions; no failures or errors; existing 1 warning, 7 deprecations, 67 skips |
| Guarded payment suite | 209 tests, 979 assertions; no failures, errors, or skips; existing 1 warning and 1 deprecation |
| Check database and public context tests | 20 tests, 181 assertions; passed |
| Full client and staff HTTP flow with MySQL | 1 test, 38 assertions; passed |

The HTTP test exercises the front controller, email-code verification POST, CSRF rejection, client report, server-derived identity, fixed deadline, staff receipt confirmation, and repeat submission/confirmation. The verification code is captured through a synthetic mail callback, so this does not verify SMTP delivery.

Concurrency tests reproduce the old stale-balance failure with two database connections and pass against the corrected locking reads. Coverage also includes project allocations, transaction rollback, identity reuse, changed currency, and existing collection holds.

## Shared staging acceptance

1. Resolve migration order, then apply the normal migration runner to a backed-up staging instance. Keep production AutoPay disabled.
2. With synthetic clients, report checks on both invoice types and confirm the deadline, unpaid balance, pending list, and collection hold.
3. Verify actual email-code delivery, receipt delivery after staff confirmation, and no duplicate receipt on repeated confirmation.
4. Verify an overdue report stays held, rejection requires explicit follow-up, partial/existing payments affect the captured balance, and an electronic payment already processing prevents reporting a check.
5. Verify public and staff screens at mobile and desktop sizes, staff permissions, and that unrelated clients cannot see check details.

Local test receipts are retained outside the repository under `C:/Users/fstor/.codex/tmp/pa-check-report-*.log`.
