#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/../src/migrations/migration_lib.php';
require_once __DIR__.'/../src/services/OpsSnapshotReadKeyProvisioner.php';
require_once __DIR__.'/../src/services/LocalOperatorKeyReceipt.php';
use App\Services\OpsSnapshotReadKeyProvisioner;
use App\Services\LocalOperatorKeyReceipt;

try {
    OpsSnapshotReadKeyProvisioner::operator();
    $values=[]; $flags=[];
    foreach(array_slice($argv,1) as $arg) {
        if(in_array($arg,['--dry-run','--apply','--reconcile','--confirm-create-staging-snapshot-key','--resume-prepared'],true)) {
            if(isset($flags[$arg]))throw new RuntimeException('duplicate_option'); $flags[$arg]=true; continue;
        }
        if(!preg_match('/^--(request-id|source-instance-id|application-key|staging-origin|plan-file|secret-file|reviewed-plan-sha256)=(.+)$/D',$arg,$m)
            || isset($values[$m[1]]))throw new RuntimeException('unknown_or_duplicate_option');
        $values[$m[1]]=$m[2];
    }
    if(count(array_intersect(array_keys($flags),['--dry-run','--apply','--reconcile']))!==1 || empty($values['plan-file']))throw new RuntimeException('explicit_mode_and_plan_required');
    $service=new OpsSnapshotReadKeyProvisioner(migration_connection());
    if(isset($flags['--dry-run'])) {
        if(count($flags)!==1 || count($values)!==6 || array_diff(array_keys($values),['request-id','source-instance-id','application-key','staging-origin','plan-file','secret-file']))throw new RuntimeException('invalid_dry_run_options');
        LocalOperatorKeyReceipt::safePath($values['plan-file']); LocalOperatorKeyReceipt::safePath($values['secret-file']);
        if($values['plan-file']===$values['secret-file'])throw new RuntimeException('distinct_private_files_required');
        $plan=['version'=>1,'provisioning'=>$service->dryRun(['requestId'=>$values['request-id'],'sourceInstanceId'=>$values['source-instance-id'],
            'applicationKey'=>$values['application-key'],'stagingOrigin'=>$values['staging-origin']]),'secretFile'=>$values['secret-file']];
        LocalOperatorKeyReceipt::writeNew($values['plan-file'],$plan);
        echo json_encode(['state'=>'dry_run','scope'=>OpsSnapshotReadKeyProvisioner::SCOPE,'contract'=>'legacy_instance_scoped','planSha256'=>hash_file('sha256',$values['plan-file'])],JSON_THROW_ON_ERROR).PHP_EOL; exit(0);
    }
    if(count($values)!==2 || array_diff(array_keys($values),['plan-file','reviewed-plan-sha256']) || !preg_match('/^[0-9a-f]{64}$/D',$values['reviewed-plan-sha256']))throw new RuntimeException('reviewed_plan_required');
    if(isset($flags['--apply']) && (!isset($flags['--confirm-create-staging-snapshot-key']) || count(array_diff(array_keys($flags),['--apply','--confirm-create-staging-snapshot-key','--resume-prepared']))))throw new RuntimeException('explicit_staging_creation_confirmation_required');
    if(isset($flags['--reconcile']) && count($flags)!==1)throw new RuntimeException('invalid_reconcile_options');
    $plan=LocalOperatorKeyReceipt::read($values['plan-file']);
    if(!hash_equals($values['reviewed-plan-sha256'],hash_file('sha256',$values['plan-file'])) || array_keys($plan)!==['version','provisioning','secretFile']
        || $plan['version']!==1 || !is_array($plan['provisioning']) || !is_string($plan['secretFile']))throw new RuntimeException('reviewed_plan_changed');
    OpsSnapshotReadKeyProvisioner::validateReviewedPlan($plan['provisioning']);
    $vault=new LocalOperatorKeyReceipt($plan['secretFile']); $vault->lock(); $existed=$vault->exists();
    if(!$existed && (isset($flags['--reconcile']) || isset($flags['--resume-prepared'])))throw new RuntimeException('existing_private_receipt_required');
    $material=$existed?$vault->material($plan):$vault->create($plan);
    $readOnly=isset($flags['--reconcile']) || ($existed && !isset($flags['--resume-prepared']));
    $vault->journal(['at'=>gmdate('c'),'operation'=>$readOnly?'reconcile':'apply','state'=>'attempting']);
    $result=$service->apply($plan['provisioning'],$material['secret'],$readOnly);
    $vault->journal(['at'=>gmdate('c'),'operation'=>$readOnly?'reconcile':'apply','result'=>$result]);
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL; exit($result['state']==='not_committed'?3:0);
} catch(Throwable) {
    if(isset($vault) && $vault instanceof LocalOperatorKeyReceipt) { try { $vault->journal(['at'=>gmdate('c'),'state'=>'held_reconciliation_required']); } catch(Throwable) {} }
    fwrite(STDERR,"Staging snapshot key provisioning held; reconcile the private receipt before retrying.\n"); exit(1);
}
