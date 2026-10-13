-- Stable non-enumerable identities for the read-only API v2 financial projection.
-- Existing numeric primary keys remain internal implementation details.
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='public_links' AND index_name='idx_public_links_financial_latest')=0,'CREATE INDEX idx_public_links_financial_latest ON public_links(document_type,document_id,revoked,created_at,id)','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='public_id')=0,'ALTER TABLE invoices ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contracts' AND column_name='public_id')=0,'ALTER TABLE contracts ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='public_id')=0,'ALTER TABLE project_invoices ADD COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='currency')=0,'ALTER TABLE invoices ADD COLUMN currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER public_id','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contracts' AND column_name='currency')=0,'ALTER TABLE contracts ADD COLUMN currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER public_id','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_invoices' AND column_name='currency')=0,'ALTER TABLE project_invoices ADD COLUMN currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER public_id','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;

-- Identity/currency backfill is not a user document edit. Preserve ON UPDATE timestamps.
UPDATE invoices SET public_id=LOWER(HEX(RANDOM_BYTES(16))),updated_at=updated_at WHERE public_id IS NULL;
UPDATE contracts SET public_id=LOWER(HEX(RANDOM_BYTES(16))),updated_at=updated_at WHERE public_id IS NULL;
UPDATE project_invoices SET public_id=LOWER(HEX(RANDOM_BYTES(16))),updated_at=updated_at WHERE public_id IS NULL;

-- Current-revision pricing snapshots are the durable document currency when
-- present. Legacy rows without one use USD because the existing public Stripe
-- checkout submits `usd` unconditionally; this records that historical payment
-- meaning without changing any total, balance, or payment amount.
UPDATE invoices document LEFT JOIN document_pricing_adjustment_snapshots snapshot
  ON snapshot.document_type='invoice' AND snapshot.document_id=document.id
 AND snapshot.document_revision=document.revision_number
SET document.currency=COALESCE(snapshot.currency,'USD'),document.updated_at=document.updated_at WHERE document.currency IS NULL;
UPDATE contracts document LEFT JOIN document_pricing_adjustment_snapshots snapshot
  ON snapshot.document_type='contract' AND snapshot.document_id=document.id
 AND snapshot.document_revision=document.revision_number
SET document.currency=COALESCE(snapshot.currency,'USD'),document.updated_at=document.updated_at WHERE document.currency IS NULL;
UPDATE project_invoices SET currency='USD',updated_at=updated_at WHERE currency IS NULL;

ALTER TABLE invoices MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16))));
ALTER TABLE contracts MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16))));
ALTER TABLE project_invoices MODIFY COLUMN public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT (LOWER(HEX(RANDOM_BYTES(16))));
ALTER TABLE invoices MODIFY COLUMN currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'USD';
ALTER TABLE contracts MODIFY COLUMN currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'USD';
ALTER TABLE project_invoices MODIFY COLUMN currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'USD';

SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='invoices' AND constraint_name='chk_invoices_public_id_shape')=0,"ALTER TABLE invoices ADD CONSTRAINT chk_invoices_public_id_shape CHECK (public_id REGEXP '^[0-9a-f]{32}$')",'SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='invoices' AND constraint_name='chk_invoices_currency_shape')=0,"ALTER TABLE invoices ADD CONSTRAINT chk_invoices_currency_shape CHECK (currency REGEXP '^[A-Z]{3}$')",'SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='contracts' AND constraint_name='chk_contracts_public_id_shape')=0,"ALTER TABLE contracts ADD CONSTRAINT chk_contracts_public_id_shape CHECK (public_id REGEXP '^[0-9a-f]{32}$')",'SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='contracts' AND constraint_name='chk_contracts_currency_shape')=0,"ALTER TABLE contracts ADD CONSTRAINT chk_contracts_currency_shape CHECK (currency REGEXP '^[A-Z]{3}$')",'SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='project_invoices' AND constraint_name='chk_project_invoices_public_id_shape')=0,"ALTER TABLE project_invoices ADD CONSTRAINT chk_project_invoices_public_id_shape CHECK (public_id REGEXP '^[0-9a-f]{32}$')",'SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='project_invoices' AND constraint_name='chk_project_invoices_currency_shape')=0,"ALTER TABLE project_invoices ADD CONSTRAINT chk_project_invoices_currency_shape CHECK (currency REGEXP '^[A-Z]{3}$')",'SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;

-- Pricing finalization already writes the authoritative current-revision
-- snapshot in the same document transaction. Mirror that frozen currency onto
-- the document without changing pricing amounts or consulting mutable config.
DROP TRIGGER IF EXISTS invoice_currency_from_pricing_snapshot;
CREATE TRIGGER invoice_currency_from_pricing_snapshot AFTER INSERT ON document_pricing_adjustment_snapshots
FOR EACH ROW UPDATE invoices SET currency=NEW.currency
WHERE NEW.document_type='invoice' AND id=NEW.document_id AND revision_number=NEW.document_revision;
DROP TRIGGER IF EXISTS contract_currency_from_pricing_snapshot;
CREATE TRIGGER contract_currency_from_pricing_snapshot AFTER INSERT ON document_pricing_adjustment_snapshots
FOR EACH ROW UPDATE contracts SET currency=NEW.currency
WHERE NEW.document_type='contract' AND id=NEW.document_id AND revision_number=NEW.document_revision;

SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='invoices' AND index_name='uq_invoices_public_id')=0,'ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_public_id (public_id)','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='contracts' AND index_name='uq_contracts_public_id')=0,'ALTER TABLE contracts ADD UNIQUE KEY uq_contracts_public_id (public_id)','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;
SET @financial_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='project_invoices' AND index_name='uq_project_invoices_public_id')=0,'ALTER TABLE project_invoices ADD UNIQUE KEY uq_project_invoices_public_id (public_id)','SELECT 1');PREPARE financial_stmt FROM @financial_sql;EXECUTE financial_stmt;DEALLOCATE PREPARE financial_stmt;

DROP TRIGGER IF EXISTS invoices_public_id_immutable;
CREATE TRIGGER invoices_public_id_immutable BEFORE UPDATE ON invoices FOR EACH ROW SET NEW.public_id=IF(NEW.public_id<=>OLD.public_id,NEW.public_id,NULL);
DROP TRIGGER IF EXISTS contracts_public_id_immutable;
CREATE TRIGGER contracts_public_id_immutable BEFORE UPDATE ON contracts FOR EACH ROW SET NEW.public_id=IF(NEW.public_id<=>OLD.public_id,NEW.public_id,NULL);
DROP TRIGGER IF EXISTS project_invoices_public_id_immutable;
CREATE TRIGGER project_invoices_public_id_immutable BEFORE UPDATE ON project_invoices FOR EACH ROW SET NEW.public_id=IF(NEW.public_id<=>OLD.public_id,NEW.public_id,NULL);
