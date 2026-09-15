-- Immutable, local release evidence for a completed API v2 directory backfill.
-- This table does not enable any API route or capability.
CREATE TABLE IF NOT EXISTS api_v2_directory_backfill_attestations (
    attestation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attestation_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attestation_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
