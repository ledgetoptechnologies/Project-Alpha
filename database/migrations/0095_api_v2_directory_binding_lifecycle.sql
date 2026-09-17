-- Close the pre-existing gap between resource tombstones and external
-- authority. Existing active bindings for a non-present resource are
-- permanently tombstoned; restore only creates a new resource revision and
-- never reactivates one of these rows. The repair ledger makes this data
-- migration retry-safe even though the migration runner executes statements
-- individually (and MySQL DDL can auto-commit).
CREATE TABLE IF NOT EXISTS api_v2_directory_binding_lifecycle_repairs (
    application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    target_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_api_v2_directory_binding_lifecycle_repair_authorization FOREIGN KEY (application_pk)
        REFERENCES api_v2_directory_authorization_state(application_pk) ON DELETE RESTRICT,
    CONSTRAINT chk_api_v2_directory_binding_lifecycle_repair_generation CHECK (
        target_authorization_generation BETWEEN 1 AND 9223372036854775807
    )
) ENGINE=InnoDB;

-- Version 0095 originally used an unconstrained ledger. Validate both any
-- retained legacy rows and this run's candidates in a per-connection staging
-- table before binding rows change. The durable ledger is still retained for
-- crash retry after the binding UPDATE, while each retry revalidates it.
DROP TEMPORARY TABLE IF EXISTS api_v2_directory_binding_lifecycle_repair_validation;
CREATE TEMPORARY TABLE api_v2_directory_binding_lifecycle_repair_validation (
    application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    target_authorization_generation BIGINT UNSIGNED NOT NULL,
    matched_authorization_application_pk BIGINT UNSIGNED NOT NULL,
    CHECK (target_authorization_generation BETWEEN 1 AND 9223372036854775807),
    CHECK (matched_authorization_application_pk=application_pk)
) ENGINE=InnoDB;

INSERT INTO api_v2_directory_binding_lifecycle_repair_validation(application_pk,target_authorization_generation,matched_authorization_application_pk)
SELECT repairs.application_pk,repairs.target_authorization_generation,authorization_state.application_pk
FROM api_v2_directory_binding_lifecycle_repairs repairs
LEFT JOIN api_v2_directory_authorization_state authorization_state
  ON authorization_state.application_pk=repairs.application_pk;

-- This is a fail-closed preflight, not merely a work list. The required
-- matched authorization key rejects a binding whose application lacks state;
-- the CHECK rejects an exhausted/invalid generation. Both happen before
-- either lifecycle table is changed. A retained ledger after a statement-level
-- interruption makes the following updates replay-safe.
INSERT INTO api_v2_directory_binding_lifecycle_repair_validation(application_pk,target_authorization_generation,matched_authorization_application_pk)
SELECT binding.application_pk,
       CASE WHEN authorization_state.authorization_generation >= 9223372036854775807 THEN 0
            ELSE authorization_state.authorization_generation + 1 END,
       authorization_state.application_pk
FROM api_v2_directory_external_bindings binding
JOIN api_v2_directory_resource_state state
  ON state.resource_type=binding.resource_type AND state.public_id=binding.public_id
LEFT JOIN api_v2_directory_authorization_state authorization_state
  ON authorization_state.application_pk=binding.application_pk
WHERE binding.status='active' AND state.present=0
GROUP BY binding.application_pk
ON DUPLICATE KEY UPDATE target_authorization_generation=VALUES(target_authorization_generation),matched_authorization_application_pk=VALUES(matched_authorization_application_pk);

INSERT INTO api_v2_directory_binding_lifecycle_repairs(application_pk,target_authorization_generation)
SELECT application_pk,target_authorization_generation
FROM api_v2_directory_binding_lifecycle_repair_validation
ON DUPLICATE KEY UPDATE target_authorization_generation=VALUES(target_authorization_generation);

UPDATE api_v2_directory_external_bindings binding
JOIN api_v2_directory_resource_state state
  ON state.resource_type=binding.resource_type AND state.public_id=binding.public_id
SET binding.status='tombstoned', binding.tombstoned_at=COALESCE(binding.tombstoned_at, CURRENT_TIMESTAMP(6))
WHERE binding.status='active' AND state.present=0;

UPDATE api_v2_directory_authorization_state authorization_state
JOIN api_v2_directory_binding_lifecycle_repairs affected
  ON affected.application_pk=authorization_state.application_pk
SET authorization_state.authorization_generation=GREATEST(
    authorization_state.authorization_generation,
    affected.target_authorization_generation
);

DROP TABLE IF EXISTS api_v2_directory_binding_lifecycle_repairs;
DROP TEMPORARY TABLE IF EXISTS api_v2_directory_binding_lifecycle_repair_validation;
