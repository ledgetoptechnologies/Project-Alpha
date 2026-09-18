<?php
declare(strict_types=1);

use App\Services\ProjectRevisionService;
require_once __DIR__.'/api_v2_project_backfill.php';
require_once __DIR__.'/api_v2_project_lifecycle.php';

function api_v2_project_release_table_exists(PDO$pdo,string$table):bool
{
    $s=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?")
        :$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $s->execute([$table]);return$s->fetchColumn()!==false;
}

function api_v2_project_release_column_exists(PDO$pdo,string$table,string$column):bool
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        foreach($pdo->query("PRAGMA table_info('".str_replace("'","''",$table)."')")->fetchAll(PDO::FETCH_ASSOC)as$row)if(($row['name']??null)===$column)return true;
        return false;
    }
    $s=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return$s->fetchColumn()!==false;
}

function api_v2_project_release_file_digest(string$path):string
{
    $contents=file_get_contents($path);if($contents===false)throw new RuntimeException('Project release source is unavailable.');return hash('sha256',str_replace(["\r\n","\r"],"\n",$contents));
}

/** @return list<string> */
function api_v2_project_release_file_accepted_digests(string$path):array
{
    $contents=file_get_contents($path);if($contents===false)throw new RuntimeException('Project release source is unavailable.');$lf=str_replace(["\r\n","\r"],"\n",$contents);return array_values(array_unique([hash('sha256',$contents),hash('sha256',$lf),hash('sha256',str_replace("\n","\r\n",$lf))]));
}

function api_v2_project_release_invalid_rows(PDO$pdo,string$sql,callable$invalid):int
{
    $count=0;foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)as$row)if($invalid($row))$count++;return$count;
}

/** @param list<string> $columns */
function api_v2_project_release_mysql_index_matches(PDO$pdo,string$table,string$name,bool$unique,array$columns):bool
{
    $s=$pdo->prepare('SELECT non_unique,column_name,sub_part FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? ORDER BY seq_in_index');$s->execute([$table,$name]);$rows=$s->fetchAll(PDO::FETCH_NUM);
    if(count($rows)!==count($columns))return false;foreach($rows as$i=>$row)if((int)$row[0]!==($unique?0:1)||(string)$row[1]!==$columns[$i]||$row[2]!==null)return false;return true;
}

function api_v2_project_release_mysql_fk_matches(PDO$pdo,string$table,string$name,string$column,string$referencedTable,string$referencedColumn):bool
{
    $s=$pdo->prepare("SELECT k.column_name,k.referenced_table_name,k.referenced_column_name,r.delete_rule,r.update_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.constraint_schema=DATABASE() AND k.table_name=? AND k.constraint_name=? ORDER BY k.ordinal_position");$s->execute([$table,$name]);$rows=$s->fetchAll(PDO::FETCH_NUM);
    return count($rows)===1&&(string)$rows[0][0]===$column&&(string)$rows[0][1]===$referencedTable&&(string)$rows[0][2]===$referencedColumn&&(string)$rows[0][3]==='RESTRICT'&&(string)$rows[0][4]==='NO ACTION';
}

function api_v2_project_release_code_digest():string
{
    $root=dirname(__DIR__,2);$paths=['src/services/ProjectRevisionService.php','src/utils/api_v2_project_lifecycle.php','src/utils/api_v2_project_sync.php','src/utils/api_v2_project_backfill.php','src/utils/api_v2_project_release_safety.php'];$parts=[];
    foreach($paths as$path)$parts[]=$path.':'.api_v2_project_release_file_digest($root.'/'.$path);
    return hash('sha256',implode("\n",$parts));
}

