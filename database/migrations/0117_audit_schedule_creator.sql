ALTER TABLE audit_schedules ADD COLUMN created_by INT NULL AFTER organization_id;
CREATE INDEX idx_audit_schedules_created_by ON audit_schedules (created_by);
UPDATE audit_schedules SET is_active=0 WHERE report_type='expense' AND created_by IS NULL;
