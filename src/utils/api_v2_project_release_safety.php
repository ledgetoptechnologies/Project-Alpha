<?php
declare(strict_types=1);

use App\Services\ProjectRevisionService;
require_once __DIR__.'/api_v2_project_backfill.php';
require_once __DIR__.'/api_v2_project_lifecycle.php';

/** Deterministic local proof; it neither grants scopes nor enables routes. */
function api_v2_project_backfill_attestation(PDO $pdo):array
{
    if($pdo->inTransaction())throw new LogicException('Project backfill attestation requires no active transaction.');
    $result=['attestationVersion'=>1,'complete'=>true,'source'=>0,'covered'=>0,'invalid'=>0,'missing'=>0,'drifted'=>0,'coverageDigest'=>hash('sha256','[]')];$coverage=[];
    foreach($pdo->query('SELECT * FROM projects ORDER BY public_id')->fetchAll(PDO::FETCH_ASSOC)as$project){$result['source']++;$project=api_v2_project_hydrate_relations($pdo,$project);$publicId=(string)($project['public_id']??'');$revision=(string)($project['revision']??'');if(preg_match('/^[0-9a-f]{32}$/D',$publicId)!==1||!ProjectRevisionService::positiveInteger($revision)){$result['invalid']++;continue;}
        $change=$pdo->prepare('SELECT projection_sha256 FROM project_changes WHERE project_public_id=? AND revision=?');$change->execute([$publicId,$revision]);$hash=$change->fetchColumn();if($hash===false){$result['missing']++;continue;}$expected=ProjectRevisionService::projectionHash($project);if(!is_string($hash)||!hash_equals($hash,$expected)){$result['drifted']++;continue;}$count=$pdo->prepare('SELECT COUNT(*) FROM project_changes WHERE project_public_id=? AND revision<=?');$count->execute([$publicId,$revision]);if((int)$count->fetchColumn()!==(int)$revision){$result['missing']++;continue;}$result['covered']++;$coverage[]=['publicId'=>$publicId,'revision'=>(int)$revision,'projectionSha256'=>$expected];}
    $result['complete']=$result['invalid']===0&&$result['missing']===0&&$result['drifted']===0;$result['coverageDigest']=hash('sha256',json_encode($coverage,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));return$result;
}

function api_v2_project_backfill_attestation_persist(PDO $pdo):string
{
    $proof=api_v2_project_backfill_attestation($pdo);if(!$proof['complete'])throw new RuntimeException('Project backfill is not complete.');$json=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$digest=hash('sha256',$json);$pdo->beginTransaction();try{$statement=$pdo->prepare('SELECT attestation_json FROM api_v2_project_backfill_attestations WHERE attestation_sha256=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$statement->execute([$digest]);$stored=$statement->fetchColumn();if($stored===false)$pdo->prepare('INSERT INTO api_v2_project_backfill_attestations(attestation_sha256,attestation_json) VALUES(?,?)')->execute([$digest,$json]);elseif(!hash_equals((string)$stored,$json))throw new RuntimeException('Project attestation digest conflict.');$pdo->commit();return$digest;}catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
}
