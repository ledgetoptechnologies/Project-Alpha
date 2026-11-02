<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/services/EmailService.php';
require_once dirname(__DIR__, 2) . '/src/services/EmailProviderManager.php';

use PHPUnit\Framework\TestCase;

/** Real loopback SMTP only; run explicitly inside a network-disabled test container. */
final class AutomatedEmailSmtpTest extends TestCase
{
    public function testProviderResolvedPresentationReachesSmtpOnceAndPreservesIssuerAndPdf(): void
    {
        if (getenv('AUTOMATED_EMAIL_SMTP_ALLOW') !== 'isolated-loopback-only') {
            self::markTestSkipped('Requires an isolated loopback SMTP container.');
        }
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $directory = sys_get_temp_dir() . '/pa-email-smtp-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $process = null;
        global $appConfig, $pdo;
        $oldConfig = $appConfig;
        $oldPdo = $pdo ?? null;
        $oldKey = getenv('APP_ENCRYPTION_KEY');
        try {
            putenv('APP_ENCRYPTION_KEY=synthetic-automated-email-smtp-only');
            $server = <<<'PHP'
<?php
$directory=$argv[1];
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if (!$server) exit(1);
file_put_contents($directory.'/port',explode(':',stream_socket_get_name($server,false))[1]);
$count=0;
while ($client=@stream_socket_accept($server,20)) {
    stream_set_timeout($client,10);
    fwrite($client,"220 synthetic SMTP capture\r\n");
    while (($line=fgets($client))!==false) {
        $command=strtoupper(strtok(trim($line),' '));
        if ($command==='EHLO'||$command==='HELO') fwrite($client,"250 synthetic.local\r\n");
        elseif ($command==='DATA') {
            fwrite($client,"354 End with dot\r\n");$data='';
            while (($body=fgets($client))!==false && $body!==".\r\n") $data.=str_starts_with($body,'..')?substr($body,1):$body;
            file_put_contents($directory.'/message-'.(++$count).'.eml',$data);
            fwrite($client,"250 captured\r\n");
        } elseif ($command==='QUIT') {fwrite($client,"221 bye\r\n");break;}
        else fwrite($client,"250 accepted\r\n");
    }
    fclose($client);
}
fclose($server);
PHP;
            file_put_contents($directory . '/smtp.php', $server);
            $process = proc_open([PHP_BINARY, $directory . '/smtp.php', $directory],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes);
            self::assertIsResource($process);
            for ($attempt = 0; $attempt < 100 && !is_file($directory . '/port'); $attempt++) usleep(20000);
            self::assertFileExists($directory . '/port');
            $port = (int)file_get_contents($directory . '/port');
            self::assertGreaterThan(0, $port);

            $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $now = static fn(): string => gmdate('Y-m-d H:i:s');
            if ($pdo instanceof \Pdo\Sqlite) $pdo->createFunction('NOW', $now);
            else $pdo->sqliteCreateFunction('NOW', $now);
            $pdo->exec('CREATE TABLE email_provider_connections (id INTEGER PRIMARY KEY,provider TEXT,sender_email TEXT,sender_name TEXT,status TEXT,credentials_enc TEXT,last_verified_at TEXT,last_error TEXT)');
            $pdo->exec('CREATE TABLE email_provider_state (id INTEGER PRIMARY KEY,active_connection_id INTEGER)');
            $pdo->exec('CREATE TABLE email_delivery_log (id INTEGER PRIMARY KEY AUTOINCREMENT,message_key TEXT UNIQUE,provider_connection_id INTEGER,document_type TEXT,document_id INTEGER,document_revision INTEGER,recipient TEXT,subject TEXT,status TEXT,provider_message_id TEXT,error_message TEXT,sent_at TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY,issuer_mode TEXT,issuer_snapshot TEXT)');
            $credentials = crypto_encrypt(json_encode(['host' => '127.0.0.1', 'port' => $port, 'secure' => 'none', 'username' => '', 'password' => ''], JSON_THROW_ON_ERROR));
            self::assertIsString($credentials);
            $pdo->prepare('INSERT INTO email_provider_connections VALUES (1,"smtp","no-reply@example.test","Synthetic Provider","configured",?,NULL,NULL)')->execute([$credentials]);
            $pdo->exec('INSERT INTO email_provider_state VALUES (1,1)');
            $snapshot = json_encode(['email' => ['display_name' => 'Synthetic Document Contact', 'reply_to' => 'contact@example.test']], JSON_THROW_ON_ERROR);
            $pdo->prepare('INSERT INTO invoices VALUES (18,"company",?)')->execute([$snapshot]);
            $appConfig = ['brand_name' => 'Synthetic Test Company', 'smtp_from_email' => 'unused@example.test',
                'email_no_reply_notice_enabled' => 1, 'email_no_reply_notice_text' => 'Unmonitored mailbox. Contact the office for help.'];

            $pdf = "%PDF-1.4\n%synthetic attachment only\n%%EOF\n";
            $options = ['message_key' => 'automated-email-smtp-invoice-18', 'document_type' => 'invoice', 'document_id' => 18,
                'attachments' => [['filename' => 'invoice_I-18.pdf', 'content' => $pdf, 'mime' => 'application/pdf']]];
            $first = EmailService::sendEmail('client@example.test', 'Invoice I-18 is due November 4, 2026', '<p>Outstanding balance: <strong>USD 26.40</strong></p><p><a href="https://example.test/invoice">View invoice</a></p>', $options);
            self::assertTrue($first[0], (string)$first[1]);
            $repeat = EmailService::sendEmail('client@example.test', 'Invoice I-18 is due November 4, 2026', '<p>Repeated body must not send.</p>', $options);
            self::assertTrue($repeat[0]);
            self::assertSame('Already sent', $repeat[1]);
            self::assertCount(1, glob($directory . '/message-*.eml'));
            $mime = (string)file_get_contents($directory . '/message-1.eml');
            $parts = self::mimeParts($mime);
            $headers = self::headers($mime);
            self::assertSame('[Automated] Invoice I-18 is due November 4, 2026', $headers['subject']);
            self::assertStringContainsString('AUTOMATED | Synthetic Document Contact', $headers['from']);
            self::assertStringContainsString('no-reply@example.test', $headers['from']);
            self::assertStringContainsString('contact@example.test', $headers['reply-to']);
            self::assertStringContainsString('AUTOMATED MESSAGE', $parts['text/html'][0]);
            self::assertStringContainsString('Replies go to your document contact at contact@example.test.', $parts['text/html'][0]);
            self::assertStringNotContainsString('Unmonitored mailbox', $parts['text/html'][0]);
            self::assertStringContainsString('https://example.test/invoice', $parts['text/html'][0]);
            self::assertStringContainsString('USD 26.40', $parts['text/plain'][0]);
            self::assertStringContainsString('AUTOMATED MESSAGE', $parts['text/plain'][0]);
            self::assertSame($pdf, $parts['application/pdf'][0]);
            self::assertStringContainsString('invoice_I-18.pdf', $mime);
            self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM email_delivery_log')->fetchColumn());
            self::assertSame('[Automated] Invoice I-18 is due November 4, 2026', $pdo->query('SELECT subject FROM email_delivery_log')->fetchColumn());
            self::assertSame('sent', $pdo->query('SELECT status FROM email_delivery_log')->fetchColumn());

            $plain = EmailService::sendEmail('client@example.test', 'Upcoming invoice reminder', "Balance: USD 26.40\nDue: November 4, 2026", ['message_key' => 'automated-email-smtp-plain', 'is_html' => false]);
            self::assertTrue($plain[0], (string)$plain[1]);
            self::assertCount(2, glob($directory . '/message-*.eml'));
            $plainMime = (string)file_get_contents($directory . '/message-2.eml');
            $plainHeaders = self::headers($plainMime);
            self::assertSame('[Automated] Upcoming invoice reminder', $plainHeaders['subject']);
            self::assertStringContainsString('NO-REPLY | Synthetic Provider', $plainHeaders['from']);
            self::assertArrayNotHasKey('reply-to', $plainHeaders);
            $plainParts = self::mimeParts($plainMime);
            self::assertStringContainsString('AUTOMATED MESSAGE', $plainParts['text/plain'][0]);
            self::assertStringContainsString('Unmonitored mailbox. Contact the office for help.', $plainParts['text/plain'][0]);
            self::assertStringContainsString('Balance: USD 26.40', $plainParts['text/plain'][0]);
            self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM email_delivery_log WHERE status="sent"')->fetchColumn());
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            $appConfig = $oldConfig;
            $pdo = $oldPdo;
            $oldKey === false ? putenv('APP_ENCRYPTION_KEY') : putenv('APP_ENCRYPTION_KEY=' . $oldKey);
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    private static function headers(string $mime): array
    {
        $head = explode("\r\n\r\n", $mime, 2)[0];
        $head = preg_replace('/\r\n[ \t]+/', ' ', $head);
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = iconv_mime_decode(trim($value), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        }
        return $headers;
    }

    private static function mimeParts(string $mime): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $mime, 2), 2, '');
        $headers = self::headers($mime);
        $type = $headers['content-type'] ?? 'text/plain';
        if (preg_match('/boundary="([^"]+)"/', $type, $match)) {
            $result = [];
            foreach (array_slice(explode('--' . $match[1], $body), 1) as $part) {
                if (str_starts_with($part, '--')) continue;
                foreach (self::mimeParts(ltrim($part, "\r\n")) as $kind => $values) $result[$kind] = array_merge($result[$kind] ?? [], $values);
            }
            return $result;
        }
        $body = preg_replace('/\r\n$/', '', $body);
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        if ($encoding === 'base64') $body = (string)base64_decode($body, true);
        elseif ($encoding === 'quoted-printable') $body = quoted_printable_decode($body);
        return [strtolower(trim(explode(';', $type, 2)[0])) => [$body]];
    }
}
