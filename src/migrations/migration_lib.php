<?php

declare(strict_types=1);

/**
 * Discover the immutable, sequential SQL migration history.
 *
 * @return array<int, array{version:int,filename:string,path:string,checksum:string,checksums:list<string>}>
 */
function migration_files(string $directory): array
{
    $paths = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($paths, SORT_STRING);

    $files = [];
    $expected = 1;
    foreach ($paths as $path) {
        $filename = basename($path);
        if (!preg_match('/^(\d{4})_[a-z0-9]+(?:_[a-z0-9]+)*\.sql$/', $filename, $matches)) {
            throw new RuntimeException("Invalid migration filename '$filename'; expected 0001_description.sql.");
        }
        if (str_ends_with($filename, '_rollback.sql')) {
            throw new RuntimeException("Rollback migration '$filename' is not permitted in the forward migration directory.");
        }

        $version = (int) $matches[1];
        if ($version !== $expected) {
            throw new RuntimeException(sprintf(
                'Migration sequence is not contiguous: expected %04d, found %04d (%s).',
                $expected,
                $version,
                $filename
            ));
        }

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration '$filename' is unreadable or empty.");
        }

        $normalizedLf = str_replace(["\r\n", "\r"], "\n", $sql);
        $normalizedCrlf = str_replace("\n", "\r\n", $normalizedLf);
        $checksums = array_values(array_unique([
            hash('sha256', $sql),
            hash('sha256', $normalizedLf),
            hash('sha256', $normalizedCrlf),
        ]));

        $files[$version] = [
            'version' => $version,
            'filename' => $filename,
            'path' => $path,
            'checksum' => $checksums[0],
            'checksums' => $checksums,
        ];
        $expected++;
    }

    return $files;
}

/**
 * Split ordinary MySQL migration SQL without interpreting semicolons inside
 * strings, identifiers, or comments. DELIMITER/procedure migrations are
 * intentionally unsupported; application migrations must remain plain SQL.
 *
 * @return list<string>
 */
function migration_statements(string $sql): array
{
    if (preg_match('/^\s*DELIMITER\b/im', $sql)) {
        throw new RuntimeException('DELIMITER blocks are not supported in Project Alpha migrations.');
    }

    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buffer .= $char;
            }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }

        if ($quote === null) {
            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $lineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
        } else {
            if ($char === '\\' && $quote !== '`' && $next !== '') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $char . $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }

        $buffer .= $char;
    }

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('Migration contains an unterminated quote or block comment.');
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

/** @return array<int, array{version:int,filename:string,checksum:?string}> */
function migration_ledger(PDO $pdo): array
{
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
    )->fetchColumn();
    if ((int) $exists !== 1) {
        throw new RuntimeException(
            'schema_migrations is missing. This is not a Project Alpha 0.5.0 database; perform the documented destructive reinstall.'
        );
    }

    $rows = $pdo->query(
        'SELECT version, filename, checksum FROM schema_migrations ORDER BY version'
    )->fetchAll(PDO::FETCH_ASSOC);
    $ledger = [];
    foreach ($rows as $row) {
        $version = (int) $row['version'];
        $ledger[$version] = [
            'version' => $version,
            'filename' => (string) $row['filename'],
            'checksum' => $row['checksum'] === null ? null : (string) $row['checksum'],
        ];
    }

    if (!isset($ledger[0]) || $ledger[0]['filename'] !== 'baseline.sql') {
        throw new RuntimeException('The 0.5.0 baseline marker is missing or invalid.');
    }

    return $ledger;
}

/**
 * @param array<int, array{version:int,filename:string,path:string,checksum:string,checksums?:list<string>}> $files
 * @param array<int, array{version:int,filename:string,checksum:?string}> $ledger
 */
function migration_validate_history(array $files, array $ledger): void
{
    $expectedLedgerVersion = 0;
    foreach ($ledger as $version => $row) {
        if ($version !== $expectedLedgerVersion) {
            throw new RuntimeException("Applied migration ledger has a gap before version $version.");
        }
        if ($version > 0) {
            if (!isset($files[$version])) {
                throw new RuntimeException(sprintf('Applied migration %04d is missing from disk.', $version));
            }
            $file = $files[$version];
            if ($row['filename'] !== $file['filename']) {
                throw new RuntimeException("Applied migration filename differs for version $version.");
            }
            $acceptedChecksums = $file['checksums'] ?? [$file['checksum']];
            $matchesChecksum = false;
            foreach ($acceptedChecksums as $checksum) {
                if (hash_equals((string) $row['checksum'], $checksum)) {
                    $matchesChecksum = true;
                    break;
                }
            }
            if (!$matchesChecksum) {
                throw new RuntimeException("Checksum mismatch for applied migration {$file['filename']}.");
            }
        }
        $expectedLedgerVersion++;
    }
}

