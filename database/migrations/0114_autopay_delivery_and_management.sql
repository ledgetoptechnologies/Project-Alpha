-- Separate from invoice reminder types; one durable delivery per collection outcome.
CREATE TABLE IF NOT EXISTS autopay_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 collection_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('failed','requires_action','receipt') NOT NULL,
 status ENUM('pending','sending','sent','suppressed') NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 lease_until DATETIME NULL, lease_token CHAR(32) NULL,
 sent_at DATETIME NULL, last_error VARCHAR(500) NULL,
 UNIQUE KEY uq_autopay_delivery(collection_id,kind)
) ENGINE=InnoDB;
SET @autopay_management_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='autopay_enrollments' AND column_name='management_token')=0,'ALTER TABLE autopay_enrollments ADD COLUMN management_token CHAR(64) NULL UNIQUE','SELECT 1');
PREPARE autopay_management_stmt FROM @autopay_management_sql; EXECUTE autopay_management_stmt; DEALLOCATE PREPARE autopay_management_stmt;
