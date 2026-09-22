-- Scope the original generic directory command receipts to the active history
-- epoch. Existing rows belong to the single current epoch at upgrade time.
-- Future epoch rotations retain old receipts as immutable history without
-- allowing them to replay in the replacement epoch.
ALTER TABLE api_v2_directory_binding_command_receipts
    ADD COLUMN history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER resource_type;
UPDATE api_v2_directory_binding_command_receipts
SET history_epoch=(SELECT history_epoch FROM api_v2_history_identity WHERE singleton=1)
WHERE history_epoch IS NULL;
ALTER TABLE api_v2_directory_binding_command_receipts
    DROP PRIMARY KEY,
    MODIFY history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD PRIMARY KEY(application_pk,resource_type,history_epoch,command_id),
    ADD CONSTRAINT chk_api_v2_directory_receipt_history_epoch CHECK(history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');

ALTER TABLE api_v2_directory_binding_revision_refresh_receipts
    ADD COLUMN history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER resource_type;
UPDATE api_v2_directory_binding_revision_refresh_receipts
SET history_epoch=(SELECT history_epoch FROM api_v2_history_identity WHERE singleton=1)
WHERE history_epoch IS NULL;
ALTER TABLE api_v2_directory_binding_revision_refresh_receipts
    DROP PRIMARY KEY,
    MODIFY history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD PRIMARY KEY(application_pk,resource_type,history_epoch,command_id),
    ADD CONSTRAINT chk_api_v2_directory_refresh_receipt_history_epoch CHECK(history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');

ALTER TABLE api_v2_directory_create_command_receipts
    ADD COLUMN history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER resource_type;
UPDATE api_v2_directory_create_command_receipts
SET history_epoch=(SELECT history_epoch FROM api_v2_history_identity WHERE singleton=1)
WHERE history_epoch IS NULL;
ALTER TABLE api_v2_directory_create_command_receipts
    DROP PRIMARY KEY,
    MODIFY history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD PRIMARY KEY(application_pk,resource_type,history_epoch,command_id),
    ADD CONSTRAINT chk_api_v2_directory_create_receipt_history_epoch CHECK(history_epoch REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
