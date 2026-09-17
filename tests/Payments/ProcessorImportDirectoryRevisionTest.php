<?php
declare(strict_types=1);

namespace Tests\Payments;

use PDO;
use PHPUnit\Framework\TestCase;

final class ProcessorImportDirectoryRevisionTest extends TestCase
{
    public function testEnrichmentRecordsOnlyChangedProfilesAndUsesCallerTransaction(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/PaymentProcessorImportService.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            INSERT INTO clients(id,public_id,name,email) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example','example@example.test');");
        $enrich = new \ReflectionMethod(\PaymentProcessorImportService::class, 'enrichClient');
        $enrich->invoke(null, $pdo, 1, ['payer_phone' => '555-0100']);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(1, (int)$pdo->query('SELECT revision FROM api_v2_directory_resource_state')->fetchColumn());

        $enrich->invoke(null, $pdo, 1, ['payer_phone' => '555-0100']);
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());

        $pdo->beginTransaction();
        $enrich->invoke(null, $pdo, 1, ['payer_city' => 'Chicago']);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(2, (int)$pdo->query('SELECT revision FROM api_v2_directory_resource_state')->fetchColumn());
        $pdo->rollBack();
        self::assertSame(1, (int)$pdo->query('SELECT revision FROM api_v2_directory_resource_state')->fetchColumn());
    }
}
