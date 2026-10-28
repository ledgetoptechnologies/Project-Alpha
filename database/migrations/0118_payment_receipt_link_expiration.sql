ALTER TABLE payment_receipts
    ADD COLUMN expires_at DATETIME NULL AFTER public_token,
    ADD COLUMN revoked TINYINT(1) NOT NULL DEFAULT 0 AFTER expires_at,
    ADD INDEX idx_payment_receipt_access (public_token,revoked,expires_at);

UPDATE payment_receipts
SET expires_at = DATE_ADD(created_at, INTERVAL 1 YEAR)
WHERE expires_at IS NULL;

ALTER TABLE payment_receipts MODIFY COLUMN expires_at DATETIME NOT NULL;