/** Read-only deterministic evidence; receipt state is evaluated separately. */
function api_v2_project_backfill_evidence(PDO$pdo):array
{
    if($pdo->inTransaction())throw new LogicException('Project backfill attestation requires no active transaction.');
    $root=dirname(__DIR__,2);$migrationPath=$root.'/database/migrations/0102_api_v2_project_synchronization.sql';$schemaDigest=api_v2_project_release_file_digest($migrationPath);$acceptedSchemaDigests=api_v2_project_release_file_accepted_digests($migrationPath);
    $violations=['schema'=>0,'migration'=>0,'constraint'=>0,'index'=>0,'foreign_key'=>0,'authorization'=>0,'binding'=>0,'receipt'=>0,'identity'=>0,'missing_history'=>0,'projection_drift'=>0];
    $required=[
        'api_v2_project_authorization_state'=>['application_pk','authorization_generation','updated_at'],
        'api_v2_project_external_bindings'=>['application_pk','external_id','project_public_id','project_revision','project_projection_sha256','created_at','updated_at'],
        'api_v2_project_command_receipts'=>['application_pk','history_epoch','command_id','command_type','request_sha256','external_id','project_public_id','expected_revision','expected_prior_revision','expected_projection_sha256','expected_authorization_generation','result_revision','result_projection_sha256','result_authorization_generation','result_portal_publish_enabled','result_public_project_enabled','created_at'],
        'api_v2_project_backfill_attestations'=>['attestation_sha256','attestation_json','created_at'],
    ];
    foreach(array_merge(['schema_migrations','api_v2_applications','projects','project_changes'],array_keys($required))as$table)if(!api_v2_project_release_table_exists($pdo,$table))$violations['schema']++;
    foreach($required as$table=>$columns)if(api_v2_project_release_table_exists($pdo,$table))foreach($columns as$column)if(!api_v2_project_release_column_exists($pdo,$table,$column))$violations['schema']++;
    if($violations['schema']===0){
        $hasChecksum=api_v2_project_release_column_exists($pdo,'schema_migrations','checksum');
        $s=$pdo->prepare('SELECT filename'.($hasChecksum?',checksum':'').' FROM schema_migrations WHERE version=102');$s->execute();$migration=$s->fetch(PDO::FETCH_ASSOC);
        $ledgerDigest=strtolower((string)($migration['checksum']??''));if(!$migration||($migration['filename']??null)!=='0102_api_v2_project_synchronization.sql'||!$hasChecksum||!in_array($ledgerDigest,$acceptedSchemaDigests,true))$violations['migration']++;
        $constraintNames=['chk_api_v2_project_auth_generation','chk_api_v2_project_binding_external','chk_api_v2_project_binding_public','chk_api_v2_project_binding_revision','chk_api_v2_project_binding_hash','chk_api_v2_project_command_shape','chk_api_v2_project_command_expected_generation','chk_api_v2_project_command_result_generation'];
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $present=array_fill_keys($pdo->query("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND constraint_type='CHECK'")->fetchAll(PDO::FETCH_COLUMN),true);
            foreach($constraintNames as$name)if(!isset($present[$name]))$violations['constraint']++;
            $indexes=[['api_v2_project_authorization_state','PRIMARY',true,['application_pk']],['api_v2_project_external_bindings','PRIMARY',true,['application_pk','external_id']],['api_v2_project_external_bindings','uq_api_v2_project_binding_public',true,['application_pk','project_public_id']],['api_v2_project_external_bindings','fk_api_v2_project_binding_project',false,['project_public_id']],['api_v2_project_command_receipts','PRIMARY',true,['application_pk','history_epoch','command_id']],['api_v2_project_command_receipts','fk_api_v2_project_command_project',false,['project_public_id']],['api_v2_project_backfill_attestations','PRIMARY',true,['attestation_sha256']]];
            foreach($indexes as[$table,$name,$unique,$columns])if(!api_v2_project_release_mysql_index_matches($pdo,$table,$name,$unique,$columns))$violations['index']++;
            $foreignKeys=[['api_v2_project_authorization_state','fk_api_v2_project_auth_application','application_pk','api_v2_applications','id'],['api_v2_project_external_bindings','fk_api_v2_project_binding_application','application_pk','api_v2_applications','id'],['api_v2_project_external_bindings','fk_api_v2_project_binding_project','project_public_id','projects','public_id'],['api_v2_project_command_receipts','fk_api_v2_project_command_application','application_pk','api_v2_applications','id'],['api_v2_project_command_receipts','fk_api_v2_project_command_project','project_public_id','projects','public_id']];
            foreach($foreignKeys as[$table,$name,$column,$referencedTable,$referencedColumn])if(!api_v2_project_release_mysql_fk_matches($pdo,$table,$name,$column,$referencedTable,$referencedColumn))$violations['foreign_key']++;
        }else{
            $source=preg_replace('/\s+/',' ',file_get_contents($migrationPath)?:'');foreach($constraintNames as$name)if(!str_contains($source,'CONSTRAINT '.$name))$violations['constraint']++;
            foreach(['application_pk BIGINT UNSIGNED NOT NULL PRIMARY KEY','PRIMARY KEY (application_pk,external_id)','UNIQUE KEY uq_api_v2_project_binding_public (application_pk,project_public_id)','PRIMARY KEY (application_pk,history_epoch,command_id)','attestation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY']as$definition)if(!str_contains($source,$definition))$violations['index']++;
            foreach(['CONSTRAINT fk_api_v2_project_auth_application FOREIGN KEY (application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT','CONSTRAINT fk_api_v2_project_binding_application FOREIGN KEY (application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT','CONSTRAINT fk_api_v2_project_binding_project FOREIGN KEY (project_public_id) REFERENCES projects(public_id) ON DELETE RESTRICT','CONSTRAINT fk_api_v2_project_command_application FOREIGN KEY (application_pk) REFERENCES api_v2_applications(id) ON DELETE RESTRICT','CONSTRAINT fk_api_v2_project_command_project FOREIGN KEY (project_public_id) REFERENCES projects(public_id) ON DELETE RESTRICT']as$definition)if(!str_contains($source,$definition))$violations['foreign_key']++;
        }
        $violations['authorization']+=(int)$pdo->query('SELECT COUNT(*) FROM api_v2_applications app LEFT JOIN api_v2_project_authorization_state auth ON auth.application_pk=app.id WHERE auth.application_pk IS NULL OR auth.authorization_generation<0 OR auth.authorization_generation>9223372036854775807')->fetchColumn();
        $violations['binding']+=api_v2_project_release_invalid_rows($pdo,'SELECT binding.*,app.id app_exists,project.id project_exists FROM api_v2_project_external_bindings binding LEFT JOIN api_v2_applications app ON app.id=binding.application_pk LEFT JOIN projects project ON project.public_id=binding.project_public_id',static fn(array$row):bool=>$row['app_exists']===null||$row['project_exists']===null||(int)$row['project_revision']<1||preg_match('/^[0-9a-f]{64}$/D',(string)$row['project_projection_sha256'])!==1);
        $violations['receipt']+=api_v2_project_release_invalid_rows($pdo,'SELECT receipt.*,app.id app_exists,project.id project_exists FROM api_v2_project_command_receipts receipt LEFT JOIN api_v2_applications app ON app.id=receipt.application_pk LEFT JOIN projects project ON project.public_id=receipt.project_public_id',static fn(array$row):bool=>$row['app_exists']===null||$row['project_exists']===null||preg_match('/^[0-9a-f]{64}$/D',(string)$row['request_sha256'])!==1||preg_match('/^[0-9a-f]{64}$/D',(string)$row['result_projection_sha256'])!==1);
    }
    $coverage=[];$source=0;$covered=0;
    if($violations['schema']===0){
        foreach($pdo->query('SELECT * FROM projects ORDER BY public_id')->fetchAll(PDO::FETCH_ASSOC)as$project){
            $source++;$project=api_v2_project_hydrate_relations($pdo,$project);$publicId=(string)($project['public_id']??'');$revision=(string)($project['revision']??'');
            if(preg_match('/^[0-9a-f]{32}$/D',$publicId)!==1||!ProjectRevisionService::positiveInteger($revision)){$violations['identity']++;continue;}
            $change=$pdo->prepare('SELECT projection_sha256 FROM project_changes WHERE project_public_id=? AND revision=?');$change->execute([$publicId,$revision]);$hash=$change->fetchColumn();
            if($hash===false){$violations['missing_history']++;continue;}$expected=ProjectRevisionService::projectionHash($project);
            if(!is_string($hash)||!hash_equals($hash,$expected)){$violations['projection_drift']++;continue;}
            $count=$pdo->prepare('SELECT COUNT(*) FROM project_changes WHERE project_public_id=? AND revision<=?');$count->execute([$publicId,$revision]);if((int)$count->fetchColumn()!==(int)$revision){$violations['missing_history']++;continue;}
            $covered++;$coverage[]=['publicId'=>$publicId,'revision'=>(int)$revision,'projectionSha256'=>$expected];
        }
    }
    return['attestationVersion'=>3,'schemaReady'=>$violations['schema']===0&&$violations['migration']===0&&$violations['constraint']===0&&$violations['index']===0&&$violations['foreign_key']===0,'evidenceComplete'=>array_sum($violations)===0,'source'=>$source,'covered'=>$covered,'schemaSha256'=>$schemaDigest,'codeSha256'=>api_v2_project_release_code_digest(),'coverageDigest'=>hash('sha256',json_encode($coverage,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'violations'=>$violations];
}

function api_v2_project_backfill_evidence_json(array$evidence):string{return json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}

/** Public release result is complete only after immutable receipt readback. */
function api_v2_project_backfill_attestation(PDO$pdo):array
{
    $evidence=api_v2_project_backfill_evidence($pdo);$json=api_v2_project_backfill_evidence_json($evidence);$digest=hash('sha256',$json);$current=false;
    if($evidence['evidenceComplete']&&api_v2_project_release_table_exists($pdo,'api_v2_project_backfill_attestations')){$s=$pdo->prepare('SELECT attestation_json FROM api_v2_project_backfill_attestations WHERE attestation_sha256=?');$s->execute([$digest]);$stored=$s->fetchColumn();$current=$stored!==false&&hash_equals($json,(string)$stored);}
    return$evidence+['attestationSha256'=>$digest,'receiptCurrent'=>$current,'complete'=>$evidence['evidenceComplete']&&$current];
}

function api_v2_project_backfill_attestation_persist(PDO$pdo):string
{
    $evidence=api_v2_project_backfill_evidence($pdo);if(!$evidence['evidenceComplete'])throw new RuntimeException('Project release evidence is incomplete.');$json=api_v2_project_backfill_evidence_json($evidence);$digest=hash('sha256',$json);
    $pdo->beginTransaction();try{$s=$pdo->prepare('SELECT attestation_json FROM api_v2_project_backfill_attestations WHERE attestation_sha256=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$digest]);$stored=$s->fetchColumn();if($stored===false)$pdo->prepare('INSERT INTO api_v2_project_backfill_attestations(attestation_sha256,attestation_json) VALUES(?,?)')->execute([$digest,$json]);elseif(!hash_equals((string)$stored,$json))throw new RuntimeException('Project attestation digest conflict.');$pdo->commit();}catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    if(!api_v2_project_backfill_attestation_receipt_is_current($pdo,$digest))throw new RuntimeException('Project attestation readback failed.');return$digest;
}

function api_v2_project_backfill_attestation_receipt_is_current(PDO$pdo,string$digest):bool
{
    if($pdo->inTransaction()||preg_match('/^[0-9a-f]{64}$/D',$digest)!==1)return false;$attestation=api_v2_project_backfill_attestation($pdo);return$attestation['complete']&&hash_equals($digest,$attestation['attestationSha256']);
}
