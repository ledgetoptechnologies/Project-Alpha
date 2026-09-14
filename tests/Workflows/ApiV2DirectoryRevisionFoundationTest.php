<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;
use PDO;

final class ApiV2DirectoryRevisionFoundationTest extends TestCase
{
    public function testDirectoryReadRequiresExplicitFeatureAndRevisionFoundation(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/database/migrations/0089_api_v2_directory_revision_foundation.sql');
        $capabilities = (string)file_get_contents($root . '/src/utils/api_v2_capabilities.php');
        $front = (string)file_get_contents($root . '/public/index.php');
        $scopes = (string)file_get_contents($root . '/src/utils/api_scopes.php');

        self::assertStringContainsString('api_v2_directory_resource_state', $migration);
        self::assertStringContainsString('api_v2_directory_resource_changes', $migration);
        self::assertStringContainsString('api_v2_directory_authorization_state', $migration);
        self::assertStringContainsString('PRIMARY KEY (resource_type, public_id, revision)', $migration);
        self::assertStringContainsString('REFERENCES api_v2_applications(id)', $migration);
        self::assertStringNotContainsString('sync_source_identity', $migration);
        self::assertStringNotContainsString('sync_resource_state', $migration);
        self::assertStringNotContainsString('INSERT INTO api_v2_directory_', $migration);
        self::assertStringContainsString('directory.clients.read', $capabilities . $scopes);
        self::assertStringContainsString('directory.organizations.read', $capabilities . $scopes);
        self::assertStringContainsString('APP_API_V2_DIRECTORY_READ_ENABLED', $front);
        self::assertStringContainsString('if (($features[$feature] ?? false) !== true) continue', $capabilities);
    }

