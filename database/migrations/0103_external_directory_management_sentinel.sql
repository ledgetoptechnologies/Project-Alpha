-- Preserve explicitly activated directory ownership if the policy tables are
-- subsequently unavailable.  app_config predates the managed-directory schema.
INSERT INTO app_config (organization_id,config_key,config_value)
SELECT 0,'api_v2_directory_management_ownership_active','1'
WHERE EXISTS (
    SELECT 1 FROM api_v2_directory_management_policy
    WHERE singleton=1 AND configured_enabled=1 AND ownership_active=1
)
ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);
