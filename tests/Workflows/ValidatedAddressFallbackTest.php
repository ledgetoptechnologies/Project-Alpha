<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ManagedDeliveryIntentSender;
use App\Services\PortalProjectionOutboxSender;
use App\Services\ValidatedAddressTransportFixture as Transport;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatedAddressFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        require dirname(__DIR__) . '/fixtures/validated-address-transport.php';
    }

    public function testBothSendersPinEveryValidatedAddressForOnePost(): void
    {
        Transport::$dns = [
            ['ipv6' => '2606:4700:4700::1111'],
            ['ip' => '1.1.1.1'],
            ['ip' => '8.8.8.8'],
            ['ip' => '1.1.1.1'],
        ];
        foreach ([new PortalProjectionOutboxSender(), new ManagedDeliveryIntentSender()] as $sender) {
            Transport::$opened = Transport::$sent = 0;
            $headers = ['Content-Type: application/json', 'Authorization: synthetic-test-only'];
            $body = '{"deliveryId":"immutable-test-id"}';
            $result = $sender->curlTransport('https://receiver.example:8443/events', $headers, $body, 5);
            self::assertSame(1, Transport::$opened);
            self::assertSame(1, Transport::$sent);
            self::assertSame(['receiver.example:8443:[2606:4700:4700::1111],1.1.1.1,8.8.8.8'], Transport::$options[CURLOPT_RESOLVE]);
            self::assertTrue(Transport::$options[CURLOPT_POST]);
            self::assertSame($body, Transport::$options[CURLOPT_POSTFIELDS]);
            self::assertSame($headers, Transport::$options[CURLOPT_HTTPHEADER]);
            self::assertFalse(Transport::$options[CURLOPT_FOLLOWLOCATION]);
            self::assertSame('', Transport::$options[CURLOPT_PROXY]);
            self::assertSame('*', Transport::$options[CURLOPT_NOPROXY]);
            self::assertTrue(Transport::$options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, Transport::$options[CURLOPT_SSL_VERIFYHOST]);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                self::assertSame(CURLPROTO_HTTPS, Transport::$options[CURLOPT_PROTOCOLS]);
            }
            self::assertSame(200, $result['status']);
            self::assertSame('{"ok":true}', $result['body']);
        }
    }

    public function testSingleFamilyAndLiteralAddressesKeepValidPinning(): void
    {
        foreach ([
            ['https://receiver.example/events', [['ip' => '1.1.1.1']], 'receiver.example:443:1.1.1.1'],
            ['https://receiver.example/events', [['ipv6' => '2606:4700:4700::1111']], 'receiver.example:443:[2606:4700:4700::1111]'],
            ['https://1.1.1.1/events', [], '1.1.1.1:443:1.1.1.1'],
        ] as [$url, $answers, $expected]) {
            Transport::$dns = $answers;
            foreach ([new PortalProjectionOutboxSender(), new ManagedDeliveryIntentSender()] as $sender) {
                Transport::$opened = Transport::$sent = 0;
                self::assertSame(200, $sender->curlTransport($url, [], '{}', 5)['status']);
                self::assertSame([$expected], Transport::$options[CURLOPT_RESOLVE]);
                self::assertSame(1, Transport::$sent);
            }
        }
    }

    public function testAnyUnsafeDnsAnswerRejectsWholeSetBeforeOpeningConnection(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '::ffff:127.0.0.1', '64:ff9b::10.0.0.1'] as $unsafe) {
            Transport::$dns = [['ipv6' => '2606:4700:4700::1111'], ['ip' => '1.1.1.1'], ['ip' => $unsafe]];
            foreach ([new PortalProjectionOutboxSender(), new ManagedDeliveryIntentSender()] as $sender) {
                Transport::$opened = Transport::$sent = 0;
                try {
                    $sender->curlTransport('https://receiver.example/events', [], '{}', 5);
                    self::fail('Unsafe answer must refuse the entire DNS set.');
                } catch (RuntimeException $error) {
                    self::assertSame('dns_no_public_address', $error->getMessage());
                }
                self::assertSame(0, Transport::$opened);
                self::assertSame(0, Transport::$sent);
            }
        }
    }

    public function testTransportFailureDoesNotRetryPostOrChangePayload(): void
    {
        Transport::$dns = [['ipv6' => '2606:4700:4700::1111'], ['ip' => '1.1.1.1']];
        Transport::$fail = true;
        foreach ([new PortalProjectionOutboxSender(), new ManagedDeliveryIntentSender()] as $sender) {
            Transport::$opened = Transport::$sent = 0;
            $result = $sender->curlTransport('https://receiver.example/events', [], '{"unchanged":true}', 5);
            self::assertSame(1, Transport::$sent);
            self::assertSame(1, Transport::$opened);
            self::assertSame('{"unchanged":true}', Transport::$options[CURLOPT_POSTFIELDS]);
            self::assertSame(0, $result['status']);
            self::assertSame('synthetic connection failure', $result['error']);
        }
    }
}