function migration_connection(): PDO
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';

    return new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @param list<string> $requiredTables @return list<string> */
function migration_required_tables_for_version(array $requiredTables, int $throughVersion): array
{
    $introduced = [
        'pricing_adjustment_definitions' => 72,
        'project_pricing_adjustment_assignments' => 72,
        'contract_pricing_adjustment_assignments' => 72,
        'document_pricing_adjustment_overrides' => 72,
        'document_pricing_adjustment_snapshots' => 72,
        'contract_settlement_terms' => 77,
        'contract_settlements' => 77,
        'contract_settlement_lines' => 77,
        'portal_client_access_roots' => 79,
        'portal_client_login_eligibility' => 79,
        'portal_service_assignments' => 80,
        'portal_service_assignment_projection_state' => 80,
        'portal_service_assignment_projection_records' => 80,
        'portal_service_assignment_projection_receipts' => 80,
        'portal_client_provisioning_backfill' => 83,
        'portal_projection_recoveries' => 87,
    ];

    return array_values(array_filter(
        $requiredTables,
        static fn(string $table): bool => ($introduced[$table] ?? 0) <= $throughVersion
    ));
}

/**
 * @param array<string, list<string>> $requiredColumns
 * @return array<string, list<string>>
 */
function migration_required_columns_for_version(array $requiredColumns, int $throughVersion): array
{
    $introduced = [
        'project_invoices' => ['revision_number' => 72],
        'pricing_adjustment_definitions' => [
            'organization_id' => 72, 'name' => 72, 'adjustment_kind' => 72,
            'percentage_rate' => 72, 'is_active' => 72, 'effective_from' => 72,
            'effective_until' => 72, 'scope_type' => 73, 'scope_key' => 73,
        ],
        'project_pricing_adjustment_assignments' => [
            'organization_id' => 72, 'project_id' => 72,
            'adjustment_definition_id' => 72, 'assigned_by' => 72,
        ],
        'contract_pricing_adjustment_assignments' => [
            'organization_id' => 72, 'contract_id' => 72,
            'adjustment_definition_id' => 72, 'assigned_by' => 72,
        ],
        'document_pricing_adjustment_overrides' => [
            'organization_id' => 72, 'document_type' => 72, 'document_id' => 72,
            'override_mode' => 72, 'adjustment_definition_id' => 72,
            'reason' => 72, 'created_by' => 72,
        ],
        'document_pricing_adjustment_snapshots' => [
            'organization_id' => 72, 'document_type' => 72, 'document_id' => 72,
            'document_revision' => 72, 'source_type' => 72, 'currency' => 72,
            'basis_minor' => 72, 'adjustment_minor' => 72, 'adjusted_minor' => 72,
            'calculation_version' => 72, 'derived_from_snapshot_id' => 74,
        ],
        'invoices' => ['generation_key' => 75],
        'invoice_adjustments' => ['affects_total' => 76],
        'contract_settlement_terms' => [
            'organization_id' => 77, 'project_id' => 77, 'contract_id' => 77,
            'contract_revision' => 77, 'policy_mode' => 77, 'commitment_end_date' => 77,
            'target_definition_id' => 77, 'frozen_target_name' => 77,
            'frozen_target_kind' => 77, 'frozen_target_percentage' => 77,
        ],
        'contract_settlements' => [
            'public_id' => 77, 'organization_id' => 77, 'project_id' => 77,
            'contract_id' => 77, 'contract_revision' => 77, 'settlement_terms_id' => 77,
            'request_key' => 77, 'basis_hash' => 77, 'status' => 77,
            'prior_contract_status' => 77, 'actual_end_date' => 77, 'currency' => 77,
            'subtotal_delta_minor' => 77, 'tax_delta_minor' => 77,
            'total_delta_minor' => 77, 'calculation_version' => 77, 'basis_json' => 77,
            'draft_invoice_id' => 77, 'requested_by' => 77, 'reviewed_by' => 77,
            'decision_reason' => 77,
        ],
        'contract_settlement_lines' => [
            'settlement_id' => 77, 'source_invoice_id' => 77, 'source_revision' => 77,
            'source_pricing_snapshot_id' => 77, 'currency' => 77, 'basis_minor' => 77,
            'historical_adjustment_minor' => 77, 'target_percentage_rate' => 77,
            'target_adjustment_minor' => 77, 'historical_total_minor' => 77,
            'target_total_minor' => 77, 'delta_minor' => 77, 'source_content_hash' => 77,
        ],
        'portal_client_access_roots' => [
            'root_type' => 79, 'root_public_id' => 79, 'access_state' => 79,
            'state_reason' => 79, 'last_reconciled_at' => 79, 'updated_by' => 79,
        ],
        'portal_client_login_eligibility' => [
            'client_id' => 79, 'portal_principal_id' => 79, 'manual_state' => 79,
            'eligibility_status' => 79, 'review_reason' => 79, 'canonical_email' => 79,
            'source_version' => 79, 'last_reconciled_at' => 79, 'updated_by' => 79,
        ],
        'portal_integration_profiles' => [
            'service_assignment_projection_enabled' => 80,
            'contact_assignment_projection_enabled' => 82,
        ],
        'portal_service_assignments' => [
            'public_id' => 80, 'subject_type' => 80, 'subject_public_id' => 80,
            'service_public_id' => 80, 'active' => 80, 'effective_from' => 80,
            'effective_until' => 80, 'deleted_at' => 80,
        ],
        'portal_service_assignment_projection_state' => [
            'integration_profile_id' => 80, 'source_generation' => 80,
            'source_sequence' => 80, 'snapshot_hash' => 80,
        ],
        'portal_service_assignment_projection_records' => [
            'integration_profile_id' => 80, 'assignment_public_id' => 80,
            'source_version' => 80, 'payload_hash' => 80, 'record_json' => 80,
        ],
        'portal_service_assignment_projection_receipts' => [
            'integration_profile_id' => 80, 'idempotency_hash' => 80,
            'payload_hash' => 80, 'result_json' => 80,
        ],
        'portal_client_provisioning_backfill' => [
            'integration_profile_id' => 83, 'root_type' => 83,
            'root_public_id' => 83, 'contract_fingerprint' => 83,
            'state' => 83, 'attempts' => 83, 'next_attempt_at' => 83,
            'last_error_code' => 83, 'completed_at' => 83,
        ],
        'portal_projection_recoveries' => [
            'integration_profile_id' => 87, 'workspace_public_id' => 87,
            'route_type' => 87, 'failed_row_cutoff_id' => 87,
            'source_generation' => 87, 'activation_delivery_id' => 87,
            'state' => 87, 'requested_by' => 87, 'completed_at' => 87,
            'failed_at' => 87, 'last_error_code' => 87,
        ],
        'archived_clients' => [
            'public_id' => 85, 'client_type' => 85, 'portal_principal_id' => 85,
            'portal_manual_state' => 85, 'portal_canonical_email' => 85,
            'portal_identity_binding_ids_json' => 85,
            'portal_principal_authorization_version' => 85,
            'portal_principal_disabled_for_archive' => 85,
            'portal_principal_was_present' => 85,
            'portal_entitlement_ids_json' => 85,
            'portal_affected_workspace_ids_json' => 85,
        ],
    ];

    foreach ($requiredColumns as $table => $columns) {
        $requiredColumns[$table] = array_values(array_filter(
            $columns,
            static fn(string $column): bool => ($introduced[$table][$column] ?? 0) <= $throughVersion
        ));
        if ($requiredColumns[$table] === []) {
            unset($requiredColumns[$table]);
        }
    }

    return $requiredColumns;
}

