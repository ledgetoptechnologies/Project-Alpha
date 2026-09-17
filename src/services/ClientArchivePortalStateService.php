<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/**
 * Preserve stable client and portal identity across physical archive/restore.
 * The controller must beginTransaction before this service and call
 * PortalProjectionMutationService::afterMutation before commit.
 */
final class ClientArchivePortalStateService
{
    /**
     * @param array<string,mixed> $client
     * @return list<string> Additional workspace IDs affected by a global principal state change.
     */
    public function archive(PDO $pdo, array $client, int $actorId): array
    {
        $this->requireTransaction($pdo);
        $clientId = (int)($client['id'] ?? 0);
        $publicId = strtolower(trim((string)($client['public_id'] ?? '')));
        if ($clientId < 1 || preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
            throw new DomainException('Client archive requires a stable public identity.');
        }

        $suffix = $this->lockSuffix($pdo);
        $eligibility = $pdo->prepare(
            'SELECT portal_principal_id,manual_state,canonical_email
             FROM portal_client_login_eligibility WHERE client_id=?' . $suffix
        );
        $eligibility->execute([$clientId]);
        $portal = $eligibility->fetch(PDO::FETCH_ASSOC) ?: [];
        $manualState = $this->manualState($portal['manual_state'] ?? null);
        $principalId = (int)($portal['portal_principal_id'] ?? 0);
        $principalWasPresent = 0;
        $bindingIds = [];
        $entitlementIds = [];
        $affectedWorkspaceIds = [];
        $recoveryVersion = null;
        $principalDisabledForArchive = null;

        if ($principalId > 0) {
            $principalStatement = $pdo->prepare(
                'SELECT id,public_id,enabled,revoked_at,authorization_version
                 FROM portal_principals WHERE id=?' . $suffix
            );
            $principalStatement->execute([$principalId]);
            $principal = $principalStatement->fetch(PDO::FETCH_ASSOC);
            if (!$principal) {
                $principalId = 0;
            } else {
                $principalWasPresent = 1;
                $links = $pdo->prepare(
                    'SELECT pc.client_id FROM portal_principal_clients pc
                     JOIN clients c ON c.id=pc.client_id
                     WHERE pc.portal_principal_id=? AND pc.client_id<>?
                       AND c.archived=0 AND c.deleted_at IS NULL
                     ORDER BY pc.client_id' . $suffix
                );
                $links->execute([$principalId, $clientId]);
                $hasOtherActiveClient = $links->fetchColumn() !== false;
                $version = PortalSourceVersion::from([
                    'clientPublicId' => $publicId,
                    'principalId' => $principalId,
                    'active' => false,
                    'reason' => 'client_archived',
                ]);

                $entitlements = $pdo->prepare(
                    "SELECT id FROM portal_v2_entitlements
                     WHERE portal_principal_id=? AND scope_type='client'
                       AND scope_public_id=? AND active=1 ORDER BY id" . $suffix
                );
                $entitlements->execute([$principalId, $publicId]);
                $entitlementIds = array_map('intval', $entitlements->fetchAll(PDO::FETCH_COLUMN));
                $pdo->prepare(
                    "UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=?
                     WHERE portal_principal_id=? AND scope_type='client'
                       AND scope_public_id=? AND active<>0"
                )->execute([$version, $this->actor($actorId), $principalId, $publicId]);
                $pdo->prepare(
                    'DELETE FROM portal_principal_clients WHERE portal_principal_id=? AND client_id=?'
                )->execute([$principalId, $clientId]);

                $recoverable = $manualState === 'automatic'
                    && (int)$principal['enabled'] === 1
                    && $principal['revoked_at'] === null;
                if ($hasOtherActiveClient) {
                    if ($recoverable) {
                        $recoveryVersion = (int)$principal['authorization_version'];
                        $principalDisabledForArchive = 0;
                    }
                } else {
                    $affectedWorkspaceIds = $this->projectedWorkspaceIds(
                        $pdo,
                        (string)$principal['public_id']
                    );
                    if ($recoverable) {
                        $bindings = $pdo->prepare(
                            'SELECT id FROM portal_identity_bindings
                             WHERE portal_principal_id=? AND enabled=1 ORDER BY id' . $suffix
                        );
                        $bindings->execute([$principalId]);
                        $bindingIds = array_map('intval', $bindings->fetchAll(PDO::FETCH_COLUMN));
                    }
                    $pdo->prepare(
                        'UPDATE portal_principals
                         SET source_version=?,enabled=0,revoked_at=CURRENT_TIMESTAMP,
                             authorization_version=authorization_version+1,updated_by=?
                         WHERE id=? AND (enabled<>0 OR revoked_at IS NULL)'
                    )->execute([$version, $this->actor($actorId), $principalId]);
                    $pdo->prepare(
                        'UPDATE portal_identity_bindings
                         SET enabled=0,revoked_at=CURRENT_TIMESTAMP,updated_by=?
                         WHERE portal_principal_id=? AND enabled<>0'
                    )->execute([$this->actor($actorId), $principalId]);
                    if ($recoverable) {
                        $current = $pdo->prepare(
                            'SELECT authorization_version FROM portal_principals WHERE id=?' . $suffix
                        );
                        $current->execute([$principalId]);
                        $recoveryVersion = (int)$current->fetchColumn();
                        $principalDisabledForArchive = 1;
                    }
                }
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO archived_clients
             (client_id,public_id,name,email,phone,organization_id,client_type,notes,
              address_line1,address_line2,city,state,postal_code,country,created_at,
              portal_principal_id,portal_manual_state,portal_canonical_email,
              portal_identity_binding_ids_json,portal_principal_authorization_version,
              portal_principal_disabled_for_archive,portal_principal_was_present,
              portal_entitlement_ids_json,portal_affected_workspace_ids_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $clientId, $publicId, $client['name'], $client['email'] ?? null,
            $client['phone'] ?? null, $client['organization_id'] ?? null,
            $this->clientType($client['client_type'] ?? null), $client['notes'] ?? null,
            $client['address_line1'] ?? null, $client['address_line2'] ?? null,
            $client['city'] ?? null, $client['state'] ?? null,
            $client['postal_code'] ?? null, $client['country'] ?? null,
            $client['created_at'] ?? null, $principalId > 0 ? $principalId : null,
            $manualState, $this->canonicalEmail($portal['canonical_email'] ?? null),
            json_encode($bindingIds, JSON_THROW_ON_ERROR), $recoveryVersion,
            $principalDisabledForArchive, $principalWasPresent,
            json_encode($entitlementIds, JSON_THROW_ON_ERROR),
            json_encode($affectedWorkspaceIds, JSON_THROW_ON_ERROR),
        ]);
        return $affectedWorkspaceIds;
    }

    /**
     * Lock, restore, and consume one exact archive record in the caller's transaction.
     * @return array{client_id:int,affected_workspace_ids:list<string>}
     */
    public function consumeAndRestore(PDO $pdo, int $archiveId, int $actorId): array
    {
        $this->requireTransaction($pdo);
        $statement = $pdo->prepare(
            'SELECT * FROM archived_clients WHERE id=?' . $this->lockSuffix($pdo)
        );
        $statement->execute([$archiveId]);
        $archive = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$archive) throw new DomainException('Archived client not found.');

        $clientId = $this->restoreLockedRow($pdo, $archive, $actorId);
        $delete = $pdo->prepare('DELETE FROM archived_clients WHERE id=?');
        $delete->execute([$archiveId]);
        if ($delete->rowCount() !== 1) {
            throw new DomainException('Archived client changed while it was being restored.');
        }
        return [
            'client_id' => $clientId,
            'affected_workspace_ids' => $this->stringIds($archive['portal_affected_workspace_ids_json'] ?? null),
        ];
    }

