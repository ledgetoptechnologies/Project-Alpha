<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/stripe_invoice_payment_amounts.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeInvoicePaymentAmountsTest extends TestCase
{
    public function testMerchantProcessorFeeDoesNotReduceInvoicePrincipal(): void
    {
        $result = stripe_invoice_payment_amounts([
            'object' => 'payment_intent', 'amount' => 52750, 'amount_received' => 52750,
            'metadata' => ['original_amount' => '527.50', 'surcharge_amount' => '0'],
            'latest_charge' => ['balance_transaction' => ['amount' => 52750, 'fee' => 1560, 'net' => 51190]],
        ], 'payment_intent');
        self::assertSame(52750, $result['payment_cents']);
        self::assertSame(527.5, $result['payment_amount']);
        self::assertSame(527.5, $result['gross_amount']);
        self::assertSame(0, $result['surcharge_cents']);
        self::assertSame(0.0, $result['surcharge_amount']);
    }

    public function testReceivedAmountIsAuthoritativeForPartialCapture(): void
    {
        $result = stripe_invoice_payment_amounts(['amount' => 52750, 'amount_received' => 10000], 'payment_intent');
        self::assertSame(10000, $result['payment_cents']);
        self::assertSame(100.0, $result['payment_amount']);
        self::assertSame(10000, $result['gross_cents']);
    }

    public function testClientFeeIsSeparatedAndBothAliasSpellingsCanAgree(): void
    {
        $result = stripe_invoice_payment_amounts([
            'amount_total' => 54310,
            'metadata' => ['original_amount' => '527.5', 'pa_invoice_amount' => '527.50',
                'surcharge_amount' => '15.60', 'pa_surcharge_amount' => '15.6'],
        ], 'checkout.session');
        self::assertSame(52750, $result['payment_cents']);
        self::assertSame(1560, $result['surcharge_cents']);
        self::assertSame(54310, $result['gross_cents']);
    }

    #[DataProvider('validLegacyAmounts')]
    public function testLegacyAndCanonicalAmountsRemainCompatible(array $object, string $type, int $principal, int $surcharge): void
    {
        $result = stripe_invoice_payment_amounts($object, $type);
        self::assertSame($principal, $result['payment_cents']);
        self::assertSame($surcharge, $result['surcharge_cents']);
        self::assertSame($principal + $surcharge, $result['gross_cents']);
    }

    public static function validLegacyAmounts(): array
    {
        return [
            'intent missing metadata' => [['amount' => 52750], 'payment_intent', 52750, 0],
            'session missing metadata' => [['amount_total' => 52750], 'checkout.session', 52750, 0],
            'received only' => [['amount_received' => 52750], 'payment_intent', 52750, 0],
            'canonical principal' => [['amount_total' => 52750, 'metadata' => ['pa_invoice_amount' => '527.50']], 'checkout.session', 52750, 0],
            'legacy inferred surcharge' => [['amount_total' => 54310, 'metadata' => ['original_amount' => '527.50']], 'checkout.session', 52750, 1560],
            'explicit surcharge only' => [['amount' => 54310, 'metadata' => ['pa_surcharge_amount' => '15.60']], 'payment_intent', 52750, 1560],
            'one cent' => [['amount_received' => 1, 'metadata' => ['original_amount' => '0.01']], 'payment_intent', 1, 0],
            'old subtraction below one cent' => [['amount_total' => 1, 'metadata' => ['original_amount' => '0.0099999999999909', 'pa_invoice_amount' => '0.01']], 'checkout.session', 1, 0],
            'old subtraction above one cent' => [['amount_total' => 1, 'metadata' => ['original_amount' => '0.010000000000005']], 'checkout.session', 1, 0],
            'lower noise bound' => [['amount_total' => 100, 'metadata' => ['original_amount' => '1.000001']], 'checkout.session', 100, 0],
            'upper noise bound' => [['amount_total' => 100, 'metadata' => ['original_amount' => '0.999999']], 'checkout.session', 100, 0],
            'fractional trailing zeros' => [['amount_total' => 52750, 'metadata' => ['original_amount' => '527.5000']], 'checkout.session', 52750, 0],
            'unrelated metadata' => [['amount_total' => 10000, 'metadata' => ['invoice_total' => '527.50']], 'checkout.session', 10000, 0],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsHoldTheEventWithoutGuessing(array $object, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        stripe_invoice_payment_amounts($object, $type);
    }

    public static function invalidAmounts(): iterable
    {
        foreach (['0', '0.00', '', '-1', '$527.50', '1,000.00', ' 527.50', '527.50 ', '1e2', 'NaN', 'INF', '527.501', "527.50\n", null, false, true, [], 527.50, INF, NAN] as $index => $value) {
            yield 'invalid principal ' . $index => [['amount_total' => 52750, 'metadata' => ['original_amount' => $value]], 'checkout.session'];
        }
        foreach (['', '-1', 'NaN', false, [], null] as $index => $value) {
            yield 'invalid surcharge ' . $index => [['amount' => 52750, 'metadata' => ['surcharge_amount' => $value]], 'payment_intent'];
        }
        foreach ([0, -1, 52750.0, '52750', true, null, []] as $index => $value) {
            yield 'invalid settled cents ' . $index => [['amount' => $value], 'payment_intent'];
        }
        yield 'zero received never falls back' => [['amount' => 52750, 'amount_received' => 0], 'payment_intent'];
        yield 'null received never falls back' => [['amount' => 52750, 'amount_received' => null], 'payment_intent'];
        yield 'received missing and amount missing' => [[], 'payment_intent'];
        yield 'session missing total' => [['amount_received' => 52750], 'checkout.session'];
        yield 'principal aliases disagree' => [['amount' => 52750, 'metadata' => ['pa_invoice_amount' => '527.50', 'original_amount' => '527.49']], 'payment_intent'];
        yield 'material subcent below' => [['amount_total' => 100, 'metadata' => ['original_amount' => '0.9999989']], 'checkout.session'];
        yield 'material subcent above' => [['amount_total' => 100, 'metadata' => ['original_amount' => '1.0000011']], 'checkout.session'];
        yield 'surcharge aliases disagree' => [['amount' => 52750, 'metadata' => ['pa_surcharge_amount' => '0', 'surcharge_amount' => '1']], 'payment_intent'];
        yield 'principal exceeds settlement' => [['amount_total' => 52750, 'metadata' => ['original_amount' => '527.51']], 'checkout.session'];
        yield 'amounts do not add up' => [['amount_total' => 52750, 'metadata' => ['original_amount' => '527.50', 'surcharge_amount' => '1']], 'checkout.session'];
        yield 'fee consumes principal' => [['amount' => 52750, 'metadata' => ['surcharge_amount' => '527.50']], 'payment_intent'];
        yield 'surcharge exceeds settlement' => [['amount' => 52750, 'metadata' => ['surcharge_amount' => '527.51']], 'payment_intent'];
        yield 'metadata integer overflow' => [['amount_total' => 52750, 'metadata' => ['original_amount' => '999999999999999999999999.00']], 'checkout.session'];
        yield 'unsupported float precision' => [['amount' => PHP_INT_MAX], 'payment_intent'];
        yield 'malformed metadata' => [['amount_total' => 52750, 'metadata' => ''], 'checkout.session'];
        yield 'null metadata' => [['amount_total' => 52750, 'metadata' => null], 'checkout.session'];
        yield 'object type disagrees' => [['object' => 'payment_intent', 'amount_total' => 52750], 'checkout.session'];
        yield 'unsupported object type' => [['amount' => 52750], 'invoice'];
    }
}
