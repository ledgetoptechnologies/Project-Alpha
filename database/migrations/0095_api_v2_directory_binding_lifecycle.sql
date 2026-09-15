-- Close the pre-existing gap between resource tombstones and external
-- authority. Existing active bindings for a non-present resource are
-- permanently tombstoned; restore only creates a new resource revision and
-- never reactivates one of these rows. The repair ledger makes this data
-- migration retry-safe even though the migration runner executes statements
-- individually (and MySQL DDL can auto-commit).
CREATE TABLE IF NOT EXISTS api_v2_directory_binding_lifecycle_repairs (
    application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    target_authorization_generation BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

INSERT IGNORE INTO api_v2_directory_binding_lifecycle_repairs(application_pk,target_authorization_generation)
SELECT binding.application_pk, authorization_state.authorization_generation + 1
FROM api_v2_directory_external_bindings binding
JOIN api_v2_directory_resource_state state
  ON state.resource_type=binding.resource_type AND state.public_id=binding.public_id
JOIN api_v2_directory_authorization_state authorization_state
  ON authorization_state.application_pk=binding.application_pk
WHERE binding.status='active' AND state.present=0;

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

DROP TABLE api_v2_directory_binding_lifecycle_repairs;
