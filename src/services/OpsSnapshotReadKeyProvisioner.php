<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/ApiV2CapabilitiesKeyProvisioner.php';

/** Root-local issuance of the existing, instance-scoped legacy snapshot grant.
 * The reviewed origin is an operator assertion, not provider attestation. */
final class OpsSnapshotReadKeyProvisioner
{
    public const SCOPE = 'ops.sync.read';
    public const ACTION = 'api_key.staging_snapshot_provision';
    private const ORIGINS = ['https://pa-stage-one.ledgetoptechnologies.com', 'https://pa-stage-two.ledgetoptechnologies.com'];
    private const CONFIG_KEYS = ['external_ops_application_key', 'external_ops_enabled', 'external_ops_webhook_url'];

    public static function operator(): array { return ApiV2CapabilitiesKeyProvisioner::operator(); }
    public function __construct(private PDO $pdo) {}

    private static function input(array $input): array
    {
        $keys = ['requestId', 'sourceInstanceId', 'applicationKey', 'stagingOrigin'];
        if (count($input)!==count($keys) || array_diff($keys,array_keys($input))) throw new RuntimeException('invalid_request');
        foreach (['requestId','sourceInstanceId'] as $key) {
            if (!is_string($input[$key]) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$input[$key])) throw new RuntimeException('invalid_request');
        }
        if (!is_string($input['applicationKey']) || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D',$input['applicationKey'])
            || !in_array($input['stagingOrigin'],self::ORIGINS,true)) throw new RuntimeException('invalid_staging_target');
        return array_intersect_key($input,array_flip($keys));
    }
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as &$entry) $entry=self::canonical($entry);
        unset($entry); return $value;
    }
    private static function digest(array $value): string { return hash('sha256',json_encode(self::canonical($value),JSON_THROW_ON_ERROR)); }
    private static function requestHash(array $input): string { return self::digest(['kind'=>'staging_legacy_snapshot_v1','input'=>self::input($input),'scope'=>self::SCOPE]); }
    private static function name(array $input): string { return 'Staging Ops snapshot '.$input['requestId']; }

    private function begin(): void
    {
        self::operator();
        if ($this->pdo->inTransaction()) throw new RuntimeException('nested_transaction_refused');
        $driver=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['mysql','sqlite'],true)) throw new RuntimeException('unsupported_database');
        foreach (['sync_source_identity','app_config','api_keys','system_audit'] as $table) {
            if (!api_v2_application_provision_table_exists($this->pdo,$table)) throw new RuntimeException('provisioning_schema_unavailable');
        }
        if ($driver==='mysql') {
            $rows=$this->pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_NAME IN ('sync_source_identity','app_config','api_keys','system_audit')")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==4 || array_filter($rows,static fn(array $row): bool => strcasecmp((string)$row['ENGINE'],'InnoDB')!==0)) throw new RuntimeException('transactional_tables_required');
        }
        // Check columns without migrating or repairing the installation.
        $this->pdo->query('SELECT id,name,key_prefix,key_hash,scopes,allowed_ips,revoked_at,api_v2_application_id FROM api_keys WHERE 1=0');
        $this->pdo->query('SELECT id,user_id,action,entity_type,entity_id,details,ip_address,user_agent FROM system_audit WHERE 1=0');
        $this->pdo->beginTransaction();
    }

    /** Existing source row serializes even requests using different receipt paths. */
    private function lock(array $input): string
    {
        $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $rows=$this->pdo->query('SELECT source_instance_id FROM sync_source_identity WHERE singleton=1'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)!==1 || $rows[0]['source_instance_id']!==$input['sourceInstanceId']) throw new RuntimeException('legacy_source_identity_changed');
        $statement=$this->pdo->prepare('SELECT config_key,config_value FROM app_config WHERE organization_id=0 AND config_key IN (?,?,?) ORDER BY config_key'.$suffix);
        $statement->execute(self::CONFIG_KEYS);
        $values=array_fill_keys(self::CONFIG_KEYS,null);
        $seen=[];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($seen[$row['config_key']])) throw new RuntimeException('ambiguous_application_configuration');
            $seen[$row['config_key']]=true; $values[$row['config_key']]=(string)$row['config_value'];
        }
        if ($values['external_ops_application_key']!==$input['applicationKey']) throw new RuntimeException('configured_application_changed');
        return self::digest($values);
    }

    /** No key generation, writes, bindings or authorization-generation changes. */
    public function dryRun(array $input): array
    {
        $input=self::input($input); $this->begin();
        try {
            $configuration=$this->lock($input); $this->pdo->rollBack();
            return ['version'=>1,'contract'=>'legacy_instance_scoped','input'=>$input,'scope'=>self::SCOPE,
                'requestSha256'=>self::requestHash($input),'configurationSha256'=>$configuration];
        } catch (Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $error; }
    }
    private static function validatePlan(array $plan): array
    {
        $keys=['version','contract','input','scope','requestSha256','configurationSha256'];
        if (count($plan)!==count($keys) || array_diff($keys,array_keys($plan)) || $plan['version']!==1
            || $plan['contract']!=='legacy_instance_scoped' || $plan['scope']!==self::SCOPE || !is_array($plan['input'])) throw new RuntimeException('invalid_plan');
        $input=self::input($plan['input']);
        if ($plan['requestSha256']!==self::requestHash($input) || !is_string($plan['configurationSha256'])
            || !preg_match('/^[0-9a-f]{64}$/D',$plan['configurationSha256'])) throw new RuntimeException('invalid_plan');
        return $input;
    }
    /** Validate reviewed metadata before the CLI generates private key material. */
    public static function validateReviewedPlan(array $plan): void { self::validatePlan($plan); }
    private static function matchesKey(array $key,array $input,string $prefix): bool
    {
        return $key['name']===self::name($input) && $key['key_prefix']===$prefix && $key['scopes']===self::SCOPE
            && $key['allowed_ips']===null && $key['revoked_at']===null && $key['api_v2_application_id']===null;
    }

    /** Caller must durably save the immutable private secret receipt first.
     * Reconcile never inserts, repairs, rebinds or reactivates a key. */
    public function apply(array $plan,#[\SensitiveParameter] string $secret,bool $reconcileOnly=false): array
    {
        $input=self::validatePlan($plan);
        if (!preg_match('/^pa_live_([0-9a-f]{12})_[0-9a-f]{64}$/D',$secret,$parts)) throw new RuntimeException('invalid_secret_receipt');
        $hash=hash('sha256',$secret); $this->begin();
        try {
            $configuration=$this->lock($input);
            $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $lookup=$this->pdo->prepare('SELECT id,name,key_prefix,scopes,allowed_ips,revoked_at,api_v2_application_id FROM api_keys WHERE key_hash=?'.$suffix);
            $lookup->execute([$hash]); $keys=$lookup->fetchAll(PDO::FETCH_ASSOC);
            $json=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(details,'$.input.requestId'))":"json_extract(details,'$.input.requestId')";
            $audits=$this->pdo->prepare("SELECT id,user_id,entity_id,details FROM system_audit WHERE action=? AND entity_type='api_key' AND {$json}=?".$suffix);
            $audits->execute([self::ACTION,$input['requestId']]); $prior=$audits->fetchAll(PDO::FETCH_ASSOC);
            $detail=['requestSha256'=>$plan['requestSha256'],'input'=>$input,'scope'=>self::SCOPE,
                'contract'=>'legacy_instance_scoped','configurationSha256'=>$plan['configurationSha256'],'actor'=>self::operator()];
            if ($keys || $prior) {
                if (count($keys)!==1 || count($prior)!==1 || !self::matchesKey($keys[0],$input,$parts[1])
                    || (int)$prior[0]['entity_id']!==(int)$keys[0]['id'] || $prior[0]['user_id']!==null
                    || self::canonical(json_decode((string)$prior[0]['details'],true,512,JSON_THROW_ON_ERROR))!==self::canonical($detail)) throw new RuntimeException('reconciliation_required');
                $this->pdo->rollBack();
                return ['state'=>'already_committed','apiKeyId'=>(int)$keys[0]['id'],'contract'=>'legacy_instance_scoped'];
            }
            $collision=$this->pdo->prepare('SELECT id FROM api_keys WHERE name=?'.$suffix); $collision->execute([self::name($input)]);
            if ($collision->fetchColumn()!==false) throw new RuntimeException('request_identity_requires_reconciliation');
            if ($reconcileOnly) { $this->pdo->rollBack(); return ['state'=>'not_committed','contract'=>'legacy_instance_scoped']; }
            if ($configuration!==$plan['configurationSha256']) throw new RuntimeException('application_configuration_changed');
            $insert=$this->pdo->prepare('INSERT INTO api_keys(name,key_prefix,key_hash,scopes,allowed_ips,api_v2_application_id) VALUES(?,?,?,?,NULL,NULL)');
            $insert->execute([self::name($input),$parts[1],$hash,self::SCOPE]); $id=(int)$this->pdo->lastInsertId();
            if ($id<1) throw new RuntimeException('key_creation_failed');
            try {
                $audit=$this->pdo->prepare('INSERT INTO system_audit(user_id,action,entity_type,entity_id,details,ip_address,user_agent) VALUES(NULL,?,?,?,?,NULL,NULL)');
                $audit->execute([self::ACTION,'api_key',$id,json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            } catch (Throwable) { throw new RuntimeException('audit_required'); }
            $auditId=(int)$this->pdo->lastInsertId();
            $verify=$this->pdo->prepare('SELECT user_id,action,entity_type,entity_id,details FROM system_audit WHERE id=?'); $verify->execute([$auditId]); $saved=$verify->fetch(PDO::FETCH_ASSOC);
            $lookup->execute([$hash]); $created=$lookup->fetchAll(PDO::FETCH_ASSOC);
            if (!$saved || $saved['user_id']!==null || $saved['action']!==self::ACTION || $saved['entity_type']!=='api_key'
                || (int)$saved['entity_id']!==$id || self::canonical(json_decode((string)$saved['details'],true,512,JSON_THROW_ON_ERROR))!==self::canonical($detail)
                || count($created)!==1 || (int)$created[0]['id']!==$id || !self::matchesKey($created[0],$input,$parts[1])) throw new RuntimeException('audit_or_key_verification_failed');
            $this->pdo->commit();
            return ['state'=>'created','apiKeyId'=>$id,'contract'=>'legacy_instance_scoped'];
        } catch (Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $error; }
    }
}
