#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/../src/migrations/migration_lib.php';
require_once __DIR__.'/../src/services/ApiV2CapabilitiesKeyProvisioner.php';
require_once __DIR__.'/../src/services/LocalOperatorKeyReceipt.php';
use App\Services\ApiV2CapabilitiesKeyProvisioner;
use App\Services\LocalOperatorKeyReceipt;

try{
    ApiV2CapabilitiesKeyProvisioner::operator();
    $values=[];$flags=[];
    foreach(array_slice($argv,1) as $arg){
        if(in_array($arg,['--dry-run','--apply','--reconcile','--confirm-create-capabilities-key','--resume-prepared'],true)){
            if(isset($flags[$arg]))throw new RuntimeException('duplicate_option');$flags[$arg]=true;continue;
        }
        if(!preg_match('/^--(request-id|source-instance-id|history-epoch|application-id|plan-file|secret-file|reviewed-plan-sha256)=(.+)$/D',$arg,$m)||isset($values[$m[1]]))throw new RuntimeException('unknown_or_duplicate_option');
        $values[$m[1]]=$m[2];
    }
    $modes=array_intersect(array_keys($flags),['--dry-run','--apply','--reconcile']);
    if(count($modes)!==1||empty($values['plan-file']))throw new RuntimeException('explicit_mode_and_plan_file_required');
    $service=new ApiV2CapabilitiesKeyProvisioner(migration_connection());
    if(isset($flags['--dry-run'])){
        if(count($flags)!==1||array_diff(array_keys($values),['request-id','source-instance-id','history-epoch','application-id','plan-file','secret-file'])||count($values)!==6)throw new RuntimeException('invalid_dry_run_options');
        LocalOperatorKeyReceipt::safePath($values['plan-file']);LocalOperatorKeyReceipt::safePath($values['secret-file']);
        if($values['secret-file']===$values['plan-file'])throw new RuntimeException('distinct_private_files_required');
        $plan=['version'=>1,'provisioning'=>$service->dryRun(['requestId'=>$values['request-id'],'sourceInstanceId'=>$values['source-instance-id'],'historyEpoch'=>$values['history-epoch'],'applicationId'=>$values['application-id']]),'secretFile'=>$values['secret-file']];
        LocalOperatorKeyReceipt::writeNew($values['plan-file'],$plan);
        echo json_encode(['state'=>'dry_run','scope'=>ApiV2CapabilitiesKeyProvisioner::SCOPE,'planSha256'=>hash_file('sha256',$values['plan-file'])],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
    }
    if(array_diff(array_keys($values),['plan-file','reviewed-plan-sha256'])||count($values)!==2||!preg_match('/^[0-9a-f]{64}$/D',$values['reviewed-plan-sha256']))throw new RuntimeException('reviewed_plan_required');
    if(isset($flags['--apply'])&&!isset($flags['--confirm-create-capabilities-key']))throw new RuntimeException('explicit_creation_confirmation_required');
    if(isset($flags['--reconcile'])&&count($flags)!==1)throw new RuntimeException('invalid_reconcile_options');
    $plan=LocalOperatorKeyReceipt::read($values['plan-file']);
    if(!hash_equals($values['reviewed-plan-sha256'],hash_file('sha256',$values['plan-file']))||array_keys($plan)!==['version','provisioning','secretFile']||$plan['version']!==1||!is_string($plan['secretFile']))throw new RuntimeException('reviewed_plan_changed');
    $vault=new LocalOperatorKeyReceipt($plan['secretFile']);$vault->lock();$existed=$vault->exists();
    if(!$existed&&isset($flags['--reconcile']))throw new RuntimeException('private_secret_receipt_missing');
    if(!$existed&&isset($flags['--resume-prepared']))throw new RuntimeException('resume_requires_existing_receipt');
    $material=$existed?$vault->material($plan):$vault->create($plan);
    // An existing prepared file never silently authorizes a fresh insertion.
    // Reconcile first; an explicit resume flag is needed for a confirmed absence.
    $readOnly=isset($flags['--reconcile'])||($existed&&!isset($flags['--resume-prepared']));
    $vault->journal(['at'=>gmdate('c'),'operation'=>$readOnly?'reconcile':'apply','state'=>'attempting']);
    $result=$service->apply($plan['provisioning'],$material['secret'],$readOnly);
    $vault->journal(['at'=>gmdate('c'),'operation'=>$readOnly?'reconcile':'apply','result'=>$result]);
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    exit($result['state']==='not_committed'?3:0);
}catch(Throwable){
    if(isset($vault)&&$vault instanceof LocalOperatorKeyReceipt){try{$vault->journal(['at'=>gmdate('c'),'state'=>'held_reconciliation_required']);}catch(Throwable){}}
    // Neither PDO diagnostics, stack arguments, credentials nor key material are output.
    fwrite(STDERR,"Capabilities key provisioning held; reconcile the private receipt before retrying.\n");exit(1);
}
