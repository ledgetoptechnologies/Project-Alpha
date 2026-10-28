# Synthetic archive receiver fixtures

This test-only companion builds on runtime commit
`5ac0fb731a04d5e8e9aefca4256143fff6cd8644`. It changes no production application
code. Keep it separate from the archive hotfix release.

Use a trusted PA PHP test image whose Composer lock matches the checkout, with
Node and Docker available. Choose an existing output directory without a
`receiver-fixtures.json` file. The runner refuses to overwrite an export.

```powershell
$env:ARCHIVE_HTTP_TEST_IMAGE = 'your-trusted-pa-test-image'
$env:ARCHIVE_HTTP_EXPORT_DIR = 'C:/task-owned-empty-directory'
node scripts/run-client-archive-policy-http-mysql.mjs
```

On Linux, set the same environment variables before running Node. Each run uses
a fresh random MySQL database/container, applies the baseline and migrations,
and exercises authenticated canonical archive/restore HTTP endpoints. MySQL and
the PHP test container each have 768 MiB memory, one CPU and 128 PID limits.
Owned containers are removed in the runner's cleanup. No existing PA stack,
volume, credentials or records are used. Outbound portal delivery is disabled,
the synthetic profile has no credentials, and no worker/sender runs.

With the export variable absent, the original regression runs without portal
fixture setup or API capture. With it present, the test creates a synthetic
read-only API key in memory and its disposable database, sets up a protected NONE
client/workspace/profile, queues a real initial snapshot, and captures:

1. Initial present inventory, client profile and portal snapshot/activation.
2. Actual archive tombstone and workspace revocation event.
3. Actual restored presence and portal snapshot/activation, with original public
   identity and a new numeric client ID after collision with an unrelated row.
4. Second archive, before fault injection.
5. Missing-policy restore refusal, retaining the tombstone and exactly the same
   portal outbox as step 4. **The final state is archived.**

The export includes actual response bodies and outbox payload JSON bytes with
SHA-256 hashes, plus parsed objects for convenience. Outbox rows are cumulative
at each phase; replay each delivery ID once for ordinary progression, and use
the same captured delivery deliberately for duplicate/reordered-event tests.
There are no API tokens, passwords, request headers, cookies, private policy
rows or real client data in the export. Do not add those to the artifact.

`payloadJson` is the actual **inner outbox projection**, not a signed HTTP wire
request. `PortalProjectionOutboxSender::sendClaim` wraps it in an outer
`portal.projection` event before signing. A receiver harness must explicitly
construct its expected outer test envelope from those captured projection
bytes. This capture does not prove HMAC authentication or network delivery.

Run again into a different output directory for a second independent source.
The synthetic client public ID and application UUID deliberately collide;
source-instance IDs, history epochs, portal workspace IDs and delivery IDs come
from the actual independent producers. Keep each directory identity together
with its corresponding portal stream in the receiver's test connector config.
Portal payloads use their own `applicationKey` and source generation/sequence;
they do not contain the directory API's source-instance/history-epoch fields.
Do not rewrite captured envelopes or pretend those identity formats are equal.

This case proves protected NONE never acquires authority on restoration.
Bound-login revocation during archive is covered separately by the service
suite; this export does not claim a bound-principal HTTP scenario. Feeding
these artifacts through isolated Operations/Client receivers, checking source
isolation, stale/replay handling, catalog visibility and ineligibility remains
the integration owner's acceptance work. Component capture alone is not full
end-to-end acceptance. Never run fault injection against production or the
retained shared staging datasets, and retain the active Viewer cleanup hold.