    /** @param array<string,mixed> $archive */
    private function restoreLockedRow(PDO $pdo, array $archive, int $actorId): int
    {
        $originalId = (int)($archive['client_id'] ?? 0);
        $publicId = strtolower(trim((string)($archive['public_id'] ?? '')));
        $stableIdentity = preg_match('/^[a-f0-9]{32}$/', $publicId) === 1;
        $exists = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE id=?');
        $exists->execute([$originalId]);
        $useOriginalId = $originalId > 0 && (int)$exists->fetchColumn() === 0;

        $columns = ['name','email','phone','organization_id','client_type','notes','address_line1',
            'address_line2','city','state','postal_code','country','source_version','created_at'];
        $values = [$archive['name'], $archive['email'] ?? null, $archive['phone'] ?? null,
            $archive['organization_id'] ?? null, $this->clientType($archive['client_type'] ?? null),
            $archive['notes'] ?? null, $archive['address_line1'] ?? null,
            $archive['address_line2'] ?? null, $archive['city'] ?? null,
            $archive['state'] ?? null, $archive['postal_code'] ?? null,
            $archive['country'] ?? null, PortalSourceVersion::from([
                'clientPublicId' => $stableIdentity ? $publicId : null, 'restored' => true,
            ]), $archive['created_at'] ?? null];
        if ($stableIdentity) { array_unshift($columns, 'public_id'); array_unshift($values, $publicId); }
        if ($useOriginalId) { array_unshift($columns, 'id'); array_unshift($values, $originalId); }
        $marks = implode(',', array_fill(0, count($columns), '?'));
        $pdo->prepare('INSERT INTO clients (' . implode(',', $columns) . ') VALUES (' . $marks . ')')
            ->execute($values);
        $clientId = $useOriginalId ? $originalId : (int)$pdo->lastInsertId();

        $storedManualState = $this->manualState($archive['portal_manual_state'] ?? null);
        $principalId = (int)($archive['portal_principal_id'] ?? 0);
        $principal = null;
        if ($principalId > 0) {
            $principalStatement = $pdo->prepare(
                'SELECT id,enabled,revoked_at,authorization_version
                 FROM portal_principals WHERE id=?' . $this->lockSuffix($pdo)
            );
            $principalStatement->execute([$principalId]);
            $principal = $principalStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($storedManualState !== null || $principalId > 0) {
            $effectiveManualState = $storedManualState ?? 'automatic';
            $wasDisabled = $archive['portal_principal_disabled_for_archive'] === null
                ? null : (int)$archive['portal_principal_disabled_for_archive'];
            $storedVersion = $archive['portal_principal_authorization_version'] === null
                ? null : (int)$archive['portal_principal_authorization_version'];
            $principalWasPresent = (int)($archive['portal_principal_was_present'] ?? 0) === 1;
            $recoverySafe = $principalId === 0 && !$principalWasPresent
                && $effectiveManualState === 'automatic';
            if ($principalId > 0 && $effectiveManualState === 'automatic'
                && $principal !== null && $storedVersion !== null && $wasDisabled !== null) {
                $recoverySafe = (int)$principal['authorization_version'] === $storedVersion
                    && (int)$principal['enabled'] === ($wasDisabled === 1 ? 0 : 1)
                    && ($wasDisabled !== 1 || $principal['revoked_at'] !== null)
                    && $this->entitlementsUnchangedSinceArchive($pdo, $archive, $principalId, $publicId);
            }
            if ($effectiveManualState === 'automatic' && !$recoverySafe) {
                $effectiveManualState = 'revoked';
            }
            $revoked = $effectiveManualState === 'revoked';
            $sourceVersion = PortalSourceVersion::from([
                'clientPublicId' => $stableIdentity ? $publicId : null,
                'principalId' => $principal !== null ? $principalId : null,
                'manualState' => $effectiveManualState, 'restored' => true,
            ]);
            $pdo->prepare(
                'INSERT INTO portal_client_login_eligibility
                 (client_id,portal_principal_id,manual_state,eligibility_status,review_reason,
                  canonical_email,source_version,last_reconciled_at,created_by,updated_by)
                 VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?)'
            )->execute([$clientId, $principal !== null ? $principalId : null,
                $effectiveManualState, $revoked ? 'revoked' : 'review_required',
                $revoked ? 'none' : 'missing_email',
                $this->canonicalEmail($archive['portal_canonical_email'] ?? null),
                $sourceVersion, $this->actor($actorId), $this->actor($actorId)]);

            if (!$revoked && $principalId > 0 && $wasDisabled === 1) {
                $bindingIds = $this->integerIds($archive['portal_identity_binding_ids_json'] ?? null);
                if ($bindingIds !== []) {
                    $marks = implode(',', array_fill(0, count($bindingIds), '?'));
                    $pdo->prepare(
                        "UPDATE portal_identity_bindings
                         SET enabled=1,revoked_at=NULL,updated_by=?
                         WHERE portal_principal_id=? AND id IN ({$marks})"
                    )->execute(array_merge([$this->actor($actorId), $principalId], $bindingIds));
                }
            }
            if (!$revoked && $principalId > 0) {
                $entitlementIds = $this->integerIds($archive['portal_entitlement_ids_json'] ?? null);
                if ($entitlementIds !== []) {
                    $marks = implode(',', array_fill(0, count($entitlementIds), '?'));
                    $archiveVersion = $this->archiveVersion($publicId, $principalId);
                    $pdo->prepare(
                        "UPDATE portal_v2_entitlements SET active=1,updated_by=?
                         WHERE portal_principal_id=? AND scope_type='client' AND scope_public_id=?
                           AND source_version=? AND id IN ({$marks})"
                    )->execute(array_merge([
                        $this->actor($actorId), $principalId, $publicId, $archiveVersion,
                    ], $entitlementIds));
                }
            }
        }
        return $clientId;
    }

