# Approved client service-request access

This additive policy allows an already approved client principal to submit a
general request for its own organization, or its own standalone client root.
It grants only `request.create`. It grants no project visibility, manager
permissions, files, employee synchronization, email or financial commands.

The existing three client-scoped portal defaults remain unchanged. Upgrading PA
alone does not enable the new policy: the normal application config setting is
absent/off until an authorized operator acknowledges a qualified receiver.
No environment variable or database migration is required.

## Receiver prerequisite and operator API

Before acknowledging the policy, independently qualify and deploy the Client
receiver's `native-readable-project-v1` rule. Any supplied project ID must require
the principal's current project `directory.read` authority, including the atomic
request-write fence. An organization request grant must never make a guessed,
unreadable project a valid request destination. Root/general requests remain
available without granting project visibility. Retain the qualified receipt.

The existing authenticated, CSRF-protected settings handler has two additive
operator/API actions, both requiring `settings.manage` and `users.manage`:

- `acknowledge-portal-request-policy` records the fixed receiver policy version,
  reviewed qualification SHA-256 and exact current receiver-route SHA-256. These
  are internal release/operator evidence fields, not a form clients or ordinary
  administrators must fill out. The server binds the acknowledgement to the
  existing producer profile and application. Route/application/profile drift
  closes new grant issuance. This is an operator acknowledgement, not network
  attestation of the remote code. An unfixed receiver rollback must not retain
  enabled request execution under this acknowledgement.
- `reconcile-portal-request-access` accepts an explicit cohort of 1–100 exact
  `client_ids`. It requires the matching qualified policy before changing
  anything. It reconciles only existing approved, active, associated principals
  in active linked roots. It never creates principals, changes eligibility,
  binds identities or provisions other people in the same organization.

Policy metadata is stored in the normal `app_config` record
`portal_client_root_request_policy`; secrets are not stored there. A qualified
acknowledgement permits request intent for newly created approved principals.
Existing clients, including protected contact identities, require the explicit
reviewed cohort action. Neutral protected-contact profile writes keep their
existing identity freeze and do not add request grants.

## Reconciliation and revocation

Only rows stamped with both this policy's deterministic opaque public ID and
matching content-derived policy source version are managed. A supported manual
entitlement edit gives the row a new version and relinquishes automatic
ownership. Existing manual allows (including inactive ones), denies, expiry
intent and unrelated capabilities are not overwritten or duplicated.

Reconciliation retires owned old-root grants when the client moves, is revoked,
deactivated or loses its association. Archive retires owned root intent before
the existing archive version/restore fence runs. Tombstones and IDs remain in
history; an approved restore reuses the owned row. Another eligible contact
under the same principal/root keeps its authority, and another valid root is
preserved. Projection changes and audit events share the caller's transaction;
explicit cohort publication does not invoke general client provisioning.

The policy controls grant issuance. Existing explicit manual authority and the
ordinary profile pause/revocation projection lifecycle remain authoritative.
Receiver flags, real catalog readiness and actual permission grants are separate
release prerequisites; this change does not enable them or create services.
