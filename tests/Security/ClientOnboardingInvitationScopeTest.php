<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/client_onboarding_access.php';

final class ClientOnboardingInvitationScopeTest extends TestCase
{
    public function testForgedOrganizationIdDoesNotExposeOrReplaceAnotherMembersInvitation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT);
            INSERT INTO users VALUES (9201,'member'),(9202,'member'),(9203,'owner');
            CREATE TABLE client_onboarding_invitations (id INTEGER PRIMARY KEY, organization_id INTEGER, created_by INTEGER, status TEXT);
            INSERT INTO client_onboarding_invitations VALUES
                (1,9,9202,'pending'),(2,9,9201,'pending'),(3,NULL,9203,'pending');");

        [$where, $params] = pa_onboarding_invitation_scope($pdo, 9201, 9);
        $list = $pdo->prepare('SELECT i.id FROM client_onboarding_invitations i WHERE ' . $where);
        $list->execute($params);
        self::assertSame([2], array_map('intval', $list->fetchAll(PDO::FETCH_COLUMN)));

        [$writeWhere, $writeParams] = pa_onboarding_invitation_scope($pdo, 9201, 9, 'client_onboarding_invitations');
        $revoke = $pdo->prepare('UPDATE client_onboarding_invitations SET status="revoked" WHERE id=? AND ' . $writeWhere);
        $revoke->execute(array_merge([1], $writeParams));
        self::assertSame(0, $revoke->rowCount());
        self::assertSame('pending', $pdo->query('SELECT status FROM client_onboarding_invitations WHERE id=1')->fetchColumn());

        [$ownerWhere, $ownerParams] = pa_onboarding_invitation_scope($pdo, 9203, 9);
        $ownerList = $pdo->prepare('SELECT i.id FROM client_onboarding_invitations i WHERE ' . $ownerWhere . ' ORDER BY i.id');
        $ownerList->execute($ownerParams);
        self::assertSame([1, 2, 3], array_map('intval', $ownerList->fetchAll(PDO::FETCH_COLUMN)));
    }
}
