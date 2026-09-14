# API v2 directory backfill

`bin/backfill-api-v2-directory.php` is a local database-only operator tool for seeding the API v2 directory revision foundation from existing clients and organizations. It makes no network calls and never reads or emits credentials, names, addresses, emails, phone numbers, or public IDs.

It defaults to dry-run. Run bounded batches (at most 500 records), retain the returned non-secret local resume cursor, and inspect aggregate counts before applying the same cursor/batch. Start with an explicit dry run:

```sh
php bin/backfill-api-v2-directory.php --type=all --limit=100 --dry-run
```

Apply requires all three flags:

```sh
php bin/backfill-api-v2-directory.php --type=all --limit=100 --apply --confirm-api-v2-directory-backfill --maintenance-window-confirmed
```

Run it only in an approved maintenance window with ordinary client and organization mutations paused. The tool locks each selected source row and directory-state row, then rechecks state in its transaction, so a concurrent writer wins rather than being overwritten; the maintenance window is still required to give a complete, reviewable historical baseline.

The tool validates migrations `0088_api_v2_application_identity.sql` and `0089_api_v2_directory_revision_foundation.sql`, all required tables/columns, cursor syntax, and every selected source identity before it writes. It fails closed on malformed local/public IDs, malformed existing state, missing matching change history, or a live row that conflicts with divergent, higher-revision, or tombstoned state. Existing current state is idempotently skipped only when its matching `upsert` change is present. No conflicting state is ever changed; investigate it separately.

After all resume cursors are exhausted, perform a coverage audit: compare the count of valid existing clients and organizations with present directory-state rows and matching `upsert` change rows, and resolve every refusal before enabling any directory route or capability.
