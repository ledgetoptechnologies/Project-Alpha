<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryLifecycleRelationshipTest extends TestCase
{
    private PDO $pdo;
    private array $headers=['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];

    protected function setUp():void
    {
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_binding_revoke_command.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_inventory.php';
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,tombstoned_at TEXT,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id));
            CREATE TABLE api_v2_directory_lifecycle_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,public_id TEXT,expected_revision INTEGER,expected_authorization_generation INTEGER,result_revision INTEGER,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id));
            CREATE TABLE api_v2_directory_relationship_command_receipts(application_pk INTEGER,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,action_name TEXT,client_public_id TEXT,expected_client_revision INTEGER,expected_authorization_generation INTEGER,result_client_revision INTEGER,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,history_epoch,command_id));
            CREATE TABLE api_v2_directory_binding_revoke_command_receipts(application_pk INTEGER,resource_type TEXT,history_epoch TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,expected_resource_revision INTEGER,expected_authorization_generation INTEGER,result_authorization_generation INTEGER,PRIMARY KEY(application_pk,resource_type,history_epoch,command_id));
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,archived INTEGER,deleted_at TEXT,source_version TEXT);
            CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT,archived INTEGER,deleted_at TEXT,source_version TEXT);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,client_id INTEGER);CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER);
            CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT);CREATE TABLE invoices(id INTEGER PRIMARY KEY,client_id INTEGER,organization_id INTEGER);
            INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000');
            INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_directory_authorization_state VALUES(3,0);
            INSERT INTO organizations VALUES(1,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','One Org',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1'),(2,'cccccccccccccccccccccccccccccccc','Two Org',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1');
            INSERT INTO clients VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','One Client',NULL,NULL,'unknown',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1');INSERT INTO invoices VALUES(30,9,1);");
        $this->pdo->beginTransaction();api_v2_directory_record($this->pdo,'organization',1);api_v2_directory_record($this->pdo,'organization',2);api_v2_directory_record($this->pdo,'client',9);$this->pdo->commit();
        foreach([['organization','org/one','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],['organization','org/two','cccccccccccccccccccccccccccccccc'],['client','client/one','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]as[$type,$external,$public]){
            $hash=$this->pdo->prepare('SELECT projection_sha256 FROM api_v2_directory_resource_state WHERE resource_type=? AND public_id=?');$hash->execute([$type,$public]);
            $this->pdo->prepare("INSERT INTO api_v2_directory_external_bindings VALUES(3,?,?,?,?,?,'active',NULL)")->execute([$type,$external,$public,1,$hash->fetchColumn()]);
        }
    }

    public function testSoftLifecyclePreservesLinkedHistoryAndDoesNotReviveBindings():void
    {
        $archive=$this->lifecycle('423e4567-e89b-42d3-a456-426614174000','1','0');
        $out=api_v2_directory_lifecycle_command_write($this->pdo,'client',str_repeat('a',32),'archive',$archive,7,$this->headers,'r1');
        self::assertSame(200,$out['status']);self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM invoices WHERE client_id=9')->fetchColumn());
        self::assertSame('tombstoned',$this->pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE resource_type='client'")->fetchColumn());
        self::assertTrue(api_v2_directory_lifecycle_command_write($this->pdo,'client',str_repeat('a',32),'archive',$archive,7,$this->headers,'r2')['payload']['replayed']);
        $restore=$this->lifecycle('523e4567-e89b-42d3-a456-426614174000','2','1');
        self::assertSame(200,api_v2_directory_lifecycle_command_write($this->pdo,'client',str_repeat('a',32),'restore',$restore,7,$this->headers,'r3')['status']);
        self::assertSame('tombstoned',$this->pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE resource_type='client'")->fetchColumn());
        self::assertSame([3,1],array_map('intval',$this->pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE resource_type='client'")->fetch(PDO::FETCH_NUM)));
    }

    public function testOrganizationLifecycleRetainsClientsAndFinancialAssociation():void
    {
        $command=$this->lifecycle('623e4567-e89b-42d3-a456-426614174000','1','0');
        self::assertSame(200,api_v2_directory_lifecycle_command_write($this->pdo,'organization',str_repeat('b',32),'archive',$command,7,$this->headers,'r')['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT organization_id FROM invoices WHERE id=30')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM organizations WHERE id=1')->fetchColumn());
        $restore=$this->lifecycle('723e4567-e89b-42d3-a456-426614174000','2','1');
        self::assertSame(200,api_v2_directory_lifecycle_command_write($this->pdo,'organization',str_repeat('b',32),'restore',$restore,7,$this->headers,'r')['status']);
    }

    public function testArchiveFailsClosedWhenNeutralProjectionCannotRevokePortalAuthority():void
    {
        $this->pdo->exec("CREATE TABLE portal_v2_workspaces(root_type TEXT,root_public_id TEXT,active INTEGER);INSERT INTO portal_v2_workspaces VALUES('standalone_client','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1)");
        $command=$this->lifecycle('e23e4567-e89b-42d3-a456-426614174000','1','0');
        self::assertSame(409,api_v2_directory_lifecycle_command_write($this->pdo,'client',str_repeat('a',32),'archive',$command,7,$this->headers,'r')['status']);
        self::assertSame(0,(int)$this->pdo->query('SELECT archived FROM clients WHERE id=9')->fetchColumn());self::assertSame('active',$this->pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE resource_type='client'")->fetchColumn());
    }

    public function testAssignMoveAndRemoveUseExactBoundOrganizationsAndFences():void
    {
        $assign=$this->relationship('823e4567-e89b-42d3-a456-426614174000','1','0',null,'org/one',str_repeat('b',32),'1');
        self::assertSame(200,api_v2_directory_relationship_command_write($this->pdo,str_repeat('a',32),'assign',$assign,7,$this->headers,'r')['status']);
        $move=$this->relationship('923e4567-e89b-42d3-a456-426614174000','2','1',str_repeat('b',32),'org/two',str_repeat('c',32),'1');
        self::assertSame(200,api_v2_directory_relationship_command_write($this->pdo,str_repeat('a',32),'move',$move,7,$this->headers,'r')['status']);
        $remove=$this->relationship('a23e4567-e89b-42d3-a456-426614174000','3','2',str_repeat('c',32));
        self::assertSame(200,api_v2_directory_relationship_command_write($this->pdo,str_repeat('a',32),'remove',$remove,7,$this->headers,'r')['status']);
        self::assertNull($this->pdo->query('SELECT organization_id FROM clients WHERE id=9')->fetchColumn());
        self::assertSame(4,(int)$this->pdo->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='client'")->fetchColumn());
        self::assertSame(3,(int)$this->pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testExplicitBindingRevocationAndHistoricalInventory():void
    {
        $command=['commandId'=>'b23e4567-e89b-42d3-a456-426614174000','externalId'=>'client/one','expectedPublicId'=>str_repeat('a',32),'expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0'];
        $out=api_v2_directory_binding_revoke_command_write($this->pdo,'client',$command,7,$this->headers,'r');self::assertSame(200,$out['status']);self::assertSame('1',$out['payload']['result']['authorizationGeneration']);
        $inventory=api_v2_directory_inventory_read($this->pdo,'all',null,2,7,$this->headers,'r');self::assertCount(2,$inventory['resources']);self::assertNotNull($inventory['nextCursor']);
        $second=api_v2_directory_inventory_read($this->pdo,'all',$inventory['nextCursor'],2,7,$this->headers,'r');self::assertCount(1,$second['resources']);
        $client=array_values(array_filter(array_merge($inventory['resources'],$second['resources']),static fn(array$r):bool=>$r['type']==='client'))[0];
        self::assertSame('tombstoned',$client['binding']['status']);
    }

    public function testReceiptsCannotReplayAcrossHistoryEpochs():void
    {
        $command=['commandId'=>'d23e4567-e89b-42d3-a456-426614174000','externalId'=>'client/one','expectedPublicId'=>str_repeat('a',32),'expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0'];
        self::assertSame(200,api_v2_directory_binding_revoke_command_write($this->pdo,'client',$command,7,$this->headers,'r')['status']);
        $next='423e4567-e89b-42d3-a456-426614174000';$this->pdo->prepare('UPDATE api_v2_history_identity SET history_epoch=?')->execute([$next]);$headers=$this->headers;$headers['epoch']=$next;
        self::assertSame(409,api_v2_directory_binding_revoke_command_write($this->pdo,'client',$command,7,$headers,'r')['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM api_v2_directory_binding_revoke_command_receipts')->fetchColumn());
    }

    public function testParsersFailClosedForDeleteAndAmbiguousMoves():void
    {
        self::assertNull(api_v2_directory_relationship_command_parse('move',json_encode($this->relationship('c23e4567-e89b-42d3-a456-426614174000','1','0',str_repeat('b',32),'org/one',str_repeat('b',32),'1'))));
        self::assertNull(api_v2_directory_lifecycle_command_parse('{"commandId":"bad"}'));
        self::assertFalse(str_contains((string)file_get_contents(dirname(__DIR__,2).'/public/index.php'),'/delete/commands'));
    }

    public function testOnlyImplementedDefaultOffRoutesAndFineGrainedScopesAreAdvertised():void
    {
        require_once dirname(__DIR__,2).'/src/utils/api_scopes.php';$root=dirname(__DIR__,2);$router=(string)file_get_contents($root.'/public/index.php');
        $scopes=['directory.clients.archive','directory.clients.restore','directory.organizations.archive','directory.organizations.restore',
            'directory.clients.organization.assign','directory.clients.organization.remove','directory.clients.organization.move','directory.clients.unbind','directory.organizations.unbind','directory.inventory.read'];
        foreach($scopes as$scope){self::assertArrayHasKey($scope,api_scope_catalog());self::assertFalse(api_key_has_scope('full',$scope,false));}
        $example=(string)file_get_contents($root.'/config/.env.example');foreach(['APP_API_V2_DIRECTORY_CLIENTS_ARCHIVE_ENABLED','APP_API_V2_DIRECTORY_ORGANIZATIONS_RESTORE_ENABLED','APP_API_V2_DIRECTORY_RELATIONSHIPS_WRITE_ENABLED','APP_API_V2_DIRECTORY_BINDING_REVOKE_ENABLED']as$flag)self::assertStringContainsString($flag.'=false',$example);
        self::assertDoesNotMatchRegularExpression('/^APP_API_V2_DIRECTORY_INVENTORY_ENABLED=/m',$example);
        self::assertStringContainsString("'APP_API_V2_DIRECTORY_' . strtoupper",$router);
        $features=['directory_client_archive'=>true,'directory_client_restore'=>true,'directory_organization_archive'=>true,'directory_organization_restore'=>true,'directory_relationship_write'=>true,'directory_binding_revoke'=>true,'directory_inventory'=>true];
        $payload=api_v2_capabilities_payload(['source_instance_id'=>'s','application_id'=>'a','history_epoch'=>'e'],'r',$scopes,$features);
        self::assertCount(12,$payload['implementedEndpoints']);self::assertCount(11,$payload['grantedCapabilities']);
        self::assertStringNotContainsString('delete/commands',json_encode($payload,JSON_THROW_ON_ERROR));
        $migration=(string)file_get_contents($root.'/database/migrations/0099_api_v2_directory_lifecycle_relationships.sql');self::assertStringContainsString('ADD COLUMN archived',$migration);self::assertStringContainsString('ON DELETE RESTRICT',$migration);
    }

    private function lifecycle(string$id,string$revision,string$generation):array{return['commandId'=>$id,'expectedRevision'=>$revision,'expectedAuthorizationGeneration'=>$generation];}
    private function relationship(string$id,string$revision,string$generation,?string$current,?string$external=null,?string$public=null,?string$orgRevision=null):array
    {return['commandId'=>$id,'expectedClientRevision'=>$revision,'expectedAuthorizationGeneration'=>$generation,'expectedCurrentOrganizationPublicId'=>$current,'organization'=>$external===null?null:['externalId'=>$external,'publicId'=>$public,'expectedRevision'=>$orgRevision]];}
}
