-- Durable confirmation for explicit verified enrollments, independent of invoices.
CREATE TABLE IF NOT EXISTS autopay_enrollment_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','sending','sent','suppressed') NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 lease_until DATETIME NULL, lease_token CHAR(32) NULL,
 sent_at DATETIME NULL, last_error VARCHAR(255) NULL,
 UNIQUE KEY uq_autopay_enrollment_notice(enrollment_id),
 CONSTRAINT fk_autopay_enrollment_notice FOREIGN KEY(enrollment_id) REFERENCES autopay_enrollments(id) ON DELETE CASCADE
) ENGINE=InnoDB;
