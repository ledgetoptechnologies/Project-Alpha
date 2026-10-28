# Backup and Recovery

Project Alpha creates daily backups from the cron service. Settings > Backup controls the UTC hour, retention, and mode.

## Modes

- **Database only** creates a compressed SQL dump.
- **Full** creates a ZIP containing `database.sql.gz`, uploads, and persistent configuration. Runtime logs and generated audit artifacts are excluded.

Weekly and monthly copies use the same artifact as the daily backup. Restoring a full backup restores the database first, followed by uploads and configuration. Project Alpha creates an emergency backup before every restore and stops if that backup fails.

## Optional Encryption

Full backups require the same strong `BACKUP_ENCRYPTION_KEY` environment value on both the `web` and `cron` services. Without it, the full backup job fails before writing a dump. ZIP contents are encrypted with AES-256. There is intentionally no browser setting for this secret.

Store the key separately from the backup volume. Losing the key makes encrypted backups unrecoverable. Changing the key does not re-encrypt old files, so retain every key needed by retained backups.

## Recovery Test

At least quarterly, restore a recent backup into an isolated staging deployment. Confirm login, document PDFs, uploads, settings, and record counts before considering the test successful. Never test a restore against production first.
