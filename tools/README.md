# Project Alpha Maintenance Tools

This directory contains operator-run maintenance utilities. Review each script and take a backup before executing it against data you care about.

## Directory cutover gate MySQL acceptance

Run the disposable two-connection MySQL acceptance gate with:

```powershell
powershell -ExecutionPolicy Bypass -File tools/run-directory-cutover-gate-mysql-integration.ps1
```

The command creates a random `directory_cutover_gate_test_<uuid>` database in
a local `mysql:8.4` container and removes the container afterwards. The test
refuses to run unless both that database-name pattern and its
`isolated-disposable-only` environment sentinel are present. It proves real
InnoDB shared/exclusive sentinel contention for activation, source-writer
denial after activation, API shared authority, takeover, concurrent API shared
gates, and rollback on stale activation evidence. Set
`DIRECTORY_CUTOVER_GATE_MYSQL_TEST_IMAGE` to run PHPUnit from an already-built
container image instead of the local PHP installation.

| Tool | Purpose |
|---|---|
| `db_backup.sh` | Create a compressed MySQL backup |
| `db_restore.sh` | Restore a selected MySQL backup |
| `rotate_encryption_key.php` | Re-encrypt supported stored secrets with a replacement key |
| `migrate_receipts_to_expenses.php` | Migrate legacy receipt records into expenses |
| `run_scheduled_audits.php` | Legacy standalone audit scheduler |

The current Docker deployment runs scheduled audits through `src/cron/process_audit_schedules.php` at 06:00 UTC. Do not install `run_scheduled_audits.php` as an additional scheduler unless you intentionally want a separate legacy path.

## General Rules

- Run tools from a trusted checkout matching the deployed version.
- Use staging or a restored database copy first.
- Supply credentials through the environment; never add them to scripts.
- Verify generated backups before destructive migrations or restores.
- Preserve the application encryption key when restoring encrypted settings.
- Capture only sanitized results in public issues.

## Scheduled Audit Verification

```bash
docker compose exec cron php /var/www/src/cron/process_audit_schedules.php
docker compose exec cron tail -n 200 /var/www/config/logs/cron/cron.log
```

Check `audit_schedules` and `audit_schedule_logs` for due dates and outcomes.

Encryption-key rotation is high risk. Back up the database and current key, run against staging, and retain the previous key until every encrypted value has been verified.
