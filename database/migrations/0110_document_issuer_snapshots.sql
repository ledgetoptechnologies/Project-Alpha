-- Preserve legacy appearance; new documents default to the selected company policy.
-- Historical identity cannot be reconstructed; no existing issuer is rewritten.
ALTER TABLE quotes ADD COLUMN issuer_mode VARCHAR(16) NOT NULL DEFAULT 'legacy', ADD COLUMN issuer_snapshot JSON NULL;
ALTER TABLE quotes ALTER COLUMN issuer_mode SET DEFAULT 'default';
ALTER TABLE contracts ADD COLUMN issuer_mode VARCHAR(16) NOT NULL DEFAULT 'legacy', ADD COLUMN issuer_snapshot JSON NULL;
ALTER TABLE contracts ALTER COLUMN issuer_mode SET DEFAULT 'default';
ALTER TABLE invoices ADD COLUMN issuer_mode VARCHAR(16) NOT NULL DEFAULT 'legacy', ADD COLUMN issuer_snapshot JSON NULL;
ALTER TABLE invoices ALTER COLUMN issuer_mode SET DEFAULT 'default';
ALTER TABLE project_invoices ADD COLUMN issuer_mode VARCHAR(16) NOT NULL DEFAULT 'legacy', ADD COLUMN issuer_snapshot JSON NULL;
ALTER TABLE project_invoices ALTER COLUMN issuer_mode SET DEFAULT 'default';
