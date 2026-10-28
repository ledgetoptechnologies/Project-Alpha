<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/expense_schedule_authorization.php';

final class ExpenseScheduleAuthorizationTest extends TestCase
{
    public function testExpenseScopeAndRecipientsFollowCurrentCreatorAuthority(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, email TEXT, is_disabled INTEGER DEFAULT 0, deleted_at TEXT);
            INSERT INTO users VALUES (9101,'member','member@example.test',0,NULL),(9102,'owner','owner@example.test',0,NULL);
            CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, organization_id INTEGER, is_system INTEGER);
            INSERT INTO roles VALUES (1,'member',NULL,1),(2,'owner',NULL,1);
            CREATE TABLE role_permissions (role_id INTEGER, permission TEXT, allowed INTEGER);
            INSERT INTO role_permissions VALUES (1,'financial.manage',1),(2,'financial.manage',1);
            CREATE TABLE user_permissions_overrides (user_id INTEGER, organization_id INTEGER, permission TEXT, allowed INTEGER);
            CREATE TABLE expenses (id INTEGER PRIMARY KEY, organization_id INTEGER, created_by INTEGER);
            INSERT INTO expenses VALUES (1,NULL,9101),(2,NULL,9102);");

        $priorSession = $_SESSION ?? null;
        unset($_SESSION['user']);
        try {
            [$where, $params] = pa_expense_schedule_scope($pdo, 9101, 0);
            self::assertStringContainsString('e.created_by = ?', $where);
            self::assertSame([9101], $params);
            $visible = $pdo->prepare('SELECT e.id FROM expenses e WHERE ' . $where);
            $visible->execute($params);
            self::assertSame([1], array_map('intval', $visible->fetchAll(PDO::FETCH_COLUMN)));
            self::assertTrue(pa_expense_schedule_recipients_allowed($pdo, 9101, ['member@example.test']));
            self::assertFalse(pa_expense_schedule_recipients_allowed($pdo, 9101, ['outside@example.test']));
            self::assertFalse(pa_expense_schedule_recipients_allowed($pdo, 9101, ['member@example.test', 'outside@example.test']));

            [$ownerWhere] = pa_expense_schedule_scope($pdo, 9102, 0);
            self::assertSame('1=1', $ownerWhere);
            self::assertTrue(pa_expense_schedule_recipients_allowed($pdo, 9102, ['outside@example.test']));

            $pdo->exec('UPDATE users SET is_disabled=1 WHERE id=9101');
            $this->expectException(DomainException::class);
            pa_expense_schedule_scope($pdo, 9101, 0);
        } finally {
            if ($priorSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $priorSession;
            }
        }
    }

    public function testLegacyScheduleWithoutCreatorFailsClosed(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->expectException(DomainException::class);
        pa_expense_schedule_scope($pdo, 0, 0);
    }
}
