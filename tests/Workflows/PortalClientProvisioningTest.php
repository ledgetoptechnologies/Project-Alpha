<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\PortalClientProvisioningService;
use App\Services\PortalAuthorityService;
use App\Services\ExternalOpsSyncOrchestrator;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/src/services/PortalSourceVersion.php';
require_once dirname(__DIR__,2).'/src/services/PortalAuthorityService.php';
require_once dirname(__DIR__,2).'/src/services/PortalClientProvisioningService.php';
require_once dirname(__DIR__,2).'/src/services/ExternalOpsSyncOrchestrator.php';

final class PortalClientProvisioningTest extends TestCase
{
    private PDO $pdo;
    private PortalClientProvisioningService $service;

    protected function setUp(): void
    {
        $this->pdo = new BackfillRacePDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[BackfillRaceStatement::class,[$this->pdo]]);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,email TEXT,organization_id INTEGER,client_type TEXT,archived INTEGER DEFAULT 0,deleted_at TEXT,source_version TEXT DEFAULT '1');
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY AUTOINCREMENT,application_key TEXT UNIQUE,display_label TEXT,enabled INTEGER,portal_projection_enabled INTEGER,relation_projection_enabled INTEGER DEFAULT 0,contact_assignment_projection_enabled INTEGER DEFAULT 0,catalog_projection_enabled INTEGER DEFAULT 0,service_assignment_projection_enabled INTEGER DEFAULT 0,pricing_preview_enabled INTEGER DEFAULT 0,draft_quote_enabled INTEGER DEFAULT 0,pricing_source TEXT,draft_source TEXT,portal_route TEXT,catalog_route TEXT,delivery_enabled INTEGER DEFAULT 0,delivery_key_id TEXT,delivery_previous_key_id TEXT,delivery_previous_valid_until TEXT,delivery_credentials_enc TEXT,delivery_timeout_seconds INTEGER DEFAULT 15,delivery_max_attempts INTEGER DEFAULT 12,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,root_type TEXT,root_public_id TEXT,display_name TEXT,source_version TEXT,active INTEGER,created_by INTEGER,updated_by INTEGER,UNIQUE(root_type,root_public_id));
CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,created_by INTEGER,updated_by INTEGER,PRIMARY KEY(profile_id,workspace_id));
CREATE TABLE portal_principals(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,authorization_version INTEGER DEFAULT 1,activated_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER,created_by INTEGER,PRIMARY KEY(portal_principal_id,client_id));
CREATE TABLE portal_identity_bindings(id INTEGER PRIMARY KEY AUTOINCREMENT,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,enabled INTEGER,bound_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_client_access_roots(root_type TEXT,root_public_id TEXT,access_state TEXT,state_reason TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER,PRIMARY KEY(root_type,root_public_id));
CREATE TABLE portal_client_login_eligibility(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,manual_state TEXT,eligibility_status TEXT,review_reason TEXT,canonical_email TEXT,source_version TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_client_provisioning_backfill(integration_profile_id INTEGER,root_type TEXT,root_public_id TEXT,contract_fingerprint TEXT,state TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,last_error_code TEXT,completed_at TEXT,PRIMARY KEY(integration_profile_id,root_type,root_public_id));
CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
 CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER DEFAULT 0,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT DEFAULT '2000-01-01 00:00:00',claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE cron_job_runs(job_name TEXT PRIMARY KEY,last_run TEXT,status TEXT,result TEXT);
CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));
CREATE TABLE portal_projection_resource_state(integration_profile_id INTEGER,workspace_public_id TEXT,route_type TEXT,resource_type TEXT,resource_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id));
CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);
CREATE TABLE integration_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,event_id TEXT UNIQUE,integration_key TEXT,event_type TEXT,schema_version INTEGER,payload_json TEXT,occurred_at TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,last_error TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE item_library(id INTEGER PRIMARY KEY,portal_public_id TEXT UNIQUE,portal_source_version TEXT,item_name TEXT,portal_summary TEXT,portal_category TEXT,portal_display_order INTEGER,portal_geometry_requirement TEXT,portal_questions_json TEXT,portal_requestable INTEGER,is_active INTEGER,entry_type TEXT);
CREATE TABLE portal_service_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,subject_type TEXT,subject_public_id TEXT,service_public_id TEXT,active INTEGER,effective_from TEXT,effective_until TEXT,deleted_at TEXT,created_by INTEGER,updated_by INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE portal_service_assignment_projection_state(integration_profile_id INTEGER PRIMARY KEY,source_generation TEXT,source_sequence INTEGER,snapshot_hash TEXT);
CREATE TABLE portal_service_assignment_projection_records(integration_profile_id INTEGER,assignment_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,assignment_public_id));
CREATE TABLE portal_service_assignment_projection_receipts(integration_profile_id INTEGER,idempotency_hash TEXT,payload_hash TEXT,result_json TEXT,PRIMARY KEY(integration_profile_id,idempotency_hash));
INSERT INTO portal_integration_profiles(id,application_key,display_label,enabled,portal_projection_enabled) VALUES(1,'generic_operations','Generic operations',0,0);
SQL);
        $this->service = new PortalClientProvisioningService();
    }

    public function testOrganizationContactWithUniqueCanonicalEmailGetsInvitationIntentOnly(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'org-a','Example Organization');
            INSERT INTO clients VALUES(20,'client-a','A Person',' Person@Example.test ',10,'business',0,NULL,'client-v1')");

        $this->pdo->beginTransaction();
        $summary = $this->service->ensureScopes($this->pdo, [['root_type'=>'organization','root_public_id'=>'org-a']], 7, ['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        $this->pdo->commit();

        self::assertSame(['roots'=>1,'workspaces'=>1,'eligible'=>1,'review_required'=>0,'revoked'=>0], $summary);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_workspaces WHERE root_type='organization' AND root_public_id='org-a' AND active=1")->fetchColumn());
        self::assertSame('person@example.test', $this->pdo->query('SELECT canonical_email FROM portal_client_login_eligibility WHERE client_id=20')->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements WHERE active=1')->fetchColumn());
        self::assertSame(['delivery.view','directory.read','workspace.view'], $this->pdo->query('SELECT capability FROM portal_v2_entitlements ORDER BY capability')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM portal_identity_bindings')->fetchColumn(), 'Project Alpha must not bind an external identity.');
    }

    public function testDuplicateAndNonHumanStandaloneRecordsFailClosedForReview(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'org-a','Example Organization');
            INSERT INTO clients VALUES
              (20,'client-a','First Person','same@example.test',10,'unknown',0,NULL,'a'),
              (21,'client-b','Second Person','SAME@example.test',10,'consumer',0,NULL,'b'),
              (22,'client-c','Unclassified Company','office@example.test',NULL,'unknown',0,NULL,'c'),
              (23,'client-d','Business Record','owner@example.test',NULL,'business',0,NULL,'d')");
        $scopes=[
            ['root_type'=>'organization','root_public_id'=>'org-a'],
            ['root_type'=>'standalone_client','root_public_id'=>'client-c'],
            ['root_type'=>'standalone_client','root_public_id'=>'client-d'],
        ];

        $this->pdo->beginTransaction();
        $summary=$this->service->ensureScopes($this->pdo,$scopes,7,['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        $this->pdo->commit();

        self::assertSame(4,$summary['review_required']);
        self::assertSame(0,$summary['eligible']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        self::assertSame(['duplicate_email','duplicate_email','non_human_record','non_human_record'],$this->pdo->query('SELECT review_reason FROM portal_client_login_eligibility ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testExplicitClientAndRootRevocationSurviveReconciliation(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'org-a','Example Organization');
            INSERT INTO clients VALUES(20,'client-a','A Person','person@example.test',10,'unknown',0,NULL,'v1')");
        $scope=[['root_type'=>'organization','root_public_id'=>'org-a']];
        $profile=['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1];
        $this->pdo->beginTransaction();$this->service->ensureScopes($this->pdo,$scope,7,$profile);$this->pdo->commit();
        $principal=(int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility')->fetchColumn();

        $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='revoked'; UPDATE portal_client_access_roots SET access_state='revoked'");
        $this->pdo->beginTransaction();$summary=$this->service->ensureScopes($this->pdo,$scope,7,$profile);$this->pdo->commit();

        self::assertSame(1,$summary['revoked']);
        self::assertSame('revoked',$this->pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principal}")->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements WHERE active=1')->fetchColumn());
    }

    public function testFutureMutationPathInvokesProvisioningBeforeProjection(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/src/services/PortalProjectionMutationService.php');
        self::assertStringContainsString('(new PortalClientProvisioningService())->ensureScopes($pdo,$scopes);',$source);
        self::assertLessThan(strpos($source,'$this->reconcileRelations($pdo,$scopes);'),strpos($source,'PortalClientProvisioningService())->ensureScopes'));
    }

    public function testStatusRequiresTheSavedProducerButNoSecondPortalCredential(): void
    {
        $this->withPortalCapabilities([], function (): void {
            $this->pdo->exec('DELETE FROM portal_integration_profiles');
            $config=(new \App\Services\ExternalOpsConfigService())->save($this->pdo,[
                'enabled'=>1,
                'label'=>'Generic operations',
                'application_key'=>'generic_operations',
                'webhook_url'=>'https://operations.example.test/api/integration/events',
                'access_client_id'=>'service-id-do-not-return',
                'access_client_secret'=>'service-secret-do-not-return',
                'hmac_secret'=>str_repeat('o',32),
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ]);

            $status=$this->service->status($this->pdo,(string)$config['application_key']);

            self::assertFalse($status['configured']);
            self::assertFalse($status['ready']);
            self::assertTrue($status['preflight']['operations_delivery_ready']);
            self::assertSame(['portal producer saved for this connection'],$status['preflight']['issues']);
            self::assertSame('unpaired',$status['transition_state']);
            self::assertStringContainsString('same connection',(string)$status['transition_message']);
            $serialized=json_encode($status,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('service-id-do-not-return',$serialized);
            self::assertStringNotContainsString('service-secret-do-not-return',$serialized);
            self::assertStringNotContainsString(str_repeat('o',32),$serialized);
        });
    }

    public function testStatusPreflightBecomesReadyFromTheSingleExternalOperationsConnection(): void
    {
        $this->withPortalCapabilities([
            'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]],
        ], function (): void {
            $this->pdo->exec('DELETE FROM portal_integration_profiles');
            $config=(new \App\Services\ExternalOpsConfigService())->save($this->pdo,[
                'enabled'=>1,
                'label'=>'Generic operations',
                'application_key'=>'generic_operations',
                'webhook_url'=>'https://operations.example.test/api/integration/events',
                'access_client_id'=>'service-id',
                'access_client_secret'=>'service-secret',
                'hmac_secret'=>str_repeat('o',32),
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ]);
            $this->service->configureConnection($this->pdo,$config,7);

            $status=$this->service->status($this->pdo,'generic_operations');

            self::assertTrue($status['configured']);
            self::assertTrue($status['ready']);
            self::assertTrue($status['preflight']['operations_delivery_ready']);
            self::assertSame([],$status['preflight']['issues']);
            self::assertNotContains(false,array_column($status['preflight']['checks'],'ready'));
            self::assertSame('stable',$status['transition_state']);
            self::assertNull($status['transition_message']);
            $serialized=json_encode($status,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('portal-v1',$serialized);
            self::assertStringNotContainsString(str_repeat('p',32),$serialized);
            self::assertStringNotContainsString('operations.example.test',$serialized);
        });
    }

    public function testStatusExposesOnlyAggregateAllowlistedRetryAndSchedulerEvidence(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]], function (): void {
            $config=(new \App\Services\ExternalOpsConfigService())->save($this->pdo,$this->connection('generic_operations','https://operations.example.test/events'));
            $this->service->configureConnection($this->pdo,$config,7);
            $empty=$this->service->status($this->pdo,'generic_operations');
            self::assertSame(0,$empty['counts']['pending']);
            self::assertSame(0,$empty['counts']['retrying']);
            self::assertSame([], $empty['delivery_diagnostics']['retries']);
            self::assertNull($empty['delivery_diagnostics']['oldest_pending_age_seconds']);
            self::assertSame('unknown',$empty['scheduler']['reconciliation']['state']);
            self::assertSame('unknown',$empty['scheduler']['delivery']['state']);
            $this->pdo->exec("INSERT INTO cron_job_runs VALUES('portal_client_provisioning_backfill',NULL,'running',NULL)");
            self::assertSame('unknown',$this->service->status($this->pdo,'generic_operations')['scheduler']['reconciliation']['state']);

            $this->pdo->exec("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,payload_json,attempts,last_http_status,last_error_code,created_at) VALUES(1,'delivery-a','workspace-private',1,1,'event','portal','{}',3,503,'http_5xx','2000-01-01 00:00:00'),(1,'delivery-b','workspace-secret',1,2,'event','portal','{}',2,0,'receiver said token=secret','2000-01-02 00:00:00'),(2,'delivery-other-profile','workspace-other',1,1,'event','portal','{}',8,429,'http_429','2000-01-01 00:00:00')");
            $retryInsert=$this->pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,payload_json,attempts,last_http_status,last_error_code,created_at) VALUES(1,?, 'workspace-not-returned',1,1,'event','portal','{}',1,NULL,'external-operations-delivery-unavailable','2000-01-03 00:00:00')");
            for($i=1;$i<=39;$i++)$retryInsert->execute(['delivery-retry-'.$i]);
            $this->pdo->exec("UPDATE cron_job_runs SET last_run='2026-09-07 20:30:00',status='success',result='Activation unchanged (prerequisites_missing); ready no' WHERE job_name='portal_client_provisioning_backfill'; INSERT INTO cron_job_runs VALUES('portal_projection_outbox','2026-09-07 20:30:01','success','Processed 41; delivered 0; retrying 41; dead-lettered 0')");

            $status=$this->service->status($this->pdo,'generic_operations');

            self::assertSame(41,$status['counts']['pending']);
            self::assertSame(41,$status['counts']['retrying']);
            self::assertSame(0,$status['counts']['failed']);
            self::assertSame('preflight_not_ready',$status['scheduler']['reconciliation']['state']);
            self::assertSame('ran',$status['scheduler']['delivery']['state']);
            self::assertSame(['external-operations-delivery-unavailable','http_5xx','delivery_retry'],array_column($status['delivery_diagnostics']['retries'],'code'));
            self::assertSame([null,503,0],array_column($status['delivery_diagnostics']['retries'],'http_status'));
            self::assertSame([1,3,2],array_column($status['delivery_diagnostics']['retries'],'attempts'));
            self::assertSame([39,1,1],array_column($status['delivery_diagnostics']['retries'],'count'));
            self::assertGreaterThan(0,(int)$status['delivery_diagnostics']['oldest_pending_age_seconds']);
            $serialized=json_encode($status,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('workspace-private',$serialized);
            self::assertStringNotContainsString('workspace-secret',$serialized);
            self::assertStringNotContainsString('receiver said token=secret',$serialized);

            $this->pdo->exec("UPDATE cron_job_runs SET status='failed',result='ready no' WHERE job_name='portal_client_provisioning_backfill'");
            self::assertSame('failed',$this->service->status($this->pdo,'generic_operations')['scheduler']['reconciliation']['state']);
        });
    }

    public function testExistingCompleteConnectionActivatesAutomaticallyOnceAndUsesSystemAttribution(): void
    {
        $this->withPortalCapabilities([
            'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]],
        ], function (): void {
            $this->pdo->exec('DELETE FROM portal_integration_profiles');
            (new \App\Services\ExternalOpsConfigService())->save($this->pdo,[
                'enabled'=>1,
                'label'=>'Generic operations',
                'application_key'=>'generic_operations',
                'webhook_url'=>'https://operations.example.test/api/integration/events',
                'access_client_id'=>'service-id',
                'access_client_secret'=>'service-secret',
                'hmac_secret'=>str_repeat('o',32),
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ]);

            $first=$this->service->activateConfiguredConnection($this->pdo);
            self::assertTrue($first['attempted']);
            self::assertTrue($first['ready']);
            self::assertSame('stable',$first['transition_state']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1')->fetchColumn());
            self::assertNull($this->pdo->query('SELECT created_by FROM portal_integration_profiles')->fetchColumn()?:null);
            self::assertNull($this->pdo->query('SELECT updated_by FROM portal_integration_profiles')->fetchColumn()?:null);
            $auditCount=(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_audit')->fetchColumn();

            $second=$this->service->activateConfiguredConnection($this->pdo);
            self::assertFalse($second['attempted']);
            self::assertTrue($second['ready']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
            self::assertSame($auditCount,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_audit')->fetchColumn());
        });
    }

    public function testDisabledStoredConnectionDoesNotActivatePortalProducer(): void
    {
        $this->withPortalCapabilities([], function (): void {
            $this->pdo->exec('DELETE FROM portal_integration_profiles');
            (new \App\Services\ExternalOpsConfigService())->save($this->pdo,[
                'enabled'=>0,
                'label'=>'Unconfigured secondary operations',
                'application_key'=>'secondary_operations',
                'webhook_url'=>'',
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ]);

            $result=$this->service->activateConfiguredConnection($this->pdo);
            self::assertFalse($result['attempted']);
            self::assertFalse($result['ready']);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
        });
    }

    public function testCompleteButDisabledStoredConnectionDoesNotActivatePortalProducer(): void
    {
        $this->withPortalCapabilities([], function (): void {
            $this->pdo->exec('DELETE FROM portal_integration_profiles');
            (new \App\Services\ExternalOpsConfigService())->save($this->pdo,[
                'enabled'=>0,
                'label'=>'Paused operations',
                'application_key'=>'paused_operations',
                'webhook_url'=>'https://operations.example.test/api/integration/events',
                'access_client_id'=>'service-id',
                'access_client_secret'=>'service-secret',
                'hmac_secret'=>str_repeat('o',32),
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ]);

            $result=$this->service->activateConfiguredConnection($this->pdo);
            self::assertFalse($result['attempted']);
            self::assertFalse($result['ready']);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
        });
    }

    public function testEnabledButIncompleteStoredConnectionDoesNotActivatePortalProducer(): void
    {
        $this->withPortalCapabilities([], function (): void {
            $this->pdo->exec("DELETE FROM portal_integration_profiles;
                INSERT INTO app_config(organization_id,config_key,config_value) VALUES
                    (0,'external_ops_enabled','1'),
                    (0,'external_ops_label','Incomplete operations'),
                    (0,'external_ops_application_key','incomplete_operations'),
                    (0,'external_ops_webhook_url','https://operations.example.test/api/integration/events')");

            $result=$this->service->activateConfiguredConnection($this->pdo);
            self::assertFalse($result['attempted']);
            self::assertFalse($result['ready']);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
        });
    }

    public function testAutomaticActivationPreservesExistingRootAndPersonRevocations(): void
    {
        $this->withPortalCapabilities([
            'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]],
        ],function():void{
            $this->prepareHistoricalSchema();
            (new \App\Services\ExternalOpsConfigService())->save($this->pdo,$this->connection('generic_operations','https://operations.example.test/events')+['hmac_secret'=>str_repeat('o',32)]);
            $this->pdo->exec("INSERT INTO organizations(id,public_id,name) VALUES(10,'org-a','Organization');
                INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',10,'business',0,NULL,'v1'),(21,'client-b','B Person','b@example.test',10,'business',0,NULL,'v1'),(22,'client-c','C Person','c@example.test',NULL,'consumer',0,NULL,'v1');
                INSERT INTO portal_client_access_roots(root_type,root_public_id,access_state) VALUES('standalone_client','client-c','revoked');
                INSERT INTO portal_client_login_eligibility(client_id,manual_state,eligibility_status) VALUES(20,'revoked','revoked');");

            $activation=$this->service->activateConfiguredConnection($this->pdo);
            self::assertTrue($activation['attempted']);
            self::assertTrue($activation['ready']);
            self::assertSame(2,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',25)['completed']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn());
            self::assertSame(['revoked','eligible','revoked'],$this->pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame('revoked',$this->pdo->query("SELECT access_state FROM portal_client_access_roots WHERE root_public_id='client-c'")->fetchColumn());
        });
    }

    public function testAutomaticActivationDrainsOldRouteBeforeEnablingChangedRoute(): void
    {
        $this->withPortalCapabilities([
            'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]],
        ],function():void{
            $old=$this->connection('generic_operations','https://old.example.test/events')+['hmac_secret'=>str_repeat('o',32)];
            $configured=(new \App\Services\ExternalOpsConfigService())->save($this->pdo,$old);
            $profileId=(int)$this->service->configureConnection($this->pdo,$configured,7);
            $this->activateWorkspace($profileId);
            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP');

            $replacement=$this->connection('generic_operations','https://replacement.example.test/events')+['hmac_secret'=>str_repeat('o',32)];
            (new \App\Services\ExternalOpsConfigService())->save($this->pdo,$replacement);
            $retiring=$this->service->activateConfiguredConnection($this->pdo);
            self::assertTrue($retiring['attempted']);
            self::assertFalse($retiring['ready']);
            self::assertSame('retiring',$retiring['transition_state']);
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertGreaterThan(0,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id={$profileId} AND is_revocation=1 AND delivered_at IS NULL")->fetchColumn());

            $stillRetiring=$this->service->activateConfiguredConnection($this->pdo);
            self::assertTrue($stillRetiring['attempted']);
            self::assertFalse($stillRetiring['ready']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());

            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP');
            $activated=$this->service->activateConfiguredConnection($this->pdo);
            self::assertTrue($activated['attempted']);
            self::assertTrue($activated['ready']);
            self::assertSame('stable',$activated['transition_state']);
            self::assertSame('https://replacement.example.test/events',$this->pdo->query("SELECT portal_route FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
        });
    }

    public function testSingleConnectionUsesExactSignedEventUrlAndExistingCredentials(): void
    {
        $previousEncryption=getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=portal-provisioning-test-key');
        try{
            $profileId=$this->service->configureConnection($this->pdo,[
                'enabled'=>1,
                'application_key'=>'new_operations',
                'label'=>'Operations',
                'webhook_url'=>'https://operations.example.test/api/integration/events',
                'access_client_id'=>'access-id',
                'access_client_secret'=>'access-secret',
                'hmac_secret'=>str_repeat('s',32),
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ],7);
            self::assertNotNull($profileId);
            $profile=$this->pdo->query('SELECT * FROM portal_integration_profiles WHERE id='.(int)$profileId)->fetch(PDO::FETCH_ASSOC);
            self::assertSame('https://operations.example.test/api/integration/events',$profile['portal_route']);
            self::assertNull($profile['delivery_key_id']);
            self::assertSame(1,(int)$profile['portal_projection_enabled']);
            self::assertSame(1,(int)$profile['relation_projection_enabled']);
            self::assertNull($profile['delivery_credentials_enc']);
        }finally{
            $previousEncryption===false?putenv('APP_ENCRYPTION_KEY'):putenv('APP_ENCRYPTION_KEY='.$previousEncryption);
        }
    }

    public function testEd25519PublicConnectionReadinessDoesNotRequirePrivateKeyOrHmac(): void
    {
        $publicKey = rtrim(strtr(base64_encode(str_repeat('p', 32)), '+/', '-_'), '=');
        $config = [
            'enabled' => 1,
            'configured_enabled' => 1,
            'delivery_ready' => 1,
            // This is exactly the public shape returned by ExternalOpsConfigService::load().
            // Private signing bytes must never be copied into this portal service.
            'delivery_issues' => [],
            'application_key' => 'generic_operations',
            'label' => 'Generic operations',
            'webhook_url' => 'https://operations.example.test/events',
            'access_client_id' => 'access-id',
            'access_client_secret' => 'access-secret',
            'signing_mode' => 'ed25519',
            'signing_key_id' => 'pa_ed25519_0123456789abcdef',
            'signing_public_key' => $publicKey,
            'timeout_seconds' => 15,
            'max_attempts' => 12,
        ];
        self::assertArrayNotHasKey('private_key_b64', $config);
        self::assertArrayNotHasKey('hmac_secret', $config);

        $profileId = $this->service->configureConnection($this->pdo, $config, 7);
        self::assertNotNull($profileId);

        $preflight = new \ReflectionMethod($this->service, 'activationPreflight');
        $status = $preflight->invoke($this->service, $config, [
            'application_key' => 'generic_operations',
            'portal_route' => 'https://operations.example.test/events',
            'enabled' => 1,
            'portal_projection_enabled' => 1,
            'delivery_enabled' => 1,
        ], ['outbound_enabled' => true, 'hooks_enabled' => true]);
        self::assertTrue($status['operations_delivery_ready']);
        self::assertTrue($status['checks'][3]['ready']);
        self::assertSame('ready outbound signing method', $status['checks'][3]['label']);
        self::assertSame([], $status['issues']);
    }

    public function testSingleConnectionKeepsServiceAssignmentsDefaultOffAndExplicitlyEnablesThem(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://operations.example.test/events');
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            self::assertSame(0,(int)$this->pdo->query("SELECT service_assignment_projection_enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE route_type='service_assignments'")->fetchColumn());

            $config['service_assignment_projection_enabled']=1;
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$config,7));
            self::assertSame(1,(int)$this->pdo->query("SELECT service_assignment_projection_enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE route_type='service_assignments'")->fetchColumn(),'An empty initial snapshot still requires a page and activation record.');

            // A caller unaware of the additive option preserves the explicit choice.
            $this->service->configureConnection($this->pdo,$config,7);
            unset($config['service_assignment_projection_enabled']);
            $this->service->configureConnection($this->pdo,$config,7);
            self::assertSame(1,(int)$this->pdo->query("SELECT service_assignment_projection_enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE route_type='service_assignments'")->fetchColumn(),'Stable saves must not enqueue duplicate snapshots.');
        });
    }

    public function testSingleConnectionDisableQueuesAssignmentRevocationSnapshot(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://operations.example.test/events');
            $config['service_assignment_projection_enabled']=1;
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            $this->pdo->exec("UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE route_type='service_assignments'");

            $config['service_assignment_projection_enabled']=0;
            $this->service->configureConnection($this->pdo,$config,7);
            self::assertSame(0,(int)$this->pdo->query("SELECT service_assignment_projection_enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE route_type='service_assignments' AND is_revocation=1 AND delivered_at IS NULL")->fetchColumn(),'Disable queues an authoritative empty page and activation that remain deliverable after the capability is off.');
        });
    }

    public function testDisableQueuesOldRouteTombstoneAndKeepsDeliveryAlive():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://old.example.test/events',true);
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            $this->activateWorkspace($profileId);

            $config['enabled']=0;$config['configured_enabled']=false;
            $this->service->configureConnection($this->pdo,$config,7);

            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(0,(int)$profile['enabled']);self::assertSame(0,(int)$profile['portal_projection_enabled']);self::assertSame(1,(int)$profile['delivery_enabled']);
            self::assertSame('https://old.example.test/events',$this->pdo->query("SELECT destination_url FROM portal_projection_outbox WHERE integration_profile_id={$profileId} AND is_revocation=1")->fetchColumn());
            self::assertSame('1',$this->pdo->query("SELECT config_value FROM app_config WHERE config_key='portal_outbound_delivery_enabled'")->fetchColumn());
        });
    }

    public function testOriginRotationWaitsForDrainThenReusesOnlyBoundProfile():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);$this->activateWorkspace($profileId);
            $this->pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json)VALUES(?,'normal-before-rotation','workspace-legacy',3,1,'event','portal',0,'https://old.example.test/api/internal/project-alpha/portal-v2','portal-v1','{}')")->execute([$profileId]);
            $new=$this->connection('generic_operations','https://new.example.test/events');
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$new,7));
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame('https://old.example.test/events',$this->pdo->query("SELECT destination_url FROM portal_projection_outbox WHERE is_revocation=1")->fetchColumn());
            $this->service->configureConnection($this->pdo,$new,7);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn(),'Replacement must remain inactive before drain.');
            $this->pdo->exec("UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1");
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$new,7));
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(1,(int)$profile['enabled']);self::assertSame('https://new.example.test/events',$profile['portal_route']);
            self::assertSame('profile_disabled_superseded',$this->pdo->query("SELECT last_error_code FROM portal_projection_outbox WHERE delivery_id='normal-before-rotation'")->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1')->fetchColumn());
        });
    }

    public function testApplicationRekeyRetiresDrainsAndRotatesSameProfile():void
    {
        $this->withPortalCapabilities([
            'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]],
            'replacement_operations'=>['portal'=>['keyId'=>'portal-v2','current'=>str_repeat('b',32)]],
        ],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);$this->activateWorkspace($profileId);
            $new=$this->connection('replacement_operations','https://new.example.test/events');
            $this->service->configureConnection($this->pdo,$new,7);
            self::assertSame('generic_operations',$this->pdo->query("SELECT application_key FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1');
            $this->service->configureConnection($this->pdo,$new,7);
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('replacement_operations',$profile['application_key']);self::assertNull($profile['delivery_key_id']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
        });
    }

    public function testStableConnectionNeverCopiesASecondPortalCredential():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://stable.example.test/events');
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            $config['hmac_secret']=str_repeat('b',32);
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$config,7));
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertNull($profile['delivery_key_id']);
            self::assertNull($profile['delivery_previous_key_id']);
            self::assertNull($profile['delivery_credentials_enc']);
        });
    }

    public function testStableConnectionIgnoresObsoletePortalSecretMap():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://stable.example.test/events');
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.json_encode([
                'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('b',32)]],
            ],JSON_THROW_ON_ERROR));
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$config,7));
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertNull($profile['delivery_key_id']);
            self::assertNull($profile['delivery_credentials_enc']);
        });
    }

    public function testOriginRotationAdministrativelyResolvesOldDeadLetteredNormalRowsOnlyAfterRevocationsDrain():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);
            $this->activateWorkspace($profileId);
            $this->pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json,dead_lettered_at,last_error_code)VALUES(?,'old-dead-letter','workspace-legacy',3,1,'event','portal',0,'https://old.example.test/api/internal/project-alpha/portal-v2','portal-v1','{}',CURRENT_TIMESTAMP,'transport_failed')")->execute([$profileId]);

            $new=$this->connection('generic_operations','https://new.example.test/events');
            $this->service->configureConnection($this->pdo,$new,7);
            self::assertNull($this->pdo->query("SELECT delivered_at FROM portal_projection_outbox WHERE delivery_id='old-dead-letter'")->fetchColumn()?:null,'A dead-lettered normal event must remain unresolved while revocations are pending.');

            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1');
            $this->service->configureConnection($this->pdo,$new,7);

            self::assertNotFalse($this->pdo->query("SELECT delivered_at FROM portal_projection_outbox WHERE delivery_id='old-dead-letter'")->fetchColumn());
            self::assertSame('transport_failed',$this->pdo->query("SELECT last_error_code FROM portal_projection_outbox WHERE delivery_id='old-dead-letter'")->fetchColumn(),'The original failure remains auditable.');
            self::assertSame('https://new.example.test/events',$this->pdo->query("SELECT portal_route FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_integration_audit WHERE action='portal.client_provisioning.retired_events_resolved'")->fetchColumn());
        });
    }

    public function testRotationPreservesNonPortalCapabilityContract():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);
            $this->pdo->exec("UPDATE portal_integration_profiles SET relation_projection_enabled=1,catalog_projection_enabled=1,pricing_preview_enabled=1,draft_quote_enabled=1,pricing_source='pricing-v1',draft_source='draft-v1',catalog_route='https://catalog.example.test/v1' WHERE id={$profileId}");
            $this->activateWorkspace($profileId);

            $new=$this->connection('generic_operations','https://new.example.test/events');
            $this->service->configureConnection($this->pdo,$new,7);
            $retired=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(0,(int)$retired['enabled']);
            self::assertSame([1,1,1,1],array_map('intval',[$retired['relation_projection_enabled'],$retired['catalog_projection_enabled'],$retired['pricing_preview_enabled'],$retired['draft_quote_enabled']]));
            self::assertSame('pricing-v1',$retired['pricing_source']);self::assertSame('draft-v1',$retired['draft_source']);

            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1');
            $this->service->configureConnection($this->pdo,$new,7);
            $active=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(1,(int)$active['enabled']);self::assertSame(1,(int)$active['portal_projection_enabled']);
            self::assertSame([1,1,1,1],array_map('intval',[$active['relation_projection_enabled'],$active['catalog_projection_enabled'],$active['pricing_preview_enabled'],$active['draft_quote_enabled']]));
            self::assertSame('pricing-v1',$active['pricing_source']);self::assertSame('draft-v1',$active['draft_source']);
            self::assertSame('https://catalog.example.test/v1',$active['catalog_route']);
        });
    }

    public function testLegacyProfileActionCannotEnableASecondPortalProducer():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://primary.example.test/events'),7);
            $this->pdo->exec("INSERT INTO portal_integration_profiles(application_key,display_label,enabled,portal_projection_enabled)VALUES('legacy_secondary','Legacy secondary',0,0)");
            $secondaryId=(int)$this->pdo->lastInsertId();
            try{
                (new PortalAuthorityService())->saveProfile($this->pdo,[
                    'profile_id'=>$secondaryId,'application_key'=>'legacy_secondary','display_label'=>'Legacy secondary',
                    'enabled'=>1,'portal_projection_enabled'=>1,'portal_route'=>'https://secondary.example.test/portal',
                ],7);
                self::fail('A second active portal producer must be rejected.');
            }catch(\DomainException$error){self::assertStringContainsString('Another workspace publisher',$error->getMessage());}
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$secondaryId}")->fetchColumn());
        });
    }

    public function testProfileBooleanWritesUseIntegerExecuteParameters():void
    {
        $authority=new PortalAuthorityService();
        $input=[
            'application_key'=>'integer_flags','display_label'=>'Integer Flags',
            'enabled'=>false,'portal_projection_enabled'=>false,'relation_projection_enabled'=>false,
            'contact_assignment_projection_enabled'=>false,'catalog_projection_enabled'=>false,
            'service_assignment_projection_enabled'=>false,'pricing_preview_enabled'=>false,'draft_quote_enabled'=>false,
            'portal_route'=>'','catalog_route'=>'',
        ];

        $profileId=$authority->saveProfile($this->pdo,$input,0);
        $input['profile_id']=$profileId;
        $authority->saveProfile($this->pdo,$input,0);

        self::assertCount(2,$this->pdo->profileWriteParameterTypes);
        foreach($this->pdo->profileWriteParameterTypes as $types){
            self::assertCount(16,$types);
            self::assertSame(array_fill(0,8,'int'),array_slice($types,2,8));
            self::assertSame('null',$types[14]);
        }
    }

    public function testFailedRevocationRequiresAuditedRetryAndKeepsOldContractUntilDelivered():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);
            $this->activateWorkspace($profileId);
            $new=$this->connection('generic_operations','https://new.example.test/events');
            $this->service->configureConnection($this->pdo,$new,7);
            $this->pdo->exec("UPDATE portal_projection_outbox SET attempts=12,dead_lettered_at=CURRENT_TIMESTAMP,last_error_code='transport_failed' WHERE is_revocation=1");

            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$new,7));
            self::assertSame('https://old.example.test/events',$this->pdo->query("SELECT portal_route FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(1,(int)$this->service->status($this->pdo,'generic_operations')['counts']['failed_revocations']);
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertNull($profile['delivery_key_id'],'The unified connection has no independently rotated portal signing key.');
            self::assertNull($profile['delivery_credentials_enc'],'The unified connection has no duplicate portal credential payload.');

            self::assertSame(1,$this->service->retryFailedRevocations($this->pdo,'generic_operations',7));
            $retried=$this->pdo->query('SELECT * FROM portal_projection_outbox WHERE is_revocation=1')->fetch(PDO::FETCH_ASSOC);
            self::assertNull($retried['dead_lettered_at']);self::assertSame(0,(int)$retried['attempts']);self::assertSame('revocation_retry_requested',$retried['last_error_code']);
            self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_integration_audit WHERE action='portal.client_provisioning.revocations_requeued'")->fetchColumn());
            self::assertSame('https://old.example.test/events',$this->pdo->query("SELECT portal_route FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());

            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1');
            $this->service->configureConnection($this->pdo,$new,7);
            self::assertSame('https://new.example.test/events',$this->pdo->query("SELECT portal_route FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
        });
    }

    public function testSameContractCannotReactivateUntilDisableRevocationDrains():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $enabled=$this->connection('generic_operations','https://same.example.test/events');
            $profileId=(int)$this->service->configureConnection($this->pdo,$enabled,7);$this->activateWorkspace($profileId);
            $disabled=$enabled;$disabled['enabled']=0;$disabled['configured_enabled']=false;
            $this->service->configureConnection($this->pdo,$disabled,7);

            $this->service->configureConnection($this->pdo,$enabled,7);
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE is_revocation=1 AND delivered_at IS NULL")->fetchColumn());

            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1');
            $this->service->configureConnection($this->pdo,$enabled,7);
            self::assertSame(1,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
        });
    }

    public function testUnchangedActiveSaveDoesNotResolveHistoricalDeadLetter():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://stable.example.test/events');
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            $this->pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json,dead_lettered_at,last_error_code)VALUES(?,'stable-dead-letter','workspace-legacy',3,1,'event','portal',0,'https://stable.example.test/api/internal/project-alpha/portal-v2','portal-v1','{}',CURRENT_TIMESTAMP,'transport_failed')")->execute([$profileId]);

            $this->service->configureConnection($this->pdo,$config,7);
            self::assertNull($this->pdo->query("SELECT delivered_at FROM portal_projection_outbox WHERE delivery_id='stable-dead-letter'")->fetchColumn()?:null);
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_integration_audit WHERE action='portal.client_provisioning.retired_events_resolved'")->fetchColumn());
        });
    }

    public function testHistoricalBackfillWaitsForFullPreflightWithoutMutations(): void
    {
        $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','person@example.test',NULL,'consumer',0,NULL,'v1')");
        $summary=$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',1);
        self::assertFalse($summary['ready']);
        self::assertSame(0,$summary['considered']);
        self::assertSame(1,$summary['remaining'],'Paused delivery must not hide historical work that still remains.');
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_client_provisioning_backfill')->fetchColumn());
    }

    public function testManualSyncUsesOneConnectionForBoundedReconciliationAndBothOutboxes(): void
    {
        $this->withPortalCapabilities([],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1');
                INSERT INTO integration_outbox(event_id,integration_key,event_type,schema_version,payload_json,occurred_at,next_attempt_at) VALUES('ordinary-1','generic_operations','client.changed',1,'{\"event_id\":\"ordinary-1\",\"event_type\":\"client.changed\"}','2026-09-05 00:00:00','2000-01-01 00:00:00')");
            $eventTypes=[];
            $capture=static function(string $url,array $headers,string $body,int $timeout)use(&$eventTypes):array{
                $payload=json_decode($body,true,64,JSON_THROW_ON_ERROR);
                $eventTypes[]=(string)($payload['event_type']??'');
                self::assertSame('https://operations.example.test/events',$url);
                self::assertGreaterThanOrEqual(2,$timeout);
                return['status'=>204];
            };

            $summary=(new ExternalOpsSyncOrchestrator())->run($this->pdo,1,10,10,$capture,$capture,20);

            self::assertTrue($summary['ready']);
            self::assertSame(1,$summary['reconciliation']['considered']);
            self::assertSame(1,$summary['reconciliation']['completed']);
            self::assertSame(0,$summary['reconciliation']['remaining']);
            self::assertSame(1,$summary['ordinary']['delivered']);
            self::assertGreaterThan(0,$summary['portal']['delivered']);
            self::assertContains('client.changed',$eventTypes);
            self::assertContains('portal.projection',$eventTypes);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM integration_outbox WHERE delivered_at IS NULL')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_projection_outbox WHERE delivered_at IS NULL')->fetchColumn());
        });
    }

    public function testCronReadinessUsesStoredExternalOperationsConnectionWithoutPortalEnvironment(): void
    {
        $this->withPortalCapabilities([],function():void{
            $this->pdo->exec('DELETE FROM portal_integration_profiles');
            $this->prepareHistoricalBackfill();
            putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
            self::assertTrue($this->service->status($this->pdo, 'generic_operations')['ready']);
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-cron','Cron Fixture','cron@example.test',NULL,'consumer',0,NULL,'v1')");
            $summary = $this->service->reconcileHistoricalBatch($this->pdo, 'generic_operations', 1);
            self::assertTrue($summary['ready']);
            self::assertSame(1, $summary['completed']);
        });
    }

    public function testHistoricalBackfillIsBoundedResumableAndIdempotent(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1'),(21,'client-b','B Person','b@example.test',NULL,'consumer',0,NULL,'v1')");
            $first=$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',1);
            self::assertSame(1,$first['completed'],json_encode($this->pdo->query('SELECT * FROM portal_client_provisioning_backfill')->fetchAll(PDO::FETCH_ASSOC)));
            self::assertSame(1,$first['remaining']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn());
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',1)['completed']);
            $outbox=(int)$this->pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn();
            self::assertGreaterThan(0,$outbox);
            self::assertSame(0,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',1)['considered']);
            self::assertSame($outbox,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_identity_bindings')->fetchColumn());
        });
    }

    public function testHistoricalBackfillRetriesPoisonRootWithoutBlockingOthersAndStopsAfterFiveAttempts(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1'),(21,'client-b','B Person','b@example.test',NULL,'consumer',0,NULL,'v1'); CREATE TRIGGER fail_one_workspace BEFORE INSERT ON portal_v2_workspaces WHEN NEW.root_public_id='client-a' BEGIN SELECT RAISE(ABORT,'private customer detail'); END;");
            $first=$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',2);
            self::assertSame(1,$first['retrying']);
            self::assertSame(1,$first['completed']);
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_principals WHERE email_hint='a@example.test'")->fetchColumn());
            self::assertSame(0,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',2)['considered']);
            for($attempt=2;$attempt<=5;$attempt++){
                $this->pdo->exec("UPDATE portal_client_provisioning_backfill SET next_attempt_at='2000-01-01 00:00:00' WHERE state='retry'");
                $this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',2);
            }
            $row=$this->pdo->query("SELECT * FROM portal_client_provisioning_backfill WHERE root_public_id='client-a'")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('failed',$row['state']);self::assertSame(5,(int)$row['attempts']);
            self::assertStringStartsWith('storage_failure:',$row['last_error_code']);
            self::assertStringNotContainsString('private customer detail',json_encode($row));
            self::assertSame(0,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',2)['considered']);
        });
    }

    private function prepareHistoricalSchema(): void
    {
        $this->pdo->exec("ALTER TABLE organizations ADD COLUMN source_version TEXT DEFAULT '1';
            CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,name TEXT,source_version TEXT);
            CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER,is_primary INTEGER DEFAULT 0);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,department_id INTEGER,client_id INTEGER,status TEXT,source_version TEXT,completed_at TEXT);
            CREATE TABLE portal_v2_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),client_id INTEGER UNIQUE,display_name TEXT,source_version TEXT,active INTEGER);
            CREATE TABLE portal_v2_relations(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),relation_type TEXT,from_type TEXT,from_public_id TEXT,to_type TEXT,to_public_id TEXT,source_version TEXT,active INTEGER,UNIQUE(relation_type,from_type,from_public_id,to_type,to_public_id));");
    }

    private function prepareHistoricalBackfill(): void
    {
        $this->prepareHistoricalSchema();
        $config=(new \App\Services\ExternalOpsConfigService())->save($this->pdo,$this->connection('generic_operations','https://operations.example.test/events')+['hmac_secret'=>str_repeat('o',32)]);
        $this->service->configureConnection($this->pdo,$config,7);
    }

    public function testHistoricalBackfillPreservesOrganizationAndIndividualRevocations(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO organizations(id,public_id,name) VALUES(10,'org-a','Organization');
                INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',10,'business',0,NULL,'v1'),(21,'client-b','B Person','b@example.test',10,'business',0,NULL,'v1'),(22,'client-c','C Person','c@example.test',NULL,'consumer',0,NULL,'v1');
                INSERT INTO portal_client_access_roots(root_type,root_public_id,access_state) VALUES('standalone_client','client-c','revoked');
                INSERT INTO portal_client_login_eligibility(client_id,manual_state,eligibility_status) VALUES(20,'revoked','revoked');");
            $summary=$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations',25);
            self::assertSame(2,$summary['completed']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn(),'Organization contacts share one workspace; revoked standalone is not provisioned.');
            self::assertSame(['revoked','eligible','revoked'],$this->pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN));
        });
    }

    public function testHistoricalBackfillPausesDisabledProducerAndReprocessesReplacementContract(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1')");
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            $fingerprint=$this->pdo->query('SELECT contract_fingerprint FROM portal_client_provisioning_backfill')->fetchColumn();
            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP');
            $config=(new \App\Services\ExternalOpsConfigService())->save($this->pdo,$this->connection('generic_operations','https://replacement.example.test/events')+['hmac_secret'=>str_repeat('o',32)]);
            $this->service->configureConnection($this->pdo,$config,7);
            self::assertFalse($this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['ready'],'Retirement must drain before replacement is reconciled.');
            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP');
            $this->service->configureConnection($this->pdo,$config,7);
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            self::assertNotSame($fingerprint,$this->pdo->query('SELECT contract_fingerprint FROM portal_client_provisioning_backfill')->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        });
    }

    public function testHistoricalBackfillRecoveryCommitsOnlyAfterProjectionSucceeds(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1'); CREATE TRIGGER reject_outbox BEFORE INSERT ON portal_projection_outbox BEGIN SELECT RAISE(ABORT,'temporary failure'); END;");
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['retrying']);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
            $this->pdo->exec("DROP TRIGGER reject_outbox; UPDATE portal_client_provisioning_backfill SET next_attempt_at='2000-01-01 00:00:00'");
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            self::assertSame('complete',$this->pdo->query('SELECT state FROM portal_client_provisioning_backfill')->fetchColumn());
        });
    }

    public function testHistoricalBackfillReactivationOfSameContractRepublishesWithoutRestoringRevokedPerson(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1')");
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='revoked'; UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP");
            $configService=new \App\Services\ExternalOpsConfigService();
            $disabled=$configService->save($this->pdo,$this->connection('generic_operations','https://operations.example.test/events',false)+['hmac_secret'=>str_repeat('o',32)]);
            $this->service->configureConnection($this->pdo,$disabled,7);
            self::assertFalse($this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['ready']);
            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP');
            $enabled=$configService->save($this->pdo,$this->connection('generic_operations','https://operations.example.test/events')+['hmac_secret'=>str_repeat('o',32)]);
            $this->service->configureConnection($this->pdo,$enabled,7);
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            self::assertSame('revoked',$this->pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT enabled FROM portal_principals')->fetchColumn());
            self::assertGreaterThan(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_projection_outbox WHERE delivered_at IS NULL')->fetchColumn());
        });
    }

    public function testHistoricalBackfillRechecksCandidateCompletedByAnotherRunner(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1')");
            $this->pdo->afterCandidateSelection=function():void{
                self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            };
            $summary=$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations');
            self::assertSame(0,$summary['completed']);
            self::assertSame(0,$summary['remaining']);
            self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM portal_integration_audit WHERE action='portal.client_provisioning.backfill_completed'")->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        });
    }

    public function testHistoricalBackfillAdoptsUniqueLegacyProducerBindingOnlyAfterRuntimeReady(): void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('p',32)]]],function():void{
            $this->prepareHistoricalBackfill();
            $this->pdo->exec("INSERT INTO clients VALUES(20,'client-a','A Person','a@example.test',NULL,'consumer',0,NULL,'v1'); DELETE FROM app_config WHERE config_key='external_ops_client_portal_profile_id'; UPDATE app_config SET config_value='0' WHERE config_key='portal_authoritative_hooks_enabled'");
            self::assertFalse($this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['ready']);
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
            $this->pdo->exec("UPDATE app_config SET config_value='1' WHERE config_key='portal_authoritative_hooks_enabled'");
            self::assertSame(1,$this->service->reconcileHistoricalBatch($this->pdo,'generic_operations')['completed']);
            self::assertSame(1,(int)$this->pdo->query("SELECT config_value FROM app_config WHERE config_key='external_ops_client_portal_profile_id'")->fetchColumn());
        });
    }

    /** @return array<string,mixed> */
    private function connection(string$key,string$url,bool$enabled=true):array{return['enabled'=>$enabled?1:0,'configured_enabled'=>$enabled,'application_key'=>$key,'label'=>'Operations','webhook_url'=>$url,'access_client_id'=>'access-id','access_client_secret'=>'access-secret','hmac_secret'=>str_repeat('h',32),'timeout_seconds'=>15,'max_attempts'=>12];}
    private function activateWorkspace(int$profileId):void{$this->pdo->exec("INSERT INTO organizations VALUES(90,'org-rotation','Rotation Org');INSERT INTO clients VALUES(91,'client-rotation','Rotation Person','rotation@example.test',90,'consumer',0,NULL,'v1')");$this->pdo->beginTransaction();$this->service->ensureScopes($this->pdo,[['root_type'=>'organization','root_public_id'=>'org-rotation']],7,$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC));$this->pdo->commit();$workspace=(string)$this->pdo->query("SELECT public_id FROM portal_v2_workspaces WHERE root_public_id='org-rotation'")->fetchColumn();$this->pdo->prepare('INSERT INTO portal_projection_state VALUES(?,?,?,?,?)')->execute([$profileId,$workspace,'generation-1',1,str_repeat('f',64)]);}
    /** @param array<string,mixed> $capabilities */
    private function withPortalCapabilities(array$capabilities,callable$test):void{$previousEncryption=getenv('APP_ENCRYPTION_KEY');$previousCapabilities=getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');putenv('APP_ENCRYPTION_KEY=portal-provisioning-lifecycle-test-key');putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.json_encode($capabilities,JSON_THROW_ON_ERROR));try{$test();}finally{$previousEncryption===false?putenv('APP_ENCRYPTION_KEY'):putenv('APP_ENCRYPTION_KEY='.$previousEncryption);$previousCapabilities===false?putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON'):putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.$previousCapabilities);}}
}

/** Deterministically inject another runner after candidate selection, before the locked recheck. */
final class BackfillRacePDO extends PDO
{
    public ?\Closure $afterCandidateSelection = null;
    /** @var list<list<string>> */
    public array $profileWriteParameterTypes = [];
}

final class BackfillRaceStatement extends \PDOStatement
{
    protected function __construct(private BackfillRacePDO $connection) {}

    public function execute(?array $params = null): bool
    {
        if($params!==null&&(str_starts_with($this->queryString,'INSERT INTO portal_integration_profiles')||str_starts_with($this->queryString,'UPDATE portal_integration_profiles SET application_key='))){
            $this->connection->profileWriteParameterTypes[]=array_map('get_debug_type',$params);
        }
        $result=parent::execute($params);
        if (str_starts_with($this->queryString,'SELECT roots.root_type,roots.root_public_id') && $this->connection->afterCandidateSelection) {
            $callback=$this->connection->afterCandidateSelection;
            $this->connection->afterCandidateSelection=null;
            $callback();
        }
        return $result;
    }
}