    /** @return list<string> */
    private function projectedWorkspaceIds(PDO $pdo, string $principalPublicId): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT workspace_public_id FROM portal_projection_resource_state
             WHERE resource_type='principal' AND resource_public_id=? ORDER BY workspace_public_id"
        );
        $statement->execute([$principalPublicId]);
        return array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** @param array<string,mixed> $archive */
    private function entitlementsUnchangedSinceArchive(PDO $pdo, array $archive, int $principalId, string $publicId): bool
    {
        $ids = $this->integerIds($archive['portal_entitlement_ids_json'] ?? null);
        if ($ids === []) return true;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            "SELECT id FROM portal_v2_entitlements
             WHERE portal_principal_id=? AND scope_type='client' AND scope_public_id=?
               AND active=0 AND source_version=? AND id IN ({$marks})
             ORDER BY id" . $this->lockSuffix($pdo)
        );
        $statement->execute(array_merge([$principalId, $publicId, $this->archiveVersion($publicId, $principalId)], $ids));
        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);
        return $found === $ids;
    }

    private function archiveVersion(string $publicId, int $principalId): string
    {
        return PortalSourceVersion::from([
            'clientPublicId'=>$publicId,'principalId'=>$principalId,
            'active'=>false,'reason'=>'client_archived',
        ]);
    }

    private function clientType(mixed $value): string
    { $type=(string)$value;return in_array($type,['unknown','business','consumer'],true)?$type:'unknown'; }
    private function manualState(mixed $value): ?string
    { $state=(string)$value;return in_array($state,['automatic','revoked'],true)?$state:null; }
    private function canonicalEmail(mixed $value): ?string
    { $email=strtolower(trim((string)$value));return $email!==''&&strlen($email)<=254?$email:null; }
    private function actor(int $actorId): ?int
    { return $actorId > 0 ? $actorId : null; }
    private function lockSuffix(PDO $pdo): string
    { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
    private function requireTransaction(PDO $pdo): void
    { if(!$pdo->inTransaction())throw new DomainException('Client archive identity preservation requires a transaction.'); }

    /** @return list<int> */
    private function integerIds(mixed $value): array
    {
        $values=$this->decodedList($value);$ids=array_values(array_unique(array_filter(
            array_map('intval',$values),static fn(int$id):bool=>$id>0)));sort($ids,SORT_NUMERIC);return$ids;
    }
    /** @return list<string> */
    private function stringIds(mixed $value): array
    {
        $ids=array_values(array_unique(array_filter(array_map(
            static fn(mixed$id):string=>trim((string)$id),$this->decodedList($value)))));sort($ids,SORT_STRING);return$ids;
    }
    /** @return list<mixed> */
    private function decodedList(mixed $value): array
    {
        if(!is_string($value)||trim($value)==='')return[];
        try{$decoded=json_decode($value,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return[];}
        return is_array($decoded)&&array_is_list($decoded)?$decoded:[];
    }
}
