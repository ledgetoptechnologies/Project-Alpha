<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/stripe_success_policy.php';
require_once dirname(__DIR__, 2) . '/src/utils/canonical_origin.php';

use PHPUnit\Framework\TestCase;

final class StripeSuccessPolicyTest extends TestCase
{
    public function testOnlyActiveInvoiceLinksCanShowPaymentStatus(): void
    {
        $link = ['document_type' => 'invoice', 'revoked' => 0, 'expires_at' => '2030-01-01 00:00:00'];
        self::assertTrue(pa_stripe_success_link_valid($link, strtotime('2029-01-01')));
        self::assertFalse(pa_stripe_success_link_valid($link, strtotime('2030-01-01')));
        self::assertFalse(pa_stripe_success_link_valid(array_replace($link, ['revoked' => 1]), strtotime('2029-01-01')));
        $link['revoked'] = 1;
        self::assertFalse(pa_stripe_success_link_valid($link, strtotime('2029-01-01')));
        self::assertFalse(pa_stripe_success_link_valid(null, time()));
        self::assertFalse(pa_stripe_success_link_valid([
            'document_type' => 'invoice', 'revoked' => 0,
            'expire_when_paid' => 0, 'expires_at' => '2020-01-01 00:00:00',
        ], strtotime('2029-01-01')));
        $checkout = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/stripe/stripe_checkout.php');
        self::assertLessThan(
            strpos($checkout, 'ALTER TABLE public_links'),
            strpos($checkout, 'pa_stripe_success_link_valid($linkRow, time())')
        );
    }

    public function testPaidSessionMustMatchTokenInvoiceAndChargedAmount(): void
    {
        $session = [
            'id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => 10250,
            'metadata' => ['token' => 'secret-token', 'pa_invoice_id' => '42', 'pa_invoice_amount' => '100.00', 'pa_surcharge_amount' => '2.50'],
        ];
        $invoice = ['stripe_session_id' => 'cs_test_123'];
        $valid = static fn(array $candidate, ?array $document = null): bool => pa_stripe_success_session_verified(
            $candidate, $document ?? $invoice, 'invoice', 42, 'secret-token', 'cs_test_123'
        );
        self::assertTrue($valid($session));
        foreach ([
            ['payment_status' => 'unpaid'], ['status' => 'open'], ['amount_total' => 1],
            ['id' => 'cs_test_other'], ['metadata' => ['token' => 'wrong', 'pa_invoice_id' => '42', 'pa_invoice_amount' => '100.00', 'pa_surcharge_amount' => '2.50']],
            ['metadata' => ['token' => 'secret-token', 'pa_invoice_id' => '43', 'pa_invoice_amount' => '100.00', 'pa_surcharge_amount' => '2.50']],
        ] as $change) {
            self::assertFalse($valid(array_replace($session, $change)));
        }
        self::assertFalse($valid($session, ['stripe_session_id' => 'cs_test_other']));
        self::assertFalse(pa_stripe_success_session_verified($session, $invoice, 'project_invoice', 42, 'secret-token', 'cs_test_123'));

        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/stripe/stripe_success.php');
        self::assertStringContainsString('getCheckoutSession($sessionId)', $page);
        self::assertStringContainsString('pa_stripe_success_session_verified', $page);
        $invoicePage = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/invoice/invoice-details.php');
        self::assertStringNotContainsString('Payment processed successfully! The invoice status will update shortly.', $invoicePage);
    }

    public function testCheckoutReturnUrlsUseConfiguredHttpsOrigin(): void
    {
        self::assertSame('https://pay.example.test', app_canonical_https_origin(['app_host' => 'https://pay.example.test/']));
        self::assertNull(app_canonical_https_origin(['app_host' => 'http://pay.example.test']));
        foreach (['stripe_charge.php', 'stripe_checkout.php'] as $file) {
            $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/stripe/' . $file);
            self::assertStringContainsString('app_canonical_https_origin($appConfig)', $controller);
            self::assertStringNotContainsString("\$_SERVER['HTTP_HOST']", $controller);
        }
    }
}