function migration_schema_health(PDO $pdo, ?int $throughVersion = null): void
{
    $throughVersion ??= PHP_INT_MAX;
    $requiredTables = [
        'users', 'organizations', 'roles', 'role_permissions',
        'clients', 'organization_departments', 'organization_department_contacts',
        'projects', 'quotes', 'contracts', 'invoices', 'payments', 'project_invoice_recipients',
        'api_keys', 'client_onboarding_invitations', 'client_onboarding_submissions',
        'business_settings', 'employee_profiles', 'project_assignments',
        'work_time_entries', 'work_time_breaks', 'work_timer_locks', 'work_break_locks',
        'work_time_revisions', 'work_approval_snapshots', 'work_pay_accruals',
        'work_billing_consumptions', 'app_sessions', 'background_jobs',
        'rate_limits', 'schema_migrations',
        'email_provider_connections', 'email_provider_state', 'email_delivery_log',
        'addresses', 'address_assignments', 'service_locations', 'jobs',
        'project_service_locations', 'document_revisions', 'document_deliveries',
        'document_address_snapshots', 'invoice_adjustments', 'route_estimate_cache',
        'pricing_adjustment_definitions', 'project_pricing_adjustment_assignments',
        'contract_pricing_adjustment_assignments', 'document_pricing_adjustment_overrides',
        'document_pricing_adjustment_snapshots', 'contract_settlement_terms',
        'contract_settlements', 'contract_settlement_lines',
        'schedule_entries', 'mileage_charge_allocations', 'mileage_tracking_sessions',
        'worker_documents', 'business_units', 'worker_profiles', 'worker_business_units',
        'business_unit_memberships',
        'client_business_units', 'worker_capability_scopes', 'work_types',
        'catalog_bundle_items', 'catalog_work_components', 'worker_compensation_rules',
        'job_work_components', 'work_assignments', 'pay_periods',
        'worker_period_submissions', 'worker_statements', 'worker_statement_lines',
        'compensation_adjustments', 'passkey_credentials', 'passkey_challenges',
        'passkey_attempts', 'time_submissions', 'time_submission_entries',
        'work_type_billing_defaults', 'work_time_billing_allocations',
        'worker_earnings', 'worker_earning_events',
        'time_correction_requests', 'time_correction_effects', 'time_correction_billing_resolutions',
        'worker_payment_records', 'worker_payment_allocations',
        'payroll_exports', 'payroll_export_rows',
        'client_credits', 'client_credit_events',
        'catalog_link_migration_review', 'workforce_deadline_events',
        'application_entitlements', 'application_entitlement_business_units',
        'application_entitlement_oversight_units', 'integration_outbox',
        'operations', 'operation_assignments', 'tasks', 'task_assignments',
        'portal_principals', 'portal_principal_clients', 'portal_identity_bindings',
        'portal_organization_entitlements', 'portal_project_entitlements',
        'notification_relay_queue', 'notification_relay_events',
        'notification_relay_rate_buckets', 'notification_relay_key_state',
        'sync_source_identity', 'sync_resource_state', 'sync_event_log',
        'sync_snapshot_sessions',
        'portal_integration_profiles', 'portal_v2_workspaces', 'portal_integration_profile_workspaces', 'portal_v2_contacts',
        'portal_v2_entitlements', 'portal_v2_relations', 'portal_projection_outbox',
        'portal_projection_state', 'portal_draft_quote_commands', 'portal_integration_audit',
        'portal_projection_resource_state', 'portal_manager_scope_state',
        'portal_integration_request_receipts', 'portal_integration_rate_buckets',
        'portal_client_access_roots', 'portal_client_login_eligibility',
        'portal_service_assignments', 'portal_service_assignment_projection_state',
        'portal_service_assignment_projection_records', 'portal_service_assignment_projection_receipts',
        'portal_client_provisioning_backfill',
        'portal_projection_recoveries',
        'managed_delivery_intent_outbox',
        'document_number_sequences',
    ];
    $requiredTables = migration_required_tables_for_version($requiredTables, $throughVersion);
    $deadTables = [
        'contract_notes', 'quote_history', 'contract_history', 'invoice_history',
        'recurring_invoices', 'recurring_invoice_items', 'webhook_deliveries',
        'notification_settings', 'notification_log', 'document_custom_field_values',
        'financial_records', 'migrations', 'employee_documents',
    ];

    $present = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $presentMap = array_fill_keys($present, true);
    $issues = [];
    foreach ($requiredTables as $table) {
        if (!isset($presentMap[$table])) {
            $issues[] = "missing table $table";
        }
    }
    foreach ($deadTables as $table) {
        if (isset($presentMap[$table])) {
            $issues[] = "legacy table still present: $table";
        }
    }

    $requiredColumns = [
        'users' => ['email', 'password_hash', 'role', 'force_password_reset', 'auth_version', 'totp_reenroll_required'],
        'organizations' => ['public_id', 'source_version', 'general_email', 'general_phone'],
        'organization_departments' => ['public_id', 'source_version'],
        'clients' => ['public_id', 'source_version', 'organization_id', 'created_by'],
        'archived_clients' => ['public_id', 'client_type', 'portal_principal_id', 'portal_manual_state', 'portal_canonical_email', 'portal_identity_binding_ids_json', 'portal_principal_authorization_version', 'portal_principal_disabled_for_archive', 'portal_principal_was_present', 'portal_entitlement_ids_json', 'portal_affected_workspace_ids_json'],
        'projects' => ['public_id', 'source_version', 'completed_at', 'organization_id', 'department_id', 'business_unit_id', 'manager_user_id', 'created_by'],
        'portal_principals' => ['public_id', 'email_hint', 'display_name', 'source_version', 'enabled', 'authorization_version', 'revoked_at'],
        'portal_identity_bindings' => ['portal_principal_id', 'issuer', 'subject_hash', 'enabled', 'revoked_at'],
        'project_clients' => ['client_id', 'send_project_invoices', 'can_view_invoice_links'],
        'project_invoice_recipients' => ['project_id', 'client_id', 'organization_id', 'manual_email', 'recipient_key', 'sort_order'],
        'entity_links' => ['include_on_invoices', 'resolver_mode', 'visibility_scope'],
        'contracts' => ['organization_id', 'show_contact_on_document', 'created_by', 'job_id', 'service_location_id', 'status', 'revision_number', 'last_sent_revision', 'signed_revision_number', 'signed_pdf_sha256'],
        'invoices' => ['organization_id', 'show_contact_on_document', 'created_by', 'collection_mode', 'job_id', 'service_location_id', 'revision_number', 'last_sent_revision', 'credit_due', 'credit_applied', 'generation_key'],
        'api_keys' => ['name', 'key_prefix', 'key_hash', 'scopes', 'allowed_ips', 'created_at', 'last_used_at', 'revoked_at'],
        'api_usage' => ['api_key_id', 'used_at'],
        'portal_integration_audit' => ['integration_profile_id','api_key_id','correlation_id','action','outcome','target_type','target_public_id','metadata_json'],
        'portal_integration_profiles' => ['service_assignment_projection_enabled', 'contact_assignment_projection_enabled'],
        'portal_service_assignments' => ['public_id','subject_type','subject_public_id','service_public_id','active','effective_from','effective_until','deleted_at'],
        'portal_service_assignment_projection_state' => ['integration_profile_id','source_generation','source_sequence','snapshot_hash'],
        'portal_service_assignment_projection_records' => ['integration_profile_id','assignment_public_id','source_version','payload_hash','record_json'],
        'portal_service_assignment_projection_receipts' => ['integration_profile_id','idempotency_hash','payload_hash','result_json'],
        'portal_projection_resource_state' => ['integration_profile_id','workspace_public_id','route_type','resource_type','resource_public_id','source_version','payload_hash','record_json'],
        'portal_manager_scope_state' => ['integration_profile_id','workspace_id','scope_type','scope_public_id','state','last_manager_removed_at','updated_by'],
        'portal_client_access_roots' => ['root_type','root_public_id','access_state','state_reason','last_reconciled_at','updated_by'],
        'portal_client_login_eligibility' => ['client_id','portal_principal_id','manual_state','eligibility_status','review_reason','canonical_email','source_version','last_reconciled_at','updated_by'],
        'portal_client_provisioning_backfill' => ['integration_profile_id','root_type','root_public_id','contract_fingerprint','state','attempts','next_attempt_at','last_error_code','completed_at'],
        'portal_projection_recoveries' => ['integration_profile_id','workspace_public_id','route_type','failed_row_cutoff_id','source_generation','activation_delivery_id','state','requested_by','completed_at','failed_at','last_error_code'],
        'managed_delivery_intent_outbox' => ['delivery_id','intent_type','target_delivery_id','integration_profile_id','destination_url','pinned_application_key','signing_key_id','signing_contract_hash','delivery_timeout_seconds','delivery_max_attempts','actor_user_id','scope_type','scope_public_id','audience_type','audience_public_id','access_mode','request_fingerprint','payload_json','attempts','next_attempt_at','claim_token','claimed_at','delivered_at','dead_lettered_at','last_http_status','last_error_code','receipt_id','revoked_at'],
        'notification_relay_queue' => ['api_key_id', 'action_name', 'template_name', 'recipient_alias', 'variables_json', 'idempotency_hash', 'payload_hash', 'status', 'attempt_count', 'next_attempt_at', 'lock_token'],
        'notification_relay_events' => ['queue_id', 'queue_reference', 'api_key_id', 'event_type', 'status', 'attempt_count', 'error_code'],
        'sync_source_identity' => ['singleton', 'source_instance_id'],
        'sync_resource_state' => ['resource_type', 'resource_id', 'resource_version', 'content_sha256', 'present'],
        'sync_event_log' => ['sequence', 'event_id', 'source_instance_id', 'resource_type', 'resource_id', 'resource_version', 'action', 'payload'],
        'sync_snapshot_sessions' => ['snapshot_id', 'source_instance_id', 'api_key_id', 'high_water_sequence', 'generated_at', 'expires_at'],
        'client_onboarding_invitations' => ['organization_id', 'invited_email', 'token_hash', 'status'],
        'client_onboarding_submissions' => ['invitation_id', 'proposed_data', 'status'],
        'team_members' => ['user_id','display_name','email','is_active','profile_source','last_synced_at'],
        'time_entries' => ['source_system', 'external_id', 'external_revision', 'external_status','team_member_id','al_business_id','source_entry_id','source_updated_at','cost_rate_snapshot','billing_rate_snapshot'],
        'business_settings' => ['business_uuid','business_name','timezone','currency','default_hourly_rate','default_billing_rate'],
        'employee_profiles' => ['user_id','employment_status','hourly_rate','currency','employee_can_view_pay'],
        'project_assignments' => ['project_id','user_id','pay_rate_override','ends_at'],
        'work_time_entries' => ['id','user_id','worker_profile_id','entered_by_user_id','project_id','job_id','work_type_id','work_assignment_id','entry_mode','owner_self_confirmed','internal_cost_rate','start_time','end_time','duration_seconds','status','workflow_status','billing_state','compensation_state','submitted_at','current_submission_id','revision'],
        'work_approval_snapshots' => ['time_entry_id','entry_revision','pay_rate','billing_rate','pay_amount','currency'],
        'work_pay_accruals' => ['approval_snapshot_id','employee_user_id','amount','currency','status'],
        'work_billing_consumptions' => ['approval_snapshot_id','billing_time_entry_id','consumption_type'],
        'app_sessions' => ['session_hash','user_id','payload','last_activity_at','absolute_expires_at','revoked_at'],
        'background_jobs' => ['queue_name','job_type','payload','state','available_at'],
        'email_provider_connections' => ['provider','credentials_enc','status','token_expires_at'],
        'addresses' => ['address_line1','city','postal_code','google_place_id','source'],
        'service_locations' => ['address_id','client_id','project_id'],
        'jobs' => ['client_id','project_id','job_code','default_service_location_id','archived'],
        'document_revisions' => ['document_type','document_id','revision_number','snapshot','content_hash'],
        'invoice_adjustments' => ['invoice_id','adjustment_type','amount','affects_total','revision_number','superseded_at'],
        'project_invoices' => ['revision_number'],
        'pricing_adjustment_definitions' => ['organization_id','scope_type','scope_key','name','adjustment_kind','percentage_rate','is_active','effective_from','effective_until'],
        'project_pricing_adjustment_assignments' => ['organization_id','project_id','adjustment_definition_id','assigned_by'],
        'contract_pricing_adjustment_assignments' => ['organization_id','contract_id','adjustment_definition_id','assigned_by'],
        'document_pricing_adjustment_overrides' => ['organization_id','document_type','document_id','override_mode','adjustment_definition_id','reason','created_by'],
        'document_pricing_adjustment_snapshots' => ['organization_id','document_type','document_id','document_revision','source_type','currency','basis_minor','adjustment_minor','adjusted_minor','calculation_version','derived_from_snapshot_id'],
        'contract_settlement_terms' => ['organization_id','project_id','contract_id','contract_revision','policy_mode','commitment_end_date','target_definition_id','frozen_target_name','frozen_target_kind','frozen_target_percentage'],
        'contract_settlements' => ['public_id','organization_id','project_id','contract_id','contract_revision','settlement_terms_id','request_key','basis_hash','status','prior_contract_status','actual_end_date','currency','subtotal_delta_minor','tax_delta_minor','total_delta_minor','calculation_version','basis_json','draft_invoice_id','requested_by','reviewed_by','decision_reason'],
        'contract_settlement_lines' => ['settlement_id','source_invoice_id','source_revision','source_pricing_snapshot_id','currency','basis_minor','historical_adjustment_minor','target_percentage_rate','target_adjustment_minor','historical_total_minor','target_total_minor','delta_minor','source_content_hash'],
        'schedule_entries' => ['project_id','job_id','starts_at','timezone','source_type'],
        'worker_documents' => ['user_id','worker_profile_id','worker_name_snapshot','category','title','signed_on','expires_on','status','worker_visible','file_path','content_sha256','version_number','archived_at'],
        'worker_profiles' => ['user_id','relationship_type','relationship_review_required','relationship_review_reason','relationship_reviewed_by','relationship_reviewed_at','time_review_policy','compensation_policy','status','display_name','owner_internal_cost_rate'],
        'business_unit_memberships' => ['business_unit_id','user_id','membership_role','is_primary','assigned_by','assigned_at','ended_at'],
        'application_entitlements' => ['user_id','application_key','enabled','manual_enabled','automatic_enabled','oversight_enabled','role_key'],
        'task_assignments' => ['task_id','user_id','assigned_by','assigned_at'],
        'item_library' => ['portal_public_id','portal_source_version','portal_requestable','portal_summary','portal_category','portal_display_order','portal_geometry_requirement','portal_questions_json','entry_type','billing_unit','tax_behavior','fulfillment_notes','client_pricing_model','client_included_minutes','client_overage_rate','pricing_currency','portal_pricing_typical_minimum','portal_pricing_typical_maximum'],
        'quotes' => ['public_id','source_version','organization_id', 'show_contact_on_document', 'created_by', 'job_id', 'service_location_id', 'revision_number', 'last_sent_revision'],
        'quote_items' => ['item_library_id','catalog_snapshot'],
        'contract_items' => ['item_library_id','catalog_snapshot'],
        'invoice_items' => ['item_library_id','catalog_snapshot'],
        'mileage_logs' => ['recorded_by_user_id','traveler_user_id','traveler_worker_id','financial_treatment'],
        'work_assignments' => ['worker_profile_id','status','compensation_snapshot','eligibility_snapshot','estimated_pay','approved_pay','eligible_at','eligible_by'],
        'time_submissions' => ['pay_period_id','worker_profile_id','submission_sequence','status','source','submitted_by','submitted_at','reviewed_by','reviewed_at'],
        'time_submission_entries' => ['submission_id','time_entry_id','entry_revision','entry_snapshot','decision','decision_reason'],
        'work_type_billing_defaults' => ['work_type_id','default_treatment','default_billing_rate','currency'],
        'work_time_billing_allocations' => ['allocation_key','time_entry_id','entry_revision','treatment','status','duration_seconds','quantity','rate','amount','invoice_id','invoice_item_id','allocation_snapshot'],
        'worker_earnings' => ['source_key','source_type','source_id','source_revision','worker_profile_id','work_time_entry_id','work_assignment_id','pay_period_id','status','method','quantity','rate','amount','calculation_snapshot','statement_line_id'],
        'worker_statements' => ['statement_version','replaces_statement_id','voided_by','voided_at','void_reason'],
        'worker_statement_lines' => ['worker_statement_id','worker_earning_id','work_assignment_id','work_time_entry_id','amount','calculation_snapshot'],
        'catalog_work_components' => ['active_item_library_id','active_work_type_id'],
        'worker_payment_records' => ['legacy_statement_id'],
        'payroll_export_rows' => ['export_row_number'],
        'passkey_credentials' => ['user_id','credential_id','user_handle','credential_record','signature_counter','revoked_at'],
        'passkey_challenges' => ['user_id','ceremony','challenge_hash','session_hash','expires_at','consumed_at'],
    ];
    $requiredColumns = migration_required_columns_for_version($requiredColumns, $throughVersion);
    $columnQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnQuery->execute([$table, $column]);
            if ((int) $columnQuery->fetchColumn() !== 1) {
                $issues[] = "missing column $table.$column";
            }
        }
    }

    if ($issues !== []) {
        throw new RuntimeException('Schema health check failed: ' . implode('; ', $issues));
    }
}
