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

    public function testDeleteTombstonesEveryBindingAndRestoreDoesNotReactivateAuthority(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_capabilities.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_binding_status.php';
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_binding_command.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $publicId = str_repeat('d', 32);
        $source = '123e4567-e89b-42d3-a456-426614174000';
        $application = '223e4567-e89b-42d3-a456-426614174000';
        $epoch = '323e4567-e89b-42d3-a456-426614174000';
        $pdo->exec("CREATE TABLE api_keys(id INTEGER PRIMARY KEY,api_v2_application_id INTEGER,revoked_at TEXT);
            CREATE TABLE api_v2_applications(id INTEGER PRIMARY KEY,application_id TEXT);
            CREATE TABLE api_v2_history_identity(singleton INTEGER PRIMARY KEY,source_instance_id TEXT,history_epoch TEXT);
            CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,tombstoned_at TEXT,created_at TEXT,PRIMARY KEY(application_pk,resource_type,external_id),UNIQUE(application_pk,resource_type,public_id));
            CREATE TABLE api_v2_directory_binding_command_receipts(application_pk INTEGER,resource_type TEXT,command_id TEXT,request_sha256 TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,PRIMARY KEY(application_pk,resource_type,command_id));
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            INSERT INTO api_keys VALUES(7,3,NULL); INSERT INTO api_v2_applications VALUES(3,'{$application}');
            INSERT INTO api_v2_history_identity VALUES(1,'{$source}','{$epoch}');
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO api_v2_directory_authorization_state VALUES(4,7);
            INSERT INTO clients(id,public_id,name) VALUES(1,'{$publicId}','Example');");
        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record($pdo, 'client', 1));
        $pdo->commit();
        $hash = (string)$pdo->query("SELECT projection_sha256 FROM api_v2_directory_resource_state WHERE public_id='{$publicId}'")->fetchColumn();
        $insert = $pdo->prepare("INSERT INTO api_v2_directory_external_bindings(application_pk,resource_type,external_id,public_id,resource_revision,resource_projection_sha256,status,created_at) VALUES(?,'client',?,?,?,?,?,'2026-09-14 12:00:00')");
        $insert->execute([3, 'old/external', $publicId, 1, $hash, 'active']);
        $insert->execute([4, 'second/external', $publicId, 1, $hash, 'active']);

        $pdo->beginTransaction();
        self::assertTrue(\api_v2_directory_record_delete($pdo, 'client', $publicId));
        $pdo->exec('DELETE FROM clients WHERE id=1');
        $pdo->commit();
        self::assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE status='tombstoned'")->fetchColumn());
        self::assertSame([1, 8], array_map('intval', $pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state ORDER BY application_pk')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(410, \api_v2_binding_status_read($pdo, 'client', 'old/external', 7, ['source' => $source, 'application' => $application, 'epoch' => $epoch], '423e4567-e89b-42d3-a456-426614174000')['status']);

        // Restoration preserves the stable identity but does not reactivate
        // either old external authority. A new explicit command may safely
        // rebind the same tombstoned external ID at the restored revision.
        // Simulate a legacy row that escaped the delete-side repair; restore
        // must close that gap before publishing the new present revision.
        $pdo->exec("UPDATE api_v2_directory_external_bindings SET status='active',tombstoned_at=NULL WHERE application_pk=3 AND external_id='old/external'; UPDATE api_v2_directory_authorization_state SET authorization_generation=0 WHERE application_pk=3");
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO clients(id,public_id,name) VALUES(1,'{$publicId}','Restored')");
        self::assertTrue(\api_v2_directory_record($pdo, 'client', 1));
        $pdo->commit();
        self::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE status='active'")->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        $rebind = \api_v2_directory_binding_command_write($pdo, 'client', [
            'commandId' => '423e4567-e89b-42d3-a456-426614174000', 'externalId' => 'old/external',
            'expectedPublicId' => $publicId, 'expectedRevision' => '3',
        ], 7, ['source' => $source, 'application' => $application, 'epoch' => $epoch], '523e4567-e89b-42d3-a456-426614174000');
        self::assertSame(200, $rebind['status']);
        self::assertFalse($rebind['payload']['replayed']);
        self::assertSame('active', $pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='old/external'")->fetchColumn());
        self::assertSame(2, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state WHERE application_pk=3')->fetchColumn());
        self::assertTrue(\api_v2_directory_binding_command_write($pdo, 'client', [
            'commandId' => '423e4567-e89b-42d3-a456-426614174000', 'externalId' => 'old/external',
            'expectedPublicId' => $publicId, 'expectedRevision' => '3',
        ], 7, ['source' => $source, 'application' => $application, 'epoch' => $epoch], '623e4567-e89b-42d3-a456-426614174000')['payload']['replayed']);
    }

    public function testIdempotentDeleteRepairsEscapedAuthorityBeforeGenerationMutation(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $publicId = str_repeat('f', 32);
        $pdo->exec("CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,tombstoned_at TEXT,PRIMARY KEY(application_pk,resource_type,external_id));
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0);
            INSERT INTO api_v2_directory_resource_state VALUES('client','{$publicId}',2,'" . hash('sha256', '') . "',0);
            INSERT INTO api_v2_directory_external_bindings VALUES(3,'client','escaped','{$publicId}',1,'" . str_repeat('a', 64) . "','active',NULL);");
        $pdo->beginTransaction();
        self::assertFalse(\api_v2_directory_record_delete($pdo, 'client', $publicId));
        $pdo->commit();
        self::assertSame('tombstoned', $pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='escaped'")->fetchColumn());
        self::assertSame(1, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());

        $pdo->exec("UPDATE api_v2_directory_external_bindings SET status='active',tombstoned_at=NULL; UPDATE api_v2_directory_authorization_state SET authorization_generation=9223372036854775807; UPDATE api_v2_directory_resource_state SET revision=1,present=1");
        $pdo->beginTransaction();
        try {
            \api_v2_directory_record_delete($pdo, 'client', $publicId);
            self::fail('Expected exhausted generation preflight to reject the repair.');
        } catch (\RuntimeException) {
            $pdo->rollBack();
        }
        self::assertSame('active', $pdo->query("SELECT status FROM api_v2_directory_external_bindings WHERE external_id='escaped'")->fetchColumn());
        self::assertSame([1, 1], array_map('intval', $pdo->query("SELECT revision,present FROM api_v2_directory_resource_state WHERE public_id='{$publicId}'")->fetch(PDO::FETCH_NUM)));
    }

    public function testBindingLifecycleRollbackLeavesRevisionBindingAndGenerationUntouched(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/api_v2_directory_revision.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE api_v2_directory_authorization_state(application_pk INTEGER PRIMARY KEY,authorization_generation INTEGER NOT NULL);
            CREATE TABLE api_v2_directory_resource_state(resource_type TEXT,public_id TEXT,revision INTEGER,projection_sha256 TEXT,present INTEGER,PRIMARY KEY(resource_type,public_id));
            CREATE TABLE api_v2_directory_resource_changes(resource_type TEXT,public_id TEXT,revision INTEGER,action TEXT,PRIMARY KEY(resource_type,public_id,revision));
            CREATE TABLE api_v2_directory_external_bindings(application_pk INTEGER,resource_type TEXT,external_id BLOB,public_id TEXT,resource_revision INTEGER,resource_projection_sha256 TEXT,status TEXT,tombstoned_at TEXT,PRIMARY KEY(application_pk,resource_type,external_id));
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,email TEXT,phone TEXT,client_type TEXT,organization_id INTEGER,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country TEXT);
            INSERT INTO api_v2_directory_authorization_state VALUES(3,0); INSERT INTO clients(id,public_id,name) VALUES(1,'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','Example');");
        $pdo->beginTransaction(); \api_v2_directory_record($pdo, 'client', 1); $pdo->commit();
        $hash = (string)$pdo->query('SELECT projection_sha256 FROM api_v2_directory_resource_state')->fetchColumn();
        $pdo->prepare("INSERT INTO api_v2_directory_external_bindings VALUES(3,'client','rollback/external','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',1,?,'active',NULL)")->execute([$hash]);
        $pdo->exec("CREATE TRIGGER stop_generation BEFORE UPDATE ON api_v2_directory_authorization_state BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        $pdo->beginTransaction();
        try {
            \api_v2_directory_record_delete($pdo, 'client', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
            self::fail('Expected authorization-generation failure.');
        } catch (\Throwable) {
            $pdo->rollBack();
        }
        self::assertSame(1, (int)$pdo->query('SELECT revision FROM api_v2_directory_resource_state')->fetchColumn());
        self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM api_v2_directory_external_bindings WHERE status='active'")->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT authorization_generation FROM api_v2_directory_authorization_state')->fetchColumn());
    }

    public function testBindingStatusLocksCanonicalRowsBeforeTheBinding(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/api_v2_binding_status.php');
        $unlockedLookup = strpos($source, "SELECT public_id,status FROM api_v2_directory_external_bindings");
        $liveLock = strpos($source, "SELECT * FROM ' . \$table . ' WHERE public_id=? LIMIT 2' . \$lock");
        $stateLock = strpos($source, "SELECT present,CAST(revision AS CHAR) state_revision,projection_sha256");
        $finalBindingLock = strpos($source, "FROM api_v2_directory_external_bindings binding LEFT JOIN api_v2_directory_authorization_state");
        self::assertNotFalse($unlockedLookup);
        self::assertNotFalse($liveLock);
        self::assertNotFalse($stateLock);
        self::assertNotFalse($finalBindingLock);
        self::assertLessThan($liveLock, $unlockedLookup);
        self::assertLessThan($stateLock, $liveLock);
        self::assertLessThan($finalBindingLock, $stateLock);
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
