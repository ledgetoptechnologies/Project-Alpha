-- Legacy card funding is unknown until Stripe verifies a new setup.
ALTER TABLE autopay_enrollments
 ADD COLUMN verified_credit_card TINYINT(1) NOT NULL DEFAULT 0 AFTER method_type;
