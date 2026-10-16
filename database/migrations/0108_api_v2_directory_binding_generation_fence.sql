-- Modern five-field directory binding commands retain the exact generation
-- they compare and acknowledge. Historical four-field receipts stay unchanged;
-- NULL is never backfilled from the current authorization generation.
ALTER TABLE api_v2_directory_binding_command_receipts
    ADD COLUMN expected_authorization_generation BIGINT UNSIGNED NULL AFTER resource_revision,
    ADD COLUMN result_authorization_generation BIGINT UNSIGNED NULL AFTER expected_authorization_generation,
    ADD CONSTRAINT chk_api_v2_directory_binding_receipt_generation CHECK (
        (expected_authorization_generation IS NULL AND result_authorization_generation IS NULL)
        OR (
            expected_authorization_generation IS NOT NULL
            AND result_authorization_generation IS NOT NULL
            AND expected_authorization_generation BETWEEN 0 AND 9223372036854775806
            AND result_authorization_generation=expected_authorization_generation+1
        )
    );
