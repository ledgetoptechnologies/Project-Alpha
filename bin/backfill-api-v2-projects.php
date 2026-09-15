#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/migrations/migration_lib.php';
require_once __DIR__ . '/../src/utils/api_v2_project_backfill.php';

$cursor=null;$limit=100;$apply=false;$dry=false;$confirmed=false;$maintenance=false;
foreach(array_slice($argv??[],1)as$argument){
    if(str_starts_with($argument,'--cursor='))$cursor=substr($argument,9);
    elseif(str_starts_with($argument,'--limit='))$limit=substr($argument,8);
    elseif($argument==='--dry-run')$dry=true;elseif($argument==='--apply')$apply=true;
    elseif($argument==='--confirm-api-v2-project-backfill')$confirmed=true;
    elseif($argument==='--maintenance-window-confirmed')$maintenance=true;
    else{fwrite(STDERR,"Unknown option.\n");exit(2);}
}
if(!is_string($limit)||preg_match('/^[1-9][0-9]{0,2}$/D',$limit)!==1||(int)$limit>500||($apply&&($dry||!$confirmed||!$maintenance))){
    fwrite(STDERR,"Usage: php bin/backfill-api-v2-projects.php [--cursor=id] [--limit=1..500] [--dry-run]\n");
    fwrite(STDERR,"Default is dry-run. Apply requires --apply --confirm-api-v2-project-backfill --maintenance-window-confirmed.\n");exit(2);
}
try{$result=api_v2_project_backfill(migration_connection(),$cursor,(int)$limit,!$apply);fwrite(STDOUT,($result['dryRun']?'Dry run':'Applied').': scanned '.$result['scanned'].'; inserted '.$result['inserted'].'; current '.$result['skippedCurrent'].'; presentation revocations '.$result['presentationRevoked'].".\n");if($result['nextCursor']!==null)fwrite(STDOUT,'Resume cursor: '.$result['nextCursor'].".\n");}
catch(Throwable $error){fwrite(STDERR,"Project backfill refused: ".$error->getMessage()."\n");exit(1);}
