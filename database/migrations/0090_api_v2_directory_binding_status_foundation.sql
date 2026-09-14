-- Generic, application-scoped external identity bindings for the API v2
-- read foundation. This migration creates no binding and does not advertise
-- any endpoint; writers must define their own atomic lifecycle separately.
CREATE TABLE IF NOT EXISTS api_v2_directory_external_bindings (
    application_pk BIGINT UNSIGNED NOT NULL,
    resource_type ENUM('client','organization') NOT NULL,
    -- Binary storage preserves every API-accepted UTF-8 byte, including a
    -- trailing space; VARCHAR comparisons may otherwise pad/normalize it.
    external_id VARBINARY(764) NOT NULL,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resource_revision BIGINT UNSIGNED NOT NULL,
    resource_projection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active','tombstoned') NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    tombstoned_at DATETIME(6) NULL,
    PRIMARY KEY (application_pk, resource_type, external_id),
    UNIQUE KEY uq_api_v2_directory_external_binding_public (application_pk, resource_type, public_id),
    CONSTRAINT fk_api_v2_directory_external_binding_application FOREIGN KEY (application_pk)
        REFERENCES api_v2_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_api_v2_directory_external_binding_resource FOREIGN KEY (resource_type, public_id)
        REFERENCES api_v2_directory_resource_state(resource_type, public_id) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_external_binding_external_id CHECK (OCTET_LENGTH(external_id) BETWEEN 1 AND 764),
    CONSTRAINT chk_api_v2_directory_external_binding_public_id CHECK (public_id REGEXP '^[0-9a-f]{32}$'),
    CONSTRAINT chk_api_v2_directory_external_binding_revision CHECK (resource_revision BETWEEN 1 AND 9223372036854775807),
    CONSTRAINT chk_api_v2_directory_external_binding_projection_hash CHECK (resource_projection_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_api_v2_directory_external_binding_lifecycle CHECK ((status='active' AND tombstoned_at IS NULL) OR (status='tombstoned' AND tombstoned_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
