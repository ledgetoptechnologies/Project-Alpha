-- Preserve the immutable client and portal identity across the legacy physical
-- archive/restore workflow. Existing archive rows remain restorable as legacy
-- records; newly archived rows always carry the complete identity state.
--
-- The release baseline already contains this final shape. Fresh installations
-- still replay every forward migration after applying the baseline, so this
-- migration must accept either the old shape (none of these columns) or the
-- baseline shape (all of them). A partial shape fails closed for investigation.

SET @archive_identity_column_count := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE()
      AND table_name='archived_clients'
      AND column_name IN (
          'public_id','client_type','portal_principal_id','portal_manual_state',
          'portal_canonical_email','portal_identity_binding_ids_json',
          'portal_principal_authorization_version',
          'portal_principal_disabled_for_archive','portal_principal_was_present',
          'portal_entitlement_ids_json','portal_affected_workspace_ids_json'
      )
);

SET @archive_identity_columns_sql := CASE @archive_identity_column_count
    WHEN 0 THEN 'ALTER TABLE archived_clients
        ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER client_id,
        ADD COLUMN client_type ENUM(''unknown'',''business'',''consumer'') NULL AFTER organization_id,
        ADD COLUMN portal_principal_id BIGINT UNSIGNED NULL AFTER created_at,
        ADD COLUMN portal_manual_state ENUM(''automatic'',''revoked'') NULL AFTER portal_principal_id,
        ADD COLUMN portal_canonical_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER portal_manual_state,
        ADD COLUMN portal_identity_binding_ids_json JSON NULL AFTER portal_canonical_email,
        ADD COLUMN portal_principal_authorization_version INT UNSIGNED NULL AFTER portal_identity_binding_ids_json,
        ADD COLUMN portal_principal_disabled_for_archive TINYINT(1) NULL AFTER portal_principal_authorization_version,
        ADD COLUMN portal_principal_was_present TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_principal_disabled_for_archive,
        ADD COLUMN portal_entitlement_ids_json JSON NULL AFTER portal_principal_was_present,
        ADD COLUMN portal_affected_workspace_ids_json JSON NULL AFTER portal_entitlement_ids_json'
    WHEN 11 THEN 'SELECT 1'
    ELSE 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''Migration 0085 found a partial archived client identity schema'''
END;
PREPARE archive_identity_columns_stmt FROM @archive_identity_columns_sql;
EXECUTE archive_identity_columns_stmt;
DEALLOCATE PREPARE archive_identity_columns_stmt;

SET @archive_identity_public_index_sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='archived_clients'
       AND index_name='uq_archived_clients_public_id')=0,
    'ALTER TABLE archived_clients ADD UNIQUE KEY uq_archived_clients_public_id (public_id)',
    'SELECT 1'
);
PREPARE archive_identity_public_index_stmt FROM @archive_identity_public_index_sql;
EXECUTE archive_identity_public_index_stmt;
DEALLOCATE PREPARE archive_identity_public_index_stmt;

SET @archive_identity_principal_index_sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='archived_clients'
       AND index_name='idx_archived_clients_portal_principal')=0,
    'ALTER TABLE archived_clients ADD KEY idx_archived_clients_portal_principal (portal_principal_id)',
    'SELECT 1'
);
PREPARE archive_identity_principal_index_stmt FROM @archive_identity_principal_index_sql;
EXECUTE archive_identity_principal_index_stmt;
DEALLOCATE PREPARE archive_identity_principal_index_stmt;

SET @archive_identity_principal_fk_sql := IF(
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema=DATABASE() AND table_name='archived_clients'
       AND constraint_name='fk_archived_clients_portal_principal'
       AND constraint_type='FOREIGN KEY')=0,
    'ALTER TABLE archived_clients ADD CONSTRAINT fk_archived_clients_portal_principal FOREIGN KEY (portal_principal_id) REFERENCES portal_principals(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE archive_identity_principal_fk_stmt FROM @archive_identity_principal_fk_sql;
EXECUTE archive_identity_principal_fk_stmt;
DEALLOCATE PREPARE archive_identity_principal_fk_stmt;
