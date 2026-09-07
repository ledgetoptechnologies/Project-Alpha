<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ManagedDeliveryIntentSender;
use App\Services\ManagedDeliveryIntentSigner;
use App\Services\ManagedDeliveryService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ManagedDeliveryIntegrationTest extends TestCase
{
    private string|false $previousEncryptionKey;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        $this->previousEncryptionKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=managed-delivery-test-key');
    }

    protected function tearDown(): void
    {
        $this->previousEncryptionKey === false ? putenv('APP_ENCRYPTION_KEY') : putenv('APP_ENCRYPTION_KEY=' . $this->previousEncryptionKey);
    }

    public function testHistoricalSignerFixtureStillProtectsLegacyPendingRows(): void
    {
        $path = dirname(__DIR__) . '/fixtures/project-alpha-delivery-intent-v1.json';
        $fixture = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('f16d540bcfbcf4c77c356fc37e2c046a23a473ebec701d526e3b8d45f38c90e8', hash_file('sha256', $path));
        foreach ($fixture['cases'] as $case) {
            $headers = $this->headers(ManagedDeliveryIntentSigner::headers([
                'applicationKey'=>$fixture['applicationKey'], 'keyId'=>$fixture['keyId'],
                'secret'=>$fixture['testSecret'], 'authHeaders'=>[],
            ], $case['deliveryId'], 'https://ops.example' . $case['path'], $case['body'], $fixture['timestamp']));
            self::assertSame($case['signature'], $headers['x-portal-integration-signature']);
        }
    }

    public function testMigrationPreservesLegacyRowsAndRemovesDuplicateConfiguration(): void
    {
        $migration = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0084_unify_managed_delivery_external_ops.sql');
        self::assertStringContainsString("ENUM('legacy_profile','external_ops')", $migration);
        self::assertStringContainsString("DEFAULT 'legacy_profile'", $migration);
        self::assertStringContainsString('integration_profile_id BIGINT UNSIGNED NULL', $migration);
        self::assertStringContainsString("'managed_delivery_intent_url','managed_delivery_profile_id'", $migration);
        self::assertStringNotContainsString('UPDATE managed_delivery_intent_outbox SET destination_url', $migration);
        self::assertStringContainsString('legacy_transport_retired_manual_remediation_required', $migration);
    }

    public function testPreflightUsesOneStrictExternalOperationsEnvelopeWhileToggleIsOff(): void
    {
        $pdo = $this->database(false);
        $captured = [];
        $sharedId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $sender = new ManagedDeliveryIntentSender();
        $result = $sender->preflight($pdo, static function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
            $captured = compact('url','headers','body','timeout');
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return ['status'=>200,'body'=>json_encode([
                'ok'=>true,'event_id'=>$request['event_id'],'status'=>'completed',
                'result'=>['status'=>'ready','schemaVersion'=>1,'integrationEnabled'=>false,'portalSupported'=>true,'guestSupported'=>false,'revocationSupported'=>true],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        }, $sharedId);
        self::assertSame('https://ops.example/v1/project-alpha/events', $captured['url']);
        $outer = json_decode($captured['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['event_id','event_type','occurred_at','schema_version','application_key','intent_kind','intent'], array_keys($outer));
        self::assertSame('delivery.intent', $outer['event_type']);
        self::assertSame('preflight', $outer['intent_kind']);
        self::assertSame('delivery.intent:preflight:' . $sharedId, $outer['event_id']);
        self::assertSame($sharedId, $outer['intent']['deliveryId']);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt'], array_keys($outer['intent']));
        $headers = $this->headers($captured['headers']);
        self::assertSame($outer['event_id'], $headers['x-pa-event-id']);
        self::assertSame('sha256=' . hash_hmac('sha256', $headers['x-pa-timestamp'] . '.' . $captured['body'], str_repeat('s', 32)), $headers['x-pa-signature']);
        self::assertSame('opaque-id', $headers['cf-access-client-id']);
        self::assertFalse($result['integrationEnabled']);

        $pdo->prepare('UPDATE app_config SET config_value=? WHERE organization_id=0 AND config_key=?')->execute(['1',ManagedDeliveryService::ENABLED_KEY]);
        (new ManagedDeliveryService())->queue($pdo, ['delivery_id'=>$sharedId,'scope_type'=>'project','scope_public_id'=>str_repeat('a',32),'audience_type'=>'principal','audience_public_id'=>str_repeat('b',32)], 7);
        $accepted = $sender->deliverDeliveryId($pdo, $sharedId, static function (string $url, array $headers, string $body) use ($sharedId): array {
            $event=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            self::assertSame('delivery.intent:provision:' . $sharedId,$event['event_id']);
            return ['status'=>200,'body'=>json_encode(['ok'=>true,'event_id'=>$event['event_id'],'status'=>'completed','result'=>['receiptId'=>'same_inner_id_receipt','status'=>'accepted']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
        });
        self::assertSame(1,$accepted['accepted']);
    }

    public function testPreUpgradeHmacClaimsRemainDispatchableAndRevocable(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        $sender = new ManagedDeliveryIntentSender();
        $provisionId = 'a1a1a1a1-a1a1-41a1-81a1-a1a1a1a1a1a1';
        $revokeId = 'b2b2b2b2-b2b2-42b2-82b2-b2b2b2b2b2b2';
        $scope = str_repeat('a', 32);
        $audience = str_repeat('b', 32);

        $service->queue($pdo, [
            'delivery_id' => $provisionId,
            'scope_type' => 'project',
            'scope_public_id' => $scope,
            'audience_type' => 'principal',
            'audience_public_id' => $audience,
        ], 7);
        $legacyHash = hash('sha256', implode("\n", [
            '0',
            'project-alpha',
            'https://ops.example/v1/project-alpha/events',
            'external_ops_hmac_v1',
            json_encode([
                'CF-Access-Client-Id' => 'opaque-id',
                'CF-Access-Client-Secret' => 'opaque-secret',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            hash('sha256', str_repeat('s', 32)),
        ]));
        $storedHash = (string)$pdo->query("SELECT signing_contract_hash FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetchColumn();
        self::assertSame($legacyHash, $storedHash, 'The existing HMAC contract epoch must remain byte-for-byte stable.');

        $transport = static function (string $url, array $headers, string $body): array {
            $event = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'event_id' => $event['event_id'],
                'status' => 'completed',
                'result' => ['receiptId' => $event['intent_kind'] === 'revoke' ? 'revoke_hmac_legacy' : 'hmac_legacy', 'status' => 'accepted'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        };
        self::assertSame(1, $sender->deliverDeliveryId($pdo, $provisionId, $transport)['accepted']);
        $service->queueRevocation($pdo, $provisionId, $revokeId, 7);
        self::assertSame($legacyHash, (string)$pdo->query("SELECT signing_contract_hash FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetchColumn());
        self::assertSame(1, $sender->deliverDeliveryId($pdo, $revokeId, $transport)['accepted']);
    }

    public function testProvisionDuplicateReplayAndRevocationRetainReceipts(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        $sender = new ManagedDeliveryIntentSender();
        $provisionId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $service->queue($pdo, [
            'delivery_id'=>$provisionId,'scope_type'=>'project','scope_public_id'=>str_repeat('a',32),
            'audience_type'=>'principal','audience_public_id'=>str_repeat('b',32),'label'=>'Johnson Road',
        ], 7);
        $pinned = $pdo->query("SELECT transport_mode,integration_profile_id,destination_url,pinned_application_key FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('external_ops', $pinned['transport_mode']);
        self::assertNull($pinned['integration_profile_id']);
        self::assertSame('https://ops.example/v1/project-alpha/events', $pinned['destination_url']);

        $seen=[];
        $transport = static function (string $url, array $headers, string $body) use (&$seen): array {
            $outer=json_decode($body,true,16,JSON_THROW_ON_ERROR);$seen[]=compact('url','headers','outer','body');
            $receipt=$outer['intent_kind']==='revoke'?'revoke_receipt_01':'receipt_01';
            return ['status'=>200,'body'=>json_encode(['ok'=>true,'event_id'=>$outer['event_id'],'status'=>'duplicate','result'=>['receiptId'=>$receipt,'status'=>'accepted']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
        };
        self::assertSame(1,$sender->deliverDeliveryId($pdo,$provisionId,$transport)['accepted']);
        self::assertSame('receipt_01',$pdo->query("SELECT receipt_id FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetchColumn());
        self::assertSame('provision',$seen[0]['outer']['intent_kind']);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt','scope','audience','accessMode','expiresAt','label','notify'],array_keys($seen[0]['outer']['intent']));

        $revokeId='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $service->queueRevocation($pdo,$provisionId,$revokeId,7);
        self::assertSame(1,$sender->deliverDeliveryId($pdo,$revokeId,$transport)['accepted']);
        self::assertSame('https://ops.example/v1/project-alpha/events',$seen[1]['url']);
        self::assertSame('revoke',$seen[1]['outer']['intent_kind']);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt','receiptId','reasonCode'],array_keys($seen[1]['outer']['intent']));
        self::assertNotNull($pdo->query("SELECT revoked_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetchColumn());
    }

    public function testChangedPayloadConflictAndMalformedSuccessFailClosed(): void
    {
        $pdo=$this->database();$service=new ManagedDeliveryService();$sender=new ManagedDeliveryIntentSender();
        foreach(['eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee','ffffffff-ffff-4fff-8fff-ffffffffffff'] as $id){$service->queue($pdo,['delivery_id'=>$id,'scope_type'=>'project','scope_public_id'=>str_repeat('a',32),'audience_type'=>'principal','audience_public_id'=>str_repeat('b',32)],7);}
        $conflict=$sender->deliverDeliveryId($pdo,'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',static fn():array=>['status'=>409,'body'=>'{}']);
        self::assertSame(1,$conflict['dead_lettered']);
        $bad=$sender->deliverDeliveryId($pdo,'ffffffff-ffff-4fff-8fff-ffffffffffff',static fn():array=>['status'=>200,'body'=>'{"ok":true,"event_id":"wrong","status":"completed","result":{"receiptId":"secret","status":"accepted"}}']);
        self::assertSame(1,$bad['dead_lettered']);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM managed_delivery_intent_outbox WHERE receipt_id IS NOT NULL')->fetchColumn());
    }

    public function testSameKindRetryKeepsExactEventIdAndBody(): void
    {
        $pdo=$this->database();$id='56565656-5656-4656-8656-565656565656';
        (new ManagedDeliveryService())->queue($pdo,['delivery_id'=>$id,'scope_type'=>'project','scope_public_id'=>str_repeat('a',32),'audience_type'=>'principal','audience_public_id'=>str_repeat('b',32)],7);
        $sender=new ManagedDeliveryIntentSender();$seen=[];
        $first=$sender->deliverDeliveryId($pdo,$id,static function(string$url,array$headers,string$body)use(&$seen):array{$seen[]=$body;return['status'=>503,'body'=>''];});
        self::assertSame(1,$first['retrying']);
        $pdo->prepare("UPDATE managed_delivery_intent_outbox SET next_attempt_at='2000-01-01 00:00:00.000000' WHERE delivery_id=?")->execute([$id]);
        $second=$sender->deliverDeliveryId($pdo,$id,static function(string$url,array$headers,string$body)use(&$seen):array{$seen[]=$body;$event=json_decode($body,true,16,JSON_THROW_ON_ERROR);return['status'=>200,'body'=>json_encode(['ok'=>true,'event_id'=>$event['event_id'],'status'=>'duplicate','result'=>['receiptId'=>'stable_retry','status'=>'accepted']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];});
        self::assertSame(1,$second['accepted']);
        self::assertCount(2,$seen);
        self::assertSame($seen[0],$seen[1]);
        self::assertSame('delivery.intent:provision:'.$id,json_decode($seen[0],true,16,JSON_THROW_ON_ERROR)['event_id']);
    }

    public function testPendingLegacyRowCannotUseRetiredDirectTransport(): void
    {
        $pdo=$this->database();$id='34343434-3434-4434-8434-343434343434';
        $payload='{"schemaVersion":1,"applicationKey":"legacy-app","deliveryId":"'.$id.'","occurredAt":"2026-09-04T12:00:00.000Z","scope":{"type":"project","publicId":"'.str_repeat('a',32).'"},"audience":{"type":"principal","publicId":"'.str_repeat('b',32).'"},"accessMode":"portal","expiresAt":null,"label":null,"notify":true}';
        $credentials=crypto_encrypt(json_encode(['currentSecret'=>str_repeat('l',32),'previousSecret'=>'','authHeaders'=>[]],JSON_THROW_ON_ERROR));
        $pdo->prepare('INSERT INTO portal_integration_profiles VALUES(1,?,?,?,?,?,?,?,?,?,?)')->execute(['legacy-app','Legacy',1,1,'legacy-v1',null,null,$credentials,3,2]);
        $hash=$this->legacyHash(1,'legacy-app','https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-v1',[],str_repeat('l',32));
        $pdo->prepare("INSERT INTO managed_delivery_intent_outbox(delivery_id,transport_mode,integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts,actor_user_id,scope_type,scope_public_id,audience_type,audience_public_id,access_mode,request_fingerprint,payload_json) VALUES(?,'legacy_profile',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,1,'https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-app','legacy-v1',$hash,3,2,7,'project',str_repeat('a',32),'principal',str_repeat('b',32),'portal',hash('sha256',$payload),$payload]);
        $calls=0;$result=(new ManagedDeliveryIntentSender())->deliverDeliveryId($pdo,$id,static function()use(&$calls):array{$calls++;return['status'=>202,'body'=>'{"receiptId":"legacy_receipt","status":"accepted"}'];});
        self::assertSame(0,$calls);
        self::assertSame(1,$result['retrying']);
        self::assertNull($pdo->query("SELECT receipt_id FROM managed_delivery_intent_outbox WHERE delivery_id='{$id}'")->fetchColumn());
        self::assertStringNotContainsString('ManagedDeliveryIntentSigner::headers', (string)file_get_contents(dirname(__DIR__,2).'/src/services/ManagedDeliveryIntentSender.php'));
    }

    public function testLegacyAcceptedReceiptCannotBeRevokedThroughCurrentReceiver(): void
    {
        $pdo=$this->database();
        $provisionId='12121212-1212-4212-8212-121212121212';
        $revokeId='23232323-2323-4232-8232-232323232323';
        $scope=str_repeat('a',32);$audience=str_repeat('b',32);
        $provision='{"schemaVersion":1,"applicationKey":"legacy-app","deliveryId":"'.$provisionId.'","occurredAt":"2026-09-04T12:00:00.000Z","scope":{"type":"project","publicId":"'.$scope.'"},"audience":{"type":"principal","publicId":"'.$audience.'"},"accessMode":"portal","expiresAt":null,"label":null,"notify":true}';
        $hash=$this->legacyHash(1,'legacy-app','https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-v1',[],str_repeat('l',32));
        $pdo->prepare("INSERT INTO managed_delivery_intent_outbox(delivery_id,transport_mode,integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts,actor_user_id,scope_type,scope_public_id,audience_type,audience_public_id,access_mode,request_fingerprint,payload_json,delivered_at,receipt_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$provisionId,'legacy_profile',1,'https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-app','legacy-v1',$hash,3,2,7,'project',$scope,'principal',$audience,'portal',hash('sha256',$provision),$provision,'2026-09-04 12:00:01.000000','legacy_receipt']);

        try {
            (new ManagedDeliveryService())->queueRevocation($pdo,$provisionId,$revokeId,7);
            self::fail('A legacy receipt must not be sent to the current receiver.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('original delivery system',$error->getMessage());
        }
        self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetchColumn());
        $historical=$pdo->query("SELECT transport_mode,receipt_id,revoked_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('legacy_profile',$historical['transport_mode']);
        self::assertSame('legacy_receipt',$historical['receipt_id']);
        self::assertNull($historical['revoked_at']);
    }

    public function testExplicitLegacyRevocationRetryRemainsBlockedWithoutMutation(): void
    {
        $pdo=$this->database();
        $provisionId='12121212-1212-4212-8212-121212121212';
        $revokeId='23232323-2323-4232-8232-232323232323';
        $scope=str_repeat('a',32);$audience=str_repeat('b',32);
        $provision='{"schemaVersion":1,"applicationKey":"legacy-app","deliveryId":"'.$provisionId.'","occurredAt":"2026-09-04T12:00:00.000Z","scope":{"type":"project","publicId":"'.$scope.'"},"audience":{"type":"principal","publicId":"'.$audience.'"},"accessMode":"portal","expiresAt":null,"label":null,"notify":true}';
        $revoke='{"schemaVersion":1,"applicationKey":"legacy-app","deliveryId":"'.$revokeId.'","occurredAt":"2026-09-04T12:01:00.000Z","receiptId":"legacy_receipt","reasonCode":"project_alpha_delivery_revoked"}';
        $hash=$this->legacyHash(1,'legacy-app','https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-v1',[],str_repeat('l',32));
        $insert=$pdo->prepare("INSERT INTO managed_delivery_intent_outbox(delivery_id,intent_type,target_delivery_id,transport_mode,integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts,actor_user_id,scope_type,scope_public_id,audience_type,audience_public_id,access_mode,request_fingerprint,payload_json,delivered_at,dead_lettered_at,receipt_id,last_error_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert->execute([$provisionId,'provision',null,'legacy_profile',1,'https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-app','legacy-v1',$hash,3,2,7,'project',$scope,'principal',$audience,'portal',hash('sha256',$provision),$provision,'2026-09-04 12:00:01.000000',null,'legacy_receipt',null]);
        $insert->execute([$revokeId,'revoke',$provisionId,'legacy_profile',1,'https://legacy.example/api/internal/project-alpha/delivery-intents','legacy-app','legacy-v1',$hash,3,2,7,'project',$scope,'principal',$audience,'portal',hash('sha256',$revoke),$revoke,null,'2026-09-04 12:02:00.000000',null,'legacy_transport_retired_manual_remediation_required']);

        try {
            (new ManagedDeliveryService())->requeueRevocation($pdo,$revokeId);
            self::fail('A legacy revocation must not be rebound to the current receiver.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('cannot be rebound automatically',$error->getMessage());
        }
        $row=$pdo->query("SELECT transport_mode,integration_profile_id,destination_url,pinned_application_key,dead_lettered_at,last_error_code,payload_json FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('legacy_profile',$row['transport_mode']);
        self::assertSame(1,$row['integration_profile_id']);
        self::assertSame('https://legacy.example/api/internal/project-alpha/delivery-intents',$row['destination_url']);
        self::assertSame('legacy-app',$row['pinned_application_key']);
        self::assertNotNull($row['dead_lettered_at']);
        self::assertSame('legacy_transport_retired_manual_remediation_required',$row['last_error_code']);
        self::assertSame('legacy-app',json_decode($row['payload_json'],true,16,JSON_THROW_ON_ERROR)['applicationKey']);
    }

    public function testUiKeepsOnlyOptInPolicyBesideSingleExternalOperationsConnection(): void
    {
        $root=dirname(__DIR__,2);$view=(string)file_get_contents($root.'/src/views/pages/settings/links.php');$handler=(string)file_get_contents($root.'/src/controllers/settings/links_handler.php');
        self::assertStringContainsString('name="managed_delivery_enabled"',$view);
        self::assertStringContainsString('name="managed_delivery_guest_links_enabled"',$view);
        self::assertStringContainsString('Custom integrations',$view);
        self::assertStringNotContainsString('name="managed_delivery_intent_url"',$view);
        self::assertStringNotContainsString('name="managed_delivery_profile_id"',$view);
        self::assertStringNotContainsString("'intent_url' =>",$handler);
        self::assertStringNotContainsString("'profile_id' =>",$handler);
        self::assertStringContainsString('Manual remediation required',$view);
        self::assertStringContainsString('Use original system',$view);
    }

    private function database(bool $managedEnabled=true): PDO
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE app_config(organization_id INTEGER NOT NULL,config_key TEXT NOT NULL,config_value TEXT NOT NULL,PRIMARY KEY(organization_id,config_key));
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,display_label TEXT,enabled INTEGER,delivery_enabled INTEGER,delivery_key_id TEXT,delivery_previous_key_id TEXT,delivery_previous_valid_until TEXT,delivery_credentials_enc TEXT,delivery_timeout_seconds INTEGER,delivery_max_attempts INTEGER);
CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivered_at TEXT,dead_lettered_at TEXT,is_revocation INTEGER DEFAULT 0);
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT); CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT); CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,archived INTEGER,deleted_at TEXT); CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,status TEXT); CREATE TABLE portal_principals(id INTEGER PRIMARY KEY,public_id TEXT,enabled INTEGER,revoked_at TEXT);
CREATE TABLE managed_delivery_intent_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,delivery_id TEXT NOT NULL UNIQUE,intent_type TEXT NOT NULL DEFAULT 'provision',target_delivery_id TEXT,transport_mode TEXT NOT NULL DEFAULT 'legacy_profile',integration_profile_id INTEGER,destination_url TEXT NOT NULL,pinned_application_key TEXT NOT NULL,signing_key_id TEXT NOT NULL,signing_contract_hash TEXT NOT NULL,delivery_timeout_seconds INTEGER NOT NULL,delivery_max_attempts INTEGER NOT NULL,actor_user_id INTEGER,scope_type TEXT NOT NULL,scope_public_id TEXT NOT NULL,audience_type TEXT NOT NULL,audience_public_id TEXT NOT NULL,access_mode TEXT NOT NULL DEFAULT 'portal',request_fingerprint TEXT NOT NULL,payload_json TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT,receipt_id TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
SQL);
        require_once dirname(__DIR__,2).'/src/utils/crypto.php';
        $credentials=crypto_encrypt(json_encode(['access_client_id'=>'opaque-id','access_client_secret'=>'opaque-secret','hmac_secret'=>str_repeat('s',32)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $configs=[ManagedDeliveryService::ENABLED_KEY=>$managedEnabled?'1':'0',ManagedDeliveryService::GUEST_KEY=>'0','external_ops_enabled'=>'1','external_ops_label'=>'Operations','external_ops_application_key'=>'project-alpha','external_ops_webhook_url'=>'https://ops.example/v1/project-alpha/events','external_ops_timeout_seconds'=>'5','external_ops_max_attempts'=>'3','external_ops_credentials_enc'=>$credentials];
        $save=$pdo->prepare('INSERT INTO app_config VALUES(0,?,?)');foreach($configs as$key=>$value)$save->execute([$key,$value]);
        $pdo->exec("INSERT INTO projects VALUES(1,'".str_repeat('a',32)."','Johnson Road','active')");$pdo->exec("INSERT INTO portal_principals VALUES(1,'".str_repeat('b',32)."',1,NULL)");return$pdo;
    }

    /** @param array<string,string> $headers */
    private function legacyHash(int $profileId,string$app,string$url,string$keyId,array$headers,string$secret):string{ksort($headers,SORT_STRING);return hash('sha256',$profileId."\n".$app."\n".$url."\n".$keyId."\n".json_encode($headers,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n".hash('sha256',$secret));}
    /** @return array<string,string> */
    private function headers(array $headers):array{$out=[];foreach($headers as$header){[$name,$value]=explode(':',$header,2);$out[strtolower($name)]=trim($value);}return$out;}
}
