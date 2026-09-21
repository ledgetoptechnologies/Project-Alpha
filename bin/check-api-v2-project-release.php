#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__.'/../src/migrations/migration_lib.php';
require_once __DIR__.'/../src/utils/api_v2_project_release_safety.php';

$arguments=array_slice($argv??[],1);$prefix='--attestation-sha256=';
if(count($arguments)!==1||!str_starts_with($arguments[0],$prefix)||preg_match('/^[0-9a-f]{64}$/D',$digest=substr($arguments[0],strlen($prefix)))!==1){fwrite(STDERR,"Usage: php bin/check-api-v2-project-release.php --attestation-sha256=<64 lowercase hex>\n");exit(2);}
try{if(!api_v2_project_backfill_attestation_receipt_is_current(migration_connection(),$digest))throw new RuntimeException('The supplied Project release attestation is missing or stale.');fwrite(STDOUT,"Project release attestation is current: $digest.\n");}
catch(Throwable$error){fwrite(STDERR,'Project release check refused: '.$error->getMessage()."\n");exit(1);}
