-- Explicit new consent only. Never migrate legacy saved-payment flags.
CREATE TABLE IF NOT EXISTS autopay_enrollments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT NOT NULL, contract_id INT NULL,
 scope ENUM('agreement','business') NOT NULL,
 status ENUM('pending','active','revoked','failed') NOT NULL DEFAULT 'pending',
 processor_account_id VARCHAR(255) NOT NULL, installation_id VARCHAR(64) NOT NULL,
 livemode TINYINT NOT NULL DEFAULT 0,
 stripe_customer_id VARCHAR(255) NOT NULL,
 payment_method_id VARCHAR(255) NULL, method_type VARCHAR(32) NULL, last4 CHAR(4) NULL,
 currency CHAR(3) NOT NULL DEFAULT 'usd',
 setup_intent_id VARCHAR(255) NULL UNIQUE, checkout_session_id VARCHAR(255) NULL UNIQUE,
 consent_version VARCHAR(32) NOT NULL, consent_text TEXT NOT NULL,
 verified_email VARCHAR(255) NOT NULL, consent_at DATETIME NOT NULL,
 consent_proof CHAR(64) NOT NULL,
 verified_at DATETIME NULL, effective_from DATETIME NULL, revoked_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_autopay_client_scope(client_id,status,contract_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS autopay_email_challenges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT NOT NULL, email VARCHAR(255) NOT NULL,
 session_hash CHAR(64) NOT NULL, code_hash VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0,
 consumed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_autopay_challenge_client(client_id,created_at)
) ENGINE=InnoDB;
