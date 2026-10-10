<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ProjectRevisionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiV2CatalogProjectReadMySqlTest extends TestCase
{
    private PDO $pdo;
    private array $headers=['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];

    protected function setUp(): void
    {
        $dsn=getenv('API_V2_READ_MYSQL_DSN');$user=getenv('API_V2_READ_MYSQL_USER');$password=getenv('API_V2_READ_MYSQL_PASSWORD');
        if(!$dsn||!$user||$password===false)self::markTestSkipped('Run tools/run-api-v2-read-mysql-integration.ps1.');
        $database=trim((string)getenv('API_V2_READ_MYSQL_DATABASE'));
        if(getenv('API_V2_READ_MYSQL_ALLOW_DESTRUCTIVE')!=='isolated-disposable-only'||preg_match('/^api_v2_read_test_[a-f0-9]{32}$/D',$database)!==1)throw new \RuntimeException('Read tests require the disposable runner sentinel.');
        $this->pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        if(!hash_equals($database,(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()))throw new \RuntimeException('Refusing a non-disposable database.');
        require_once dirname(__DIR__,2).'/src/utils/api_v2_catalog_inventory.php';
        require_once dirname(__DIR__,2).'/src/utils/api_v2_project_sync.php';
        $this->resetSchema();
    }

    public function testCatalogUsesMySqlByteBoundsAndRejectsMixedSnapshotPages(): void
    {
        $questions='[{"id":"notes","label":"Résumé 😀","type":"text","required":false}]';
        $insert=$this->pdo->prepare("INSERT INTO item_library(portal_public_id,item_name,portal_summary,portal_category,portal_display_order,portal_geometry_requirement,portal_questions_json) VALUES(?,?,?,?,?,'none',?)");
        $insert->execute([str_repeat('1',32),'Áerial 😀','Résumé 😀','Survey',1,$questions]);
        $insert->execute([str_repeat('2',32),'Mapping','Second','Survey',2,'[]']);
        self::assertGreaterThan(mb_strlen('Áerial 😀'),(int)$this->pdo->query("SELECT OCTET_LENGTH(item_name) FROM item_library WHERE portal_public_id='".str_repeat('1',32)."'")->fetchColumn());
        $page=\api_v2_catalog_inventory_read($this->pdo,null,1,7,$this->headers,'first');
        self::assertSame(200,$page['status']);self::assertCount(1,$page['payload']['items']);self::assertNotNull($page['payload']['nextCursor']);
        self::assertLessThanOrEqual(\API_V2_CATALOG_RESPONSE_MAX_BYTES,strlen(json_encode($page['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
        $unchanged=\api_v2_catalog_inventory_read($this->pdo,$page['payload']['nextCursor'],1,7,$this->headers,'unchanged');
        self::assertSame(200,$unchanged['status']);self::assertSame([str_repeat('2',32)],array_column($unchanged['payload']['items'],'publicId'));self::assertNull($unchanged['payload']['nextCursor']);
        $this->pdo->exec("UPDATE item_library SET portal_summary='Changed between pages' WHERE portal_public_id='".str_repeat('2',32)."'");
        $stale=\api_v2_catalog_inventory_read($this->pdo,$page['payload']['nextCursor'],1,7,$this->headers,'stale');
        self::assertSame(409,$stale['status']);self::assertSame(['code'=>'catalog_snapshot_changed'],$stale['payload']['error']);
        self::assertSame('source-sentinel',(string)$this->pdo->query('SELECT marker FROM source_mutation_sentinel')->fetchColumn());
        self::assertSame('public-link-sentinel',(string)$this->pdo->query('SELECT marker FROM public_links')->fetchColumn());
    }

    public function testCatalogRejectsActualMySqlRawOctetOverflow(): void
    {
        $oversized=str_repeat('x',\API_V2_CATALOG_MAX_RAW_BYTES+1);
        $statement=$this->pdo->prepare("INSERT INTO item_library(portal_public_id,item_name,portal_summary,portal_category,portal_display_order,portal_geometry_requirement,portal_questions_json) VALUES(?,?,'summary','Survey',1,'none','[]')");
        $statement->execute([str_repeat('9',32),$oversized]);unset($oversized);
        self::assertGreaterThan(\API_V2_CATALOG_MAX_RAW_BYTES,(int)$this->pdo->query('SELECT OCTET_LENGTH(item_name) FROM item_library')->fetchColumn());
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Catalog raw data exceeds inventory limit');
        \api_v2_catalog_inventory_read($this->pdo,null,1,7,$this->headers,'oversized');
    }

    public function testAdoptionDiscoveryScopesBindingsAndFailsClosedOnBadHistory(): void
    {
        $this->insertProject(str_repeat('1',32),'Available');$calling=$this->insertProject(str_repeat('2',32),'Calling bound');$other=$this->insertProject(str_repeat('3',32),'Other app bound');$broken=$this->insertProject(str_repeat('4',32),'Broken history');
        $this->pdo->prepare('INSERT INTO api_v2_project_external_bindings(application_pk,external_id,project_public_id,project_revision,project_projection_sha256) VALUES(3,?,?,1,?)')->execute(['caller-secret',$calling['public_id'],ProjectRevisionService::projectionHash($calling)]);
        $this->pdo->prepare('INSERT INTO api_v2_project_external_bindings(application_pk,external_id,project_public_id,project_revision,project_projection_sha256) VALUES(4,?,?,1,?)')->execute(['other-secret',$other['public_id'],ProjectRevisionService::projectionHash($other)]);
        $this->pdo->prepare('DELETE FROM project_changes WHERE project_public_id=?')->execute([$broken['public_id']]);
        $bindings=$this->pdo->query('SELECT * FROM api_v2_project_external_bindings ORDER BY application_pk')->fetchAll();
        $page=\api_v2_project_adoption_candidates_read($this->pdo,null,2,7,$this->headers,'page');
        self::assertSame(200,$page['status']);self::assertSame([str_repeat('1',32),str_repeat('3',32)],array_column($page['payload']['projects'],'publicId'));
        self::assertStringNotContainsString('other-secret',json_encode($page['payload'],JSON_THROW_ON_ERROR));
        self::assertSame(['status'=>409],\api_v2_project_adoption_candidates_read($this->pdo,$page['payload']['nextCursor'],10,7,$this->headers,'broken'));
        $bad=$this->headers;$bad['epoch']='523e4567-e89b-42d3-a456-426614174000';self::assertSame(['status'=>409],\api_v2_project_adoption_candidates_read($this->pdo,null,10,7,$bad,'bad-history'));
        self::assertSame($bindings,$this->pdo->query('SELECT * FROM api_v2_project_external_bindings ORDER BY application_pk')->fetchAll());
        self::assertSame('source-sentinel',(string)$this->pdo->query('SELECT marker FROM source_mutation_sentinel')->fetchColumn());self::assertSame('public-link-sentinel',(string)$this->pdo->query('SELECT marker FROM public_links')->fetchColumn());
    }

    public function testAdoptionDiscoveryUsesCommittedSnapshotDuringCallingBindingTransaction(): void
    {
        $project=$this->insertProject(str_repeat('5',32),'Concurrent candidate');
        $writer=new PDO((string)getenv('API_V2_READ_MYSQL_DSN'),(string)getenv('API_V2_READ_MYSQL_USER'),(string)getenv('API_V2_READ_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $writer->beginTransaction();
        try {
            $statement=$writer->prepare('INSERT INTO api_v2_project_external_bindings(application_pk,external_id,project_public_id,project_revision,project_projection_sha256) VALUES(3,?,?,1,?)');
            $statement->execute(['in-flight-private-id',$project['public_id'],ProjectRevisionService::projectionHash($project)]);
            self::assertSame(1,(int)$writer->query('SELECT COUNT(*) FROM api_v2_project_external_bindings')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM api_v2_project_external_bindings')->fetchColumn());

            $during=\api_v2_project_adoption_candidates_read($this->pdo,null,10,7,$this->headers,'during');
            self::assertSame(200,$during['status']);self::assertSame([str_repeat('5',32)],array_column($during['payload']['projects'],'publicId'));
            self::assertStringNotContainsString('in-flight-private-id',json_encode($during['payload'],JSON_THROW_ON_ERROR));
            self::assertSame(1,(int)$writer->query('SELECT COUNT(*) FROM api_v2_project_external_bindings')->fetchColumn(),'Reader must not mutate the pending binding.');
            $writer->commit();
        } catch (\Throwable $error) {
            if($writer->inTransaction())$writer->rollBack();
            throw $error;
        }

        $after=\api_v2_project_adoption_candidates_read($this->pdo,null,10,7,$this->headers,'after');
        self::assertSame(200,$after['status']);self::assertSame([],$after['payload']['projects']);
        self::assertStringNotContainsString('in-flight-private-id',json_encode($after['payload'],JSON_THROW_ON_ERROR));
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM api_v2_project_external_bindings')->fetchColumn());
        self::assertSame('source-sentinel',(string)$this->pdo->query('SELECT marker FROM source_mutation_sentinel')->fetchColumn());self::assertSame('public-link-sentinel',(string)$this->pdo->query('SELECT marker FROM public_links')->fetchColumn());
    }

    private function insertProject(string $publicId,string $name): array
    {
        $statement=$this->pdo->prepare("INSERT INTO projects(public_id,organization_id,client_id,name,status,revision,source_version,invoice_billing_period,project_invoice_auto_email) VALUES(?,10,11,?,'not_started',1,'browser-v1','per_invoice',0)");$statement->execute([$publicId,$name]);$id=(int)$this->pdo->lastInsertId();
        $this->pdo->beginTransaction();try{$project=(new ProjectRevisionService($this->pdo))->initialize($id,'baseline');$this->pdo->commit();return$project;}catch(\Throwable$error){$this->pdo->rollBack();throw$error;}
    }

    private function resetSchema(): void
    {
        $tables=['api_v2_project_external_bindings','project_changes','project_retention_guards','projects','clients','organizations','api_v2_project_authorization_state','item_library','api_keys','api_v2_applications','api_v2_history_identity','source_mutation_sentinel','public_links'];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach($tables as$table)$this->pdo->exec("DROP TABLE IF EXISTS `$table`");$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->exec("CREATE TABLE api_v2_applications(id BIGINT PRIMARY KEY,application_id CHAR(36));CREATE TABLE api_keys(id BIGINT PRIMARY KEY,api_v2_application_id BIGINT,revoked_at DATETIME NULL);CREATE TABLE api_v2_history_identity(singleton TINYINT PRIMARY KEY,source_instance_id CHAR(36),history_epoch CHAR(36));CREATE TABLE api_v2_project_authorization_state(application_pk BIGINT PRIMARY KEY,authorization_generation BIGINT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);CREATE TABLE organizations(id BIGINT PRIMARY KEY,public_id CHAR(32));CREATE TABLE clients(id BIGINT PRIMARY KEY,public_id CHAR(32));CREATE TABLE projects(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_id CHAR(32) UNIQUE,client_id BIGINT NULL,organization_id BIGINT NULL,department_id BIGINT NULL,name VARCHAR(255),description TEXT NULL,status VARCHAR(32),completed_at DATETIME NULL,archived_at DATETIME NULL,portal_publish_enabled TINYINT DEFAULT 1,public_project_enabled TINYINT DEFAULT 0,revision BIGINT DEFAULT 1,estimated_start DATE NULL,estimated_end DATE NULL,source_version VARCHAR(191),invoice_billing_period VARCHAR(32),project_invoice_auto_email TINYINT,created_by BIGINT NULL,updated_at DATETIME(6) NULL) ENGINE=InnoDB;CREATE TABLE project_retention_guards(project_public_id CHAR(32) PRIMARY KEY);CREATE TABLE project_changes(project_public_id CHAR(32),revision BIGINT,action_name VARCHAR(32),projection_sha256 CHAR(64),application_pk BIGINT NULL,command_id CHAR(36) NULL,actor_user_id BIGINT NULL,changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(project_public_id,revision));CREATE TABLE api_v2_project_external_bindings(application_pk BIGINT,external_id VARBINARY(764),project_public_id CHAR(32),project_revision BIGINT,project_projection_sha256 CHAR(64),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(application_pk,external_id),UNIQUE(application_pk,project_public_id));CREATE TABLE item_library(id BIGINT AUTO_INCREMENT PRIMARY KEY,entry_type VARCHAR(32) DEFAULT 'service',is_active TINYINT DEFAULT 1,portal_requestable TINYINT DEFAULT 1,portal_public_id CHAR(32),item_name LONGTEXT,portal_summary LONGTEXT,portal_category VARCHAR(100),portal_display_order INT,portal_geometry_requirement VARCHAR(16),portal_questions_json JSON) CHARACTER SET utf8mb4;CREATE TABLE source_mutation_sentinel(marker VARCHAR(64));CREATE TABLE public_links(marker VARCHAR(64));INSERT INTO api_v2_applications VALUES(3,'223e4567-e89b-42d3-a456-426614174000'),(4,'423e4567-e89b-42d3-a456-426614174000');INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_history_identity VALUES(1,'123e4567-e89b-42d3-a456-426614174000','323e4567-e89b-42d3-a456-426614174000');INSERT INTO api_v2_project_authorization_state(application_pk,authorization_generation) VALUES(3,0),(4,9);INSERT INTO organizations VALUES(10,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');INSERT INTO clients VALUES(11,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');INSERT INTO source_mutation_sentinel VALUES('source-sentinel');INSERT INTO public_links VALUES('public-link-sentinel')");
    }
}
