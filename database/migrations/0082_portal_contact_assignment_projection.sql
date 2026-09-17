-- Migration 0082: default-off schema-v4 contact-assignment projection.
--
-- This is a producer capability only. It does not add or infer contacts,
-- principals, memberships, entitlements, billing authority, or notifications.
-- Existing schema-v2/v3 profiles remain unchanged until an administrator
-- explicitly enables the capability on the single external connection.

SET @portal_contact_assignment_sql=IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE()
       AND table_name='portal_integration_profiles'
       AND column_name='contact_assignment_projection_enabled')=0,
    'ALTER TABLE portal_integration_profiles ADD COLUMN contact_assignment_projection_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER relation_projection_enabled',
    'SELECT 1'
);
PREPARE portal_contact_assignment_stmt FROM @portal_contact_assignment_sql;
EXECUTE portal_contact_assignment_stmt;
DEALLOCATE PREPARE portal_contact_assignment_stmt;
