<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/workforce_availability.php';

final class InvoiceWorkforceShelvedUiTest extends TestCase
{
    public function testCreateDoesNotRenderNewTimeOrMileageImportsWhileShelved(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/invoice/invoices-create.php');
        $guard = '<?php if (pa_workforce_enabled()): ?>';
        $buttonsStart = strpos($source, $guard);
        self::assertNotFalse($buttonsStart);
        $buttonsEnd = strpos($source, '<?php endif; ?>', $buttonsStart);
        self::assertNotFalse($buttonsEnd);
        $buttons = substr($source, $buttonsStart, $buttonsEnd + strlen('<?php endif; ?>') - $buttonsStart);
        ob_start();
        eval('?>' . $buttons);
        $renderedButtons = (string) ob_get_clean();
        self::assertStringNotContainsString('btnAddFromTrackedTime', $renderedButtons);
        self::assertStringNotContainsString('btnAddFromMileage', $renderedButtons);

        $modalsStart = strpos($source, $guard, $buttonsEnd);
        $modalsEnd = strpos($source, '<script src=', $modalsStart);
        self::assertNotFalse($modalsStart);
        self::assertNotFalse($modalsEnd);
        ob_start();
        eval('?>' . substr($source, $modalsStart, $modalsEnd - $modalsStart));
        $renderedModals = (string) ob_get_clean();
        self::assertStringNotContainsString('trackedTimeModal', $renderedModals);
        self::assertStringNotContainsString('billableMileageModal', $renderedModals);
        self::assertStringContainsString('onclick="addItemInv()"', $source);
        self::assertStringContainsString('name="billing_mode" value="hourly"', $source);
    }

    public function testEditDoesNotRenderDeadWorkforceActionsButKeepsInvoiceRowsAndManualHourly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/invoice/invoices-edit.php');
        $start = strpos($source, '<?php if (pa_workforce_enabled() && !$isGeneralRecipientInvoice): ?>');
        $end = strpos($source, '<form id="invEditForm"', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $isGeneralRecipientInvoice = false;
        ob_start();
        eval('?>' . substr($source, $start, $end - $start));
        $rendered = (string) ob_get_clean();
        foreach (['Add confirmed time', 'workforce/action', 'Confirm and add', 'workforce/approvals', 'Owner relationship'] as $action) {
            self::assertStringNotContainsString($action, $rendered);
        }
        self::assertStringContainsString('name="billing_mode" value="hourly"', $source);
        self::assertStringContainsString('foreach ($items as $it)', $source);
        self::assertTrue(pa_workforce_page_shelved('time-tracking/unbilled'));
    }
}