    public function testRevisionIsAtomicAndNoopUpdatesDoNotEmitChanges(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            INSERT INTO clients(id,public_id,name,email) VALUES(1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','Example',NULL);");
        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record($pdo, 'client', 1));
        self::assertFalse(\api_v2_directory_record($pdo, 'client', 1));
        $pdo->commit();
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        $pdo->beginTransaction();
        $pdo->exec("UPDATE clients SET name='Changed' WHERE id=1");
        self::assertTrue(\api_v2_directory_record($pdo, 'client', 1));
        $pdo->rollBack();
        self::assertSame(1, (int)$pdo->query('SELECT revision FROM api_v2_directory_resource_state')->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
        $this->expectException(\LogicException::class);
        \api_v2_directory_record($pdo, 'client', 1);
    }

    public function testDeleteTombstoneIsAtomicIdempotentAndRestorableWithTheSamePublicId(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $publicId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $pdo->exec("CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            INSERT INTO clients(id,public_id,name) VALUES(1,'{$publicId}','Example');");

        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record($pdo, 'client', 1));
        $pdo->commit();
        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record_delete($pdo, 'client', $publicId));
        $pdo->exec('DELETE FROM clients WHERE id=1');
        $pdo->rollBack();
        self::assertSame(1, (int)$pdo->query("SELECT present FROM api_v2_directory_resource_state WHERE public_id='{$publicId}'")->fetchColumn());

        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record_delete($pdo, 'client', $publicId));
        $pdo->exec('DELETE FROM clients WHERE id=1');
        $pdo->commit();
        self::assertSame([2, 0], array_map('intval', $pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE public_id='{$publicId}'")->fetch(PDO::FETCH_NUM)));
        $pdo->beginTransaction();
        self::assertFalse(\api_v2_directory_record_delete($pdo, 'client', $publicId));
        $pdo->commit();

        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO clients(id,public_id,name) VALUES(1,'{$publicId}','Restored')");
        self::assertTrue(\api_v2_directory_record($pdo, 'client', 1));
        $pdo->commit();
        self::assertSame([3, 1], array_map('intval', $pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE public_id='{$publicId}'")->fetch(PDO::FETCH_NUM)));
        self::assertSame(['upsert', 'delete', 'upsert'], $pdo->query("SELECT action FROM api_v2_directory_resource_changes WHERE public_id='{$publicId}' ORDER BY revision")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testOrganizationDeletionLocksChildrenBeforeParentAndResnapshotsThem(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/controllers/organization/organizations_delete.php'
        );
        $firstChildren = strpos($source, '$lockClientIds();');
        $parent = strpos($source, '$organization = $pdo->prepare(\'SELECT public_id FROM organizations');
        $snapshot = strpos($source, '$clientIds = $lockClientIds();');

        self::assertNotFalse($firstChildren);
        self::assertNotFalse($parent);
        self::assertNotFalse($snapshot);
        self::assertLessThan($parent, $firstChildren);
        self::assertLessThan($snapshot, $parent);
    }

    public function testOrganizationClientAttachAndDetachRecordTheLockedClientMutation(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['organization_add_client.php', 'organization_remove_client.php'] as $controller) {
            $source = (string) file_get_contents($root . '/src/controllers/organization/' . $controller);
            $lock = strpos($source, 'lockedClientScopes($pdo,$client_id');
            $mutation = strpos($source, 'UPDATE clients SET organization_id');
            $record = strpos($source, "api_v2_directory_record(\$pdo,'client',\$client_id)");
            $after = strpos($source, 'clientScopes($pdo,$client_id)');

            self::assertStringContainsString("require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';", $source);
            self::assertNotFalse($lock);
            self::assertNotFalse($mutation);
            self::assertNotFalse($record);
            self::assertNotFalse($after);
            self::assertLessThan($mutation, $lock, $controller . ' must lock before changing the relationship.');
            self::assertLessThan($record, $mutation, $controller . ' must revision the changed profile before projection reconciliation.');
            self::assertLessThan($after, $record, $controller . ' must reconcile after the revision write.');
        }
    }

    public function testAlternateOrganizationCreateRecordsInsideItsTransactionAndSuppressesNoop(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/src/controllers/organization/org_create.php');
        $insert = strpos($source, "INSERT INTO organizations");
        $record = strpos($source, "api_v2_directory_record(\$pdo, 'organization', \$id)");
        $commit = strpos($source, '$pdo->commit();');
        self::assertNotFalse($insert);
        self::assertNotFalse($record);
        self::assertNotFalse($commit);
        self::assertLessThan($record, $insert);
        self::assertLessThan($commit, $record);

        require_once $root . '/src/utils/api_v2_directory_revision.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,general_email TEXT,general_phone TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            INSERT INTO organizations(id,public_id,name) VALUES(1,'cccccccccccccccccccccccccccccccc','Alternate create');");
        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record($pdo, 'organization', 1));
        self::assertFalse(\api_v2_directory_record($pdo, 'organization', 1));
        $pdo->commit();
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM api_v2_directory_resource_changes')->fetchColumn());
    }

    public function testOrganizationRelationshipRevisionIsGuardedByAnActualUpdate(): void
    {
        $root = dirname(__DIR__, 2);
        $attach = (string) file_get_contents($root . '/src/controllers/organization/organization_add_client.php');
        self::assertStringContainsString('static function()use($pdo,$organization_id,$client_id,$currentOrganizationId)', $attach);
        self::assertStringContainsString("if(\$actualOrganizationId!==\$currentOrganizationId)throw new DomainException", $attach);
        self::assertStringContainsString('if($actualOrganizationId===$organization_id)return', $attach);
        self::assertStringContainsString("\$oldOrganizationPredicate=\$currentOrganizationId===0?'organization_id IS NULL':'organization_id=?'", $attach);
        self::assertStringContainsString('if($currentOrganizationId>0)$params[]=$currentOrganizationId', $attach);
        self::assertStringContainsString('if($update->rowCount()!==1)throw new DomainException', $attach);
        self::assertStringContainsString("SELECT organization_id FROM clients WHERE id=?", $attach);
        self::assertStringContainsString("throw new DomainException('Client organization relationship changed.')", $attach);

        $detach = (string) file_get_contents($root . '/src/controllers/organization/organization_remove_client.php');
        $guard = strpos($detach, "if(\$update->rowCount()!==1)throw new DomainException");
        $record = strpos($detach, "api_v2_directory_record(\$pdo,'client',\$client_id)");
        self::assertStringContainsString('WHERE id=? AND organization_id=?', $detach);
        self::assertNotFalse($guard);
        self::assertNotFalse($record);
        self::assertLessThan($record, $guard, 'A stale detach must fail before it can emit a directory revision.');
    }
}
