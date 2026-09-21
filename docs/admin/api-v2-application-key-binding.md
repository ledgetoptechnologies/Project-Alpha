---
layout: default
title: API v2 application key binding
---

# API v2 application key binding

`bin/bind-api-v2-key-to-application.php` is a local, database-only operator
tool for adding a dedicated explicit-scope API key to an **existing** API v2
application. Use it when independent least-privilege keys for the same
external application need to resolve the same Directory and Project mappings.
It does not create an application, create an API key, generate or accept a
secret, enable an endpoint, backfill a resource, or grant portal access.

The command requires migrations 0088, 0089, and 0102, an active key with
`api.capabilities.read` and no legacy `full` scope, the target application's
public UUID, and valid Directory and Project authorization state for every
affected application. It takes only the numeric key ID; never put a key secret
in a shell command, ticket, or output capture.

Take a database backup, review the selected key and target public UUID, and
run the exact dry run before applying:

```bash
php bin/bind-api-v2-key-to-application.php \
  --api-key-id=123 \
  --application-id=423e4567-e89b-42d3-a456-426614174000 \
  --dry-run

php bin/bind-api-v2-key-to-application.php \
  --api-key-id=123 \
  --application-id=423e4567-e89b-42d3-a456-426614174000 \
  --apply \
  --confirm-bind-existing-api-v2-application
```

An unbound eligible key is attached atomically and advances that application's
Directory and Project authorization generations, so clients must refresh their
authorization handshake. A same-key/same-application rerun is a read-only
no-op after it revalidates both authorization states.

## Rebinding requires an explicit old application selection

The command refuses to move a key that is bound to another application unless
the operator supplies that current public UUID and explicitly confirms the
move. First run the selected rebind as a dry run:

```bash
php bin/bind-api-v2-key-to-application.php \
  --api-key-id=123 \
  --application-id=423e4567-e89b-42d3-a456-426614174000 \
  --rebind-from-application-id=323e4567-e89b-42d3-a456-426614174000 \
  --dry-run
```

Then repeat it with both confirmations:

```bash
php bin/bind-api-v2-key-to-application.php \
  --api-key-id=123 \
  --application-id=423e4567-e89b-42d3-a456-426614174000 \
  --rebind-from-application-id=323e4567-e89b-42d3-a456-426614174000 \
  --apply \
  --confirm-bind-existing-api-v2-application \
  --confirm-rebind-api-v2-application
```

An acknowledged rebind locks the key and both applications, validates every
Directory and Project authorization generation, advances all four generations,
and changes the key binding in one transaction. Existing clients must refresh
their authorization handshake after that change. If any state is missing,
invalid, exhausted, or concurrently changed, the command rolls back and makes
no binding or generation change.

The six read-only API v2 routes are enabled by default and remain protected by
application binding and exact scopes. Keep every command route disabled until
the separate Directory/Project backfill, release evidence, deployment review,
and cutover procedures are complete. Set a read-only route's documented
environment variable to `false` only when an installation must suppress it.
