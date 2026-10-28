# Project Alpha — TrueNAS SCALE Installation

## Requirements
- TrueNAS SCALE 24.10 (Electric Eel) or later
- A dataset for PA data (recommended: `apps/project-alpha`)

## Catalog Status

The included `docker-compose.truenas.yml` installs Project Alpha as a TrueNAS
**Custom App**. It does not by itself add Project Alpha to Discover Apps.

An official catalog listing is submitted separately to the
[`truenas/apps`](https://github.com/truenas/apps) repository under
`ix-dev/community/project-alpha`. That submission requires `app.yaml`,
`ix_values.yaml`, `questions.yaml`, `README.md`, a Jinja
`templates/docker-compose.yaml`, and at least one `templates/test_values` file.
Follow the upstream
[`CONTRIBUTIONS.md`](https://github.com/truenas/apps/blob/master/CONTRIBUTIONS.md)
and run its render/deployment tests before opening the catalog pull request.

## Quick Install via Custom App (Docker Compose)

1. **Create datasets** on your TrueNAS pool:
   - `tank/apps/project-alpha/uploads`
   - `tank/apps/project-alpha/config`
   - `tank/apps/project-alpha/backups`
   - `tank/apps/project-alpha/db`

2. **Go to** Apps > Custom Apps > Install from YAML

3. **Paste** the contents of `docker-compose.truenas.yml` (in this repo)

4. **Change all passwords** — search for `changeme` and replace with strong, unique passwords

5. **Full backup encryption:** Set the same strong `BACKUP_ENCRYPTION_KEY`
   value on both the `web` and `cron` services before enabling Full Backup Mode.
   Keep a protected copy outside TrueNAS; the archive cannot be restored without it.

6. **Deploy** — PA will be available at `http://your-truenas-ip:1627`

7. **First login:** Open PA and use the first-time setup form to create the initial administrator. No default login exists.

8. **Configure Stripe:** Go to Settings > Billing to enter your Stripe API keys

## Manual Install via Docker Compose (SSH)

If you prefer SSH over the UI:

```bash
# Create datasets
zfs create tank/apps/project-alpha
zfs create tank/apps/project-alpha/uploads
zfs create tank/apps/project-alpha/config
zfs create tank/apps/project-alpha/backups
zfs create tank/apps/project-alpha/db

# Clone the repo
cd /mnt/tank/apps/project-alpha
git clone https://github.com/ledgetoptechnologies/Project-Alpha.git .

# Edit docker-compose.truenas.yml — change all passwords!
vi docker-compose.truenas.yml

# Deploy
docker compose -f docker-compose.truenas.yml up -d
```

## Updating

```bash
cd /mnt/tank/apps/project-alpha
docker compose -f docker-compose.truenas.yml pull
docker compose -f docker-compose.truenas.yml up -d
```

## Backup

PA data is stored in the datasets you created. Use TrueNAS snapshot and replication tasks to back up:
- `tank/apps/project-alpha/db` (database)
- `tank/apps/project-alpha/uploads` (uploaded files)
- `tank/apps/project-alpha/config` (configuration)

The built-in cron container also performs daily MySQL dumps to the backups volume.
Snapshots are not a substitute for application-consistent database backups;
retain both and test restoring them periodically.

## Support
- GitHub: https://github.com/ledgetoptechnologies/Project-Alpha/issues
- Website: https://project-alpha.tech
- Email: bkoltz@ledgetoptechnologies.com
