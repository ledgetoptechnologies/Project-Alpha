<?php

declare(strict_types=1);

use App\Services\ExternalOpsConfigService;
use App\Services\PortalClientProvisioningService;
use App\Services\PortalProjectionOutboxSender;

require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../utils/cron_state.php';

$jobName='portal_client_provisioning_backfill';
try {
    $service=new PortalClientProvisioningService();
    $activation=$service->activateConfiguredConnection($pdo);
    $config=(new ExternalOpsConfigService())->load($pdo);
    $summary=$service->reconcileHistoricalBatch(
        $pdo,
        (string)($config['application_key']??''),
        25
    );
    $delivery=(new PortalProjectionOutboxSender())->deliverDue($pdo,50,null,20);
    $preflightCodes=(array)($summary['preflight_codes']??[]);
    $message=sprintf(
        'Activation %s (%s); ready %s; considered %d; completed %d; retrying %d; failed %d; remaining %d; portal delivered %d; portal retrying %d; portal dead-lettered %d',
        $activation['attempted']?'checked':'unchanged',
        $activation['transition_state'],
        $summary['ready']?'yes':'no',
        $summary['considered'],
        $summary['completed'],
        $summary['retrying'],
        $summary['failed'],
        $summary['remaining'],
        $delivery['delivered'],
        $delivery['failed'],
        $delivery['dead_lettered']
    );
    if($preflightCodes!==[])$message.='; preflight_codes='.implode(',',$preflightCodes);
    if ($summary['failed']>0 || $delivery['dead_lettered']>0) {
        cron_state_mark_failure($pdo,$jobName,new RuntimeException($message.'; repair the root failure and run the audited reconcile action.'));
    } else {
        cron_state_mark_success($pdo,$jobName,$message);
    }
    error_log('[portal_client_provisioning_backfill] '.$message);
    exit($summary['failed']>0 || $delivery['dead_lettered']>0 ? 1 : 0);
} catch (Throwable $error) {
    $code=substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12);
    error_log('[portal_client_provisioning_backfill] failed code='.$code);
    cron_state_mark_failure($pdo,$jobName,new RuntimeException('Portal client provisioning backfill failed; diagnostic code '.$code));
    exit(1);
}
