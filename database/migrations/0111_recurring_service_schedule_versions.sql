-- Additive only: existing rates, schedules, signed documents and invoices are untouched.
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='rate_basis')=0,'ALTER TABLE quotes ADD COLUMN rate_basis ENUM(''per_period'',''monthly'') NOT NULL DEFAULT ''per_period''','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='monthly_rate')=0,'ALTER TABLE quotes ADD COLUMN monthly_rate DECIMAL(12,2) NULL','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contracts' AND column_name='rate_basis')=0,'ALTER TABLE contracts ADD COLUMN rate_basis ENUM(''per_period'',''monthly'') NOT NULL DEFAULT ''per_period''','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contracts' AND column_name='monthly_rate')=0,'ALTER TABLE contracts ADD COLUMN monthly_rate DECIMAL(12,2) NULL','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_recurring_services' AND column_name='rate_basis')=0,'ALTER TABLE contract_recurring_services ADD COLUMN rate_basis ENUM(''per_period'',''monthly'') NOT NULL DEFAULT ''per_period''','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_recurring_services' AND column_name='monthly_rate')=0,'ALTER TABLE contract_recurring_services ADD COLUMN monthly_rate DECIMAL(12,2) NULL','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;
SET @schedule_version_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contract_recurring_services' AND column_name='billing_anchor_date')=0,'ALTER TABLE contract_recurring_services ADD COLUMN billing_anchor_date DATE NULL','SELECT 1');
PREPARE schedule_version_stmt FROM @schedule_version_sql; EXECUTE schedule_version_stmt; DEALLOCATE PREPARE schedule_version_stmt;

CREATE TABLE IF NOT EXISTS contract_recurring_service_changes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    recurring_service_id BIGINT NOT NULL,
    effective_date DATE NOT NULL,
    state ENUM('pending','approved','applied','cancelled') NOT NULL,
    old_values JSON NOT NULL,
    new_values JSON NOT NULL,
    signed_document_path VARCHAR(500) NULL,
    created_by INT NULL,
    approved_at DATETIME NULL,
    applied_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crsc_service (recurring_service_id,state,effective_date),
    CONSTRAINT fk_crsc_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_crsc_service FOREIGN KEY (recurring_service_id) REFERENCES contract_recurring_services(id) ON DELETE CASCADE,
    CONSTRAINT fk_crsc_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A period is half-open: [coverage_start, coverage_end). The exclusive end is
-- the earliest next charge date, even if collection is delayed or partial.
CREATE TABLE IF NOT EXISTS recurring_invoice_coverage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recurring_service_id BIGINT NOT NULL,
    invoice_id INT NOT NULL,
    coverage_start DATE NOT NULL,
    coverage_end DATE NOT NULL,
    rate_basis ENUM('per_period','monthly') NOT NULL,
    monthly_rate DECIMAL(12,2) NULL,
    amount DECIMAL(12,2) NOT NULL,
    schedule_snapshot JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ric_service_period (recurring_service_id,coverage_start),
    INDEX idx_ric_invoice (invoice_id),
    CONSTRAINT fk_ric_service FOREIGN KEY (recurring_service_id) REFERENCES contract_recurring_services(id) ON DELETE CASCADE,
    CONSTRAINT fk_ric_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT chk_ric_period CHECK (coverage_end > coverage_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
