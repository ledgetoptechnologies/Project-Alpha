# Managed delivery

Managed delivery delegates portal grants and optional guest links to the same external application configured under **Settings → Custom integrations**. Project Alpha does not connect to object storage and does not store object paths, recipient email addresses, bearer URLs, or storage credentials. Standalone Dropbox, Google Drive, S3, and R2 resolvers remain available for installations that do not use an external operations application.

The provider and guest links are separate, default-off policy toggles under **Settings → Links & Storage**. There is no second destination URL, profile, Access token, or signing key for managed delivery. Complete and enable the External Operations connection first, then use **Test delivery capability** before enabling the provider.

## Wire contract

Preflight, provision, and revoke are sent to the configured External Operations signed event URL with the existing Cloudflare Access service-token headers and `X-PA-Event-ID`, `X-PA-Timestamp`, and `X-PA-Signature` authentication. The strict outer envelope is:

```json
{
  "event_id": "delivery.intent:provision:<intent.deliveryId>",
  "event_type": "delivery.intent",
  "occurred_at": "2026-09-04T12:00:00.000Z",
  "schema_version": 1,
  "application_key": "configured-application-key",
  "intent_kind": "preflight | provision | revoke",
  "intent": {}
}
```

`event_id` is the bounded operation identity `delivery.intent:<intent_kind>:<intent.deliveryId>` and is the immutable retry identity. The intent delivery ID is limited to 96 characters. This lets preflight, provision, and revoke use distinct idempotency records even if a caller reuses the same inner ID. Project Alpha retries only timeouts, rate limits, and server failures with the same event ID and byte-stable envelope. A changed payload for an existing operation ID is a terminal conflict.

The receiver returns HTTP 200 with exact `{ "ok": true, "event_id": "…", "status": "completed|duplicate", "result": { … } }`. Provision and revoke results contain only `{ "receiptId": "…", "status": "accepted" }`; only that neutral receipt and local status are retained. Preflight returns the existing readiness capability object.

## Durable delivery and upgrade behavior

Every request is written to `managed_delivery_intent_outbox` before transmission. New rows pin the External Operations destination, application key, HMAC credential epoch hash, timeout, and retry limit without copying a secret. The External Operations connection cannot rotate while a queued unified intent, recoverable revocation, or accepted unrevoked `external_ops` delivery still depends on it. Retired `legacy_profile` rows preserve history but never establish continuity with the current receiver.

Migration `0084_unify_managed_delivery_external_ops.sql` removes the obsolete URL/profile settings and marks existing rows as `legacy_profile`. It terminally pauses every unresolved legacy row with the explicit `legacy_transport_retired_manual_remediation_required` status so no old direct receiver can be called after the upgrade. Historical accepted receipts remain unchanged. An administrator can recreate a failed provision as a new request, but Project Alpha never sends or rebinds a legacy receipt or revocation through the current External Operations receiver: those records require manual remediation in the original delivery system and remain available for audit. New rows use `external_ops`. Do not delete a legacy portal integration profile while historical rows still reference it.

The one-minute sender uses bounded batches, claim leases, retry backoff, and dead-letter state. Accepted `external_ops` deliveries can be revoked explicitly. A failed `external_ops` revocation can be explicitly requeued using the same durable row; it never creates a second revocation. Legacy rows remain blocked for manual remediation.

## Rollout

1. Apply migrations through `0084` using the normal backed-up migration service.
2. Confirm the existing External Operations connection is enabled and its signed event URL, application key, Access service token, and HMAC secret are healthy.
3. Run **Test delivery capability** while managed delivery is still disabled.
4. Enable managed delivery. Leave guest links disabled unless explicitly required.
5. Confirm one portal provision and revoke lifecycle, then inspect only neutral receipts and bounded error codes.

No client invitation, automatic delivery, or public-link fallback is triggered by this migration or by enabling the provider.
