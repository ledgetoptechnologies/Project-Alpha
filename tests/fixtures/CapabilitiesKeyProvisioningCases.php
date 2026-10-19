<?php
declare(strict_types=1);
namespace Tests\Fixtures;
use App\Services\ApiV2CapabilitiesKeyProvisioner;
use PDO;
use RuntimeException;

require_once dirname(__DIR__,2).'/src/services/ApiV2CapabilitiesKeyProvisioner.php';

final class CapabilityLostAckPDO extends PDO
{
    public bool $loseCommitAck=false;
    public function commit(): bool
    {
        $result=parent::commit();
        if($this->loseCommitAck){$this->loseCommitAck=false;throw new RuntimeException('synthetic_lost_commit_ack');}
        return $result;
    }
}

trait CapabilitiesKeyProvisioningCases
{
    private function input(): array{return ['requestId'=>'523e4567-e89b-42d3-a456-426614174000','sourceInstanceId'=>'123e4567-e89b-42d3-a456-426614174000','historyEpoch'=>'223e4567-e89b-42d3-a456-426614174000','applicationId'=>'323e4567-e89b-42d3-a456-426614174000'];}
    private function secret(): string{return 'pa_live_'.str_repeat('a',12).'_'.str_repeat('b',64);}
    private function service(): ApiV2CapabilitiesKeyProvisioner{return new ApiV2CapabilitiesKeyProvisioner($this->pdo);}
    private function countKeys(): int{return(int)$this->pdo->query('SELECT COUNT(*) FROM api_keys')->fetchColumn();}
    private function generation(): array{return $this->pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn()===false?[]:[(string)$this->pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn(),(string)$this->pdo->query('SELECT authorization_generation FROM api_v2_project_authorization_state WHERE application_pk=3')->fetchColumn()];}
    private function otherKey(): array{return$this->pdo->query('SELECT * FROM api_keys WHERE id=7')->fetch(PDO::FETCH_ASSOC);}
    public function testDryRunHasNoDatabaseWritesOrKeyMaterial(): void
    {
        $before=$this->otherKey();$plan=$this->service()->dryRun($this->input());
        self::assertSame(['4','5'],$this->generation());self::assertSame(1,$this->countKeys());self::assertSame($before,$this->otherKey());
        self::assertSame('api.capabilities.read',$plan['scope']);self::assertStringNotContainsString('pa_live_',json_encode($plan));
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM system_audit')->fetchColumn());
    }
    public function testApplyBindsExactlyOneScopeAndAuditsLocalOperatorWithoutChangingOtherKeys(): void
    {
        $before=$this->otherKey();$plan=$this->service()->dryRun($this->input());$r=$this->service()->apply($plan,$this->secret());
        self::assertSame('created',$r['state']);self::assertSame(2,$this->countKeys());self::assertSame(['5','6'],$this->generation());self::assertSame($before,$this->otherKey());
        $key=$this->pdo->query('SELECT * FROM api_keys WHERE id<>7')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('api.capabilities.read',$key['scopes']);self::assertSame(3,(int)$key['api_v2_application_id']);self::assertNull($key['allowed_ips']);
        $audit=$this->pdo->query('SELECT * FROM system_audit')->fetch(PDO::FETCH_ASSOC);self::assertNull($audit['user_id']);
        self::assertSame('local_operator',json_decode($audit['details'],true)['actor']['kind']);self::assertStringNotContainsString($this->secret(),$audit['details']);
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM api_v2_applications')->fetchColumn());
    }
    public function testReplayPrecedesAdvancedGenerationCheckAndDoesNotAdvanceAgain(): void
    {
        $plan=$this->service()->dryRun($this->input());$r=$this->service()->apply($plan,$this->secret());
        $this->pdo->exec('UPDATE api_v2_directory_authorization_state SET authorization_generation=10 WHERE application_pk=3');
        $again=$this->service()->apply($plan,$this->secret());self::assertSame('already_committed',$again['state']);self::assertSame($r['apiKeyId'],$again['apiKeyId']);
        self::assertSame(['10','6'],$this->generation());self::assertSame(2,$this->countKeys());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM system_audit')->fetchColumn());
    }
    public function testLostCommitAcknowledgementReconcilesWithoutNewKeyOrGenerationAdvance(): void
    {
        $plan=$this->service()->dryRun($this->input());$this->pdo->loseCommitAck=true;
        try{$this->service()->apply($plan,$this->secret());self::fail('expected acknowledgement loss');}catch(RuntimeException $e){self::assertSame('synthetic_lost_commit_ack',$e->getMessage());}
        self::assertSame('already_committed',$this->service()->apply($plan,$this->secret(),true)['state']);self::assertSame(2,$this->countKeys());self::assertSame(['5','6'],$this->generation());
    }
    public function testReconciliationOfAbsentCommitNeverCreatesAnything(): void
    {
        $plan=$this->service()->dryRun($this->input());self::assertSame('not_committed',$this->service()->apply($plan,$this->secret(),true)['state']);
        self::assertSame(1,$this->countKeys());self::assertSame(['4','5'],$this->generation());
    }
    public function testAuditFailureRollsBackBothKeyAndGenerationChanges(): void
    {
        $plan=$this->service()->dryRun($this->input());
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"CREATE TRIGGER fail_operator_audit BEFORE INSERT ON system_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit refusal'":"CREATE TRIGGER fail_operator_audit BEFORE INSERT ON system_audit BEGIN SELECT RAISE(ABORT,'synthetic audit refusal'); END";
        $this->pdo->exec($sql);
        try{$this->service()->apply($plan,$this->secret());self::fail('audit failure must refuse');}catch(RuntimeException $e){self::assertSame('audit_required',$e->getMessage());}
        finally{$this->pdo->exec('DROP TRIGGER fail_operator_audit');}
        self::assertSame(1,$this->countKeys());self::assertSame(['4','5'],$this->generation());
    }
    public function testChangedSourceOrGenerationRefusesBeforeCreation(): void
    {
        $plan=$this->service()->dryRun($this->input());$this->pdo->exec('UPDATE api_v2_project_authorization_state SET authorization_generation=6 WHERE application_pk=3');
        try{$this->service()->apply($plan,$this->secret());self::fail('generation changed');}catch(RuntimeException $e){self::assertSame('authorization_generation_changed',$e->getMessage());}
        $this->pdo->exec("UPDATE api_v2_history_identity SET history_epoch='623e4567-e89b-42d3-a456-426614174000'");
        try{$this->service()->apply($plan,$this->secret());self::fail('source changed');}catch(RuntimeException $e){self::assertSame('source_identity_changed',$e->getMessage());}
        self::assertSame(1,$this->countKeys());
    }
    public function testExistingKeyWithoutExactAuditOrWithChangedScopeCannotBeRepaired(): void
    {
        $plan=$this->service()->dryRun($this->input());$r=$this->service()->apply($plan,$this->secret());
        $this->pdo->prepare("UPDATE api_keys SET scopes='full' WHERE id=?")->execute([$r['apiKeyId']]);
        try{$this->service()->apply($plan,$this->secret());self::fail('broader key');}catch(RuntimeException $e){self::assertSame('reconciliation_required',$e->getMessage());}
        $this->pdo->prepare('UPDATE api_keys SET scopes=? WHERE id=?')->execute(['api.capabilities.read',$r['apiKeyId']]);$this->pdo->exec('DELETE FROM system_audit');
        try{$this->service()->apply($plan,$this->secret());self::fail('missing audit');}catch(RuntimeException $e){self::assertSame('reconciliation_required',$e->getMessage());}
        self::assertSame(2,$this->countKeys());self::assertSame(['5','6'],$this->generation());
    }
    public function testSameRequestWithDifferentSecretCannotDuplicateThroughAnotherFile(): void
    {
        $plan=$this->service()->dryRun($this->input());$this->service()->apply($plan,$this->secret());
        try{$this->service()->apply($plan,'pa_live_'.str_repeat('c',12).'_'.str_repeat('d',64));self::fail('duplicate request');}catch(RuntimeException $e){self::assertSame('reconciliation_required',$e->getMessage());}
        self::assertSame(2,$this->countKeys());self::assertSame(['5','6'],$this->generation());
    }
    public function testChangingScopeOrExistingApplicationPinsIsRefused(): void
    {
        $plan=$this->service()->dryRun($this->input());$plan['scope']='full';
        try{$this->service()->apply($plan,$this->secret());self::fail('invalid scope');}catch(RuntimeException $e){self::assertSame('invalid_plan',$e->getMessage());}
        $input=$this->input();$input['applicationId']='723e4567-e89b-42d3-a456-426614174000';
        try{$this->service()->dryRun($input);self::fail('new app');}catch(RuntimeException $e){self::assertSame('existing_application_required',$e->getMessage());}
        self::assertSame(1,$this->countKeys());
    }
    public function testFakeHumanSessionDoesNotBecomeLocalOperatorAuthority(): void
    {
        $_SESSION['user']=['id'=>1,'role'=>'admin'];
        try{$this->service()->dryRun($this->input());self::fail('fake session');}catch(RuntimeException $e){self::assertSame('local_root_cli_required',$e->getMessage());}
        finally{unset($_SESSION['user']);}
        self::assertSame(1,$this->countKeys());
    }
}
