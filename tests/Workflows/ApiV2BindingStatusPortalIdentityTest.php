<?php
declare(strict_types=1);
namespace Tests\Workflows;
use PHPUnit\Framework\TestCase;
final class ApiV2BindingStatusPortalIdentityTest extends TestCase
{
    private \PDO $pdo;
    private array $client;
    protected function setUp(): void
    {
        require_once dirname(__DIR__,2).'/src/utils/api_v2_binding_status.php';
        $this->pdo=new \PDO('sqlite::memory:',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("CREATE TABLE organizations(id INTEGER,public_id TEXT,archived INTEGER,deleted_at TEXT);
          CREATE TABLE portal_principals(id INTEGER,public_id TEXT,source_version TEXT,authorization_version INTEGER,enabled INTEGER,revoked_at TEXT);
          CREATE TABLE portal_client_login_eligibility(client_id INTEGER,portal_principal_id INTEGER,manual_state TEXT,eligibility_status TEXT,source_version TEXT);
          CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER);
          CREATE TABLE portal_client_access_roots(root_type TEXT,root_public_id TEXT,access_state TEXT);");
        $this->client=['id'=>9,'public_id'=>str_repeat('a',32),'archived'=>0,'deleted_at'=>null,'organization_id'=>null];
        $this->pdo->exec("INSERT INTO portal_principals VALUES(3,'".str_repeat('b',32)."','principal-v1',1,1,NULL);
          INSERT INTO portal_client_login_eligibility VALUES(9,3,'automatic','eligible','eligibility-v1');
          INSERT INTO portal_principal_clients VALUES(3,9);
          INSERT INTO portal_client_access_roots VALUES('standalone_client','".str_repeat('a',32)."','active');");
    }
    private function proof(): array { return \api_v2_binding_status_client_portal_identity($this->pdo,$this->client); }
    public function testExactOwnAssociationHasNoEmailAndVersionChangesWithPrincipalAuthority(): void
    {
        $first=$this->proof();self::assertSame('eligible',$first['state']);self::assertSame(str_repeat('b',32),$first['principalPublicId']);
        self::assertSame(['state','principalPublicId','associationVersion'],array_keys($first));self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/',$first['associationVersion']);
        $this->pdo->exec('UPDATE portal_principals SET authorization_version=2');
        self::assertNotSame($first['associationVersion'],$this->proof()['associationVersion']);
    }
    public function testOrganizationRootUsesExactParentAndRevocationBlocksIt(): void
    {
        $this->client['organization_id']=4;
        $this->pdo->exec("INSERT INTO organizations VALUES(4,'".str_repeat('c',32)."',0,NULL);INSERT INTO portal_client_access_roots VALUES('organization','".str_repeat('c',32)."','active')");
        self::assertSame('eligible',$this->proof()['state']);
        $this->pdo->exec("UPDATE portal_client_access_roots SET access_state='revoked' WHERE root_type='organization'");
        self::assertSame('revoked',$this->proof()['state']);self::assertNull($this->proof()['principalPublicId']);
    }
    public function testArchivedDeletedOrMissingParentNeverInheritsStaleActiveRoot(): void
    {
        $this->client['organization_id']=4;
        $this->pdo->exec("INSERT INTO organizations VALUES(4,'".str_repeat('c',32)."',1,NULL);INSERT INTO portal_client_access_roots VALUES('organization','".str_repeat('c',32)."','active')");
        self::assertSame('revoked',$this->proof()['state']);
        $this->pdo->exec("UPDATE organizations SET archived=0,deleted_at='2026-10-18 01:00:00'");
        self::assertSame('revoked',$this->proof()['state']);
        $this->pdo->exec('DELETE FROM organizations');self::assertSame('revoked',$this->proof()['state']);
    }
    public function testAmbiguousOrOtherClientAssociationsNeverGrantOwnProfile(): void
    {
        $this->pdo->exec('INSERT INTO portal_principal_clients VALUES(3,10)');
        self::assertSame('review_required',$this->proof()['state']);
        $this->pdo->exec('DELETE FROM portal_principal_clients WHERE client_id=10;INSERT INTO portal_client_login_eligibility VALUES(10,3,\'automatic\',\'eligible\',\'other-v1\')');
        self::assertSame('review_required',$this->proof()['state']);
        $this->pdo->exec('DELETE FROM portal_client_login_eligibility WHERE client_id=10;DELETE FROM portal_principal_clients WHERE client_id=9;INSERT INTO portal_principal_clients VALUES(3,10)');
        self::assertSame('review_required',$this->proof()['state']);
    }
    public function testRevokedInactiveAndMissingSchemasFailClosed(): void
    {
        $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='revoked'");self::assertSame('revoked',$this->proof()['state']);
        $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='automatic';UPDATE portal_principals SET enabled=0");self::assertSame('revoked',$this->proof()['state']);
        $this->client['archived']=1;self::assertSame('revoked',$this->proof()['state']);$this->client['archived']=0;
        $this->pdo->exec('DROP TABLE portal_client_login_eligibility');self::assertSame('unavailable',$this->proof()['state']);
    }
}
