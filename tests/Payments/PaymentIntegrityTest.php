<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php';
require_once dirname(__DIR__, 2) . '/src/utils/payment_corrections.php';
require_once dirname(__DIR__, 2) . '/src/utils/stripe_financial_events.php';
require_once dirname(__DIR__, 2) . '/src/utils/payment_accounting.php';

use PHPUnit\Framework\TestCase;

final class PaymentIntegrityTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        foreach (array_reverse($this->ids['payment_corrections'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM payment_corrections WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['stripe_refunds'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM stripe_refunds WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['public_links'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM public_links WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['payments'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['invoices'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['contracts'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['clients'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach (array_reverse($this->ids['organizations'] ?? []) as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id = ?')->execute([$id]);
        }
    }

    public function testDirectPaymentCannotOverpayAndRefreshesInvoiceBalance(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 100.00);
        $publicLinkId = $this->insertInvoicePublicLink($invoiceId);

        $first = invoice_record_locked_payment($this->pdo, $invoiceId, 40.00, 'cash', null, null, [
            'organization_id' => $orgId,
            'source' => 'test',
        ]);
        $this->ids['payments'][] = (int)$first['payment_id'];

        self::assertSame('partial', $first['status']);
        self::assertEqualsWithDelta(40.00, $first['amount_paid'], 0.005);
        self::assertEqualsWithDelta(60.00, $first['balance_due'], 0.005);
        self::assertSame(0, $this->publicLinkRevoked($publicLinkId), 'Partial payment should not revoke invoice links.');

        $this->expectException(RuntimeException::class);
        try {
            invoice_record_locked_payment($this->pdo, $invoiceId, 70.00, 'cash', null, null, [
                'organization_id' => $orgId,
                'source' => 'test',
            ]);
        } finally {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id = ?');
            $count->execute([$invoiceId]);
            self::assertSame(1, (int)$count->fetchColumn(), 'Rejected overpayment must not insert a payment.');
        }
    }

    public function testFullPaymentMarksInvoicePaidRevokesLinkAndCompletesContractWhenRequested(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $contractId = $this->insertContract($orgId, $clientId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 100.00, $contractId);
        $publicLinkId = $this->insertInvoicePublicLink($invoiceId);

        $result = invoice_record_locked_payment($this->pdo, $invoiceId, 100.00, 'check', '1001', 'Paid in full', [
            'organization_id' => $orgId,
            'complete_contract_when_paid' => true,
            'source' => 'test',
        ]);
        $this->ids['payments'][] = (int)$result['payment_id'];

        self::assertSame('paid', $result['status']);
        self::assertEqualsWithDelta(100.00, $result['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.00, $result['balance_due'], 0.005);
        $publicLink = $this->publicLinkState($publicLinkId);
        self::assertSame(1, (int)$publicLink['revoked'], 'Paid invoices should leave the public link in a redirected terminal state.');
        self::assertStringContainsString('reason=paid', (string)$publicLink['redirect']);
        self::assertNotEmpty($publicLink['expires_at']);

        $status = $this->pdo->prepare('SELECT status FROM contracts WHERE id = ?');
        $status->execute([$contractId]);
        self::assertSame('completed', (string)$status->fetchColumn());
    }

    public function testFullPaymentDoesNotCompleteAnOngoingLongTermContract(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $contractId = $this->insertContract($orgId, $clientId, 'long_term', 'active');
        $invoiceId = $this->insertInvoice($orgId, $clientId, 120.00, $contractId, 'long_term');

        $result = invoice_record_locked_payment($this->pdo, $invoiceId, 120.00, 'check', 'LT-1001', null, [
            'organization_id' => $orgId,
            'complete_contract_when_paid' => true,
            'source' => 'test',
        ]);
        $this->ids['payments'][] = (int)$result['payment_id'];

        self::assertSame('paid', $result['status']);
        $status = $this->pdo->prepare('SELECT status FROM contracts WHERE id = ?');
        $status->execute([$contractId]);
        self::assertSame('active', (string)$status->fetchColumn(), 'A recurring installment must not complete its long-term contract.');
    }

    public function testStripePaymentCanReplaceMistakenCashEntryAndVoidDuplicateInvoiceWithoutRefunding(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $contractId = $this->insertContract($orgId, $clientId, 'long_term', 'active');
        $sourceInvoiceId = $this->insertInvoice($orgId, $clientId, 100.00);
        $targetInvoiceId = $this->insertInvoice($orgId, $clientId, 100.00, $contractId, 'long_term');
        $sourcePublicLinkId = $this->insertInvoicePublicLink($sourceInvoiceId);

        $stripe = invoice_record_locked_payment($this->pdo, $sourceInvoiceId, 100.00, 'stripe', null, null, [
            'organization_id' => $orgId,
            'source' => 'test_stripe',
        ]);
        $stripePaymentId = $this->remember('payments', (int)$stripe['payment_id']);
        $stripeIntentId = 'pi_correction_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('UPDATE payments SET stripe_payment_intent_id=?,processor_provider="stripe",processor_payment_id=? WHERE id=?')
            ->execute([$stripeIntentId, $stripeIntentId, $stripePaymentId]);

        $cash = invoice_record_locked_payment($this->pdo, $targetInvoiceId, 100.00, 'cash', null, 'Mistaken duplicate entry', [
            'organization_id' => $orgId,
            'complete_contract_when_paid' => true,
            'source' => 'test_manual',
        ]);
        $cashPaymentId = $this->remember('payments', (int)$cash['payment_id']);

        $receiptToken = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO payment_receipts (payment_id,invoice_id,receipt_number,public_token,expires_at,amount) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR),100)')
            ->execute([$stripePaymentId, $sourceInvoiceId, 'R-CORR-' . bin2hex(random_bytes(5)), $receiptToken]);

        $result = payment_reallocate_to_invoice(
            $this->pdo,
            $stripePaymentId,
            $targetInvoiceId,
            [$cashPaymentId],
            false,
            null,
            true,
            [],
            'Duplicate first-month invoice; move the real Stripe payment to the recurring invoice.',
            null
        );
        $this->remember('payment_corrections', (int)$result['correction_id']);

        self::assertTrue($result['source_voided']);
        self::assertSame('void', $result['source_status']);
        self::assertSame('paid', $result['target_status']);

        $moved = $this->pdo->prepare('SELECT invoice_id,contract_id,status,refunded_amount,stripe_payment_intent_id FROM payments WHERE id=?');
        $moved->execute([$stripePaymentId]);
        $moved = $moved->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame($targetInvoiceId, (int)$moved['invoice_id']);
        self::assertSame($contractId, (int)$moved['contract_id']);
        self::assertSame('succeeded', $moved['status']);
        self::assertEqualsWithDelta(0.0, (float)$moved['refunded_amount'], 0.005);
        self::assertSame($stripeIntentId, $moved['stripe_payment_intent_id']);

        $reversed = $this->pdo->prepare('SELECT status,reversed_at,reversal_reason,refunded_amount FROM payments WHERE id=?');
        $reversed->execute([$cashPaymentId]);
        $reversed = $reversed->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('reversed', $reversed['status']);
        self::assertNotEmpty($reversed['reversed_at']);
        self::assertStringContainsString('Duplicate first-month invoice', (string)$reversed['reversal_reason']);
        self::assertEqualsWithDelta(0.0, (float)$reversed['refunded_amount'], 0.005, 'A reversal must not be recorded as a refund.');

        $source = $this->pdo->prepare('SELECT status,amount_paid,balance_due,void_reason FROM invoices WHERE id=?');
        $source->execute([$sourceInvoiceId]);
        $source = $source->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('void', $source['status']);
        self::assertEqualsWithDelta(0.0, (float)$source['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.0, (float)$source['balance_due'], 0.005);
        self::assertStringContainsString('Duplicate first-month invoice', (string)$source['void_reason']);

        $target = $this->pdo->prepare('SELECT status,amount_paid,balance_due FROM invoices WHERE id=?');
        $target->execute([$targetInvoiceId]);
        $target = $target->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('paid', $target['status']);
        self::assertEqualsWithDelta(100.0, (float)$target['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.0, (float)$target['balance_due'], 0.005);

        $contract = $this->pdo->prepare('SELECT status FROM contracts WHERE id=?');
        $contract->execute([$contractId]);
        self::assertSame('active', (string)$contract->fetchColumn());
        self::assertStringContainsString('reason=void', (string)$this->publicLinkState($sourcePublicLinkId)['redirect']);

        $receipt = $this->pdo->prepare('SELECT invoice_id FROM payment_receipts WHERE payment_id=?');
        $receipt->execute([$stripePaymentId]);
        self::assertSame($targetInvoiceId, (int)$receipt->fetchColumn(), 'The real payment receipt must follow the corrected invoice.');

        $refunds = $this->pdo->prepare('SELECT COUNT(*) FROM stripe_refunds WHERE payment_id=?');
        $refunds->execute([$stripePaymentId]);
        self::assertSame(0, (int)$refunds->fetchColumn(), 'A correction must not create a Stripe refund record.');
    }

    public function testLegacyLocalRefundCanBeVerifiedClearedAndTwoDuplicateCashEntriesReversed(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $contractId = $this->insertContract($orgId, $clientId, 'long_term', 'active');
        $sourceInvoiceId = $this->insertInvoice($orgId, $clientId, 300.00);
        $targetInvoiceId = $this->insertInvoice($orgId, $clientId, 300.00, $contractId, 'long_term');
        $sourcePublicLinkId = $this->insertInvoicePublicLink($sourceInvoiceId);
        $this->pdo->prepare('UPDATE invoices SET doc_number=2 WHERE id=?')->execute([$sourceInvoiceId]);
        $this->pdo->prepare('UPDATE invoices SET doc_number=1 WHERE id=?')->execute([$targetInvoiceId]);

        $stripe = invoice_record_locked_payment($this->pdo, $sourceInvoiceId, 300.00, 'stripe', null, null, [
            'organization_id' => $orgId,
            'source' => 'test_stripe',
        ]);
        $stripePaymentId = $this->remember('payments', (int)$stripe['payment_id']);
        $stripeIntentId = 'pi_legacy_correction_' . bin2hex(random_bytes(5));
        $this->pdo->prepare('
            UPDATE payments
            SET stripe_payment_intent_id=?,processor_provider="stripe",processor_payment_id=?,
                processor_gross_amount=300,processor_fee_amount=9,processor_net_amount=291,
                processor_fee_source="actual",refunded_amount=291
            WHERE id=?
        ')->execute([$stripeIntentId, $stripeIntentId, $stripePaymentId]);

        $sourceCash = invoice_record_locked_payment($this->pdo, $sourceInvoiceId, 291.00, 'cash', null, 'Net deposit entered as cash', [
            'organization_id' => $orgId,
            'source' => 'test_manual',
        ]);
        $sourceCashId = $this->remember('payments', (int)$sourceCash['payment_id']);
        $targetCash = invoice_record_locked_payment($this->pdo, $targetInvoiceId, 300.00, 'cash', null, 'Recurring invoice marked paid manually', [
            'organization_id' => $orgId,
            'complete_contract_when_paid' => true,
            'source' => 'test_manual',
        ]);
        $targetCashId = $this->remember('payments', (int)$targetCash['payment_id']);

        // Match the reported legacy state: the imported Stripe row and source
        // invoice lost denormalized organization values, both cash duplicates
        // were mistakenly recorded as fully refunded, and the recurring
        // invoice predates the finalized_at metadata convention.
        $this->pdo->prepare('UPDATE payments SET organization_id=NULL WHERE id=?')->execute([$stripePaymentId]);
        $this->pdo->prepare('UPDATE invoices SET organization_id=NULL WHERE id=?')->execute([$sourceInvoiceId]);
        $this->pdo->prepare('UPDATE payments SET refunded_amount=amount WHERE id IN (?,?)')
            ->execute([$sourceCashId, $targetCashId]);
        invoice_refresh_payment_totals($this->pdo, $sourceInvoiceId);
        invoice_refresh_payment_totals($this->pdo, $targetInvoiceId);
        $this->pdo->prepare('UPDATE invoices SET finalized_at=NULL WHERE id=?')->execute([$targetInvoiceId]);

        $result = payment_reallocate_to_invoice(
            $this->pdo,
            $stripePaymentId,
            $targetInvoiceId,
            [$sourceCashId, $targetCashId],
            true,
            0.0,
            true,
            [],
            'Restore the real Stripe payment, reverse duplicate cash entries, and void the duplicate invoice.',
            null
        );
        $this->remember('payment_corrections', (int)$result['correction_id']);

        self::assertSame([$sourceCashId, $targetCashId], $result['reversed_payment_ids']);
        self::assertEqualsWithDelta(291.0, $result['cleared_local_refund_amount'], 0.005);
        self::assertTrue($result['source_voided']);

        $moved = $this->pdo->prepare('SELECT * FROM payments WHERE id=?');
        $moved->execute([$stripePaymentId]);
        $moved = $moved->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame($targetInvoiceId, (int)$moved['invoice_id']);
        self::assertSame($contractId, (int)$moved['contract_id']);
        self::assertSame($orgId, (int)$moved['organization_id']);
        self::assertSame('stripe', $moved['payment_method']);
        self::assertEqualsWithDelta(300.0, (float)$moved['amount'], 0.005, 'The invoice receives the gross client payment.');
        self::assertEqualsWithDelta(0.0, (float)$moved['refunded_amount'], 0.005);
        self::assertEqualsWithDelta(9.0, (float)$moved['processor_fee_amount'], 0.005);
        self::assertEqualsWithDelta(291.0, payment_accounting_net_income($moved), 0.005, 'Stripe net income remains gross less the processor fee.');

        $manualRows = $this->pdo->prepare('SELECT id,status,amount,refunded_amount FROM payments WHERE id IN (?,?) ORDER BY id');
        $manualRows->execute([$sourceCashId, $targetCashId]);
        foreach ($manualRows->fetchAll(PDO::FETCH_ASSOC) as $manual) {
            self::assertSame('reversed', $manual['status']);
            self::assertEqualsWithDelta(
                (float)$manual['amount'],
                (float)$manual['refunded_amount'],
                0.005,
                'The mistaken local refund remains visible only in the reversed audit row.'
            );
        }

        $source = $this->pdo->prepare('SELECT status,amount_paid,balance_due FROM invoices WHERE id=?');
        $source->execute([$sourceInvoiceId]);
        $source = $source->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('void', $source['status']);
        self::assertEqualsWithDelta(0.0, (float)$source['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.0, (float)$source['balance_due'], 0.005);
        self::assertStringContainsString('reason=void', (string)$this->publicLinkState($sourcePublicLinkId)['redirect']);

        $target = $this->pdo->prepare('SELECT status,amount_paid,balance_due FROM invoices WHERE id=?');
        $target->execute([$targetInvoiceId]);
        $target = $target->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('paid', $target['status']);
        self::assertEqualsWithDelta(300.0, (float)$target['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.0, (float)$target['balance_due'], 0.005);

        $contract = $this->pdo->prepare('SELECT status FROM contracts WHERE id=?');
        $contract->execute([$contractId]);
        self::assertSame('active', (string)$contract->fetchColumn());

        $correction = $this->pdo->prepare('SELECT reversed_payment_ids,cleared_local_refund_amount,processor_refund_verified_amount FROM payment_corrections WHERE id=?');
        $correction->execute([(int)$result['correction_id']]);
        $correction = $correction->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame([$sourceCashId, $targetCashId], array_map('intval', json_decode((string)$correction['reversed_payment_ids'], true)));
        self::assertEqualsWithDelta(291.0, (float)$correction['cleared_local_refund_amount'], 0.005);
        self::assertEqualsWithDelta(0.0, (float)$correction['processor_refund_verified_amount'], 0.005);

        $refunds = $this->pdo->prepare('SELECT COUNT(*) FROM stripe_refunds WHERE payment_id=?');
        $refunds->execute([$stripePaymentId]);
        self::assertSame(0, (int)$refunds->fetchColumn());
    }

    public function testLegacyLocalRefundCannotBeClearedWhenStripeReportsARealRefund(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $sourceInvoiceId = $this->insertInvoice($orgId, $clientId, 300.00);
        $targetInvoiceId = $this->insertInvoice($orgId, $clientId, 300.00, null, 'long_term');
        $payment = invoice_record_locked_payment($this->pdo, $sourceInvoiceId, 300.00, 'stripe', null, null, [
            'organization_id' => $orgId,
        ]);
        $paymentId = $this->remember('payments', (int)$payment['payment_id']);
        $this->pdo->prepare('UPDATE payments SET stripe_payment_intent_id=?,refunded_amount=291 WHERE id=?')
            ->execute(['pi_real_refund_' . bin2hex(random_bytes(5)), $paymentId]);

        try {
            payment_reallocate_to_invoice(
                $this->pdo,
                $paymentId,
                $targetInvoiceId,
                [],
                true,
                25.00,
                false,
                [],
                'Should be rejected because Stripe reports a real refund.',
                null
            );
            self::fail('A locally recorded refund was cleared despite Stripe reporting a real refund.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('actually refunded', $error->getMessage());
        }

        $state = $this->pdo->prepare('SELECT invoice_id,refunded_amount FROM payments WHERE id=?');
        $state->execute([$paymentId]);
        $state = $state->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame($sourceInvoiceId, (int)$state['invoice_id']);
        self::assertEqualsWithDelta(291.0, (float)$state['refunded_amount'], 0.005);
    }

    public function testCorrectionPageUsesRealInvoiceDateAndShowsErrorsInsteadOfGoingBlank(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/payments/payment-correction.php');
        self::assertStringNotContainsString('i.issue_date', $view);
        self::assertStringContainsString('i.document_date', $view);
        self::assertStringContainsString('catch (Throwable $e)', $view);
        self::assertStringContainsString('No accounting data was changed', $view);
        self::assertStringContainsString('replacement_payment_ids[]', $view);
        self::assertStringContainsString('clear_local_refund', $view);
        self::assertStringContainsString('c.organization_id AS source_client_organization_id', $view);
        self::assertStringNotContainsString('i.organization_id <=> ?', $view);
        self::assertStringNotContainsString('AND i.finalized_at IS NOT NULL', $view);
        self::assertStringNotContainsString('AND p.refunded_amount <= 0.005', $view);
        self::assertStringContainsString('locally recorded as refunded', $view);

        $paymentsList = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/payments/payments-list.php');
        self::assertStringContainsString('AS has_correction_target', $paymentsList);
        self::assertStringContainsString('No other invoice for this client', $paymentsList);
    }

    public function testLongTermSequenceDoesNotAdvanceRegularInvoiceSequence(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $regularBefore = pa_next_invoice_doc_number($this->pdo, 'regular');

        $longTermOne = $this->insertInvoice($orgId, $clientId, 10.00, null, 'long_term');
        $longTermTwo = $this->insertInvoice($orgId, $clientId, 20.00, null, 'long_term');
        $this->pdo->prepare('UPDATE invoices SET doc_number=900001 WHERE id=?')->execute([$longTermOne]);
        $this->pdo->prepare('UPDATE invoices SET doc_number=900002 WHERE id=?')->execute([$longTermTwo]);

        self::assertSame($regularBefore, pa_next_invoice_doc_number($this->pdo, 'regular'));
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/invoice_numbers.php');
        self::assertStringContainsString('WHERE invoice_type = "regular" OR invoice_type IS NULL', $source);
    }

    public function testStripeRefundWebhookClearsPaidAtAndIsIdempotent(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $contractId = $this->insertContract($orgId, $clientId, 'long_term', 'active');
        $invoiceId = $this->insertInvoice($orgId, $clientId, 100.00, $contractId, 'long_term');
        $payment = invoice_record_locked_payment($this->pdo, $invoiceId, 100.00, 'stripe', null, null, [
            'organization_id' => $orgId,
            'complete_contract_when_paid' => true,
        ]);
        $paymentId = $this->remember('payments', (int)$payment['payment_id']);
        $intentId = 'pi_refund_' . bin2hex(random_bytes(6));
        $refundId = 're_refund_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('UPDATE payments SET stripe_payment_intent_id=? WHERE id=?')->execute([$intentId, $paymentId]);

        stripe_record_refund($this->pdo, [
            'id' => $refundId,
            'payment_intent' => $intentId,
            'amount' => 3000,
            'status' => 'succeeded',
        ]);
        $refundRow = $this->pdo->prepare('SELECT id FROM stripe_refunds WHERE stripe_refund_id=?');
        $refundRow->execute([$refundId]);
        $this->remember('stripe_refunds', (int)$refundRow->fetchColumn());

        $partial = $this->pdo->prepare('SELECT status,amount_paid,balance_due,paid_at FROM invoices WHERE id=?');
        $partial->execute([$invoiceId]);
        $partial = $partial->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('partial', $partial['status']);
        self::assertEqualsWithDelta(70.0, (float)$partial['amount_paid'], 0.005);
        self::assertEqualsWithDelta(30.0, (float)$partial['balance_due'], 0.005);
        self::assertNull($partial['paid_at']);

        stripe_record_refund($this->pdo, [
            'id' => $refundId,
            'payment_intent' => $intentId,
            'amount' => 10000,
            'status' => 'succeeded',
        ]);
        $full = $this->pdo->prepare('SELECT status,amount_paid,balance_due,paid_at FROM invoices WHERE id=?');
        $full->execute([$invoiceId]);
        $full = $full->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('unpaid', $full['status']);
        self::assertEqualsWithDelta(0.0, (float)$full['amount_paid'], 0.005);
        self::assertEqualsWithDelta(100.0, (float)$full['balance_due'], 0.005);
        self::assertNull($full['paid_at']);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM stripe_refunds WHERE stripe_refund_id=?');
        $count->execute([$refundId]);
        self::assertSame(1, (int)$count->fetchColumn());
        $contract = $this->pdo->prepare('SELECT status FROM contracts WHERE id=?');
        $contract->execute([$contractId]);
        self::assertSame('active', (string)$contract->fetchColumn());
    }

    public function testDirectStripeRefundLinksLegacyChargeOnlyPaymentImmediately(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 100.00);
        $payment = invoice_record_locked_payment($this->pdo, $invoiceId, 100.00, 'stripe', null, null, [
            'organization_id' => $orgId,
        ]);
        $paymentId = $this->remember('payments', (int)$payment['payment_id']);
        $this->pdo->prepare('UPDATE payments SET processor_provider="stripe",processor_payment_id=? WHERE id=?')
            ->execute(['ch_legacy_' . bin2hex(random_bytes(6)), $paymentId]);

        $refundId = 're_direct_' . bin2hex(random_bytes(6));
        stripe_record_refund($this->pdo, [
            'id' => $refundId,
            'payment_intent' => 'pi_not_stored_' . bin2hex(random_bytes(6)),
            'amount' => 2500,
            'status' => 'succeeded',
        ], $paymentId);

        $refund = $this->pdo->prepare('SELECT id,payment_id FROM stripe_refunds WHERE stripe_refund_id=?');
        $refund->execute([$refundId]);
        $refund = $refund->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->remember('stripe_refunds', (int)$refund['id']);
        self::assertSame($paymentId, (int)$refund['payment_id']);

        $invoice = $this->pdo->prepare('SELECT status,amount_paid,balance_due FROM invoices WHERE id=?');
        $invoice->execute([$invoiceId]);
        $invoice = $invoice->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('partial', (string)$invoice['status']);
        self::assertEqualsWithDelta(75.0, (float)$invoice['amount_paid'], 0.005);
        self::assertEqualsWithDelta(25.0, (float)$invoice['balance_due'], 0.005);
    }

    public function testProcessorRefundActionCreatesARealIdempotentStripeRefund(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/payments_refund.php');
        $service = (string)file_get_contents(dirname(__DIR__, 2) . '/src/services/StripeService.php');
        $paymentsList = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/payments/payments-list.php');

        self::assertStringContainsString('processor_refund', $controller);
        self::assertStringContainsString('refund_request_token', $controller);
        self::assertStringContainsString('$stripe->refundCharge(', $controller);
        self::assertStringContainsString('stripe_record_refund($pdo, $refund, $paymentId)', $controller);
        self::assertStringContainsString('pa-refund-v1-', $controller);
        self::assertStringContainsString('?string $idempotencyKey = null', $service);
        self::assertStringContainsString("\$this->apiRequest('POST', 'refunds', \$payload, \$idempotencyKey)", $service);
        self::assertStringContainsString('Send Stripe refund', $paymentsList);
        self::assertStringContainsString('I confirm this sends the selected amount back through Stripe.', $paymentsList);
    }

    public function testFullyRefundedZeroBalancePaymentHistoryDoesNotBlockInvoiceVoid(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 291.00);
        $payment = invoice_record_locked_payment($this->pdo, $invoiceId, 291.00, 'cash', null, null, [
            'organization_id' => $orgId,
        ]);
        $paymentId = $this->remember('payments', (int)$payment['payment_id']);
        $this->pdo->prepare('UPDATE payments SET refunded_amount=amount WHERE id=?')->execute([$paymentId]);
        invoice_refresh_payment_totals($this->pdo, $invoiceId);

        $result = invoice_void($this->pdo, $invoiceId, [], 'Duplicate one-off invoice with fully refunded history.');
        self::assertSame('unpaid', $result['previous_status']);

        $invoice = $this->pdo->prepare('SELECT status,amount_paid,balance_due FROM invoices WHERE id=?');
        $invoice->execute([$invoiceId]);
        $invoice = $invoice->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('void', (string)$invoice['status']);
        self::assertEqualsWithDelta(0.0, (float)$invoice['amount_paid'], 0.005);
        self::assertEqualsWithDelta(0.0, (float)$invoice['balance_due'], 0.005);

        $history = $this->pdo->prepare('SELECT status,amount,refunded_amount FROM payments WHERE id=?');
        $history->execute([$paymentId]);
        $history = $history->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('succeeded', (string)$history['status']);
        self::assertEqualsWithDelta(291.0, (float)$history['amount'], 0.005);
        self::assertEqualsWithDelta(291.0, (float)$history['refunded_amount'], 0.005);

        $reversed = payment_reverse_manual_entry(
            $this->pdo,
            $paymentId,
            'Hide the duplicate zero-balance entry after voiding.',
            42
        );
        self::assertSame('void', $reversed['invoice_status']);
        $status = $this->pdo->prepare('SELECT status,balance_due FROM invoices WHERE id=?');
        $status->execute([$invoiceId]);
        $status = $status->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('void', (string)$status['status']);
        self::assertEqualsWithDelta(0.0, (float)$status['balance_due'], 0.005);
    }

    public function testMistakenRefundedManualEntryCanBeReversedWithoutMovingMoney(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 300.00);
        $payment = invoice_record_locked_payment($this->pdo, $invoiceId, 300.00, 'cash', null, null, [
            'organization_id' => $orgId,
        ]);
        $paymentId = $this->remember('payments', (int)$payment['payment_id']);
        $this->pdo->prepare('UPDATE payments SET refunded_amount=amount WHERE id=?')->execute([$paymentId]);
        invoice_refresh_payment_totals($this->pdo, $invoiceId);

        $result = payment_reverse_manual_entry(
            $this->pdo,
            $paymentId,
            ' Duplicate manual entry created while correcting allocation. ',
            42
        );
        self::assertSame($invoiceId, $result['invoice_id']);
        self::assertSame('unpaid', $result['invoice_status']);
        self::assertEqualsWithDelta(300.0, $result['refunded_amount'], 0.005);

        $reversed = $this->pdo->prepare('SELECT status,reversed_at,reversed_by,reversal_reason,refunded_amount FROM payments WHERE id=?');
        $reversed->execute([$paymentId]);
        $reversed = $reversed->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame('reversed', (string)$reversed['status']);
        self::assertNotEmpty($reversed['reversed_at']);
        self::assertSame(42, (int)$reversed['reversed_by']);
        self::assertSame('Duplicate manual entry created while correcting allocation.', (string)$reversed['reversal_reason']);
        self::assertEqualsWithDelta(300.0, (float)$reversed['refunded_amount'], 0.005);
    }

    public function testManualEntryReversalIsRoutedPermissionedAndVisible(): void
    {
        $root = dirname(__DIR__, 2);
        $router = (string)file_get_contents($root . '/public/index.php');
        $acl = (string)file_get_contents($root . '/src/utils/acl_middleware.php');
        $audit = (string)file_get_contents($root . '/src/utils/audit_middleware.php');
        $controller = (string)file_get_contents($root . '/src/controllers/payments_reverse.php');
        $paymentsList = (string)file_get_contents($root . '/src/views/pages/payments/payments-list.php');

        self::assertStringContainsString("'payments/payment-reverse'", $router);
        self::assertStringContainsString("'payments/payment-reverse'  => 'invoices.mark_paid'", $acl);
        self::assertStringContainsString("'payments/payment-reverse'", $audit);
        self::assertStringContainsString('payment_reverse_manual_entry($pdo, $paymentId, $reason, $userId)', $controller);
        self::assertStringContainsString('payment.manual_entry_reversed', $controller);
        self::assertStringContainsString('Reverse entry', $paymentsList);
        self::assertStringContainsString('class="payment-reverse-dialog"', $paymentsList);
        self::assertStringContainsString('.showModal()', $paymentsList);
    }

    public function testUnpaidInvoiceCanBeVoidedAndSafelyReenabled(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $invoiceId = $this->insertInvoice($orgId, $clientId, 175.00);
        $publicLinkId = $this->insertInvoicePublicLink($invoiceId);

        $result = invoice_void($this->pdo, $invoiceId, [], '  Created   for the wrong client  ', 987);
        self::assertSame('unpaid', $result['previous_status']);
        self::assertSame('Created for the wrong client', $result['reason']);

        $state = $this->invoiceVoidState($invoiceId);
        self::assertSame('void', $state['status']);
        self::assertEqualsWithDelta(0.0, (float)$state['balance_due'], 0.005);
        self::assertSame('unpaid', $state['void_previous_status']);
        self::assertSame('Created for the wrong client', $state['void_reason']);
        self::assertSame(987, (int)$state['voided_by']);
        self::assertNotEmpty($state['voided_at']);

        $publicLink = $this->publicLinkState($publicLinkId);
        self::assertSame(1, (int)$publicLink['revoked']);
        self::assertStringContainsString('reason=void', (string)$publicLink['redirect']);

        try {
            invoice_record_locked_payment($this->pdo, $invoiceId, 25.00, 'cash', null, null);
            self::fail('A payment was recorded against a void invoice.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('void or cancelled invoice', $error->getMessage());
        }

        $reenabled = invoice_reenable_void($this->pdo, $invoiceId);
        self::assertSame('unpaid', $reenabled['restored_status']);
        self::assertSame('Created for the wrong client', $reenabled['reason']);

        $restored = $this->invoiceVoidState($invoiceId);
        self::assertSame('unpaid', $restored['status']);
        self::assertEqualsWithDelta(175.0, (float)$restored['balance_due'], 0.005);
        self::assertNull($restored['voided_at']);
        self::assertNull($restored['void_reason']);
        self::assertSame(1, $this->publicLinkRevoked($publicLinkId), 'Re-enabling must not resurrect an old client link.');
    }

    public function testDraftInvoiceReturnsToDraftAfterVoidAndPaidInvoiceIsRejected(): void
    {
        $orgId = $this->insertOrganization();
        $clientId = $this->insertClient($orgId);
        $draftId = $this->insertInvoice($orgId, $clientId, 80.00);
        $this->pdo->prepare('UPDATE invoices SET status="draft",finalized_at=NULL,balance_due=0 WHERE id=?')->execute([$draftId]);

        invoice_void($this->pdo, $draftId, [], 'Duplicate draft');
        $reenabled = invoice_reenable_void($this->pdo, $draftId);
        self::assertSame('draft', $reenabled['restored_status']);
        $draft = $this->invoiceVoidState($draftId);
        self::assertSame('draft', $draft['status']);
        self::assertEqualsWithDelta(0.0, (float)$draft['balance_due'], 0.005);

        $paidId = $this->insertInvoice($orgId, $clientId, 60.00);
        $payment = invoice_record_locked_payment($this->pdo, $paidId, 10.00, 'cash', null, null);
        $this->ids['payments'][] = (int)$payment['payment_id'];

        try {
            invoice_void($this->pdo, $paidId, [], 'Should not work');
            self::fail('Partially paid invoice was voided.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('cannot be voided', $error->getMessage());
        }
        $paidState = $this->invoiceVoidState($paidId);
        self::assertSame('partial', $paidState['status']);
    }

    public function testDepositControllersUseCentralizedPaymentGuardrails(): void
    {
        $deposit = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/contract/contract_deposit_received.php');
        self::assertStringContainsString('require_record_ownership', $deposit);
        self::assertStringContainsString('invoice_record_locked_payment', $deposit);
        self::assertStringContainsString('Deposit exceeds the outstanding invoice balance', $deposit);
        self::assertStringNotContainsString('INSERT INTO payments', $deposit);
    }

    public function testQuoteApprovalsStoreCalculatedContractDepositAmount(): void
    {
        $approval = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/quote/quote_approve.php');
        $publicApproval = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/public_view/public_quote_action.php');

        foreach ([$approval, $publicApproval] as $source) {
            self::assertStringContainsString('$contractDepositAmount', $source);
            self::assertStringContainsString("max(0, min(100, \$depositValue)) * \$quoteTotal / 100", $source);
            self::assertStringContainsString("min(max(0, \$depositValue), \$quoteTotal)", $source);
        }
    }

    private function insertOrganization(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO organizations (name) VALUES (?)');
        $stmt->execute(['Payment Integrity ' . bin2hex(random_bytes(4))]);
        return $this->remember('organizations', (int)$this->pdo->lastInsertId());
    }

    private function insertClient(int $orgId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO clients (name, email, organization_id) VALUES (?, ?, ?)');
        $stmt->execute(['Payment Client ' . bin2hex(random_bytes(4)), 'pay-' . bin2hex(random_bytes(4)) . '@example.invalid', $orgId]);
        return $this->remember('clients', (int)$this->pdo->lastInsertId());
    }

    private function insertContract(int $orgId, int $clientId, string $contractType = 'regular', string $status = 'pending'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO contracts (client_id, organization_id, status, contract_type, total, deposit_type, deposit_amount, deposit_paid) VALUES (?, ?, ?, ?, 100, "none", 0, 0)');
        $stmt->execute([$clientId, $orgId, $status, $contractType]);
        return $this->remember('contracts', (int)$this->pdo->lastInsertId());
    }

    private function insertInvoice(int $orgId, int $clientId, float $total, ?int $contractId = null, string $invoiceType = 'regular'): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO invoices
                (client_id, contract_id, organization_id, invoice_type, status, subtotal, total, amount_paid, balance_due, finalized_at, collection_mode)
            VALUES (?, ?, ?, ?, "unpaid", ?, ?, 0, ?, NOW(), "direct")
        ');
        $stmt->execute([$clientId, $contractId, $orgId, $invoiceType, $total, $total, $total]);
        return $this->remember('invoices', (int)$this->pdo->lastInsertId());
    }

    private function insertInvoicePublicLink(int $invoiceId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO public_links (token, document_type, document_id, revoked) VALUES (?, "invoice", ?, 0)');
        $stmt->execute([bin2hex(random_bytes(16)), $invoiceId]);
        return $this->remember('public_links', (int)$this->pdo->lastInsertId());
    }

    private function publicLinkRevoked(int $publicLinkId): int
    {
        $stmt = $this->pdo->prepare('SELECT revoked FROM public_links WHERE id = ?');
        $stmt->execute([$publicLinkId]);
        return (int)$stmt->fetchColumn();
    }

    private function publicLinkState(int $publicLinkId): array
    {
        $stmt = $this->pdo->prepare('SELECT revoked, redirect, expires_at FROM public_links WHERE id = ?');
        $stmt->execute([$publicLinkId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function invoiceVoidState(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT status,balance_due,voided_at,voided_by,void_reason,void_previous_status FROM invoices WHERE id=?');
        $stmt->execute([$invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function remember(string $bucket, int $id): int
    {
        $this->ids[$bucket][] = $id;
        return $id;
    }
}
