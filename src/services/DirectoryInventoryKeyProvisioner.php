<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../utils/api_v2_application_provisioning.php';
require_once __DIR__ . '/../utils/api_v2_capabilities.php';
require_once __DIR__ . '/ApiV2CapabilitiesKeyProvisioner.php';

/** Dedicated two-scope reader issuance, not a general key-management API. */
final class DirectoryInventoryKeyProvisioner
{
    public const SCOPES = ['api.capabilities.read','directory.inventory.read'];
    private const SCOPE_STORAGE = 'api.capabilities.read,directory.inventory.read';
    public const ACTION = 'api_key.directory_inventory_provision';

    public static function operator(): array
    {
        return ApiV2CapabilitiesKeyProvisioner::operator();
    }

    public function __construct(private PDO $pdo) {}

    private static function input(array $input): array
    {
        $keys=['requestId','sourceInstanceId','historyEpoch','applicationId'];
        if (count($input)!==count($keys) || array_diff($keys,array_keys($input))) throw new RuntimeException('invalid_request');
        $normalized=[];
        foreach ($keys as $key) {
            if (!is_string($input[$key]) || !api_v2_application_provision_uuid_v4_valid($input[$key])) throw new RuntimeException('invalid_request');
            $normalized[$key]=$input[$key];
        }
        if (!api_v2_identity_is_valid(['source_instance_id'=>$normalized['sourceInstanceId'],
            'application_id'=>$normalized['applicationId'],'history_epoch'=>$normalized['historyEpoch']])) throw new RuntimeException('invalid_request');
        return $normalized;
    }

    private static function requestHash(array $input): string
    {
        return hash('sha256',json_encode(['kind'=>'directory_inventory_observer_v1','input'=>self::input($input),'scope'=>self::SCOPE_STORAGE],JSON_THROW_ON_ERROR));
    }

