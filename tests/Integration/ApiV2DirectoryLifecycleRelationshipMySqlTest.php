<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ApiV2DirectoryLifecycleRelationshipMySqlTest extends TestCase
{
    private PDO $first;private PDO $second;

    protected function setUp():void
    {
        $dsn=getenv('API_V2_ORG_PROFILE_MYSQL_DSN');$user=getenv('API_V2_ORG_PROFILE_MYSQL_USER');$password=getenv('API_V2_ORG_PROFILE_MYSQL_PASSWORD');
        if(!$dsn||!$user||$password===false)self::markTestSkipped('Run tools/run-api-v2-organization-profile-mysql-integration.ps1 for disposable MySQL lifecycle tests.');
        $database=(string)(getenv('API_V2_ORG_PROFILE_MYSQL_DATABASE')?:'');
        if(getenv('API_V2_ORG_PROFILE_MYSQL_ALLOW_DESTRUCTIVE')!=='isolated-disposable-only'||preg_match('/^api_v2_org_profile_test_[a-f0-9]{32}$/D',$database)!==1)throw new \RuntimeException('Lifecycle MySQL tests require the disposable runner.');
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$this->first=new PDO($dsn,$user,$password,$options);$this->second=new PDO($dsn,$user,$password,$options);
        if(!hash_equals($database,(string)$this->first->query('SELECT DATABASE()')->fetchColumn()))throw new \RuntimeException('Refusing a non-disposable database.');
        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->second->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;SET SESSION innodb_lock_wait_timeout=1');
        require_once dirname(__DIR__,2).'/src/utils/api_v2_directory_binding_revoke_command.php';$this->resetSchema();
    }

    protected function tearDown():void{foreach([$this->first??null,$this->second??null]as$pdo)if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();}

    public function testSourceLockContentionThenSafeArchiveAndRestore():void
    {
        $archive=['commandId'=>'423e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0'];
        $this->first->beginTransaction();$this->first->query("SELECT id FROM clients WHERE public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' FOR UPDATE");
        try{\api_v2_directory_lifecycle_command_write($this->second,'client',str_repeat('a',32),'archive',$archive,7,$this->headers(),'r');self::fail('Lifecycle command crossed a source lock.');}
        catch(PDOException$error){self::assertSame(1205,(int)($error->errorInfo[1]??0),$error->getMessage());}
        self::assertFalse($this->second->inTransaction());$this->first->commit();
        $result=\api_v2_directory_lifecycle_command_write($this->second,'client',str_repeat('a',32),'archive',$archive,7,$this->headers(),'r');self::assertSame(200,$result['status']);
        self::assertSame(1,(int)$this->first->query('SELECT COUNT(*) FROM invoices WHERE client_id=9')->fetchColumn());self::assertSame('tombstoned',$this->first->query('SELECT status FROM api_v2_directory_external_bindings')->fetchColumn());
        $restore=['commandId'=>'523e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'2','expectedAuthorizationGeneration'=>'1'];
        self::assertSame(200,\api_v2_directory_lifecycle_command_write($this->first,'client',str_repeat('a',32),'restore',$restore,7,$this->headers(),'r')['status']);
        self::assertSame('tombstoned',$this->first->query('SELECT status FROM api_v2_directory_external_bindings')->fetchColumn());
    }

    public function testReceiptFailureRollsBackLifecycleAndAuthority():void
    {
        $this->first->exec("CREATE TRIGGER stop_lifecycle_receipt BEFORE INSERT ON api_v2_directory_lifecycle_command_receipts FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062,MESSAGE_TEXT='blocked receipt'");
        $command=['commandId'=>'623e4567-e89b-42d3-a456-426614174000','expectedRevision'=>'1','expectedAuthorizationGeneration'=>'0'];
        self::assertSame(409,\api_v2_directory_lifecycle_command_write($this->first,'client',str_repeat('a',32),'archive',$command,7,$this->headers(),'r')['status']);
        self::assertSame('0',(string)$this->first->query('SELECT archived FROM clients WHERE id=9')->fetchColumn());self::assertSame('active',$this->first->query('SELECT status FROM api_v2_directory_external_bindings')->fetchColumn());
        self::assertSame('0',(string)$this->first->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());self::assertSame('1',(string)$this->first->query('SELECT revision FROM api_v2_directory_resource_state')->fetchColumn());
    }

    private function headers():array{return['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];}

    private function resetSchema():void
    {
        $this->first->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['api_v2_directory_binding_revoke_command_receipts','api_v2_directory_relationship_command_receipts','api_v2_directory_lifecycle_command_receipts','api_v2_directory_external_bindings','api_v2_directory_resource_changes','api_v2_directory_resource_state','api_v2_directory_authorization_state','api_keys','api_v2_applications','api_v2_history_identity','organization_department_contacts','projects','invoices','clients','organizations','app_config']as$table)$this->first->exec("DROP TABLE IF EXISTS `$table`");$this->first->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->first->exec("CREATE TABLE api_v2_applications(id BIGINT UNSIGNED PRIMARY KEY,application_id CHAR(36),name VARCHAR(191))ENGINE=InnoDB;
            CREATE TABLE api_keys(id BIGINT UNSIGNED PRIMARY KEY,api_v2_application_id BIGINT UNSIGNED,revoked_at DATETIME NULL,FOREIGN KEY(api_v2_application_id)REFERENCES api_v2_applications(id))ENGINE=InnoDB;
            CREATE TABLE api_v2_history_identity(singleton TINYINT UNSIGNED PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36))ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_authorization_state(application_pk BIGINT UNSIGNED PRIMARY KEY,authorization_generation BIGINT UNSIGNED,FOREIGN KEY(application_pk)REFERENCES api_v2_applications(id))ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_state(resource_type ENUM('client','organization'),public_id CHAR(32),revision BIGINT UNSIGNED,projection_sha256 CHAR(64),present TINYINT,PRIMARY KEY(resource_type,public_id))ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_resource_changes(resource_type ENUM('client','organization'),public_id CHAR(32),revision BIGINT UNSIGNED,action ENUM('upsert','delete'),PRIMARY KEY(resource_type,public_id,revision),FOREIGN KEY(resource_type,public_id)REFERENCES api_v2_directory_resource_state(resource_type,public_id))ENGINE=InnoDB;
            CREATE TABLE api_v2_directory_external_bindings(application_pk BIGINT UNSIGNED,resource_type ENUM('client','organization'),external_id VARBINARY(764),public_id CHAR(32),resource_revision BIGINT UNSIGNED,resource_projection_sha256 CHAR(64),status ENUM('active','tombstoned'),tombstoned_at DATETIME(6),PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE KEY uq_test_binding_public(application_pk,resource_type,public_id),FOREIGN KEY(application_pk)REFERENCES api_v2_applications(id),FOREIGN KEY(resource_type,public_id)REFERENCES api_v2_directory_resource_state(resource_type,public_id))ENGINE=InnoDB;
            CREATE TABLE organizations(id INT PRIMARY KEY,public_id CHAR(32) UNIQUE,name VARCHAR(150),general_email VARCHAR(255),general_phone VARCHAR(50),address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(100),postal_code VARCHAR(32),country VARCHAR(100),link_strategy VARCHAR(32),source_version VARCHAR(191))ENGINE=InnoDB;
            CREATE TABLE clients(id INT PRIMARY KEY,public_id CHAR(32) UNIQUE,name VARCHAR(150),email VARCHAR(255),phone VARCHAR(50),client_type ENUM('unknown','business','consumer'),organization_id INT NULL,address_line1 VARCHAR(255),address_line2 VARCHAR(255),city VARCHAR(100),state VARCHAR(2),postal_code VARCHAR(20),country VARCHAR(100),archived TINYINT DEFAULT 0,deleted_at DATETIME NULL,source_version VARCHAR(191),FOREIGN KEY(organization_id)REFERENCES organizations(id))ENGINE=InnoDB;
            CREATE TABLE projects(id INT PRIMARY KEY,client_id INT);CREATE TABLE organization_department_contacts(department_id INT,client_id INT);CREATE TABLE invoices(id INT PRIMARY KEY,client_id INT,FOREIGN KEY(client_id)REFERENCES clients(id)ON DELETE RESTRICT);CREATE TABLE app_config(organization_id INT,config_key VARCHAR(191),config_value TEXT);
            INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000','Disposable');INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_directory_authorization_state VALUES(3,0);INSERT INTO organizations VALUES(1,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','Org',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'overall_folder','v1');INSERT INTO clients VALUES(9,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Client',NULL,NULL,'unknown',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'v1');INSERT INTO invoices VALUES(30,9);");
        $migration=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0099_api_v2_directory_lifecycle_relationships.sql');$this->first->exec($migration);
        $this->first->beginTransaction();\api_v2_directory_record($this->first,'client',9);$this->first->commit();$hash=$this->first->query('SELECT projection_sha256 FROM api_v2_directory_resource_state')->fetchColumn();$this->first->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status)VALUES(3,'client','client/one','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,?,'active')")->execute([$hash]);
    }
}
