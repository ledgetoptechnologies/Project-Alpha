<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/general_recipient_invoices.php';

use PHPUnit\Framework\TestCase;

final class GeneralRecipientInvoiceTest extends TestCase
{
    public function testGeneralRecipientIsAnExplicitPresentationModeOnly(): void
    {
        self::assertTrue(pa_invoice_is_general_recipient(['recipient_presentation_mode' => 'general']));
        self::assertFalse(pa_invoice_is_general_recipient(['recipient_presentation_mode' => 'named']));
        self::assertFalse(pa_invoice_is_general_recipient([]));
        self::assertTrue(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'regular', 'collection_mode' => 'direct',
        ]));
        self::assertFalse(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'long_term', 'collection_mode' => 'direct',
        ]));
        self::assertFalse(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'regular', 'collection_mode' => 'project_aggregate',
        ]));
        self::assertFalse(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'regular', 'collection_mode' => 'direct',
            'job_id' => 42,
        ]));
        self::assertFalse(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'regular', 'collection_mode' => 'direct',
            'service_location_id' => 7,
        ]));
    }

    public function testPaidGeneralRecipientReceiptExpiresSevenDaysAfterRecordedPayment(): void
    {
        $paidAt = '2026-07-01 12:00:00';
        self::assertTrue(pa_general_recipient_public_receipt_window_open([
            'recipient_presentation_mode' => 'general', 'status' => 'paid', 'paid_at' => $paidAt,
        ], strtotime('2026-07-08 11:59:59')));
        self::assertFalse(pa_general_recipient_public_receipt_window_open([
            'recipient_presentation_mode' => 'general', 'status' => 'paid', 'paid_at' => $paidAt,
        ], strtotime('2026-07-08 12:00:00')));
        self::assertFalse(pa_general_recipient_public_receipt_window_open([
            'recipient_presentation_mode' => 'general', 'status' => 'unpaid', 'paid_at' => $paidAt,
        ]));
    }

    public function testIntegrationKeepsAccountingClientAndBlocksUnsafeAutomation(): void
    {
        $root = dirname(__DIR__, 2);
        $create = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');
        $lifecycle = (string)file_get_contents($root . '/src/utils/invoice_lifecycle.php');
        $reminders = (string)file_get_contents($root . '/src/utils/invoice_notifications.php');
        $mail = (string)file_get_contents($root . '/src/controllers/email_send.php');
        $public = (string)file_get_contents($root . '/src/controllers/public_view/public_doc.php');
        $links = (string)file_get_contents($root . '/src/utils/public_links.php');
        $wrapper = (string)file_get_contents($root . '/src/views/public/doc-wrapper.php');
        $pdf = (string)file_get_contents($root . '/src/views/pages/invoice/invoice-details.php');

        self::assertMatchesRegularExpression('/\(client_id,recipient_presentation_mode,(?:show_contact_on_document,)?project_id/', $create);
        self::assertStringContainsString('General-recipient invoices must be one-off direct invoices', $create);
        self::assertStringContainsString('General-recipient invoices cannot be emailed automatically', $create);
        self::assertStringContainsString('pa_invoice_is_general_recipient($invoice)', $lifecycle);
        self::assertStringContainsString('recipient_presentation_mode, "named") <> "general"', $reminders);
        self::assertStringContainsString('General-recipient invoices are shared manually', $mail);
        self::assertStringContainsString('pa_general_recipient_public_receipt_window_open', $public);
        self::assertStringContainsString('A paid general-recipient invoice remains an active, non-payable receipt', $links);
        self::assertStringContainsString('after payment it remains a receipt for seven days', $links);
        self::assertStringContainsString('After payment, this link remains available as a receipt for seven days.', $wrapper);
        self::assertStringContainsString('pa_document_recipient($inv, $isGeneralRecipientInvoice)', $pdf);
    }

    public function testNormalCreateFlowDoesNotExposeAnonymousRecipientMode(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/invoice/invoices-create.php');
        $controller = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');

        self::assertStringNotContainsString('id="generalRecipientInvoice"', $view);
        self::assertStringNotContainsString('General-recipient invoice', $view);
        self::assertStringNotContainsString("getElementById('generalRecipientInvoice')", $view);
        self::assertStringContainsString('recipient_presentation_mode', $controller);
        self::assertStringContainsString('PA_GENERAL_RECIPIENT_MODE', $controller);
    }

    public function testFinalizeAndLinkFlowIsAtomicAndIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $lifecycle = (string)file_get_contents($root . '/src/utils/invoice_lifecycle.php');
        $create = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');
        $finalize = (string)file_get_contents($root . '/src/controllers/invoice/invoice_finalize.php');
        $publicLinkCreate = (string)file_get_contents($root . '/src/controllers/public_link_create.php');

        self::assertStringContainsString('function invoice_finalize_and_create_general_recipient_link', $lifecycle);
        self::assertStringContainsString('invoice_finalize($pdo, $invoiceId, $appConfig, \'general_recipient_manual_link\'', $lifecycle);
        self::assertStringContainsString('FOR UPDATE', $lifecycle);
        self::assertStringContainsString('bin2hex(random_bytes(32))', $lifecycle);
        self::assertStringContainsString('expire_when_paid=1 AND expires_at IS NULL', $lifecycle);
        self::assertStringContainsString('invoice_finalize_and_create_general_recipient_link($pdo, $invoice_id', $create);
        self::assertStringContainsString('invoice_finalize_and_create_general_recipient_link($pdo, $id', $finalize);
        self::assertMatchesRegularExpression(
            '/invoice_finalize_and_create_general_recipient_link\(\R\s*\$pdo,\R\s*\$id,/',
            $publicLinkCreate
        );
        self::assertStringNotContainsString('invoice_send_finalized($pdo, $id', substr($finalize, 0, strpos($finalize, '} else {') ?: 0));
    }

    public function testReceiptWindowIsNotRewrittenAsLegacyExpiryAndPdfAllowsIt(): void
    {
        $root = dirname(__DIR__, 2);
        $public = (string)file_get_contents($root . '/src/controllers/public_view/public_doc.php');
        $pdf = (string)file_get_contents($root . '/src/controllers/public_view/public_doc_pdf.php');
        $links = (string)file_get_contents($root . '/src/utils/public_links.php');

        self::assertStringContainsString('&& empty($row[\'expires_at\'])', $public);
        self::assertStringContainsString('&& empty($row[\'expires_at\'])', $pdf);
        self::assertStringContainsString('pa_general_recipient_public_receipt_window_open($invoice)', $pdf);
        self::assertStringContainsString('AND revoked=0', $links);
        self::assertStringNotContainsString('AND revoked=0 AND expires_at IS NULL', $links);
        self::assertFileExists($root . '/database/migrations/0060_general_recipient_invoices.sql');
        self::assertFileDoesNotExist($root . '/database/migrations/0060_client_portal_foundation.sql');
    }

    public function testCreateScreenUsesOnlyNormalProjectSelectionWhileHistoricalGeneralInvoicesRemainSupported(): void
    {
        $root = dirname(__DIR__, 2);
        $createView = (string)file_get_contents($root . '/src/views/pages/invoice/invoices-create.php');
        $createScript = (string)file_get_contents($root . '/public/assets/js/invoices-create-logic.js');
        $detailView = (string)file_get_contents($root . '/src/views/pages/invoice/invoice-details.php');

        self::assertStringContainsString('function loadProjectsForClientInv(clientId)', $createScript);
        self::assertStringNotContainsString('generalRecipientInvoice', $createView);
        self::assertStringNotContainsString('Finalize &amp; Create Link', $createView);
        self::assertStringContainsString("'Finalize & Create Link'", $detailView);
        self::assertStringContainsString('htmlspecialchars($finalizeLabel', $detailView);
        self::assertStringContainsString('!$isGeneralRecipientInvoice', $detailView);
        self::assertStringContainsString('flash_general_recipient_link', $detailView);
        self::assertStringNotContainsString("\$_GET['general_public_link']", $detailView);
        self::assertStringContainsString('if (!$isGeneralRecipientInvoice)', $detailView);
        $contentLinkOffset = strpos($detailView, '$invoiceContentLinksHtml');
        self::assertNotFalse($contentLinkOffset);
        self::assertStringContainsString(
            'if (!$isGeneralRecipientInvoice)',
            substr($detailView, max(0, (int)$contentLinkOffset - 160), 160)
        );
        self::assertStringNotContainsString("querySelectorAll('input[name^=\"time_entry_ids[\"], input[name^=\"mileage_allocation_ids[\"]')", $createView);
    }

    public function testGeneralRecipientPaymentsCannotCreateASeparateClientReceipt(): void
    {
        $root = dirname(__DIR__, 2);
        $receipts = (string)file_get_contents($root . '/src/utils/payment_receipts.php');
        $publicReceipt = (string)file_get_contents($root . '/src/controllers/public_view/payment_receipt.php');
        $paymentView = (string)file_get_contents($root . '/src/views/pages/payments/payments-create.php');
        $paymentLogic = (string)file_get_contents($root . '/public/assets/js/payments-create-logic.js');

        self::assertStringContainsString('i.recipient_presentation_mode', $receipts);
        self::assertStringContainsString('pa_invoice_is_general_recipient($payment)', $receipts);
        self::assertStringContainsString('pa_invoice_is_general_recipient($receipt)', $publicReceipt);
        self::assertStringContainsString('data-general-recipient=', $paymentView);
        self::assertStringContainsString('The original public invoice link becomes the receipt for seven days after payment', $paymentLogic);
    }

    public function testDraftEditsAndTrackedTimeCannotAttachPrivateRelationships(): void
    {
        $root = dirname(__DIR__, 2);
        $edit = (string)file_get_contents($root . '/src/views/pages/invoice/invoices-edit.php');
        $update = (string)file_get_contents($root . '/src/controllers/invoice/invoices_update.php');
        $eligibility = (string)file_get_contents($root . '/src/services/WorkTimeInvoiceEligibilityService.php');

        self::assertStringContainsString('$isGeneralRecipientInvoice = pa_invoice_is_general_recipient($inv)', $edit);
        self::assertStringContainsString("\$isGeneralRecipientInvoice ? 'readonly' : ''", $edit);
        self::assertStringContainsString('if (!$isGeneralRecipientInvoice)', $edit);
        self::assertStringContainsString('$changesPrivateRelationship', $update);
        self::assertStringContainsString('if (pa_workforce_enabled() && !$isGeneralRecipientInvoice)', $update);
        self::assertStringContainsString('General-recipient invoices cannot include tracked time.', $eligibility);
    }

    public function testStripeDescriptionsNeverExposeTheInternalClient(): void
    {
        $root = dirname(__DIR__, 2);
        $checkout = (string)file_get_contents($root . '/src/controllers/stripe/stripe_checkout.php');
        $charge = (string)file_get_contents($root . '/src/controllers/stripe/stripe_charge.php');

        self::assertStringContainsString('pa_invoice_is_general_recipient($invoice)', $checkout);
        self::assertStringContainsString("? 'General Recipient'", $charge);
    }

    public function testBearerTokenNeverAppearsInAuthenticatedRedirectUrls(): void
    {
        $root = dirname(__DIR__, 2);
        $create = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');
        $finalize = (string)file_get_contents($root . '/src/controllers/invoice/invoice_finalize.php');

        self::assertStringContainsString("\$_SESSION['flash_general_recipient_link']", $create);
        self::assertStringContainsString("\$_SESSION['flash_general_recipient_link']", $finalize);
        self::assertStringNotContainsString('general_public_link=', $create);
        self::assertStringNotContainsString('general_public_link=', $finalize);
    }
}
