<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;

/** Revokes Project presentation without erasing any bearer or delivery history. */
final class ProjectPresentationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{changed:bool,localDisabled:bool,pendingStopped:int,managedRevocationIds:list<string>} */
    public function revokeForArchive(array $project, int $actorUserId, bool $dryRun = false): array
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Project presentation revocation requires an active transaction.');
        $publicId = (string)($project['public_id'] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1) throw new DomainException('Project presentation identity is invalid.');

        $assignments = [];
        $localDisabled = false;
        foreach (['portal_publish_enabled','public_project_enabled'] as $column) {
            if (!ProjectLifecycleSchema::hasProjectColumn($this->pdo, $column)) continue;
            $assignments[] = $column . '=0';
            $localDisabled = $localDisabled || !empty($project[$column]);
        }
        if (!$dryRun && $assignments !== []) {
            $this->pdo->prepare('UPDATE projects SET ' . implode(',', $assignments) . ' WHERE id=?')->execute([(int)$project['id']]);
        }

        $pendingStopped = 0;
        $revocations = [];
        $newRevocationRequired = false;
        if (!ProjectLifecycleSchema::hasTable($this->pdo, 'managed_delivery_intent_outbox')) {
            return ['changed'=>$localDisabled,'localDisabled'=>$localDisabled,'pendingStopped'=>$pendingStopped,'managedRevocationIds'=>$revocations];
        }
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            "SELECT provision.*,revocation.delivery_id revocation_delivery_id,revocation.delivered_at revocation_delivered_at,
                    revocation.dead_lettered_at revocation_dead_lettered_at
             FROM managed_delivery_intent_outbox provision
             LEFT JOIN managed_delivery_intent_outbox revocation
               ON revocation.target_delivery_id=provision.delivery_id AND revocation.intent_type='revoke'
             WHERE provision.intent_type='provision' AND provision.scope_type='project' AND provision.scope_public_id=?
               AND provision.revoked_at IS NULL ORDER BY provision.id" . $lock
        );
        $statement->execute([$publicId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
            if (empty($delivery['delivered_at'])) {
                if (empty($delivery['dead_lettered_at'])) {
                    $pendingStopped++;
                    if (!$dryRun) {
                        $this->pdo->prepare("UPDATE managed_delivery_intent_outbox
                            SET dead_lettered_at=CURRENT_TIMESTAMP,last_error_code='project_archived_before_delivery',claim_token=NULL,claimed_at=NULL
                            WHERE id=? AND delivered_at IS NULL AND dead_lettered_at IS NULL")->execute([(int)$delivery['id']]);
                    }
                }
                continue;
            }
            if (!empty($delivery['revocation_delivery_id'])) {
                if (empty($delivery['revocation_delivered_at']) && !empty($delivery['revocation_dead_lettered_at'])) {
                    throw new DomainException('Resolve the failed managed-delivery revocation before archiving this Project.');
                }
                $revocations[] = (string)$delivery['revocation_delivery_id'];
                continue;
            }
            if ($dryRun) {
                $newRevocationRequired = true;
                $revocations[] = 'required:' . (string)$delivery['delivery_id'];
                continue;
            }
            $revocationId = self::uuid();
            (new ManagedDeliveryService())->queueRevocation($this->pdo, (string)$delivery['delivery_id'], $revocationId, $actorUserId);
            $newRevocationRequired = true;
            $revocations[] = $revocationId;
        }
        return ['changed'=>$localDisabled || $pendingStopped > 0 || $newRevocationRequired,'localDisabled'=>$localDisabled,'pendingStopped'=>$pendingStopped,'managedRevocationIds'=>$revocations];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
