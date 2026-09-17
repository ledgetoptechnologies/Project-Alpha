-- Route new managed-delivery intents through the one existing External
-- Operations connection. Existing rows keep their pinned legacy metadata for
-- audit and accepted-receipt history, but unresolved legacy work is stopped.

ALTER TABLE managed_delivery_intent_outbox
    DROP FOREIGN KEY fk_managed_delivery_profile;

ALTER TABLE managed_delivery_intent_outbox
    ADD COLUMN transport_mode ENUM('legacy_profile','external_ops') NOT NULL DEFAULT 'legacy_profile' AFTER target_delivery_id,
    MODIFY COLUMN integration_profile_id BIGINT UNSIGNED NULL;

ALTER TABLE managed_delivery_intent_outbox
    ADD CONSTRAINT fk_managed_delivery_profile
        FOREIGN KEY (integration_profile_id) REFERENCES portal_integration_profiles(id) ON DELETE RESTRICT;

-- Never let an unresolved row continue making direct calls to the retired
-- receiver. Historical accepted receipts remain untouched. An administrator
-- may recreate a failed provision as a new request. Legacy accepted receipts
-- and revocations require manual remediation in the original delivery system;
-- they are never rebound to the current External Operations contract.
UPDATE managed_delivery_intent_outbox
SET dead_lettered_at=COALESCE(dead_lettered_at,CURRENT_TIMESTAMP(6)),
    last_error_code='legacy_transport_retired_manual_remediation_required',
    claim_token=NULL,
    claimed_at=NULL
WHERE transport_mode='legacy_profile'
  AND delivered_at IS NULL;

-- These values were a second receiver/signer configuration. Pending legacy
-- rows already pin the complete old contract and do not depend on these keys.
DELETE FROM app_config
WHERE organization_id=0
  AND config_key IN ('managed_delivery_intent_url','managed_delivery_profile_id');
