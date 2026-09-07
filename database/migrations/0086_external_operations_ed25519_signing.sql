-- Adds a non-secret schema marker for the encrypted External Operations
-- credential envelope. Existing HMAC-SHA256 credentials remain active and are
-- read as schema version 1 until an administrator explicitly stages and
-- activates an Ed25519 key on the same connection.
--
-- Private Ed25519 key bytes are intentionally never stored in this migration
-- or a standalone table. They remain only in external_ops_credentials_enc,
-- encrypted by Project Alpha's persisted application encryption key.

INSERT INTO app_config (organization_id,config_key,config_value)
VALUES (0,'external_ops_signing_schema_version','2')
ON DUPLICATE KEY UPDATE config_value=config_value;
