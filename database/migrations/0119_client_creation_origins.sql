-- Records clients that a background process created rather than staff, so the
-- client portal can tell an imported payer from a client staff added. A client
-- created by the payment-processor import (from the payer email) does not get
-- an individual portal login; staff-created and consumer clients do.
CREATE TABLE IF NOT EXISTS client_creation_origins (
 client_id INT NOT NULL PRIMARY KEY,
 origin ENUM('payment_import') NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing imported payers carry the import's fixed note.
INSERT IGNORE INTO client_creation_origins(client_id, origin)
SELECT id, 'payment_import' FROM clients
WHERE notes LIKE 'Created from % standalone payment import.%';

ALTER TABLE portal_client_login_eligibility
  MODIFY review_reason ENUM('none','missing_email','invalid_email','duplicate_email','non_human_record','principal_conflict','root_revoked','client_inactive','imported_payer') NOT NULL DEFAULT 'missing_email';
