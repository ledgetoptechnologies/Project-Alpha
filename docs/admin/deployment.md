---
title: Deployment
description: How Project Alpha is deployed and operated.
---

# Deployment

Project Alpha is commonly deployed with Docker Compose or as a TrueNAS Scale Custom App using published GHCR images.

## Services

| Service | Purpose |
|---|---|
| `web` | PHP and Apache application runtime |
| `db` | Project Alpha's MySQL 8.4 image with built-in at-rest encryption and keyring health checks |
| `worker` | Durable background and maintenance jobs |
| `cron` | Scheduled jobs, reminders, backups, and reconciliation |
| `migrate` | One-shot database initialization and migration validation |

## Log storage and rotation

Repository-owned logs use the shared config volume. Normal web/PHP errors are
written to `/var/www/config/logs/system/error_log.txt`; scheduled command output
is written to `/var/www/config/logs/cron/cron.log`. These are the authoritative
paths. Legacy `/var/log` placeholders are not used.

The cron service is the single rotation owner. Its repository schedule runs the
sweep as the unprivileged web user once per minute. Container startup establishes
the writable paths but never races a second sweep. On upgrade, a readable regular
active log that is not writable by the application user is copied to a private
sibling, durability- and content-verified, then atomically adopted without a
privileged pathname metadata change. Startup fails closed if a configured active
log or log directory is a symlink or special file.

Active `.log` and `.txt` files rotate when they reach the 10 MiB threshold.
Rotation renames the active inode and recreates the original path with its
existing owner, group, and mode; it never uses copy/truncate.
Already-running repository writers safely finish in the renamed archive; PHP
engine messages reopen the configured path per write and web Monolog handlers
close at request completion. Archives are compressed only after 24 hours without
a write and, on Linux, after no process visible to the rotation container still
has the archive inode open. Compression is streamed to a private temporary file,
durability-synced where supported, and decoded/hash-verified before atomic
publication and source removal. Five compressed size generations are retained
per stream. Completed date-named logs are compressed after the same quiet period
and retain 30 generations.

The threshold bounds the active file at each sweep; a single record or output
written between minute sweeps can temporarily exceed it. PHP also suppresses
only consecutive identical engine errors from the same source file and line.
Distinct messages and the same message from another source remain visible.
Compressed archives are operational files on the config volume and are not
exposed by the Settings log viewer.

## Basic Docker Flow

```bash
git clone https://github.com/ledgetoptechnologies/Project-Alpha.git
cd Project-Alpha
docker compose pull
docker compose up -d
```

Before first start, replace both database passwords and adjust the public
origin, backup encryption key, port, and storage mappings as needed. No
administrator environment variable is required. On a clean database, open PA
and complete the first-time setup form to create the initial normal
administrator.

`docker-compose.yml` is the only tracked deployment definition. It contains the
production image tags, port, service settings, and named volumes directly.
Environment-specific copies belong to the deployment host, not the repository.

The optional External Operations module is disabled by default. Deployments
that use it configure the one connection from the administrator-only **Custom
integrations** settings page. Ordinary Operations updates, portal workspace
and membership records, service assignments, contact roles, and revocations
all use the same signed event URL, Access service identity, application key,
and HMAC secret. Project Alpha does not require a second portal URL, signing
secret, Compose override, or connection profile.

The saved credentials are encrypted with the persisted application encryption
key and are loaded by both event senders. Portal records are wrapped in the
normal signed-event envelope with event type `portal.projection`; Operations
routes the validated inner projection to its client portal. Keep the visible
connection disabled until its Operations receiver contract is deployed. Do not
paste its credentials or expanded Compose configuration into diagnostics. See
[External Operations](external-operations.html).

## Administrator Recovery

PA does not keep a permanent default administrator. If email recovery is unavailable, a Docker operator can issue a one-time temporary password for an existing active administrator:

```bash
docker compose exec web php bin/admin-recovery.php admin@example.com
```

Use either the administrator username or email. The command refuses non-admin and inactive accounts, revokes existing sessions and password-reset tokens, records an audit event, and forces a password change. The temporary password is printed once and is not logged.

Lost TOTP requires a separate explicit operation:

