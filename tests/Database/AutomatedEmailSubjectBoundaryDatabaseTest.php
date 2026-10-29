<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/services/EmailProviderManager.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real MySQL character-width regression; temporary tables, no transport calls. */
final class AutomatedEmailSubjectBoundaryDatabaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('EMAIL_BOUNDARY_ALLOW') !== 'isolated-disposable-only') {
            self::markTestSkipped('Explicit disposable email-boundary MySQL opt-in required.');
        }
        $dsn = (string)getenv('EMAIL_BOUNDARY_DSN');
        if (!preg_match('/^mysql:host=127\.0\.0\.1;port=3306;dbname=pa_email_boundary_[a-f0-9]{16};charset=utf8mb4$/D', $dsn)) {
            self::fail('Email-boundary tests require the exact isolated loopback database DSN.');
        }
        $this->pdo = new PDO($dsn, (string)getenv('EMAIL_BOUNDARY_USER'), (string)getenv('EMAIL_BOUNDARY_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        // Same delivery columns and VARCHAR(500) contract as migration 0043.
        // A connection-local table avoids changing any persistent application table.
        $this->pdo->exec('CREATE TEMPORARY TABLE email_delivery_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            message_key VARCHAR(190) NOT NULL,
            provider_connection_id INT NULL,
            document_type ENUM("quote","contract","invoice","project_invoice","onboarding","notification","other") NOT NULL DEFAULT "other",
            document_id INT NULL,
            document_revision INT NULL,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            provider_message_id VARCHAR(255) NULL,
            status ENUM("pending","sent","failed","unknown") NOT NULL DEFAULT "pending",
            error_message VARCHAR(1000) NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_email_delivery_message_key(message_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS email_delivery_log');
        }
    }

    public static function boundarySubjects(): iterable
    {
        yield '500 ASCII characters' => [str_repeat('A', 500)];
        yield '500 multibyte characters' => [str_repeat('é🦉', 250)];
    }

    #[DataProvider('boundarySubjects')]
    public function testPrefixedSubjectFitsLogWithoutTruncatingOutboundMessage(string $original): void
    {
        self::assertSame('mysql', $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(500, mb_strlen($original));
        $message = new EmailMessage('client@example.invalid', $original, '<p>Synthetic content</p>', 'sender@example.invalid', 'Synthetic Company');
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 0]);
        $outboundSubject = '[Automated] ' . $original;
        self::assertSame(512, mb_strlen($message->subject));
        self::assertSame($outboundSubject, $message->subject);

        $manager = new EmailProviderManager($this->pdo, []);
        $reserve = new ReflectionMethod($manager, 'reserveDelivery');
        $reservation = $reserve->invoke($manager, 'synthetic-subject-boundary', 1, $message, ['document_type' => 'notification']);
        self::assertSame('reserved', $reservation['status']);
        $stored = $this->pdo->query('SELECT subject,CHAR_LENGTH(subject) AS character_count FROM email_delivery_log')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertSame(500, (int)$stored['character_count']);
        self::assertSame(mb_substr($outboundSubject, 0, 500), $stored['subject']);
        self::assertTrue(mb_check_encoding($stored['subject'], 'UTF-8'));
        self::assertSame($outboundSubject, $message->subject);
        self::assertStringContainsString($original, $message->body);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM email_delivery_log')->fetchColumn());
    }
}
