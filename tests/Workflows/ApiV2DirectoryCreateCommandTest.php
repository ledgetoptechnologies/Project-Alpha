<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_create_command.php';

final class ApiV2DirectoryCreateCommandTest extends TestCase
{
    private function database(bool $projectionHooks = false, bool $activePortalProfile = false): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,tombstoned_at TEXT,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id));
            CREATE TABLE api_v2_directory_create_command_receipts(application_pk INTEGER,resource_type TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,result_authorization_generation INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(application_pk,resource_type,command_id));
            CREATE TABLE organizations(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,name TEXT UNIQUE,general_email TEXT,general_phone TEXT,notes TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,source_version TEXT,tax_exempt_file TEXT,link_strategy TEXT DEFAULT 'overall_folder');
            CREATE TABLE clients(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,name TEXT,email TEXT,phone TEXT,organization_id INTEGER,client_type TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,source_version TEXT,notes TEXT,archived INTEGER DEFAULT 0,deleted_at TEXT,stripe_customer_id TEXT,stripe_payment_method_id TEXT,auto_pay_enabled INTEGER DEFAULT 0,config TEXT,custom_fields TEXT);
            CREATE TABLE addresses(id INTEGER PRIMARY KEY AUTOINCREMENT,label TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,google_place_id TEXT,source TEXT,created_by INTEGER,archived INTEGER DEFAULT 0);
            CREATE TABLE address_assignments(id INTEGER PRIMARY KEY AUTOINCREMENT,address_id INTEGER,entity_type TEXT,entity_id INTEGER,purpose TEXT,is_default INTEGER,UNIQUE(entity_type,entity_id,purpose,address_id));
            CREATE TABLE invoices(id INTEGER PRIMARY KEY AUTOINCREMENT,client_id INTEGER,total REAL);
            CREATE TABLE portal_principals(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,authorization_version INTEGER DEFAULT 1,activated_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
            CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER,created_by INTEGER,PRIMARY KEY(portal_principal_id,client_id));
            CREATE TABLE portal_identity_bindings(id INTEGER PRIMARY KEY AUTOINCREMENT,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,enabled INTEGER,bound_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
            CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER);
            CREATE TABLE portal_client_login_eligibility(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,manual_state TEXT,eligibility_status TEXT,review_reason TEXT,canonical_email TEXT,source_version TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER);
            CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
            CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,name TEXT,source_version TEXT);
            CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER,is_primary INTEGER DEFAULT 0);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,department_id INTEGER,client_id INTEGER,status TEXT,source_version TEXT,completed_at TEXT);
            CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,root_type TEXT,root_public_id TEXT,display_name TEXT,source_version TEXT,active INTEGER,created_by INTEGER,updated_by INTEGER,UNIQUE(root_type,root_public_id));
            CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT UNIQUE,display_label TEXT,enabled INTEGER,portal_projection_enabled INTEGER,relation_projection_enabled INTEGER DEFAULT 0,contact_assignment_projection_enabled INTEGER DEFAULT 0,portal_route TEXT,delivery_key_id TEXT);
            CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,created_by INTEGER,updated_by INTEGER,PRIMARY KEY(profile_id,workspace_id));
            CREATE TABLE portal_client_access_roots(root_type TEXT,root_public_id TEXT,access_state TEXT,state_reason TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER,PRIMARY KEY(root_type,root_public_id));
            CREATE TABLE portal_v2_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),client_id INTEGER UNIQUE,display_name TEXT,source_version TEXT,active INTEGER);
            CREATE TABLE portal_v2_relations(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),relation_type TEXT,from_type TEXT,from_public_id TEXT,to_type TEXT,to_public_id TEXT,source_version TEXT,active INTEGER,UNIQUE(relation_type,from_type,from_public_id,to_type,to_public_id));
            CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));
            CREATE TABLE portal_projection_resource_state(integration_profile_id INTEGER,workspace_public_id TEXT,route_type TEXT,resource_type TEXT,resource_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id));
            CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER DEFAULT 0,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            INSERT INTO api_keys VALUES(7,3,NULL);
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0);");
        $pdo->prepare("INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled',?)")
            ->execute([$projectionHooks ? '1' : '0']);
        if ($activePortalProfile) {
            $pdo->exec("INSERT INTO portal_integration_profiles(id,application_key,display_label,enabled,portal_projection_enabled,relation_projection_enabled) VALUES(1,'generic_operations','Generic operations',1,1,1);
                INSERT INTO app_config VALUES(0,'external_ops_client_portal_profile_id','1')");
        }
        return $pdo;
    }

    private function headers(): array
    {
        return ['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];
    }

    private function organizationCommand(string $externalId = 'org-1', string $generation = '0'): array
    {
        return ['commandId'=>'423e4567-e89b-42d3-a456-426614174000','externalId'=>$externalId,'expectedAuthorizationGeneration'=>$generation,'profile'=>[
            'name'=>'Example Organization','generalEmail'=>'office@example.test','generalPhone'=>'555-0100','addressLine1'=>'1 Main St','addressLine2'=>'','city'=>'Madison','state'=>'WI','postalCode'=>'53703','country'=>'US']];
    }

    private function clientCommand(?array $organization = null, string $generation = '0'): array
    {
        return ['commandId'=>'523e4567-e89b-42d3-a456-426614174000','externalId'=>'client-1','expectedAuthorizationGeneration'=>$generation,'profile'=>[
            'name'=>'Example Client','email'=>'client@example.test','phone'=>'555-0101','clientType'=>'business','addressLine1'=>'2 Main St','addressLine2'=>'','city'=>'Madison','state'=>'WI','postalCode'=>'53703','country'=>'US'], 'organization'=>$organization];
    }

    public function testOrganizationCreateAndExactReplayAreImmutable(): void
    {
        $pdo=$this->database(); $command=$this->organizationCommand();
        $first=api_v2_directory_create_command_write($pdo,'organization',$command,7,$this->headers(),'request-1');
        self::assertSame(201,$first['status']); self::assertFalse($first['payload']['replayed']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D',$first['payload']['result']['resource']['publicId']);
        self::assertSame('1',$first['payload']['result']['resource']['revision']); self::assertSame('1',$first['payload']['result']['authorizationGeneration']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        self::assertSame('active',$pdo->query('SELECT status FROM api_v2_directory_external_bindings')->fetchColumn());
        $replay=api_v2_directory_create_command_write($pdo,'organization',$command,7,$this->headers(),'request-2');
        self::assertSame(200,$replay['status']); self::assertTrue($replay['payload']['replayed']);
        self::assertSame($first['payload']['result'],$replay['payload']['result']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn());

        $reordered=['profile'=>array_reverse($command['profile'],true),'expectedAuthorizationGeneration'=>'0','externalId'=>'org-1','commandId'=>$command['commandId']];
        $canonical=api_v2_directory_create_command_parse('organization',json_encode($reordered,JSON_THROW_ON_ERROR));
        self::assertNotNull($canonical);
        $reorderedReplay=api_v2_directory_create_command_write($pdo,'organization',$canonical,7,$this->headers(),'request-3');
        self::assertSame(200,$reorderedReplay['status']); self::assertTrue($reorderedReplay['payload']['replayed']);
    }

    public function testModifiedReuseDuplicateAndStaleGenerationConflictWithoutMerge(): void
    {
        $pdo=$this->database(); $command=$this->organizationCommand();
        self::assertSame(201,api_v2_directory_create_command_write($pdo,'organization',$command,7,$this->headers(),'one')['status']);
        $changed=$command; $changed['profile']['name']='Changed';
        self::assertSame(409,api_v2_directory_create_command_write($pdo,'organization',$changed,7,$this->headers(),'two')['status']);
        $duplicate=$this->organizationCommand('org-2','1'); $duplicate['commandId']='623e4567-e89b-42d3-a456-426614174000';
        $duplicate['profile']['generalEmail']='replacement@example.test';
        self::assertSame(409,api_v2_directory_create_command_write($pdo,'organization',$duplicate,7,$this->headers(),'three')['status']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
        self::assertSame('office@example.test',$pdo->query('SELECT general_email FROM organizations')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_create_command_receipts')->fetchColumn());
        $stale=$this->clientCommand(null,'0');
        self::assertSame(409,api_v2_directory_create_command_write($pdo,'client',$stale,7,$this->headers(),'four')['status']);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
    }

    public function testClientAssignmentRequiresExactActiveApplicationBindingRevision(): void
    {
        $pdo=$this->database();
        $org=$this->organizationCommand();
        $created=api_v2_directory_create_command_write($pdo,'organization',$org,7,$this->headers(),'org');
        self::assertSame(201,$created['status']);
        $client=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        $outcome=api_v2_directory_create_command_write($pdo,'client',$client,7,$this->headers(),'client');
        self::assertSame(201,$outcome['status']);
        self::assertSame((int)$pdo->query('SELECT id FROM organizations')->fetchColumn(),(int)$pdo->query('SELECT organization_id FROM clients')->fetchColumn());
        self::assertSame(2,(int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());

        $invalid=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'2'],'2');
        $invalid['commandId']='723e4567-e89b-42d3-a456-426614174000'; $invalid['externalId']='client-2';
        self::assertSame(409,api_v2_directory_create_command_write($pdo,'client',$invalid,7,$this->headers(),'invalid')['status']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertNull(api_v2_directory_create_command_parse('client',json_encode(array_replace($client,['organization'=>['id'=>1,'expectedRevision'=>'1']]),JSON_THROW_ON_ERROR)));
        self::assertNull(api_v2_directory_create_command_parse('client',json_encode(array_replace($client,['organization'=>['name'=>'Example Organization','expectedRevision'=>'1']]),JSON_THROW_ON_ERROR)));
    }

    public function testTombstonedExternalIdAndReceiptFailureRollBackEverything(): void
    {
        $pdo=$this->database();
        $pdo->exec("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status,tombstoned_at) VALUES(3,'client','client-1','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'" . str_repeat('b',64) . "','tombstoned',CURRENT_TIMESTAMP)");
        self::assertSame(409,api_v2_directory_create_command_write($pdo,'client',$this->clientCommand(),7,$this->headers(),'tombstone')['status']);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());

        $fresh=$this->database();
        $fresh->exec("CREATE TRIGGER stop_create_receipt BEFORE INSERT ON api_v2_directory_create_command_receipts BEGIN SELECT RAISE(ABORT,'blocked'); END");
        self::assertSame(409,api_v2_directory_create_command_write($fresh,'client',$this->clientCommand(),7,$this->headers(),'rollback')['status']);
        self::assertSame(0,(int)$fresh->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0,(int)$fresh->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
        self::assertSame(0,(int)$fresh->query('SELECT COUNT(*) FROM api_v2_directory_resource_state')->fetchColumn());
        self::assertSame(0,(int)$fresh->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testIdentityRelationshipAndPrivateSideEffectsFailClosed(): void
    {
        $pdo=$this->database();
        foreach (['source','application','epoch'] as $header) {
            self::assertSame(409,api_v2_directory_create_command_write($pdo,'client',$this->clientCommand(),7,array_replace($this->headers(),[$header=>'wrong']),'bad-header')['status']);
        }
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());

        $organization=api_v2_directory_create_command_write($pdo,'organization',$this->organizationCommand(),7,$this->headers(),'organization');
        self::assertSame(201,$organization['status']);
        $pdo->exec("UPDATE api_v2_directory_external_bindings SET status='tombstoned',tombstoned_at=CURRENT_TIMESTAMP WHERE resource_type='organization'");
        $assigned=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        self::assertSame(409,api_v2_directory_create_command_write($pdo,'client',$assigned,7,$this->headers(),'inactive-relationship')['status']);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());

        $fresh=$this->database();
        $created=api_v2_directory_create_command_write($fresh,'client',$this->clientCommand(),7,$this->headers(),'standalone-client');
        self::assertSame(201,$created['status']);
        $client=$fresh->query('SELECT notes,stripe_customer_id,stripe_payment_method_id,auto_pay_enabled,config,custom_fields FROM clients')->fetch();
        self::assertSame(['notes'=>null,'stripe_customer_id'=>null,'stripe_payment_method_id'=>null,'auto_pay_enabled'=>0,'config'=>null,'custom_fields'=>null],$client);
        self::assertSame(0,(int)$fresh->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
        self::assertSame(0,(int)$fresh->query('SELECT COUNT(*) FROM portal_principal_clients')->fetchColumn());
        self::assertSame(0,(int)$fresh->query('SELECT COUNT(*) FROM portal_client_login_eligibility')->fetchColumn());
    }

    public function testProjectionBoundaryIsAtomicAndExactReplayDoesNotDuplicateIt(): void
    {
        $pdo=$this->database(true, true);
        foreach (['portal_v2_workspaces','portal_integration_profile_workspaces','portal_client_access_roots','portal_client_login_eligibility','portal_principals','portal_principal_clients','portal_v2_entitlements','portal_identity_bindings'] as $table) {
            foreach (['INSERT','UPDATE','DELETE'] as $operation) {
                $pdo->exec("CREATE TRIGGER forbid_".strtolower($operation)."_{$table} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT,'portal authority mutation forbidden'); END");
            }
        }
        self::assertSame(201,api_v2_directory_create_command_write($pdo,'organization',$this->organizationCommand(),7,$this->headers(),'organization')['status']);
        $client=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        $first=api_v2_directory_create_command_write($pdo,'client',$client,7,$this->headers(),'client');
        self::assertSame(201,$first['status']);
        self::assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM portal_v2_relations WHERE relation_type='contains' AND from_type='organization' AND to_type='client' AND active=1")->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_v2_contacts WHERE active=0')->fetchColumn());
        $replay=api_v2_directory_create_command_write($pdo,'client',$client,7,$this->headers(),'client-replay');
        self::assertSame(200,$replay['status']); self::assertTrue($replay['payload']['replayed']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_v2_relations')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_v2_contacts')->fetchColumn());
        foreach (['portal_v2_workspaces','portal_client_access_roots','portal_client_login_eligibility','portal_principals','portal_principal_clients','portal_v2_entitlements','portal_identity_bindings'] as $table) {
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),$table);
        }

        $rollback=$this->database(true, true);
        self::assertSame(201,api_v2_directory_create_command_write($rollback,'organization',$this->organizationCommand(),7,$this->headers(),'organization')['status']);
        $rollback->exec("CREATE TRIGGER stop_relation_projection BEFORE INSERT ON portal_v2_relations BEGIN SELECT RAISE(ABORT,'blocked projection'); END");
        self::assertSame(409,api_v2_directory_create_command_write($rollback,'client',$client,7,$this->headers(),'blocked-client')['status']);
        self::assertSame(0,(int)$rollback->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0,(int)$rollback->query('SELECT COUNT(*) FROM portal_v2_contacts')->fetchColumn());
        self::assertSame(0,(int)$rollback->query('SELECT COUNT(*) FROM portal_v2_relations')->fetchColumn());
        foreach (['portal_v2_workspaces','portal_client_access_roots','portal_client_login_eligibility','portal_principals','portal_principal_clients','portal_v2_entitlements','portal_identity_bindings'] as $table) {
            self::assertSame(0,(int)$rollback->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),$table);
        }
        self::assertSame(1,(int)$rollback->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
        self::assertSame(1,(int)$rollback->query('SELECT COUNT(*) FROM api_v2_directory_create_command_receipts')->fetchColumn());
    }

    public function testExistingWorkspaceReceivesOnlyNeutralOutboxChanges(): void
    {
        $pdo=$this->database(true, true);
        $organization=api_v2_directory_create_command_write($pdo,'organization',$this->organizationCommand(),7,$this->headers(),'organization');
        self::assertSame(201,$organization['status']);
        $organizationPublicId=(string)$organization['payload']['result']['resource']['publicId'];
        $pdo->prepare("INSERT INTO portal_v2_workspaces(id,public_id,root_type,root_public_id,display_name,source_version,active) VALUES(1,'workspace-existing','organization',?,'Example Organization','workspace-before',1)")->execute([$organizationPublicId]);
        $pdo->exec("INSERT INTO portal_integration_profile_workspaces(profile_id,workspace_id,active) VALUES(1,1,1);
            INSERT INTO portal_projection_state VALUES(1,'workspace-existing','generation-existing',4,'snapshot-existing');
            INSERT INTO portal_projection_resource_state VALUES
              (1,'workspace-existing','portal','principal','principal-drift','principal-v1','" . str_repeat('a',64) . "','{}'),
              (1,'workspace-existing','portal','entitlement','entitlement-drift','entitlement-v1','" . str_repeat('b',64) . "','{}')");

        $client=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        self::assertSame(201,api_v2_directory_create_command_write($pdo,'client',$client,7,$this->headers(),'client')['status']);

        $resources=[];
        foreach($pdo->query('SELECT payload_json FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)as$payload){
            $event=json_decode((string)$payload,true,32,JSON_THROW_ON_ERROR)['event']??[];
            $resources[]=(string)($event['resource']??'');
        }
        self::assertNotEmpty($resources);
        self::assertSame([],array_values(array_intersect(['principal','entitlement','contact_assignment'],$resources)));
        self::assertContains('entity',$resources);
        self::assertContains('relation',$resources);
        self::assertSame(2,(int)$pdo->query("SELECT COUNT(*) FROM portal_projection_resource_state WHERE resource_type IN ('principal','entitlement')")->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_v2_workspaces')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_integration_profile_workspaces')->fetchColumn());
        foreach(['portal_client_access_roots','portal_client_login_eligibility','portal_principals','portal_principal_clients','portal_v2_entitlements','portal_identity_bindings']as$table)self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),$table);

        $outboxCount=(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn();
        $replay=api_v2_directory_create_command_write($pdo,'client',$client,7,$this->headers(),'replay');
        self::assertSame(200,$replay['status']);
        self::assertSame($outboxCount,(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
    }

    public function testExistingWorkspaceWithoutCheckpointIsNotBootstrappedByCreate(): void
    {
        $pdo=$this->database(true, true);
        $organization=api_v2_directory_create_command_write($pdo,'organization',$this->organizationCommand(),7,$this->headers(),'organization');
        self::assertSame(201,$organization['status']);
        $organizationPublicId=(string)$organization['payload']['result']['resource']['publicId'];
        $pdo->prepare("INSERT INTO portal_v2_workspaces(id,public_id,root_type,root_public_id,display_name,source_version,active) VALUES(1,'workspace-unpublished','organization',?,'Example Organization','workspace-before',1)")->execute([$organizationPublicId]);
        $pdo->exec('INSERT INTO portal_integration_profile_workspaces(profile_id,workspace_id,active) VALUES(1,1,1)');

        $client=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        self::assertSame(201,api_v2_directory_create_command_write($pdo,'client',$client,7,$this->headers(),'client')['status']);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_state')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_resource_state')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM portal_v2_relations')->fetchColumn());
        foreach(['portal_client_access_roots','portal_client_login_eligibility','portal_principals','portal_principal_clients','portal_v2_entitlements','portal_identity_bindings']as$table)self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),$table);
    }

    public function testRoutesScopesFlagsAndMigrationAreExplicitAndDefaultOff(): void
    {
        $root=dirname(__DIR__,2);
        $route=(string)file_get_contents($root.'/public/index.php');
        $controller=(string)file_get_contents($root.'/src/controllers/api/directory_create_command_v2.php');
        $migration=(string)file_get_contents($root.'/database/migrations/0097_api_v2_directory_create_command_receipts.sql');
        self::assertStringContainsString('/api/v2/directory/(clients|organizations|units)/commands',$route);
        self::assertStringContainsString('APP_API_V2_DIRECTORY_CLIENTS_CREATE_ENABLED',$route);
        self::assertStringContainsString('APP_API_V2_DIRECTORY_ORGANIZATIONS_CREATE_ENABLED',$route);
        self::assertStringContainsString("api_require_key(['api.capabilities.read', \$scope], false)",$controller);
        self::assertStringContainsString('directory.clients.organization.assign',$controller);
        self::assertStringContainsString('api_v2_directory_create_command_receipts',$migration);
        self::assertStringNotContainsString('workspace',$migration);
        self::assertFalse(api_key_has_scope('full','directory.clients.create',false));
        self::assertFalse(api_key_has_scope('full','directory.organizations.create',false));
        self::assertFalse(api_key_has_scope('full','directory.clients.organization.assign',false));
        $identity=['source_instance_id'=>'s','application_id'=>'a','history_epoch'=>'e'];
        $payload=api_v2_capabilities_payload($identity,'r',['directory.clients.create'],[]);
        self::assertCount(1,$payload['implementedEndpoints']);
        $enabled=api_v2_capabilities_payload($identity,'r',['directory.clients.create','directory.clients.organization.assign'],['directory_client_create'=>true,'directory_organization_create'=>true]);
        self::assertCount(3,$enabled['implementedEndpoints']);
        self::assertSame('/api/v2/directory/organizations/commands',$enabled['implementedEndpoints'][1]['path']);
        self::assertSame('/api/v2/directory/clients/commands',$enabled['implementedEndpoints'][2]['path']);
        self::assertSame([['name'=>'api.capabilities.read'],['name'=>'directory.clients.create'],['name'=>'directory.clients.organization.assign']],$enabled['grantedCapabilities']);
    }
}