```bash
docker compose exec web php bin/admin-recovery.php admin@example.com --reset-totp
```

This resets TOTP, records a separate audit event, and requires fresh enrollment after the password change. Do not use `--reset-totp` for password-only recovery.

Upgrades and migrations do not create, replace, or reveal TOTP backup codes.
Existing TOTP enrollment continues unchanged. If an enrolled user still has
their authenticator but no saved backup codes, they can use **My Account > Set
Up 2FA > Regenerate Backup Codes** and confirm with a current TOTP code. If an
administrator has neither the authenticator nor a backup code, use the explicit
`--reset-totp` recovery option above.

## Backup Encryption

`BACKUP_ENCRYPTION_KEY` is optional and does not affect startup or migrations.
When it is empty, scheduled and manual backups still run, but their contents
are not encrypted by PA. To enable application-level AES-256 archive
encryption, place the same strong, stable value in both the `web` and `cron`
service definitions and store it outside the server in a password manager.

The key is not written to the database, generated automatically, or recoverable
by PA. Changing or losing it prevents PA from opening backups encrypted with
the old value. Existing unencrypted backups are not retroactively encrypted,
and the required pre-migration safety dump remains a compressed `.sql.gz` file
on the backup volume rather than an encrypted application archive.

## MySQL-Native Encryption

The published database image enables MySQL's file keyring, application and
system tablespace encryption, redo/undo log encryption, and binary/relay log
encryption. Its one-shot
migrator converts existing InnoDB tables before the web service starts. Follow
the backup, key custody, verification, and recovery requirements in
[Database Encryption](database-encryption.html) before enabling it on an
established installation.

## TrueNAS

Paste the canonical `docker-compose.yml` into the TrueNAS Custom App. Its
default named volumes work as-is. To use datasets instead, edit the volume
sources in the YAML pasted into TrueNAS, for example:

```yaml
- /mnt/tank/apps/project-alpha/uploads:/var/www/src/uploads
- /mnt/tank/apps/project-alpha/config:/var/www/config
- /mnt/tank/apps/project-alpha/backups:/var/www/backups
- /mnt/tank/apps/project-alpha/db:/var/lib/mysql
- /mnt/tank/apps/project-alpha/mysql-keyring:/var/lib/mysql-keyring
```

Replace `/mnt/tank` with the actual pool path. Keep staging and production
project names, credentials, ports, and storage paths separate.

## Public Access

Put PA behind HTTPS before using public links, Stripe webhooks, or client-facing pages. Set the application domain in **Settings > System** so emails and public links use the correct host.

## Upgrade Safety

Validate new images in staging, confirm backups, review migration notes, then deploy production. Publishing an image does not automatically update a TrueNAS Custom App.

For an existing production installation:

1. Take and verify an external backup before changing Compose.
2. Preserve the current Compose project/app name, database credentials, and
   every existing volume or TrueNAS dataset mapping. Changing those mappings
   can make PA start against a new empty database even though the old data still
   exists elsewhere.
3. Add the `worker` service while retaining the existing `web`, `cron`, `db`,
   `migrate`, upload, config, backup, and database storage mappings.
4. Set `BACKUP_ENCRYPTION_KEY` in both `web` and `cron` only if you have safely
   stored the chosen key. It may remain empty for the upgrade.
5. Run `docker compose pull` followed by `docker compose up -d` without `-v`.
6. Check `docker compose ps -a` and `docker compose logs migrate`; `migrate`
   must exit with status 0 before `web`, `worker`, and `cron` are considered
   ready.

When upgrading from a release that used `ADMIN_PASSWORD`, deploy this release first and confirm `migrate` exits successfully. Only then remove `ADMIN_PASSWORD`, `ADMIN_EMAIL`, and `ADMIN_USERNAME` from the host's Compose configuration and recreate the services. Existing administrator accounts and password hashes are preserved; the upgrade intentionally invalidates pre-upgrade browser sessions once so they are re-established with the versioned session model.

When an upgrade changes scheduled or background jobs, pull and recreate `web`, `worker`, and `cron`. Verify `/var/www/config/logs/cron/cron.log` and confirm Settings > Backup records a successful `backup_database` run with a file under `/var/www/backups/daily`.

