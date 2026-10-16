<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use LogicException;

/** A contact address is not permission to replace an existing portal login. */
final class PortalContactIdentityPolicy
{
    public static function available(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_client_contact_identity_policy'")->fetchColumn();
        }
        return (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_client_contact_identity_policy'")->fetchColumn();
    }

    public static function forClient(PDO $pdo, int $clientId): array|false
    {
        if (!self::available($pdo)) return false;
        // Authority is a current read, even when an earlier directory query
        // established a REPEATABLE READ snapshot before the policy existed.
        // Mutation callers already hold the client lock; keep client -> policy.
        $lock=$pdo->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $statement=$pdo->prepare('SELECT * FROM portal_client_contact_identity_policy WHERE client_id=?'.$lock);
        $statement->execute([$clientId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /** Caller has locked the exact client and its application binding. This
     * creates policy/audit only; it never creates or changes portal authority. */
    public static function preserveExisting(PDO $pdo, int $clientId, array $identity, string $commandId, string $requestHash): void
    {
        if (!$pdo->inTransaction() || !self::available($pdo)) throw new LogicException('contact-identity-policy-unavailable');
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $statement=$pdo->prepare('SELECT client_id FROM portal_client_contact_identity_policy WHERE client_id=?'.$lock);
        $statement->execute([$clientId]);
        if ($statement->fetchColumn()!==false) return;
        $statement=$pdo->prepare('SELECT portal_principal_id,canonical_email FROM portal_client_login_eligibility WHERE client_id=?'.$lock);
        $statement->execute([$clientId]);$existing=$statement->fetch(PDO::FETCH_ASSOC);
        $principalId=$existing && $existing['portal_principal_id']!==null?(int)$existing['portal_principal_id']:null;
        $email=$principalId?strtolower(trim((string)($existing['canonical_email']??''))):null;
        if ($principalId) {
            $statement=$pdo->prepare('SELECT id FROM portal_principals WHERE id=?'.$lock);$statement->execute([$principalId]);
            if ($statement->fetchColumn()===false) throw new LogicException('contact-identity-principal-unavailable');
        }
        $pdo->prepare('INSERT INTO portal_client_contact_identity_policy(client_id,portal_principal_id,login_email,application_pk,history_epoch,command_id,request_sha256) VALUES(?,?,?,?,?,?,?)')
            ->execute([$clientId,$principalId,$email?:null,(int)$identity['application_pk'],$identity['history_epoch'],$commandId,$requestHash]);
    }
}
