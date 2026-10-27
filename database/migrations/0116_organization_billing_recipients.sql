-- Financial delivery preferences are PA-owned, separate from shared directory profiles.
-- No rows are seeded: existing installations retain their existing routing until configured.
CREATE TABLE organization_billing_preferences (
 organization_id INT NOT NULL PRIMARY KEY,
 copy_working_contact TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_org_billing_preferences FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE organization_billing_recipients (
 organization_id INT NOT NULL,
 client_id INT NOT NULL,
 PRIMARY KEY (organization_id,client_id),
 CONSTRAINT fk_org_billing_recipient_policy FOREIGN KEY (organization_id) REFERENCES organization_billing_preferences(organization_id) ON DELETE CASCADE,
 CONSTRAINT fk_org_billing_recipient_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE invoice_billing_preferences (
 invoice_id INT NOT NULL PRIMARY KEY,
 copy_working_contact TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_invoice_billing_preferences FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE invoice_billing_recipients (
 invoice_id INT NOT NULL,
 client_id INT NOT NULL,
 PRIMARY KEY (invoice_id,client_id),
 CONSTRAINT fk_invoice_billing_recipient_policy FOREIGN KEY (invoice_id) REFERENCES invoice_billing_preferences(invoice_id) ON DELETE CASCADE,
 CONSTRAINT fk_invoice_billing_recipient_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE payment_receipt_deliveries (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 receipt_id BIGINT NOT NULL,
 recipient_key VARCHAR(64) NOT NULL,
 email_to VARCHAR(255) NOT NULL,
 recipient_name VARCHAR(150) NOT NULL DEFAULT '',
 emailed_at DATETIME NULL,
 suppressed_at DATETIME NULL,
 UNIQUE KEY uq_payment_receipt_delivery (receipt_id,recipient_key),
 CONSTRAINT fk_payment_receipt_delivery FOREIGN KEY (receipt_id) REFERENCES payment_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
