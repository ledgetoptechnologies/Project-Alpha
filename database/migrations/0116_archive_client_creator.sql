ALTER TABLE archived_clients ADD COLUMN created_by INT NULL AFTER organization_id;
CREATE INDEX idx_archived_clients_created_by ON archived_clients (created_by);
