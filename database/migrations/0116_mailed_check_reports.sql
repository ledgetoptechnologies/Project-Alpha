-- Client-reported checks are reservations, never income or completed payments.
CREATE TABLE IF NOT EXISTS mailed_check_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_type ENUM('invoice','project_invoice') NOT NULL,
 document_id INT NOT NULL,
 document_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 client_id INT NOT NULL,
 client_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 check_number VARCHAR(100) NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('pending','received','rejected') NOT NULL DEFAULT 'pending',
 reported_at DATETIME NOT NULL,
 expected_by DATETIME NOT NULL,
 payment_id BIGINT UNSIGNED NULL,
 resolved_by INT NULL,
 resolved_at DATETIME NULL,
 resolution_note VARCHAR(1000) NULL,
 UNIQUE KEY uq_mailed_check_document(document_type,document_public_id),
 KEY ix_mailed_check_review(status,expected_by)
) ENGINE=InnoDB;