    private static function name(array $input): string
    {
        return 'Directory inventory observer '.$input['requestId'];
    }

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as &$entry) $entry=self::canonical($entry);
        unset($entry);return $value;
    }

    private function lock(array $input): array
    {
        $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $identity=$this->pdo->query('SELECT source_instance_id,history_epoch FROM api_v2_history_identity WHERE singleton=1'.$suffix)->fetch(PDO::FETCH_ASSOC);
        if (!$identity || $identity['source_instance_id']!==$input['sourceInstanceId'] || $identity['history_epoch']!==$input['historyEpoch']) throw new RuntimeException('source_identity_changed');
        $application=$this->pdo->prepare('SELECT id,application_id FROM api_v2_applications WHERE application_id=?'.$suffix);
        $application->execute([$input['applicationId']]);$row=$application->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['application_id']!==$input['applicationId']) throw new RuntimeException('existing_application_required');
        return $row;
    }

    private function begin(): void
    {
        self::operator();
        if ($this->pdo->inTransaction() || !api_v2_application_existing_binding_schema_ready($this->pdo)
            || !api_v2_application_provision_table_exists($this->pdo,'system_audit')) throw new RuntimeException('provisioning_schema_unavailable');
        $driver=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['mysql','sqlite'],true)) throw new RuntimeException('unsupported_database');
        if ($driver==='mysql') {
            $tables=['api_keys','system_audit','api_v2_history_identity','api_v2_applications','api_v2_directory_authorization_state','api_v2_project_authorization_state'];
            $rows=$this->pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_NAME IN ('".implode("','",$tables)."')")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==count($tables) || array_filter($rows,static fn(array $row): bool=>strcasecmp((string)$row['ENGINE'],'InnoDB')!==0)) throw new RuntimeException('transactional_tables_required');
        }
        $this->pdo->beginTransaction();
    }

    /** No key generation, audit insertion, binding, or generation change. */
    public function dryRun(array $input): array
    {
        $input=self::input($input);$this->begin();
        try {
            $app=$this->lock($input);
            $states=api_v2_application_existing_binding_authorization_states($this->pdo,[(int)$app['id']],true)[(int)$app['id']];
            $this->pdo->rollBack();
            return ['version'=>1,'input'=>$input,'scope'=>self::SCOPE_STORAGE,'requestSha256'=>self::requestHash($input),
                'directoryGeneration'=>$states['directory'],'projectGeneration'=>$states['project']];
        } catch (Throwable $error) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    private static function validatePlan(array $plan): array
    {
        $keys=['version','input','scope','requestSha256','directoryGeneration','projectGeneration'];
        if (count($plan)!==count($keys) || array_diff($keys,array_keys($plan)) || $plan['version']!==1
            || $plan['scope']!==self::SCOPE_STORAGE || !is_array($plan['input'])) throw new RuntimeException('invalid_plan');
        $input=self::input($plan['input']);
        if ($plan['requestSha256']!==self::requestHash($input)
            || !api_v2_application_provision_generation_valid($plan['directoryGeneration'],true)
            || !api_v2_application_provision_generation_valid($plan['projectGeneration'],true)) throw new RuntimeException('invalid_plan');
        return $input;
    }
    public static function validateReviewedPlan(array $plan): void { self::validatePlan($plan); }

    /** The caller must have durably saved the immutable secret receipt first.
     * Reconciliation never inserts or repairs a key/audit/binding. */
    public function apply(array $plan,#[\SensitiveParameter] string $secret,bool $reconcileOnly=false): array
    {
        $input=self::validatePlan($plan);
        if (preg_match('/^pa_live_([0-9a-f]{12})_[0-9a-f]{64}$/D',$secret,$parts)!==1) throw new RuntimeException('invalid_secret_receipt');
        $hash=hash('sha256',$secret);$this->begin();
        try {
            $app=$this->lock($input);$pk=(int)$app['id'];
            $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $lookup=$this->pdo->prepare('SELECT id,name,key_prefix,scopes,allowed_ips,revoked_at,api_v2_application_id FROM api_keys WHERE key_hash=?'.$suffix);
            $lookup->execute([$hash]);$found=$lookup->fetchAll(PDO::FETCH_ASSOC);$key=count($found)===1?$found[0]:null;
            if (count($found)>1) throw new RuntimeException('reconciliation_required');
            $json=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(details,'$.input.requestId'))":"json_extract(details,'$.input.requestId')";
            $audits=$this->pdo->prepare("SELECT id,user_id,entity_id,details FROM system_audit WHERE action=? AND entity_type='api_key' AND {$json}=?".$suffix);
            $audits->execute([self::ACTION,$input['requestId']]);$prior=$audits->fetchAll(PDO::FETCH_ASSOC);
            // Test for a prior commit before comparing generations: the commit
            // itself advanced them, and later ordinary PA work may advance more.
            if ($key || $prior) {
                if (!$key || count($prior)!==1 || (int)$prior[0]['entity_id']!==(int)$key['id'] || $prior[0]['user_id']!==null
                    || $key['name']!==self::name($input) || $key['key_prefix']!==$parts[1] || $key['scopes']!==self::SCOPE_STORAGE
                    || $key['allowed_ips']!==null || $key['revoked_at']!==null || (int)$key['api_v2_application_id']!==$pk) throw new RuntimeException('reconciliation_required');
                $detail=json_decode((string)$prior[0]['details'],true,512,JSON_THROW_ON_ERROR);
                $expectedDetail=['requestSha256'=>$plan['requestSha256'],'input'=>$input,'scope'=>self::SCOPE_STORAGE,'actor'=>self::operator(),
                    'directoryBefore'=>(string)$plan['directoryGeneration'],'projectBefore'=>(string)$plan['projectGeneration']];
                if (self::canonical($detail)!==self::canonical($expectedDetail)) throw new RuntimeException('reconciliation_required');
                $this->pdo->rollBack();
                return ['state'=>'already_committed','apiKeyId'=>(int)$key['id'],'applicationId'=>$input['applicationId']];
            }
            $collision=$this->pdo->prepare('SELECT id FROM api_keys WHERE name=?'.$suffix);$collision->execute([self::name($input)]);
            if ($collision->fetchColumn()!==false) throw new RuntimeException('request_identity_requires_reconciliation');
            $states=api_v2_application_existing_binding_authorization_states($this->pdo,[$pk],true);
            if ($reconcileOnly) {$this->pdo->rollBack();return ['state'=>'not_committed','applicationId'=>$input['applicationId']];}
            if ($states[$pk]['directory']!==(string)$plan['directoryGeneration'] || $states[$pk]['project']!==(string)$plan['projectGeneration']) throw new RuntimeException('authorization_generation_changed');
            $insert=$this->pdo->prepare('INSERT INTO api_keys(name,key_prefix,key_hash,scopes,allowed_ips,api_v2_application_id) VALUES(?,?,?,?,NULL,?)');
            $insert->execute([self::name($input),$parts[1],$hash,self::SCOPE_STORAGE,$pk]);$id=(int)$this->pdo->lastInsertId();
            if ($id<1) throw new RuntimeException('key_creation_failed');
            api_v2_application_existing_binding_advance_authorization_states($this->pdo,$states);
            $detail=['requestSha256'=>$plan['requestSha256'],'input'=>$input,'scope'=>self::SCOPE_STORAGE,'actor'=>self::operator(),
                'directoryBefore'=>$states[$pk]['directory'],'projectBefore'=>$states[$pk]['project']];
            // This grant requires an audit in the same transaction. Do not use
            // the ordinary best-effort logger: its diagnostics can expose SQL
            // details before the CLI's generic failure handler takes over.
            try {
                $insertAudit=$this->pdo->prepare('INSERT INTO system_audit (user_id,action,entity_type,entity_id,details,ip_address,user_agent) VALUES (NULL,?,?,?,?,NULL,NULL)');
                $insertAudit->execute([self::ACTION,'api_key',$id,json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            } catch (Throwable) {
                throw new RuntimeException('audit_required');
            }
            $auditId=(int)$this->pdo->lastInsertId();
            $verify=$this->pdo->prepare("SELECT user_id,action,entity_type,entity_id,details FROM system_audit WHERE id=?");$verify->execute([$auditId]);$audit=$verify->fetch(PDO::FETCH_ASSOC);
            if (!$audit || $audit['user_id']!==null || $audit['action']!==self::ACTION || $audit['entity_type']!=='api_key'
                || (int)$audit['entity_id']!==$id || self::canonical(json_decode((string)$audit['details'],true,512,JSON_THROW_ON_ERROR))!==self::canonical($detail)) throw new RuntimeException('audit_required');
            $lookup->execute([$hash]);$created=$lookup->fetchAll(PDO::FETCH_ASSOC);
            if (count($created)!==1 || (int)$created[0]['id']!==$id || $created[0]['name']!==self::name($input)
                || $created[0]['key_prefix']!==$parts[1] || $created[0]['scopes']!==self::SCOPE_STORAGE
                || $created[0]['allowed_ips']!==null || $created[0]['revoked_at']!==null || (int)$created[0]['api_v2_application_id']!==$pk) throw new RuntimeException('key_verification_failed');
            $this->pdo->commit();
            return ['state'=>'created','apiKeyId'=>$id,'applicationId'=>$input['applicationId']];
        } catch (Throwable $error) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }
}
