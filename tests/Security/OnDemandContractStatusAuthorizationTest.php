<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/on_demand_contract_status.php';

final class OnDemandContractStatusAuthorizationTest extends TestCase
{
    public function testMemberCannotPauseOrResumeAnotherMembersContract(): void
    {
        $pdo = $this->database();
        try {
            pa_on_demand_contract_change_status($pdo, 10, 2, 'active', 'paused');
            self::fail('Another member changed the contract.');
        } catch (DomainException $error) {
            self::assertStringContainsString('access', $error->getMessage());
        }
        self::assertSame('active', $this->contractStatus($pdo));

        pa_on_demand_contract_change_status($pdo, 10, 1, 'active', 'paused');
        self::assertSame('paused', $this->contractStatus($pdo));

        try {
            pa_on_demand_contract_change_status($pdo, 10, 2, 'paused', 'active');
            self::fail('Another member resumed the contract.');
        } catch (DomainException $error) {
            self::assertStringContainsString('access', $error->getMessage());
        }
        self::assertSame('paused', $this->contractStatus($pdo));
        pa_on_demand_contract_change_status($pdo, 10, 1, 'paused', 'active');
        self::assertSame('active', $this->contractStatus($pdo));
    }

    public function testTerminalOrWrongTypeCannotBeTransitioned(): void
    {
        $pdo = $this->database();
        $pdo->exec("UPDATE contracts SET status='cancelled' WHERE id=10");
        $this->expectException(DomainException::class);
        pa_on_demand_contract_change_status($pdo, 10, 1, 'active', 'paused');
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT);
            INSERT INTO users VALUES (1, 'member'), (2, 'member');
            CREATE TABLE contracts (id INTEGER PRIMARY KEY, created_by INTEGER, contract_type TEXT, status TEXT);
            INSERT INTO contracts VALUES (10, 1, 'on_demand', 'active');");
        return $pdo;
    }

    private function contractStatus(PDO $pdo): string
    {
        return (string)$pdo->query('SELECT status FROM contracts WHERE id=10')->fetchColumn();
    }
}
