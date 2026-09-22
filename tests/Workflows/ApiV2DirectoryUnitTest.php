<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryUnitTest extends TestCase
{
    private function database(): PDO
    {
        require_once dirname(__DIR__,2).'/vendor/autoload.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_read.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_create_command.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_lifecycle_command.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_unit_profile_command.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_unit_contact_command.php';
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
          INSERT INTO app_config VALUES(0,'api_v2_directory_management_ownership_active','0'),(0,'portal_authoritative_hooks_enabled','0');
          CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
          CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
          CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
          CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
          CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
          CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
          CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,tombstoned_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id));
          CREATE TABLE api_v2_directory_create_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id));
          CREATE TABLE api_v2_directory_lifecycle_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id));
          CREATE TABLE api_v2_directory_unit_profile_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_projection_sha256 TEXT,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,history_epoch,command_id));
          CREATE TABLE api_v2_directory_unit_contact_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,unit_public_id TEXT,client_public_id TEXT,expected_unit_revision INTEGER,expected_authorization_generation INTEGER,result_unit_revision INTEGER,result_projection_sha256 TEXT,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,history_epoch,command_id));
          CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,archived INTEGER DEFAULT 0,deleted_at TEXT,source_version TEXT);
          CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,archived INTEGER DEFAULT 0,deleted_at TEXT,source_version TEXT);
          CREATE TABLE organization_departments(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,organization_id INTEGER,name TEXT,folder_name TEXT,folder_aliases TEXT,resolver_mode TEXT DEFAULT 'manual_only',notes TEXT,source_version TEXT,archived INTEGER DEFAULT 0,deleted_at TEXT,updated_at TEXT);
          CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER,role TEXT,is_primary INTEGER,PRIMARY KEY(department_id,client_id));
          CREATE TABLE addresses(id INTEGER PRIMARY KEY,label TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,google_place_id TEXT,source TEXT,created_by INTEGER,archived INTEGER DEFAULT 0);
          CREATE TABLE address_assignments(id INTEGER PRIMARY KEY,address_id INTEGER,entity_type TEXT,entity_id INTEGER,purpose TEXT,is_default INTEGER);
          INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_directory_authorization_state VALUES(3,0);
          INSERT INTO organizations VALUES(10,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Example Org',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1');
          INSERT INTO clients VALUES(20,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Alice','a@example.test',NULL,'consumer',10,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1');
          INSERT INTO organization_departments(public_id,organization_id,name,source_version,archived) VALUES('cccccccccccccccccccccccccccccccc',10,'Field','v1',0);");
        $pdo->beginTransaction();foreach([['organization',10],['client',20],['unit',1]]as[$type,$id])api_v2_directory_record($pdo,$type,$id,false);$pdo->commit();
        foreach([['organization','org-ext','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],['client','alice-ext','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],['unit','field-ext','cccccccccccccccccccccccccccccccc']]as[$type,$external,$public]){$state=$pdo->query("SELECT revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='$type'")->fetch(PDO::FETCH_ASSOC);$pdo->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(3,?,?,?,?,?,'active')")->execute([$type,$external,$public,$state['revision'],$state['projection_sha256']]);}
        return$pdo;
    }

    private function headers():array{return['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];}

    public function testCanonicalReadAndProfileReplay():void
    {
        $pdo=$this->database();$read=api_v2_directory_read($pdo,'unit',str_repeat('c',32),7,$this->headers(),'423e4567-e89b-42d3-a456-426614174000');
        self::assertSame(str_repeat('b',32),$read['data']['organizationPublicId']);self::assertSame([],$read['data']['contacts']);self::assertArrayNotHasKey('notes',$read['data']);
        $command=['commandId'=>'523e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0','profile'=>['name'=>'Field Ops']];
        $first=api_v2_directory_unit_profile_command_write($pdo,str_repeat('c',32),$command,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174000');self::assertSame(200,$first['status']);self::assertSame('2',$first['payload']['result']['resource']['revision']);
        $replay=api_v2_directory_unit_profile_command_write($pdo,str_repeat('c',32),$command,7,$this->headers(),'723e4567-e89b-42d3-a456-426614174000');self::assertTrue($replay['payload']['replayed']);self::assertSame(409,api_v2_directory_unit_profile_command_write($pdo,str_repeat('c',32),array_replace($command,['profile'=>['name'=>'Other']]),7,$this->headers(),'823e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testContactCommandsRequireCurrentSameOrganizationBindingsAndReplay():void
    {
        $pdo=$this->database();$assign=['commandId'=>'523e4567-e89b-42d3-a456-426614174000','expectedUnitRevision'=>'1','expectedAuthorizationGeneration'=>'0','client'=>['externalId'=>'alice-ext','expectedPublicId'=>str_repeat('a',32),'expectedRevision'=>'1'],'role'=>'billing'];
        $first=api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'assign',$assign,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174000');self::assertSame(200,$first['status']);self::assertSame('2',$first['payload']['result']['resource']['revision']);self::assertSame('1',$first['payload']['result']['authorizationGeneration']);
        self::assertTrue(api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'assign',$assign,7,$this->headers(),'723e4567-e89b-42d3-a456-426614174000')['payload']['replayed']);
        $primary=['commandId'=>'823e4567-e89b-42d3-a456-426614174000','expectedUnitRevision'=>'2','expectedAuthorizationGeneration'=>'1','client'=>$assign['client']];
        self::assertSame(409,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'set-primary',$primary,7,$this->headers(),'923e4567-e89b-42d3-a456-426614174000')['status'],'A stale unit binding cannot authorize another topology change.');
        $state=$pdo->query("SELECT revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetch(PDO::FETCH_ASSOC);$pdo->prepare("UPDATE api_v2_directory_external_bindings SET resource_revision=?,resource_projection_sha256=? WHERE resource_type='unit'")->execute([$state['revision'],$state['projection_sha256']]);
        self::assertSame(200,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'set-primary',$primary,7,$this->headers(),'a23e4567-e89b-42d3-a456-426614174000')['status']);
        $read=api_v2_directory_read($pdo,'unit',str_repeat('c',32),7,$this->headers(),'b23e4567-e89b-42d3-a456-426614174000');self::assertSame([['clientPublicId'=>str_repeat('a',32),'role'=>'billing','primary'=>true]],$read['data']['contacts']);
        $stale=array_replace($primary,['commandId'=>'c23e4567-e89b-42d3-a456-426614174000']);self::assertSame(409,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'remove',$stale,7,$this->headers(),'d23e4567-e89b-42d3-a456-426614174000')['status']);
        $state=$pdo->query("SELECT revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetch(PDO::FETCH_ASSOC);$pdo->prepare("UPDATE api_v2_directory_external_bindings SET resource_revision=?,resource_projection_sha256=? WHERE resource_type='unit'")->execute([$state['revision'],$state['projection_sha256']]);
        $remove=['commandId'=>'e23e4567-e89b-42d3-a456-426614174000','expectedUnitRevision'=>'3','expectedAuthorizationGeneration'=>'2','client'=>$assign['client']];
        self::assertSame(200,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'remove',$remove,7,$this->headers(),'f23e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame([],api_v2_directory_read($pdo,'unit',str_repeat('c',32),7,$this->headers(),'423e4567-e89b-42d3-a456-426614174001')['data']['contacts']);
        $state=$pdo->query("SELECT revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetch(PDO::FETCH_ASSOC);$pdo->prepare("UPDATE api_v2_directory_external_bindings SET resource_revision=?,resource_projection_sha256=? WHERE resource_type='unit'")->execute([$state['revision'],$state['projection_sha256']]);
        $missing=array_replace($remove,['commandId'=>'523e4567-e89b-42d3-a456-426614174001','expectedUnitRevision'=>'4','expectedAuthorizationGeneration'=>'3']);
        self::assertSame(409,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'remove',$missing,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174001')['status']);
        self::assertSame([4,3],array_map('intval',[$pdo->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetchColumn(),$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn()]));
    }

    public function testSetPrimaryRejectsMissingAssignmentWithoutStateChange():void
    {
        $pdo=$this->database();$command=['commandId'=>'523e4567-e89b-42d3-a456-426614174001','expectedUnitRevision'=>'1','expectedAuthorizationGeneration'=>'0','client'=>['externalId'=>'alice-ext','expectedPublicId'=>str_repeat('a',32),'expectedRevision'=>'1']];
        self::assertSame(409,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'set-primary',$command,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174001')['status']);
        self::assertSame([1,0],array_map('intval',[$pdo->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetchColumn(),$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn()]));
    }

    public function testCrossOrganizationContactIsRejected():void
    {
        $pdo=$this->database();$pdo->exec("INSERT INTO organizations VALUES(11,'dddddddddddddddddddddddddddddddd','Other',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1');INSERT INTO clients VALUES(21,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','Eve',NULL,NULL,'consumer',11,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1')");$pdo->beginTransaction();api_v2_directory_record($pdo,'organization',11,false);api_v2_directory_record($pdo,'client',21,false);$pdo->commit();$state=$pdo->query("SELECT revision,projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type='client' AND public_id='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'")->fetch(PDO::FETCH_ASSOC);$pdo->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status) VALUES(3,'client','eve-ext',?,?,?,'active')")->execute([str_repeat('e',32),$state['revision'],$state['projection_sha256']]);
        $command=['commandId'=>'523e4567-e89b-42d3-a456-426614174000','expectedUnitRevision'=>'1','expectedAuthorizationGeneration'=>'0','client'=>['externalId'=>'eve-ext','expectedPublicId'=>str_repeat('e',32),'expectedRevision'=>'1'],'role'=>'contact'];
        self::assertSame(409,api_v2_directory_unit_contact_command_write($pdo,str_repeat('c',32),'assign',$command,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174000')['status']);
    }

    public function testUnitCreateRequiresOrganizationProofAndBindsResult():void
    {
        $pdo=$this->database();$command=['commandId'=>'523e4567-e89b-42d3-a456-426614174000','externalId'=>'new-unit-ext','expectedAuthorizationGeneration'=>'0','profile'=>['name'=>'Survey'],'organization'=>['externalId'=>'org-ext','expectedRevision'=>'1']];
        self::assertSame($command,api_v2_directory_create_command_parse('unit',json_encode($command,JSON_THROW_ON_ERROR)));
        $result=api_v2_directory_create_command_write($pdo,'unit',$command,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174000');self::assertSame(201,$result['status']);self::assertSame('unit',$result['payload']['result']['resource']['type']);self::assertSame(2,(int)$pdo->query("SELECT COUNT(*) FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetchColumn());
    }

    public function testUnitArchiveRestoreIsSoftIdempotentAndDoesNotReviveBinding():void
    {
        $pdo=$this->database();$archive=['commandId'=>'523e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0'];
        $first=api_v2_directory_lifecycle_command_write($pdo,'unit',str_repeat('c',32),'archive',$archive,7,$this->headers(),'623e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200,$first['status']);self::assertSame([1,1],array_map('intval',$pdo->query('SELECT archived,deleted_at IS NOT NULL FROM organization_departments WHERE id=1')->fetch(PDO::FETCH_NUM)));
        self::assertSame('tombstoned',$pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE resource_type='unit'")->fetchColumn());
        self::assertTrue(api_v2_directory_lifecycle_command_write($pdo,'unit',str_repeat('c',32),'archive',$archive,7,$this->headers(),'723e4567-e89b-42d3-a456-426614174000')['payload']['replayed']);
        $restore=['commandId'=>'823e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'2','expectedAuthorizationGeneration'=>'1'];
        self::assertSame(200,api_v2_directory_lifecycle_command_write($pdo,'unit',str_repeat('c',32),'restore',$restore,7,$this->headers(),'923e4567-e89b-42d3-a456-426614174000')['status']);
        self::assertSame([0,0],array_map('intval',$pdo->query('SELECT archived,deleted_at IS NOT NULL FROM organization_departments WHERE id=1')->fetch(PDO::FETCH_NUM)));
        self::assertSame([3,1],array_map('intval',$pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE resource_type='unit'")->fetch(PDO::FETCH_NUM)));
        self::assertSame('tombstoned',$pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE resource_type='unit'")->fetchColumn());
    }

    public function testCapabilitiesAdvertiseOnlyEnabledScopedUnitRoutes():void
    {
        $scopes=['directory.units.read','directory.units.binding_status.read','directory.units.bind','directory.units.binding.revision.refresh','directory.units.write','directory.units.create','directory.units.organization.assign','directory.units.archive','directory.units.restore','directory.units.contacts.assign','directory.units.contacts.remove','directory.units.contacts.set_primary','directory.units.unbind','directory.inventory.read'];
        $features=['directory_read'=>true,'binding_status'=>true,'directory_binding'=>true,'directory_binding_refresh'=>true,'directory_unit_write'=>true,'directory_unit_create'=>true,'directory_unit_archive'=>true,'directory_unit_restore'=>true,'directory_unit_contacts_write'=>true,'directory_binding_revoke'=>true,'directory_inventory'=>true];
        $payload=api_v2_capabilities_payload(['source_instance_id'=>'s','application_id'=>'a','history_epoch'=>'e'],'r',$scopes,$features);$json=json_encode($payload,JSON_THROW_ON_ERROR);$paths=array_column($payload['implementedEndpoints'],'path');
        foreach($scopes as$scope)self::assertStringContainsString($scope,$json);
        self::assertContains('/api/v2/directory/units/{publicId}/contacts/set-primary/commands',$paths);
        self::assertNotContains('/api/v2/directory/clients/{publicId}/profile/commands',$paths);
    }

    public function testStaticContractIsDefaultOffAndKeepsProjectDepartmentOwnershipLocal():void
    {
        $root=dirname(__DIR__,2);$router=(string)file_get_contents($root.'/public/index.php');$env=(string)file_get_contents($root.'/config/.env.example');$docs=(string)file_get_contents($root.'/docs/admin/api-v2-directory-lifecycle.md');
        foreach(['APP_API_V2_DIRECTORY_UNITS_WRITE_ENABLED','APP_API_V2_DIRECTORY_UNITS_CREATE_ENABLED','APP_API_V2_DIRECTORY_UNITS_ARCHIVE_ENABLED','APP_API_V2_DIRECTORY_UNITS_RESTORE_ENABLED','APP_API_V2_DIRECTORY_UNIT_CONTACTS_WRITE_ENABLED']as$flag)self::assertStringContainsString($flag.'=false',$env);
        self::assertStringContainsString('/contacts/(?:assign|remove|set-primary)/commands',$router);self::assertStringContainsString('projects.department_id',$docs);self::assertStringContainsString('Link resolution configuration likewise',$docs);self::assertStringNotContainsString('business_units',api_v2_directory_projection_hash('unit',['name'=>'x','organization_public_id'=>str_repeat('a',32),'contacts'=>[]]));
    }

    public function testFreshInstallRunsUnitMigrationAfterHistoricalBaseline():void
    {
        $root=dirname(__DIR__,2);$baseline=(string)file_get_contents($root.'/database/baseline.sql');$runner=(string)file_get_contents($root.'/docker/migrate.sh');$migration=(string)file_get_contents($root.'/database/migrations/0104_api_v2_directory_units.sql');
        self::assertStringContainsString("VALUES (0, 'baseline.sql', NULL)",$baseline);
        self::assertTrue(strpos($runner,'< "$BASELINE"')<strpos($runner,'run_migrations.php --verbose'));
        self::assertStringContainsString('ADD COLUMN archived',$migration);self::assertStringContainsString("ENUM('client','organization','unit')",$migration);
        self::assertStringContainsString('CREATE TABLE api_v2_directory_unit_profile_command_receipts',$migration);self::assertStringContainsString('CREATE TABLE api_v2_directory_unit_contact_command_receipts',$migration);
    }
}
