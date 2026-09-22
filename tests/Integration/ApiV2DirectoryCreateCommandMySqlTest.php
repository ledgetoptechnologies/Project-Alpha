<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryCreateCommandMySqlTest extends TestCase
{
    private PDO $first;
    private PDO $second;

    protected function setUp(): void
    {
        $dsn=getenv('API_V2_DIRECTORY_CREATE_MYSQL_DSN'); $user=getenv('API_V2_DIRECTORY_CREATE_MYSQL_USER'); $password=getenv('API_V2_DIRECTORY_CREATE_MYSQL_PASSWORD');
        if (!$dsn || !$user || $password === false) self::markTestSkipped('Run tools/run-api-v2-directory-create-mysql-integration.ps1 for isolated MySQL tests.');
        $database=trim((string)(getenv('API_V2_DIRECTORY_CREATE_MYSQL_DATABASE') ?: ''));
        if (getenv('API_V2_DIRECTORY_CREATE_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only' || preg_match('/^api_v2_create_test_[a-f0-9]{32}$/D',$database)!==1) throw new \RuntimeException('Directory create MySQL tests require the disposable runner sentinel.');
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
        $this->first=new PDO($dsn,$user,$password,$options); $this->second=new PDO($dsn,$user,$password,$options);
        if (!hash_equals($database,(string)$this->first->query('SELECT DATABASE()')->fetchColumn())) throw new \RuntimeException('Refusing to reset a non-disposable database.');
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; SET SESSION innodb_lock_wait_timeout=1');
        require_once dirname(__DIR__,2).'/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_create_command.php';
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first ?? null,$this->second ?? null] as $pdo) if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }

    public function testSuccessReplayStaleDuplicateBindingAndAssignment(): void
    {
        $organization=$this->organizationCommand();
        $first=\api_v2_directory_create_command_write($this->first,'organization',$organization,7,$this->headers(),'one');
        self::assertSame(201,$first['status']); self::assertSame('1',$first['payload']['result']['authorizationGeneration']);
        $replay=\api_v2_directory_create_command_write($this->second,'organization',$organization,7,$this->headers(),'two');
        self::assertSame(200,$replay['status']); self::assertTrue($replay['payload']['replayed']);
        $duplicate=$this->organizationCommand(); $duplicate['commandId']='623e4567-e89b-42d3-a456-426614174000'; $duplicate['externalId']='org-2'; $duplicate['expectedAuthorizationGeneration']='1'; $duplicate['profile']['generalEmail']='replacement@example.test';
        self::assertSame(409,\api_v2_directory_create_command_write($this->first,'organization',$duplicate,7,$this->headers(),'duplicate')['status']);
        self::assertSame(1,(int)$this->first->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
        self::assertSame('office@example.test',$this->first->query('SELECT general_email FROM organizations')->fetchColumn());
        $client=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        self::assertSame(201,\api_v2_directory_create_command_write($this->first,'client',$client,7,$this->headers(),'client')['status']);
        self::assertSame((int)$this->first->query('SELECT id FROM organizations')->fetchColumn(),(int)$this->first->query('SELECT organization_id FROM clients')->fetchColumn());
        $stale=$this->clientCommand(null,'1'); $stale['commandId']='723e4567-e89b-42d3-a456-426614174000'; $stale['externalId']='client-2';
        self::assertSame(409,\api_v2_directory_create_command_write($this->first,'client',$stale,7,$this->headers(),'stale')['status']);
    }

    public function testReceiptFailureRollsBackEveryCreatedRowAndGeneration(): void
    {
        $this->first->exec("CREATE TRIGGER stop_directory_create_receipt BEFORE INSERT ON api_v2_directory_create_command_receipts FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='blocked receipt'");
        self::assertSame(409,\api_v2_directory_create_command_write($this->first,'client',$this->clientCommand(),7,$this->headers(),'rollback')['status']);
        foreach (['clients','addresses','address_assignments','api_v2_directory_resource_state','api_v2_directory_resource_changes','api_v2_directory_external_bindings','api_v2_directory_create_command_receipts'] as $table) self::assertSame(0,(int)$this->first->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),$table);
        self::assertSame(0,(int)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testApplicationLockSerializesConcurrentCreateDecisions(): void
    {
        $this->first->beginTransaction(); $this->first->query('SELECT id FROM api_v2_applications WHERE id=3 FOR UPDATE')->fetchColumn();
        try { \api_v2_directory_create_command_write($this->second,'client',$this->clientCommand(),7,$this->headers(),'blocked'); self::fail('Create crossed the application lock.'); }
        catch (PDOException $error) { self::assertSame(1205,(int)($error->errorInfo[1] ?? 0),$error->getMessage()); }
        self::assertFalse($this->second->inTransaction()); $this->first->commit();
        self::assertSame(201,\api_v2_directory_create_command_write($this->second,'client',$this->clientCommand(),7,$this->headers(),'after')['status']);
    }

    public function testAssignedCreateContendsWithBrowserOrganizationProfileOrderAndRejectsStaleRetry(): void
    {
        self::assertSame(201,\api_v2_directory_create_command_write($this->first,'organization',$this->organizationCommand(),7,$this->headers(),'organization')['status']);
        $organizationId=(int)$this->first->query('SELECT id FROM organizations')->fetchColumn();
        $addressColumns=array_fill_keys(['address_line1','address_line2','city','state','postal_code','country'],true);

        $this->first->beginTransaction();
        (new \App\Services\OrganizationProfileMutationService())->mutate($this->first,$organizationId,[
            'name'=>'Example Organization Updated','general_email'=>'updated@example.test','general_phone'=>'556','notes'=>'',
            'address'=>['address_line1'=>'9 Changed','address_line2'=>'','city'=>'Madison','state'=>'WI','postal_code'=>'53704','country'=>'US'],
            'google_place_id'=>'','address_label'=>'Billing address','actor_id'=>0,
        ],$addressColumns);
        self::assertTrue($this->first->inTransaction());

        $client=$this->clientCommand(['externalId'=>'org-1','expectedRevision'=>'1'],'1');
        try { \api_v2_directory_create_command_write($this->second,'client',$client,7,$this->headers(),'contended'); self::fail('Assigned create crossed the organization profile lock boundary.'); }
        catch (PDOException $error) { self::assertSame(1205,(int)($error->errorInfo[1]??0),$error->getMessage()); }
        self::assertFalse($this->second->inTransaction());
        self::assertSame(0,(int)$this->second->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(1,(int)$this->second->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());

        $this->first->commit();
        self::assertSame('2',(string)$this->second->query("SELECT revision FROM api_v2_directory_resource_state WHERE resource_type='organization'")->fetchColumn());
        self::assertSame(409,\api_v2_directory_create_command_write($this->second,'client',$client,7,$this->headers(),'stale-after-profile')['status']);
        self::assertSame(0,(int)$this->second->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        self::assertSame(0,(int)$this->second->query("SELECT COUNT(*) FROM api_v2_directory_create_command_receipts WHERE resource_type='client'")->fetchColumn());
        self::assertSame(0,(int)$this->second->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE resource_type='client'")->fetchColumn());
    }

    private function headers(): array { return ['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000']; }
    private function organizationCommand(): array { return ['commandId'=>'423e4567-e89b-42d3-a456-426614174000','externalId'=>'org-1','expectedAuthorizationGeneration'=>'0','profile'=>['name'=>'Example Organization','generalEmail'=>'office@example.test','generalPhone'=>'555','addressLine1'=>'1 Main','addressLine2'=>'','city'=>'Madison','state'=>'WI','postalCode'=>'53703','country'=>'US']]; }
    private function clientCommand(?array $organization=null,string $generation='0'): array { return ['commandId'=>'523e4567-e89b-42d3-a456-426614174000','externalId'=>'client-1','expectedAuthorizationGeneration'=>$generation,'profile'=>['name'=>'Example Client','email'=>'client@example.test','phone'=>'555','clientType'=>'business','addressLine1'=>'2 Main','addressLine2'=>'','city'=>'Madison','state'=>'WI','postalCode'=>'53703','country'=>'US'],'organization'=>$organization]; }

    private function resetSchema(): void
    {
        $tables=['api_v2_directory_create_command_receipts','api_v2_directory_external_bindings','api_v2_directory_resource_changes','api_v2_directory_resource_state','api_v2_directory_authorization_state','api_keys','api_v2_applications','api_v2_history_identity','app_config','address_assignments','addresses','clients','organizations'];
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0'); foreach($tables as$table)$this->first->exec("DROP TABLE IF EXISTS `$table`"); $this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE api_v2_applications(id BIGINT UNSIGNED PRIMARY KEY,application_id CHAR(36),name VARCHAR(191)) ENGINE=InnoDB;
            CREATE TABLE api_keys(id BIGINT UNSIGNED PRIMARY KEY,api_v2_application_id BIGINT UNSIGNED,revoked_at DATETIME NULL) ENGINE=InnoDB;
            CREATE TABLE api_v2_history_identity(singleton TINYINT UNSIGNED PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT UNSIGNED PRIMARY KEY,authorization_generation BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_state(resource_type ENUM('client','organization'),public_id CHAR(32),revision BIGINT UNSIGNED,projection_sha256 CHAR(64),present TINYINT,PRIMARY KEY(resource_type,public_id)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_changes(resource_type ENUM('client','organization'),public_id CHAR(32),revision BIGINT UNSIGNED,action ENUM('upsert','delete'),PRIMARY KEY(resource_type,public_id,revision)) ENGINE=InnoDB;
            CREATE TABLE organizations(id INT AUTO_INCREMENT PRIMARY KEY,public_id CHAR(32) UNIQUE,name VARCHAR(150) UNIQUE,general_email VARCHAR(255),general_phone VARCHAR(50),notes TEXT,address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(100),postal_code VARCHAR(32),country VARCHAR(100),source_version VARCHAR(191)) ENGINE=InnoDB;
            CREATE TABLE clients(id INT AUTO_INCREMENT PRIMARY KEY,public_id CHAR(32) UNIQUE,name VARCHAR(150),email VARCHAR(255),phone VARCHAR(50),organization_id INT NULL,client_type ENUM('unknown','business','consumer'),address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(2),postal_code VARCHAR(20),country VARCHAR(100),source_version VARCHAR(191),notes TEXT,archived TINYINT DEFAULT 0,deleted_at DATETIME NULL) ENGINE=InnoDB;
            CREATE TABLE addresses(id INT AUTO_INCREMENT PRIMARY KEY,label VARCHAR(255),address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(100),postal_code VARCHAR(32),country VARCHAR(100),google_place_id VARCHAR(255),source VARCHAR(32),created_by INT NULL,archived TINYINT DEFAULT 0) ENGINE=InnoDB;
            CREATE TABLE address_assignments(id INT AUTO_INCREMENT PRIMARY KEY,address_id INT,entity_type VARCHAR(32),entity_id INT,purpose VARCHAR(32),is_default TINYINT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_assignment(entity_type,entity_id,purpose,address_id)) ENGINE=InnoDB;
            CREATE TABLE app_config(organization_id INT,config_key VARCHAR(191),config_value TEXT,PRIMARY KEY(organization_id,config_key)) ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_external_bindings(application_pk BIGINT UNSIGNED,resource_type ENUM('client','organization'),external_id VARBINARY(764),public_id CHAR(32),resource_revision BIGINT UNSIGNED,resource_projection_sha256 CHAR(64),status ENUM('active','tombstoned'),created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),tombstoned_at DATETIME(6),PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE KEY uq_public(application_pk,resource_type,public_id)) ENGINE=InnoDB;
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000','test'); INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000'); INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','0');");
        $migration=file_get_contents(dirname(__DIR__,2).'/database/migrations/0097_api_v2_directory_create_command_receipts.sql'); self::assertNotFalse($migration); $this->first->exec($migration);
        $this->first->exec("ALTER TABLE api_v2_directory_create_command_receipts ADD COLUMN history_epoch CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER resource_type, DROP PRIMARY KEY, ADD PRIMARY KEY(application_pk,resource_type,history_epoch,command_id)");
    }
}
