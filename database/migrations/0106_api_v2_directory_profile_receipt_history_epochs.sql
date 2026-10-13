-- Scope organization/client profile command receipts to the history epoch in
-- which they were accepted. Existing rows belong to the single current epoch
-- at upgrade time; future epoch rotations retain them as immutable history.
ALTER TABLE api_v2_directory_organization_profile_command_receipts
    ADD COLUMN history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER application_pk;
UPDATE api_v2_directory_organization_profile_command_receipts
SET history_epoch=(SELECT history_epoch FROM api_v2_history_identity WHERE singleton=1)
WHERE history_epoch IS NULL;
ALTER TABLE api_v2_directory_organization_profile_command_receipts
    DROP PRIMARY KEY,
    MODIFY history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD PRIMARY KEY(application_pk,history_epoch,command_id),
    ADD CONSTRAINT chk_api_v2_org_profile_receipt_history_epoch CHECK(history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');

ALTER TABLE api_v2_directory_client_profile_command_receipts
    ADD COLUMN history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER application_pk;
UPDATE api_v2_directory_client_profile_command_receipts
SET history_epoch=(SELECT history_epoch FROM api_v2_history_identity WHERE singleton=1)
WHERE history_epoch IS NULL;
ALTER TABLE api_v2_directory_client_profile_command_receipts
    DROP PRIMARY KEY,
    MODIFY history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD PRIMARY KEY(application_pk,history_epoch,command_id),
    ADD CONSTRAINT chk_api_v2_client_profile_receipt_history_epoch CHECK(history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
