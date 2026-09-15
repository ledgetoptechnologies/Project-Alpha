-- Archive is a revocation boundary, while restore is identity/lifecycle only.
-- Bearer tokens and delivery receipts remain for audit but cannot reactivate.

ALTER TABLE api_v2_project_lifecycle_command_receipts
    ADD COLUMN result_portal_publish_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER result_archived_at,
    ADD COLUMN result_public_project_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER result_portal_publish_enabled,
    ADD CONSTRAINT chk_project_receipt_portal_publish CHECK (result_portal_publish_enabled IN (0,1)),
    ADD CONSTRAINT chk_project_receipt_public_publish CHECK (result_public_project_enabled IN (0,1));

UPDATE projects
SET portal_publish_enabled=0,public_project_enabled=0
WHERE archived_at IS NOT NULL;

-- Stop undelivered presentation before an archived Project can be restored.
-- Accepted receipts require the canonical PHP backfill/service so the pinned
-- receiver contract can be validated and an explicit revoke intent retained.
UPDATE managed_delivery_intent_outbox delivery
JOIN projects project
  ON delivery.scope_type='project' AND delivery.scope_public_id=project.public_id
SET delivery.dead_lettered_at=COALESCE(delivery.dead_lettered_at,CURRENT_TIMESTAMP(6)),
    delivery.last_error_code=COALESCE(delivery.last_error_code,'project_archived_before_delivery'),
    delivery.claim_token=NULL,delivery.claimed_at=NULL
WHERE project.archived_at IS NOT NULL
  AND delivery.intent_type='provision'
  AND delivery.delivered_at IS NULL;
