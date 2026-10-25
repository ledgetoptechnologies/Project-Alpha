<?php
declare(strict_types=1);
namespace App\Services;

use DomainException;
use PDO;

/** App-stored, default-off operator acknowledgement of a qualified receiver.
 * The receipt is evidence attribution, not remote code attestation. */
final class PortalClientRequestPolicy
{
    public const RECEIVER_POLICY = 'native-readable-project-v1';
    private const KEY = 'portal_client_root_request_policy';

    public static function enabled(PDO $pdo,int $profileId): bool
    {
        $statement=$pdo->prepare('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key=?');
        $statement->execute([self::KEY]);
        $policy=json_decode((string)($statement->fetchColumn()?:''),true);
        if(!is_array($policy)||($policy['enabled']??false)!==true||($policy['profileId']??null)!==$profileId
            ||($policy['receiverPolicy']??null)!==self::RECEIVER_POLICY
            ||!preg_match('/^[a-f0-9]{64}$/',(string)($policy['qualificationSha256']??'')))return false;
        $profile=$pdo->prepare('SELECT application_key,portal_route FROM portal_integration_profiles WHERE id=?');
        $profile->execute([$profileId]);$profile=$profile->fetch(PDO::FETCH_ASSOC);
        return $profile && hash_equals((string)$profile['application_key'],(string)($policy['applicationKey']??''))
            && hash_equals(hash('sha256',trim((string)$profile['portal_route'])),(string)($policy['receiverRouteSha256']??''));
    }

    public static function saveAcknowledgement(PDO $pdo,array $profile,string $receiverPolicy,string $routeSha256,string $qualificationSha256,int $actorId): void
    {
        if(!$pdo->inTransaction()||$actorId<1||empty($profile['enabled'])||empty($profile['portal_projection_enabled'])
            ||$receiverPolicy!==self::RECEIVER_POLICY||!preg_match('/^[a-f0-9]{64}$/',$qualificationSha256)
            ||trim((string)($profile['portal_route']??''))===''
            ||!hash_equals(hash('sha256',trim((string)$profile['portal_route'])),$routeSha256))
            throw new DomainException('A reviewed exact receiver project-read policy and qualification receipt are required.');
        $policy=['enabled'=>true,'profileId'=>(int)$profile['id'],'applicationKey'=>(string)$profile['application_key'],
            'receiverPolicy'=>$receiverPolicy,'receiverRouteSha256'=>$routeSha256,'qualificationSha256'=>$qualificationSha256,'acknowledgedBy'=>$actorId];
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? 'INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?) ON CONFLICT(organization_id,config_key) DO UPDATE SET config_value=excluded.config_value'
            : 'INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
        $pdo->prepare($sql)->execute([self::KEY,json_encode($policy,JSON_THROW_ON_ERROR)]);
    }
}
