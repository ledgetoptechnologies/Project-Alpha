<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExternalDirectoryManagementPolicyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_management.php';
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,filename TEXT);
          INSERT INTO schema_migrations VALUES(88,'0088_api_v2_application_identity.sql'),(89,'0089_api_v2_directory_revision_foundation.sql'),(90,'0090_api_v2_directory_binding_status_foundation.sql'),(91,'0091_api_v2_directory_binding_command_receipts.sql'),(92,'0092_api_v2_directory_binding_revision_refresh_receipts.sql'),(93,'0093_api_v2_directory_organization_profile_command_receipts.sql'),(94,'0094_api_v2_directory_client_profile_command_receipts.sql'),(95,'0095_api_v2_directory_binding_lifecycle.sql'),(96,'0096_api_v2_directory_backfill_attestations.sql'),(97,'0097_api_v2_directory_create_command_receipts.sql'),(98,'0098_external_directory_management_policy.sql'),(99,'0099_api_v2_directory_lifecycle_relationships.sql'),(103,'0103_external_directory_management_sentinel.sql'),(104,'0104_api_v2_directory_units.sql');
          CREATE TABLE api_v2_directory_management_policy(singleton INTEGER PRIMARY KEY,configured_enabled INTEGER,ownership_active INTEGER,application_pk INTEGER,source_instance_id TEXT,application_id TEXT,history_epoch TEXT,release_attestation_sha256 TEXT,last_effective INTEGER,last_reason TEXT,configured_by INTEGER,configured_at TEXT,updated_at TEXT);
          CREATE TABLE api_v2_directory_management_attestations(attestation_sha256 TEXT PRIMARY KEY,attestation_json TEXT,created_by INTEGER,created_at TEXT);
          CREATE TABLE api_v2_directory_management_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT,outcome TEXT,reason TEXT,application_pk INTEGER,actor_user_id INTEGER,target_type TEXT,action_name TEXT,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
          CREATE TABLE api_v2_directory_lifecycle_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_authorization_generation INTEGER);
          CREATE TABLE api_v2_directory_relationship_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,client_public_id TEXT,expected_client_revision INTEGER,expected_authorization_generation INTEGER,result_client_revision INTEGER,result_authorization_generation INTEGER);
          CREATE TABLE api_v2_directory_binding_revoke_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id TEXT,public_id TEXT,expected_resource_revision INTEGER,expected_authorization_generation INTEGER,result_authorization_generation INTEGER);
          CREATE TABLE api_v2_directory_unit_profile_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,result_authorization_generation INTEGER);
          CREATE TABLE api_v2_directory_unit_contact_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,unit_public_id TEXT,client_public_id TEXT,expected_unit_revision INTEGER,expected_authorization_generation INTEGER,result_unit_revision INTEGER,result_projection_sha256 TEXT,result_authorization_generation INTEGER);
          CREATE TABLE organizations(id INTEGER PRIMARY KEY,archived INTEGER,deleted_at TEXT);
          CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,name TEXT,archived INTEGER,deleted_at TEXT);
          CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER,role TEXT,is_primary INTEGER);
          CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT,name TEXT);
          CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
          CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
          CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,scopes TEXT,revoked_at TEXT);
          CREATE TABLE app_config(organization_id INTEGER NOT NULL,config_key TEXT NOT NULL,config_value TEXT,PRIMARY KEY(organization_id,config_key));
          INSERT INTO api_v2_directory_management_policy VALUES(1,0,0,NULL,NULL,NULL,NULL,NULL,0,'not_configured',NULL,NULL,CURRENT_TIMESTAMP);
          INSERT INTO app_config VALUES(0,'api_v2_directory_management_ownership_active','0');");
    }

    public function testStandaloneInstallationIsNotChangedByDefault(): void
    {
        $status = api_v2_directory_management_status($this->pdo);
        self::assertFalse($status['configured']);
        self::assertFalse($status['effective']);
        self::assertFalse(api_v2_directory_management_guard($this->pdo, 'client', 'create'));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM api_v2_directory_management_audit')->fetchColumn());
    }

    public function testConfiguredPolicyFailsOpenUntilEveryReplacementRouteExists(): void
    {
        $source='123e4567-e89b-42d3-a456-426614174000';
        $application='223e4567-e89b-42d3-a456-426614174000';
        $epoch='323e4567-e89b-42d3-a456-426614174000';
        $this->pdo->prepare('INSERT INTO api_v2_applications VALUES(1,?,?)')->execute([$application,'Example application']);
        $this->pdo->prepare('INSERT INTO api_v2_history_identity VALUES(1,?,?)')->execute([$source,$epoch]);
        $this->pdo->exec('INSERT INTO api_v2_directory_authorization_state VALUES(1,0)');
        $this->pdo->prepare("UPDATE api_v2_directory_management_policy SET configured_enabled=1,application_pk=1,source_instance_id=?,application_id=?,history_epoch=?,last_reason='pending_evaluation' WHERE singleton=1")
            ->execute([$source,$application,$epoch]);
        $status = api_v2_directory_management_status($this->pdo);
        self::assertTrue($status['configured']);
        self::assertFalse($status['effective']);
        self::assertSame('route_disabled', $status['reason']);
        self::assertFalse(api_v2_directory_management_guard($this->pdo, 'client', 'profile'), 'Degraded configured policy fails open for administrator availability.');
        self::assertStringContainsString('configured but inactive', api_v2_directory_management_warning($status));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM api_v2_directory_management_audit WHERE event_type='effective_state_changed'")->fetchColumn());
    }

    public function testPreviouslyActivatedOwnershipDoesNotReopenLocalWritersOnHealthDrift(): void
    {
        $source='123e4567-e89b-42d3-a456-426614174000';$application='223e4567-e89b-42d3-a456-426614174000';$epoch='323e4567-e89b-42d3-a456-426614174000';
        $this->pdo->prepare('INSERT INTO api_v2_applications VALUES(1,?,?)')->execute([$application,'Example application']);
        $this->pdo->prepare('INSERT INTO api_v2_history_identity VALUES(1,?,?)')->execute([$source,$epoch]);
        $this->pdo->exec('INSERT INTO api_v2_directory_authorization_state VALUES(1,0)');
        $this->pdo->prepare("UPDATE api_v2_directory_management_policy SET configured_enabled=1,ownership_active=1,application_pk=1,source_instance_id=?,application_id=?,history_epoch=?,last_effective=1,last_reason='ready' WHERE singleton=1")
            ->execute([$source,$application,$epoch]);
        $status=api_v2_directory_management_status($this->pdo);
        self::assertTrue($status['effective']);
        self::assertSame('managed_degraded',$status['reason']);
        self::assertSame('route_disabled',$status['eligibility_reason']);
        self::assertTrue(api_v2_directory_management_guard($this->pdo,'relationship','remove'));
        self::assertStringContainsString('remain blocked',api_v2_directory_management_warning($status));
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM api_v2_directory_management_audit WHERE event_type='browser_write_denied'")->fetchColumn());
    }

    public function testActivatedSentinelFailsClosedWhenPolicySchemaIsMissingBeforeItsRead(): void
    {
        $this->pdo->prepare('UPDATE app_config SET config_value=? WHERE config_key=?')->execute(['1',API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $this->pdo->exec('DROP TABLE api_v2_directory_management_policy');
        $status = api_v2_directory_management_status($this->pdo);
        self::assertTrue($status['effective']);
        self::assertSame('managed_degraded', $status['reason']);
        self::assertSame('schema_unavailable', $status['eligibility_reason']);
        self::assertTrue(api_v2_directory_management_guard($this->pdo, 'client', 'create'));
    }

    public function testActivatedSentinelFailsClosedWhenHealthThrowsAfterPolicyRead(): void
    {
        $source='123e4567-e89b-42d3-a456-426614174000';$application='223e4567-e89b-42d3-a456-426614174000';$epoch='323e4567-e89b-42d3-a456-426614174000';
        $this->pdo->prepare('INSERT INTO api_v2_applications VALUES(1,?,?)')->execute([$application,'Example application']);
        $this->pdo->prepare('INSERT INTO api_v2_history_identity VALUES(1,?,?)')->execute([$source,$epoch]);
        $this->pdo->exec('INSERT INTO api_v2_directory_authorization_state VALUES(1,0)');
        $this->pdo->prepare("UPDATE api_v2_directory_management_policy SET configured_enabled=1,ownership_active=1,application_pk=1,source_instance_id=?,application_id=?,history_epoch=?,last_effective=1,last_reason='ready' WHERE singleton=1")->execute([$source,$application,$epoch]);
        $this->pdo->prepare('UPDATE app_config SET config_value=? WHERE config_key=?')->execute(['1',API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $flags=api_v2_directory_management_required_flags();foreach($flags as $flag)putenv($flag.'=true');
        try {
            $this->pdo->exec('DROP TABLE api_keys');
            $status = api_v2_directory_management_status($this->pdo);
            self::assertTrue($status['effective']);
            self::assertSame('managed_degraded', $status['reason']);
            self::assertSame('health_unavailable', $status['eligibility_reason']);
            self::assertTrue(api_v2_directory_management_guard($this->pdo, 'organization', 'profile'));
        } finally { foreach($flags as $flag)putenv($flag); }
    }

    public function testSentinelDisagreementAndSentinelReadFailureDoNotReopenWriters(): void
    {
        $this->pdo->prepare('UPDATE app_config SET config_value=? WHERE config_key=?')->execute(['1',API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $status = api_v2_directory_management_status($this->pdo);
        self::assertTrue($status['effective']);
        self::assertSame('policy_disagrees_with_sentinel', $status['eligibility_reason']);
        $this->pdo->exec('DROP TABLE api_v2_directory_management_policy; DROP TABLE app_config');
        $status = api_v2_directory_management_status($this->pdo);
        self::assertTrue($status['effective']);
        self::assertSame('schema_unavailable', $status['eligibility_reason']);
    }

    public function testSentinelParserOnlyAcceptsExplicitLocalOrManagedValues(): void
    {
        $this->pdo->exec("DELETE FROM app_config WHERE config_key='api_v2_directory_management_ownership_active'");
        self::assertNull(api_v2_directory_management_sentinel_active($this->pdo));
        $this->pdo->prepare('INSERT INTO app_config VALUES(0,?,?)')->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY, '0']);
        self::assertFalse(api_v2_directory_management_sentinel_active($this->pdo));
        $this->pdo->prepare('UPDATE app_config SET config_value=? WHERE config_key=?')->execute(['unexpected',API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        self::assertNull(api_v2_directory_management_sentinel_active($this->pdo));
        $status=api_v2_directory_management_status($this->pdo);
        self::assertTrue($status['effective'], 'An unreadable sentinel cannot reopen an inactive policy.');
    }

    public function testUpgradeInitializesUnmanagedInstallToExplicitLocalSentinel(): void
    {
        $this->pdo->exec("DELETE FROM app_config WHERE config_key='api_v2_directory_management_ownership_active'");
        self::assertTrue(api_v2_directory_management_status($this->pdo)['effective'], 'A missing upgrade sentinel is fail-closed.');
        // Migration 0103's INSERT IGNORE default preserves ordinary inactive
        // upgraded installations as editable without overriding an existing 1.
        $this->pdo->prepare('INSERT INTO app_config VALUES(0,?,?)')->execute([API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY,'0']);
        self::assertFalse(api_v2_directory_management_status($this->pdo)['effective']);
        $migration=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0103_external_directory_management_sentinel.sql');
        self::assertStringContainsString('INSERT IGNORE INTO app_config',$migration);
        self::assertStringContainsString("VALUES (0,'api_v2_directory_management_ownership_active','0')",$migration);
        self::assertStringContainsString("SET config_value='1'",$migration);
    }

    public function testExplicitConfirmedAdministratorTakeoverReturnsLocalControlAndAudits(): void
    {
        $this->pdo->exec("UPDATE api_v2_directory_management_policy SET configured_enabled=1,ownership_active=1,last_effective=1,last_reason='managed_degraded' WHERE singleton=1");
        try{api_v2_directory_management_save($this->pdo,false,0,9,false);self::fail('Missing confirmation accepted.');}catch(DomainException){}
        self::assertSame(1,(int)$this->pdo->query('SELECT ownership_active FROM api_v2_directory_management_policy')->fetchColumn());
        $status=api_v2_directory_management_save($this->pdo,false,0,9,true);
        self::assertFalse($status['configured']);self::assertFalse($status['effective']);
        self::assertSame(0,(int)$this->pdo->query('SELECT ownership_active FROM api_v2_directory_management_policy')->fetchColumn());
        self::assertSame('disabled',(string)$this->pdo->query("SELECT outcome FROM api_v2_directory_management_audit WHERE event_type='policy_saved' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testRequiredCapabilitiesRejectLegacyFullAndCoverEveryTopologyOperation(): void
    {
        $required = api_v2_directory_management_required_scopes();
        foreach (['create','write','archive','restore','bind','unbind','read','inventory.read','binding_status.read','organization.assign','organization.remove','organization.move'] as $fragment) {
            self::assertNotEmpty(array_filter($required, static fn(string $scope): bool => str_contains($scope, $fragment)), $fragment);
        }
        foreach ($required as $scope) self::assertFalse(api_key_has_scope('full', $scope, false));
        self::assertGreaterThanOrEqual(13, count(api_v2_directory_management_required_flags()));
    }

    public function testLiveFlagKeyRevocationScopeSiblingAndAttestationChecksAreIndependent(): void
    {
        $flags=api_v2_directory_management_required_flags();foreach($flags as $flag)putenv($flag.'=true');
        try{
            self::assertTrue(api_v2_directory_management_flags_ready());putenv($flags[0].'=false');self::assertFalse(api_v2_directory_management_flags_ready());putenv($flags[0].'=true');
            $this->pdo->exec("INSERT INTO api_keys VALUES(1,7,'full',NULL)");self::assertFalse(api_v2_directory_management_key_ready($this->pdo,7));
            $required=api_v2_directory_management_required_scopes();$missing=$required;array_pop($missing);
            $this->pdo->prepare('UPDATE api_keys SET scopes=? WHERE id=1')->execute([implode(',',$missing)]);self::assertFalse(api_v2_directory_management_key_ready($this->pdo,7));
            $this->pdo->prepare('UPDATE api_keys SET scopes=? WHERE id=1')->execute([implode(',',$required)]);self::assertTrue(api_v2_directory_management_key_ready($this->pdo,7));
            $this->pdo->exec("INSERT INTO api_keys VALUES(2,7,'api.capabilities.read',NULL)");self::assertFalse(api_v2_directory_management_key_ready($this->pdo,7));
            $this->pdo->exec("UPDATE api_keys SET revoked_at='2026-01-01' WHERE id=2");self::assertTrue(api_v2_directory_management_key_ready($this->pdo,7));
            $policy=['release_attestation_sha256'=>''];self::assertFalse(api_v2_directory_management_attestation_ready($this->pdo,$policy));
            $writerDigest=api_v2_directory_management_code_digest();self::assertNotSame('',$writerDigest);
            $json=json_encode(['version'=>1,'schemaVersion'=>104,'writerDigest'=>$writerDigest,'backfillDigest'=>str_repeat('a',64)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$digest=hash('sha256',$json);
            $this->pdo->prepare('INSERT INTO api_v2_directory_management_attestations(attestation_sha256,attestation_json) VALUES(?,?)')->execute([$digest,$json]);
            self::assertTrue(api_v2_directory_management_attestation_ready($this->pdo,['release_attestation_sha256'=>$digest]));
            $this->pdo->prepare('UPDATE api_v2_directory_management_attestations SET attestation_json=? WHERE attestation_sha256=?')->execute(['{}',$digest]);self::assertFalse(api_v2_directory_management_attestation_ready($this->pdo,['release_attestation_sha256'=>$digest]));
        }finally{foreach($flags as $flag)putenv($flag);}
    }

    public function testActivationAttestationRequiresCurrentBackfillReceipt(): void
    {
        $this->pdo->exec('CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_backfill_attestations(attestation_sha256 TEXT PRIMARY KEY,attestation_json TEXT NOT NULL,created_at TEXT);');
        foreach (['public_id','name','general_email','general_phone','address_line1','address_line2','city','state','postal_code','country'] as $column) $this->pdo->exec('ALTER TABLE organizations ADD COLUMN ' . $column . ' TEXT');
        $backfill = api_v2_directory_backfill_attestation($this->pdo);
        self::assertTrue($backfill['complete']);
        $backfillDigest = api_v2_directory_backfill_attestation_persist($this->pdo);
        $json = json_encode(['version'=>1,'schemaVersion'=>104,'writerDigest'=>api_v2_directory_management_code_digest(),'backfillDigest'=>$backfillDigest], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $releaseDigest = hash('sha256', $json);
        $this->pdo->prepare('INSERT INTO api_v2_directory_management_attestations(attestation_sha256,attestation_json) VALUES(?,?)')->execute([$releaseDigest,$json]);
        $policy=['release_attestation_sha256'=>$releaseDigest];
        self::assertTrue(api_v2_directory_management_activation_attestation_ready($this->pdo,$policy));
        $this->pdo->exec("INSERT INTO clients(id,public_id,name) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Changed after configuration')");
        self::assertFalse(api_v2_directory_management_activation_attestation_ready($this->pdo,$policy));
        self::assertFalse(api_v2_directory_management_backfill_health_ready($this->pdo));
        api_v2_directory_backfill($this->pdo,'all',null,10,false);
        self::assertTrue(api_v2_directory_management_backfill_health_ready($this->pdo), 'A synchronized post-activation projection remains healthy.');
        $source='123e4567-e89b-42d3-a456-426614174000';$application='223e4567-e89b-42d3-a456-426614174000';$epoch='323e4567-e89b-42d3-a456-426614174000';
        $this->pdo->prepare('INSERT INTO api_v2_applications VALUES(1,?,?)')->execute([$application,'Example application']);
        $this->pdo->prepare('INSERT INTO api_v2_history_identity VALUES(1,?,?)')->execute([$source,$epoch]);
        $this->pdo->exec('INSERT INTO api_v2_directory_authorization_state VALUES(1,0)');
        $this->pdo->prepare('INSERT INTO api_keys VALUES(1,1,?,NULL)')->execute([implode(',',api_v2_directory_management_required_scopes())]);
        $this->pdo->prepare("UPDATE api_v2_directory_management_policy SET configured_enabled=1,ownership_active=1,application_pk=1,source_instance_id=?,application_id=?,history_epoch=?,release_attestation_sha256=?,last_effective=1,last_reason='ready' WHERE singleton=1")->execute([$source,$application,$epoch,$releaseDigest]);
        $this->pdo->prepare('UPDATE app_config SET config_value=? WHERE config_key=?')->execute(['1',API_V2_DIRECTORY_MANAGEMENT_SENTINEL_KEY]);
        $flags=api_v2_directory_management_required_flags();foreach($flags as $flag)putenv($flag.'=true');
        try {
            self::assertSame('ready',api_v2_directory_management_status($this->pdo)['reason']);
            $this->pdo->exec("UPDATE clients SET name='Projection drift' WHERE id=1");
            self::assertFalse(api_v2_directory_management_backfill_health_ready($this->pdo), 'Unrecorded projection drift degrades active health.');
            self::assertSame('managed_degraded',api_v2_directory_management_status($this->pdo)['reason']);
        } finally { foreach($flags as $flag)putenv($flag); }
    }

    public function testEveryBrowserWriterHasCentralAndControllerLevelProtection(): void
    {
        $root = dirname(__DIR__, 2);
        $router = (string)file_get_contents($root . '/public/index.php');
        self::assertStringContainsString('api_v2_directory_management_browser_writers', $router);
        foreach (api_v2_directory_management_browser_writers() as $route => $_) self::assertStringContainsString("'{$route}'", (string)file_get_contents($root . '/src/utils/api_v2_directory_management.php'));
        foreach (api_v2_directory_writer_inventory() as $writer) {
            if ($writer['governance'] === 'non_projection' || str_starts_with($writer['path'], 'src/services/')) continue;
            $contents = (string)file_get_contents($root . '/' . $writer['path']);
            self::assertStringContainsString('api_v2_directory_management_guard', $contents, $writer['path']);
        }
    }

    public function testGenericUiGuardsAndLabelsArePresent(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['client/clients-list.php','client/clients-create.php','client/clients-edit.php','client/archived-clients.php','client/onboarding.php','organization/organizations-list.php','organization/organizations-create.php','organization/organizations-edit.php','organization/organization-view.php'] as $view) {
            self::assertStringContainsString('directoryManagementStatus', (string)file_get_contents($root . '/src/views/pages/' . $view), $view);
        }
        self::assertSame('Directory changes are managed by an authorized external application', API_V2_DIRECTORY_MANAGEMENT_LABEL);
    }

    public function testInternalOrganizationNotesAndDocumentsRemainOutsideDirectoryProjection(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['organization-update-notes.php','organizations_upload.php','organization_document_upload.php'] as $controller) {
            $source = (string)file_get_contents($root . '/src/controllers/organization/' . $controller);
            self::assertStringNotContainsString('api_v2_directory_management_guard', $source, $controller);
        }
        $lifecycleDocs = (string)file_get_contents($root . '/docs/admin/api-v2-directory-lifecycle.md');
        self::assertStringContainsString('PA-internal organization notes and organization document uploads remain local', $lifecycleDocs);
        self::assertStringContainsString('A `unit` is backed by `organization_departments`', $lifecycleDocs);
    }

    public function testAuthorizedStatelessApiCommandsRemainOutsideTheBrowserGuard(): void
    {
        $root=dirname(__DIR__,2);$router=(string)file_get_contents($root.'/public/index.php');
        self::assertLessThan(strpos($router,'api_v2_directory_management_browser_writers'),strpos($router,"require_once __DIR__ . '/../src/controllers/api/directory_create_command_v2.php'"));
        foreach(['directory_create_command_v2.php','directory_client_profile_command_v2.php','directory_organization_profile_command_v2.php','directory_unit_profile_command_v2.php','directory_unit_contact_command_v2.php','directory_binding_command_v2.php','directory_binding_revision_refresh_v2.php','directory_lifecycle_command_v2.php','directory_relationship_command_v2.php','directory_binding_revoke_command_v2.php','directory_inventory_v2.php'] as $controller){
            self::assertStringNotContainsString('api_v2_directory_management_guard',(string)file_get_contents($root.'/src/controllers/api/'.$controller),$controller);
        }
    }

    public function testMigrationIsRegisteredWithoutChangingEarlierHealthSnapshots(): void
    {
        require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
        $tables=['api_v2_directory_management_policy','api_v2_directory_management_attestations','api_v2_directory_management_audit'];
        self::assertSame([], migration_required_tables_for_version($tables, 97));
        self::assertSame($tables, migration_required_tables_for_version($tables, 98));
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0098_external_directory_management_policy.sql');
        self::assertStringContainsString('configured_enabled TINYINT(1) NOT NULL DEFAULT 0',$sql);
        self::assertStringContainsString('api_v2_directory_management_audit',$sql);
        $newTables=['api_v2_directory_lifecycle_command_receipts','api_v2_directory_relationship_command_receipts','api_v2_directory_binding_revoke_command_receipts'];
        self::assertSame([],migration_required_tables_for_version($newTables,98));
        self::assertSame($newTables,migration_required_tables_for_version($newTables,99));
        $sentinelMigration=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0103_external_directory_management_sentinel.sql');
        self::assertStringContainsString("api_v2_directory_management_ownership_active","$sentinelMigration");
        self::assertStringContainsString('ownership_active=1',$sentinelMigration);
        $unitTables=['api_v2_directory_unit_profile_command_receipts','api_v2_directory_unit_contact_command_receipts'];
        self::assertSame([],migration_required_tables_for_version($unitTables,103));
        self::assertSame($unitTables,migration_required_tables_for_version($unitTables,104));
        self::assertStringContainsString("('api_v2_directory_management_ownership_active', '0')",(string)file_get_contents(dirname(__DIR__,2).'/database/baseline.sql'));
    }
}
