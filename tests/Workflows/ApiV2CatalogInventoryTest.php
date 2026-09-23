<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiV2CatalogInventoryTest extends TestCase
{
    private PDO $pdo;
    private array $headers=['source'=>'123e4567-e89b-42d3-a456-426614174000','application'=>'223e4567-e89b-42d3-a456-426614174000','epoch'=>'323e4567-e89b-42d3-a456-426614174000'];

    protected function setUp():void
    {
        require_once dirname(__DIR__,2).'/src/utils/api_v2_catalog_inventory.php';
        require_once dirname(__DIR__,2).'/src/utils/api_scopes.php';
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);CREATE TABLE item_library(id INTEGER PRIMARY KEY,portal_public_id TEXT,item_name TEXT,portal_summary TEXT,portal_category TEXT,portal_display_order INTEGER,portal_geometry_requirement TEXT,portal_questions_json TEXT,is_active INTEGER,portal_requestable INTEGER,entry_type TEXT);INSERT INTO api_keys VALUES(7,3,NULL);INSERT INTO api_v2_applications VALUES(3,'{$this->headers['application']}');INSERT INTO api_v2_history_identity VALUES(1,'{$this->headers['source']}','{$this->headers['epoch']}');");
        $insert=$this->pdo->prepare('INSERT INTO item_library VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([1,str_repeat('b',32),'Second',null,'Mapping',2,'required','[]',1,1,'service']);
        $insert->execute([2,str_repeat('a',32),'First','Summary','Survey',1,'optional',json_encode([['id'=>'acreage','label'=>'Acres','type'=>'number','required'=>true,'helpText'=>null,'minimum'=>0]]) ,1,1,'service']);
        $insert->execute([3,str_repeat('c',32),'Private',null,'Internal',0,'none','[]',1,0,'service']);
        $insert->execute([4,str_repeat('d',32),'Requestable fee',null,'Fees',0,'none','[]',1,1,'fee']);
    }

    public function testReturnsBoundedDeterministicFullSnapshot():void
    {
        $first=api_v2_catalog_inventory_read($this->pdo,null,1,7,$this->headers,'request-one');
        self::assertSame(200,$first['status']);$payload=$first['payload'];
        self::assertSame(['apiVersion','sourceInstanceId','applicationId','historyEpoch','requestId','snapshotId','totalCount','items','nextCursor'],array_keys($payload));
        self::assertSame(2,$payload['totalCount']);self::assertCount(1,$payload['items']);self::assertSame(str_repeat('a',32),$payload['items'][0]['publicId']);self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/',$payload['items'][0]['version']);self::assertNotNull($payload['nextCursor']);
        self::assertNotContains(str_repeat('d',32),array_column($payload['items'],'publicId'));
        $second=api_v2_catalog_inventory_read($this->pdo,$payload['nextCursor'],1,7,$this->headers,'request-two');
        self::assertSame(200,$second['status']);self::assertSame($payload['snapshotId'],$second['payload']['snapshotId']);self::assertSame(str_repeat('b',32),$second['payload']['items'][0]['publicId']);self::assertNull($second['payload']['nextCursor']);
    }

    public function testRejectsMixedSnapshotAfterEditOrRemoval():void
    {
        $first=api_v2_catalog_inventory_read($this->pdo,null,1,7,$this->headers,'request-one');
        $this->pdo->exec("UPDATE item_library SET portal_summary='Changed' WHERE id=1");
        $changed=api_v2_catalog_inventory_read($this->pdo,$first['payload']['nextCursor'],1,7,$this->headers,'request-two');
        self::assertSame(409,$changed['status']);self::assertSame('catalog_snapshot_changed',$changed['payload']['error']['code']);
    }

    public function testAcceptsCanonicalOptionalFieldsAndQuestionVariants():void
    {
        $questions=[
            ['id'=>'format','label'=>'Output format','type'=>'select','required'=>false,'options'=>[['label'=>'GeoTIFF','value'=>'geotiff']]],
            ['id'=>'accuracy','label'=>'Accuracy','type'=>'number','required'=>true,'helpText'=>null,'minimum'=>0,'maximum'=>2.5],
            ['id'=>'notes','label'=>'Notes','type'=>'text','required'=>false],
        ];
        $update=$this->pdo->prepare('UPDATE item_library SET portal_summary=NULL,portal_questions_json=? WHERE id=1');$update->execute([json_encode($questions,JSON_THROW_ON_ERROR)]);
        $result=api_v2_catalog_inventory_read($this->pdo,null,10,7,$this->headers,'compatibility');
        self::assertSame(200,$result['status']);self::assertSame($questions,$result['payload']['items'][1]['questions']);self::assertNull($result['payload']['items'][1]['summary']);
    }

    public function testByteBudgetPaginatesMaximumValidMultibyteItems():void
    {
        $options=[];for($i=0;$i<50;$i++)$options[]=['value'=>str_pad((string)$i,2,'0',STR_PAD_LEFT).str_repeat('😀',98),'label'=>str_repeat('😀',200)];
        $questions=[];for($i=0;$i<10;$i++)$questions[]=['id'=>'choice_'.$i,'label'=>str_repeat('😀',200),'type'=>'multi-select','required'=>true,'helpText'=>str_repeat('😀',500),'options'=>$options];
        $update=$this->pdo->prepare('UPDATE item_library SET item_name=?,portal_summary=?,portal_category=?,portal_questions_json=?,is_active=1,portal_requestable=1 WHERE id IN (1,2,3)');
        $update->execute([str_repeat('😀',255),str_repeat('😀',1000),str_repeat('😀',100),json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $cursor=null;$seen=[];$pages=0;
        do{$result=api_v2_catalog_inventory_read($this->pdo,$cursor,3,7,$this->headers,'maximum-page-'.(++$pages));self::assertSame(200,$result['status']);self::assertNotEmpty($result['payload']['items']);self::assertSame(3,$result['payload']['totalCount']);self::assertLessThanOrEqual(API_V2_CATALOG_RESPONSE_MAX_BYTES,strlen(json_encode($result['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));array_push($seen,...array_column($result['payload']['items'],'publicId'));$cursor=$result['payload']['nextCursor'];}while($cursor!==null);
        self::assertGreaterThan(1,$pages);self::assertSame([str_repeat('a',32),str_repeat('b',32),str_repeat('c',32)],$seen);
    }

    public function testRequiresExactIdentityAndValidCursor():void
    {
        self::assertSame(['status'=>409],api_v2_catalog_inventory_read($this->pdo,null,10,7,array_replace($this->headers,['epoch'=>'wrong']),'request'));
        self::assertSame(['status'=>400],api_v2_catalog_inventory_read($this->pdo,'not-a-cursor',10,7,$this->headers,'request'));
        self::assertFalse(api_key_has_scope('full','catalog.inventory.read',false));
    }

    public function testRouteFeatureRemainsDefaultOff():void
    {
        $previous=getenv('APP_API_V2_CATALOG_INVENTORY_ENABLED');
        try{putenv('APP_API_V2_CATALOG_INVENTORY_ENABLED');self::assertFalse(api_v2_enabled('APP_API_V2_CATALOG_INVENTORY_ENABLED'));putenv('APP_API_V2_CATALOG_INVENTORY_ENABLED=true');self::assertTrue(api_v2_enabled('APP_API_V2_CATALOG_INVENTORY_ENABLED'));}
        finally{$previous===false?putenv('APP_API_V2_CATALOG_INVENTORY_ENABLED'):putenv('APP_API_V2_CATALOG_INVENTORY_ENABLED='.$previous);}
    }
}
