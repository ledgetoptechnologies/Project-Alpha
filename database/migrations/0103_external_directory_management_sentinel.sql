-- Preserve explicitly activated directory ownership if the policy tables are
-- subsequently unavailable.  app_config predates the managed-directory schema.
-- Seed every upgraded installation with the explicit local value.  INSERT
-- IGNORE preserves an intentional value already written by an earlier run.
INSERT IGNORE INTO app_config (organization_id,config_key,config_value)
VALUES (0,'api_v2_directory_management_ownership_active','0');

-- An activated policy wins over the default, while an existing active
-- sentinel is never downgraded for an inactive/missing policy row.
UPDATE app_config
SET config_value='1'
WHERE organization_id=0
  AND config_key='api_v2_directory_management_ownership_active'
  AND EXISTS (
      SELECT 1 FROM api_v2_directory_management_policy
      WHERE singleton=1 AND configured_enabled=1 AND ownership_active=1
  );
