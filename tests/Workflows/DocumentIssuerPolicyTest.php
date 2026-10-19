<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\DocumentIssuerPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class DocumentIssuerPolicyTest extends TestCase
{
    private const CONFIG = ['from_company' => 'Drone Services', 'from_name' => 'Beau', 'from_email' => 'billing@example.test', 'from_address_line1' => '1 Company Way'];

    public function testDefaultIsCompanyRegardlessOfPersonalProfile(): void
    {
        $issuer = DocumentIssuerPolicy::resolve(self::CONFIG, ['id' => 2, 'username' => 'Colin', 'document_sender_enabled' => 1, 'document_sender_name' => 'Colin']);
        self::assertSame('Drone Services', $issuer['sender']['name']);
        self::assertNull($issuer['email']['reply_to']);
        self::assertSame(2, $issuer['original_creator_id']);
    }

    public function testCreatorModeUsesOriginalCreatorWithoutRequiringOptInProfile(): void
    {
        $issuer = DocumentIssuerPolicy::resolve(self::CONFIG + ['document_issuer_mode' => 'creator'], ['id' => 2, 'username' => 'Colin', 'email' => 'colin@example.test']);
        self::assertSame('Colin', $issuer['sender']['name']);
        self::assertSame('billing@example.test', $issuer['email']['from_address']);
        self::assertSame('colin@example.test', $issuer['email']['reply_to']);
        self::assertSame('1 Company Way', $issuer['sender']['address_line1']);
        self::assertSame('Colin · Drone Services', $issuer['email']['display_name']);
    }

    public function testExplicitCompanyOverrideWinsAndMissingCreatorIsCompanyFallback(): void
    {
        $config = self::CONFIG + ['document_issuer_mode' => 'creator'];
        self::assertSame('company', DocumentIssuerPolicy::resolve($config, ['id' => 2, 'username' => 'Colin'], 'company')['mode']);
        $issuer = DocumentIssuerPolicy::resolve($config, null);
        self::assertSame('Drone Services', $issuer['sender']['name']);
        self::assertNull($issuer['email']['reply_to']);
    }

    public function testCreatorProfileCannotReplaceCompanyOrTransportAndRejectsHeaderInjection(): void
    {
        $issuer = DocumentIssuerPolicy::resolve(self::CONFIG, ['id' => 2, 'username' => 'Colin', 'email' => "colin@example.test\r\nBcc: other@example.test", 'document_sender_enabled' => 1, 'document_sender_company' => 'Other Company', 'document_sender_address_line1' => 'Other Address'], 'creator');
        self::assertSame('Drone Services', $issuer['sender']['company']);
        self::assertSame('1 Company Way', $issuer['sender']['address_line1']);
        self::assertSame('billing@example.test', $issuer['email']['from_address']);
        self::assertNull($issuer['email']['reply_to']);
    }

    public function testMimeReplyToUsesCreatorButFromStaysCompany(): void
    {
        require_once __DIR__ . '/../../src/utils/mailer.php';
        [$ok,$mime] = mailer_build_mime('client@example.test','Invoice','Body','billing@example.test','Colin · Drone Services',[],true,null,'colin@example.test');
        self::assertTrue($ok);
        self::assertStringContainsString('Reply-To: colin@example.test',$mime);
        self::assertStringContainsString('<billing@example.test>',$mime);
        self::assertStringNotContainsString('From: colin@example.test',$mime);
    }

    public function testInvalidModeIsRejected(): void
    {
        $this->expectException(DomainException::class);
        DocumentIssuerPolicy::resolve(self::CONFIG, null, 'some-person');
    }
}
