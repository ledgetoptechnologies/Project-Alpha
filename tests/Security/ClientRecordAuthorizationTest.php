<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/client_record_authorization.php';

final class ClientRecordAuthorizationTest extends TestCase
{
    public function testMemberCanOnlyMutateOwnClientAndLegacyArchiveFailsClosed(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT);
            INSERT INTO users VALUES (9011,'member'),(9012,'member'),(9013,'owner');");

        self::assertTrue(pa_client_record_mutation_allowed($pdo, 9011, 9011));
        self::assertFalse(pa_client_record_mutation_allowed($pdo, 9012, 9011));
        self::assertFalse(pa_client_record_mutation_allowed($pdo, 9011, null));
        self::assertTrue(pa_client_record_mutation_allowed($pdo, 9013, null));
        self::assertFalse(pa_client_record_mutation_allowed($pdo, 0, 0));
    }
}
