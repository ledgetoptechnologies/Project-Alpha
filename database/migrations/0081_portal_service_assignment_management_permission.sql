-- Migration 0081: explicit authority for staff-managed portal service assignments.
--
-- Assignments describe which catalog services a business entity may request.
-- They do not grant portal login, workspace membership, delivery access, or
-- any other entitlement. Existing owners receive the permission; other
-- system roles remain denied unless an administrator delegates it.

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'portal_service_assignments.manage', CASE WHEN name = 'owner' THEN 1 ELSE 0 END
FROM roles
WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);
