<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/public_links.php';

final class PublicLinkEmailReuseTest extends TestCase
{
    public function testActiveInvoiceAndProjectInvoiceTokensAreReusedUnchanged(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = $this->database();
        $pdo->exec(
            "INSERT INTO public_links
             (document_type,document_id,token,expires_at,expire_when_paid,revoked)
             VALUES
             ('invoice',11,'existing-invoice-token',NULL,1,0),
             ('project_invoice',22,'existing-project-token','2099-01-01 00:00:00',1,0)"
        );

        $invoice = pa_public_link_reuse_or_create($pdo, 'invoice', 11, null, true);
        $projectInvoice = pa_public_link_reuse_or_create($pdo, 'project_invoice', 22, null, true);

        self::assertFalse($invoice['created']);
        self::assertSame('existing-invoice-token', $invoice['token']);
        self::assertFalse($projectInvoice['created']);
        self::assertSame('existing-project-token', $projectInvoice['token']);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM public_links')->fetchColumn());
        self::assertSame(
            ['existing-invoice-token', 'existing-project-token'],
            $pdo->query('SELECT token FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testRevokedAndExpiredLinksRemainHistoricalWhenANewLinkIsNeeded(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $pdo = $this->database();
        $pdo->exec(
            "INSERT INTO public_links
             (document_type,document_id,token,expires_at,expire_when_paid,revoked)
             VALUES
             ('invoice',33,'revoked-token',NULL,1,1),
             ('invoice',33,'expired-token','2000-01-01 00:00:00',1,0)"
        );

        $created = pa_public_link_reuse_or_create($pdo, 'invoice', 33, null, true);

        self::assertTrue($created['created']);
        self::assertNotContains($created['token'], ['revoked-token', 'expired-token']);
        $history = $pdo->query('SELECT token,revoked,expires_at FROM public_links ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('revoked-token', $history[0]['token']);
        self::assertSame(1, (int)$history[0]['revoked']);
        self::assertSame('expired-token', $history[1]['token']);
        self::assertSame('2000-01-01 00:00:00', $history[1]['expires_at']);
        self::assertSame($created['token'], $history[2]['token']);
        self::assertSame(0, (int)$history[2]['revoked']);
    }

    public function testEveryInvoiceEmailPathUsesTheNonRotatingHelper(): void
    {
        $root = dirname(__DIR__, 2);
        $manual = (string)file_get_contents($root . '/src/controllers/email_send.php');
        $automated = (string)file_get_contents($root . '/src/utils/invoice_notifications.php');
        $project = (string)file_get_contents($root . '/src/utils/project_invoice_notifications.php');
        $automated = str_replace("\r\n", "\n", $automated);

        self::assertStringContainsString('pa_public_link_reuse_or_create($pdo, $type, $id', $manual);
        self::assertStringContainsString("pa_public_link_reuse_or_create(\n                    \$pdo, 'invoice'", $automated);
        self::assertStringContainsString('project_invoice_ensure_public_link', $project);
        foreach ([$manual, $automated, $project] as $source) {
            self::assertStringContainsString("!empty(\$publicLink['created'])", $source);
            self::assertStringNotContainsString('UPDATE public_links SET revoked=1 WHERE id=?', $source);
        }
        $helper = (string)file_get_contents($root . '/src/utils/public_links.php');
        self::assertStringContainsString("'project_invoice' => 'project_invoices'", $helper);
        self::assertStringContainsString("' FOR UPDATE'", $helper);
        self::assertLessThan(
            strpos($helper, '$active = pa_public_link_active'),
            strpos($helper, '$document = $pdo->prepare(\'SELECT id FROM \'')
        );
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE public_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_type TEXT NOT NULL,
                document_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                redirect TEXT NULL,
                expires_at TEXT NULL,
                expire_when_paid INTEGER NOT NULL DEFAULT 0,
                revoked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE project_invoices (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE contracts (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO invoices (id) VALUES (11),(33)');
        $pdo->exec('INSERT INTO project_invoices (id) VALUES (22)');
        return $pdo;
    }
}
