# Local operator capabilities key

`bin/provision-api-v2-capabilities-key.php` is an optional local installation tool.
It creates one key with exactly `api.capabilities.read` on an **existing** API v2
application. It cannot select other scopes, create applications, modify old keys,
impersonate an admin, or run from a web request. The caller must be a real root PHP
CLI process with no active PHP session. The audit actor is `local_operator`, UID 0,
with no human user ID.

Use this for an Ops Sync observer only after independently verifying the source
instance, history epoch, and the existing Ops application UUID. A generic app name
is not sufficient. Public application UUIDs and source identity are not secrets.
The key is secret even though its scope is read-only.

## Preparation

The application must be migrated through 0102 or later, with complete directory
and project authorization states. The tool uses the normal installation database
configuration, so run it in the intended installation's PHP container, preserving
its existing environment. No new configuration variables or Compose changes are
required by the tool itself.

Choose a durable private location **outside the webroot**, owned by root, with a
0700 parent directory. Its ancestor directories must also be root-owned and not
writable by other users (a root-owned sticky `/tmp` is permitted for tests only).
Do not use ephemeral container storage for operational keys. If the installation
has no suitable persistent private mount, prepare one before using this tool.
The tool refuses symlinks, hard links, permissive files, and unsafe parents.

Run a dry run with independently verified UUIDs and a new random request UUIDv4:

```sh
php bin/provision-api-v2-capabilities-key.php --dry-run \
  --request-id=<new-request-uuid> \
  --source-instance-id=<expected-source-uuid> \
  --history-epoch=<expected-history-epoch> \
  --application-id=<existing-ops-application-uuid> \
  --plan-file=/protected/persistent/observer-plan.json \
  --secret-file=/protected/persistent/observer-key.json
```

Dry run generates no key and changes no database rows. It exclusively creates a
private plan containing the exact identity, scope, current two authorization
generations, and request hash. Review that plan and its printed SHA-256 before
continuing. Keep the request identity and paths for reconciliation.

## Apply and reconciliation

**Binding a new key advances both application authorization generations once.**
Existing key permissions remain unchanged, but pending Ops commands may need their
usual authorization refresh/reconciliation. Coordinate outstanding commands before
applying, then recheck both source connections afterward.

```sh
php bin/provision-api-v2-capabilities-key.php --apply \
  --plan-file=/protected/persistent/observer-plan.json \
  --reviewed-plan-sha256=<reviewed-plan-sha256> \
  --confirm-create-capabilities-key
```

Before any grant, the tool saves the generated secret and immutable plan in a new
0600 file, flushes and fsyncs both the file and parent directory, and writes a
private attempt journal. Only then does it atomically insert the key, bind it to
the selected application, advance generations, and verify the exact audit row.
Audit failure rolls back the key and both generations. The tool never prints key
material, database diagnostics, or credentials. Transfer the saved secret through
the destination's supported private secret mechanism; never paste it into logs.

After interruption, an ambiguous database acknowledgement, or any failure, preserve
the plan, key receipt, and outcome journals. Do not delete them and rerun with a new
request or secret. Reconcile with the original material:

```sh
php bin/provision-api-v2-capabilities-key.php --reconcile \
  --plan-file=/protected/persistent/observer-plan.json \
  --reviewed-plan-sha256=<reviewed-plan-sha256>
```

- `already_committed` (exit 0): exact original key and audit are present. It makes
  no changes, even if later work advanced authorization generations again.
- `not_committed` (exit 3): no original grant exists. Reconciliation never inserts
  or repairs anything. After confirming the original reviewed identities and
  generations still apply, the original apply command plus `--resume-prepared`
  can use the same saved key. Without that flag, an existing receipt only reconciles.
- Held (exit 1): inspect private receipts and current **nonsecret** metadata. A
  changed identity, generation, existing key scope/revocation/binding, missing
  audit, mismatched request, or unsafe path needs operator review. The tool does
  not repair inconsistent grants, restore a database, or automatically generate
  another key.

Request serialization uses the existing source/application row locks; separate
output files cannot create the same request twice. The private file lock also
prevents concurrent use of the same receipt. A revoked key is never reactivated.

## Tests

The SQLite and filesystem cases require a disposable Linux root CLI test runner.
The database suite additionally requires an isolated MySQL database named
`pa_capability_proof_<lowercase-alphanumeric-suffix>` and the explicit fixture
opt-in `PA_TEST_DISPOSABLE_CAPABILITY_KEYS=1`. **That suite resets its synthetic key,
application, authorization, and audit rows. Never enable it on an installation.**
Load `database/baseline.sql` and run all normal migrations before the suite. It
covers real MySQL JSON/audit semantics, transactions, lost commit acknowledgement,
concurrent duplicate requests, unchanged existing keys, CLI crash receipts,
explicit resume, and secret-free output. No live credentials or emails are needed.
