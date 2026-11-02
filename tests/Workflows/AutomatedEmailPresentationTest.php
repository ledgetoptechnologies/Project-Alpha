<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/services/EmailProviders.php';
require_once dirname(__DIR__, 2) . '/src/services/EmailPresentation.php';

use PHPUnit\Framework\TestCase;

final class AutomatedEmailPresentationTest extends TestCase
{
    public function testHtmlPresentationRemainsClearlyAutomatedWhenOptionalNoticeIsOff(): void
    {
        $message = $this->message();
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 0]);

        self::assertSame('[Automated] Invoice I-18 is due', $message->subject);
        self::assertSame('AUTOMATED | Example Company', $message->fromName);
        self::assertStringContainsString('automated', strtolower($message->body));
        self::assertStringContainsString('<p>Outstanding balance: <strong>$263.75</strong></p>', $message->body);
        self::assertStringContainsString('href="https://example.test/invoice?token=synthetic"', $message->body);
        self::assertStringNotContainsString('Please do not reply', $message->body);
        self::assertTrue($message->isHtml);
    }

    public function testNoReplyNoticeAndSenderAreProminentWhenConfigured(): void
    {
        $message = $this->message();
        EmailPresentation::apply($message, [
            'email_no_reply_notice_enabled' => 1,
            'email_no_reply_notice_text' => 'Unmonitored mailbox. Contact our office for help.',
        ]);

        self::assertSame('NO-REPLY | Example Company', $message->fromName);
        self::assertStringContainsString('Unmonitored mailbox. Contact our office for help.', $message->body);
        self::assertStringContainsString('automated', strtolower($message->body));
        self::assertNull($message->replyTo);
    }

    public function testCreatorReplyAddressPreventsContradictoryNoReplyPresentation(): void
    {
        $message = $this->message();
        $message->fromName = 'Colin · Example Company';
        $message->replyTo = 'colin@example.test';
        EmailPresentation::apply($message, [
            'email_no_reply_notice_enabled' => 1,
            'email_no_reply_notice_text' => 'CUSTOM NO-REPLY MESSAGE MUST NOT APPEAR',
        ]);

        self::assertSame('AUTOMATED | Colin · Example Company', $message->fromName);
        self::assertSame('colin@example.test', $message->replyTo);
        self::assertStringContainsString('colin@example.test', $message->body);
        self::assertStringNotContainsString('CUSTOM NO-REPLY MESSAGE MUST NOT APPEAR', $message->body);
        self::assertStringNotContainsString('Please do not reply', $message->body);
    }

    public function testPresentationIsIdempotentForTheSameMessage(): void
    {
        $message = $this->message();
        $config = ['email_no_reply_notice_enabled' => 1];
        EmailPresentation::apply($message, $config);
        $presented = [$message->subject, $message->fromName, $message->body];
        EmailPresentation::apply($message, $config);

        self::assertSame($presented, [$message->subject, $message->fromName, $message->body]);
        self::assertSame(1, substr_count($message->subject, '[Automated]'));
        self::assertTrue($message->presentationApplied);
    }

    public function testExistingSubjectPrefixIsRecognizedWithoutCaseSensitivity(): void
    {
        $message = $this->message();
        $message->subject = '[AUTOMATED] Invoice already labeled';
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 0]);

        self::assertSame('[automated] invoice already labeled', strtolower($message->subject));
        self::assertSame(1, substr_count(strtolower($message->subject), '[automated]'));
    }

    public function testPlainTextHasLeadingAutomationNoticeAndPreservesContent(): void
    {
        $message = $this->message();
        $message->isHtml = false;
        $message->body = "Your verification code is 12345678.\nIt expires in ten minutes.";
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 0]);

        self::assertStringContainsString('automated', strtolower(explode("\n", $message->body)[0]));
        self::assertStringContainsString("Your verification code is 12345678.\nIt expires in ten minutes.", $message->body);
        self::assertStringNotContainsString('<table', $message->body);
        self::assertFalse($message->isHtml);
    }

    public function testHtmlHeadingAndSenderCannotInjectMarkup(): void
    {
        $message = $this->message();
        $message->subject = 'Invoice <img src=x onerror=alert(1)> & next';
        $message->fromName = 'Company <script>bad()</script> & Partners';
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 0]);

        self::assertStringContainsString('Invoice &lt;img src=x onerror=alert(1)&gt; &amp; next', $message->body);
        self::assertStringContainsString('Company &lt;script&gt;bad()&lt;/script&gt; &amp; Partners', $message->body);
        self::assertStringNotContainsString('<script>', $message->body);
        self::assertStringNotContainsString('<img src=x', $message->body);
    }

    public function testFullHtmlDocumentKeepsContentWithoutNestingDocuments(): void
    {
        $message = $this->message();
        $message->body = '<!doctype html><html><head><title>Old title</title></head><body><p>Preserve this original content</p></body></html>';
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 0]);

        self::assertStringContainsString('<p>Preserve this original content</p>', $message->body);
        self::assertLessThanOrEqual(1, preg_match_all('/<html(?:\s|>)/i', $message->body));
        self::assertLessThanOrEqual(1, preg_match_all('/<body(?:\s|>)/i', $message->body));
        self::assertLessThanOrEqual(1, preg_match_all('/<!doctype/i', $message->body));
    }

    public function testMimePreservesUtf8IdentityReplyAddressAttachmentsAndMessageId(): void
    {
        $message = $this->message();
        $message->subject = 'Invoice café — I-18';
        $message->fromName = 'Élodie · Example Company';
        $message->replyTo = 'accounts@example.test';
        $message->body = '<p>Thank you, José. Your balance is €20.00.</p>';
        $message->attachments = [['filename' => 'invoice.pdf', 'content' => '%PDF-synthetic-only', 'mime' => 'application/pdf']];
        $identity = [$message->to, $message->fromEmail, $message->replyTo, $message->messageId, $message->attachments];
        EmailPresentation::apply($message, ['email_no_reply_notice_enabled' => 1]);
        self::assertSame($identity, [$message->to, $message->fromEmail, $message->replyTo, $message->messageId, $message->attachments]);

        [$ok, $mime, $error] = mailer_build_mime(
            $message->to, $message->subject, $message->body, $message->fromEmail,
            $message->fromName, $message->attachments, $message->isHtml,
            $message->messageId, $message->replyTo
        );
        self::assertTrue($ok, $error);
        self::assertSame('[Automated] Invoice café — I-18', $this->header($mime, 'Subject'));
        self::assertStringContainsString('AUTOMATED | Élodie · Example Company', $this->header($mime, 'From'));
        self::assertStringContainsString('<sender@example.test>', $this->header($mime, 'From'));
        self::assertStringContainsString('client@example.test', $this->header($mime, 'To'));
        self::assertSame('accounts@example.test', $this->header($mime, 'Reply-To'));
        self::assertSame('<synthetic-automated-email@example.test>', $this->header($mime, 'Message-ID'));
        self::assertStringContainsString('application/pdf', $mime);
        self::assertStringContainsString('invoice.pdf', $mime);
        self::assertStringContainsString(base64_encode('%PDF-synthetic-only'), $mime);
        self::assertStringContainsString('José', $message->body);
        self::assertStringContainsString('€20.00', $message->body);
    }

    private function message(): EmailMessage
    {
        return new EmailMessage(
            'client@example.test', 'Invoice I-18 is due',
            '<p>Outstanding balance: <strong>$263.75</strong></p><p><a href="https://example.test/invoice?token=synthetic">View invoice</a></p>',
            'sender@example.test', 'Example Company', [], true,
            '<synthetic-automated-email@example.test>'
        );
    }

    private function header(string $mime, string $name): string
    {
        preg_match('/^' . preg_quote($name, '/') . ':([^\r\n]*(?:\r?\n[ \t]+[^\r\n]*)*)/mi', $mime, $matches);
        return trim(mb_decode_mimeheader((string)preg_replace('/\r?\n[ \t]+/', ' ', $matches[1] ?? '')));
    }
}
