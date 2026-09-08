<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientOrganizationDetailLayoutTest extends TestCase
{
    public function testOrganizationDetailUsesSingleSidebarGapAndStacksDenseContentOnPhones(): void
    {
        $organization = $this->view('organization/organization-view.php');

        self::assertStringContainsString('.org-view__sidebar { display: grid; gap: 14px;', $organization);
        self::assertStringNotContainsString('.org-card + .org-card { margin-top: 18px;', $organization);
        self::assertStringContainsString('@media (max-width: 600px)', $organization);
        self::assertStringContainsString('.org-view__stats { grid-template-columns:1fr; }', $organization);
        self::assertStringContainsString('.org-dept-card__body { grid-template-columns:minmax(0,1fr);', $organization);
        self::assertStringContainsString('.org-dept-contact-assignment { align-items:stretch;flex-direction:column;', $organization);
    }

    public function testDepartmentContactsAndAssignmentsCanShrinkAndWrap(): void
    {
        $organization = $this->view('organization/organization-view.php');

        self::assertStringContainsString('class="org-dept-contact-row"', $organization);
        self::assertStringContainsString('.org-dept-contact-row__identity { min-width:0;overflow-wrap:anywhere;', $organization);
        self::assertStringContainsString('.org-dept-contact-assignment select { flex:1 1 12rem;min-width:0;', $organization);
        self::assertStringContainsString('class="org-dept-contact-assignment"', $organization);
    }

    public function testDepartmentModalHasDialogSemanticsAndSafeKeyboardFocusHandling(): void
    {
        $organization = $this->view('organization/organization-view.php');
        $script = (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/organization-view-logic.js');

        self::assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="departmentModalTitle" aria-hidden="true"', $organization);
        self::assertStringContainsString('class="org-department-modal__dialog" tabindex="-1"', $organization);
        self::assertStringContainsString('max-height:calc(100dvh - 40px);overflow-y:auto;', $organization);
        self::assertStringContainsString("modal.setAttribute('aria-hidden', 'false');", $script);
        self::assertStringContainsString("modal.setAttribute('aria-hidden', 'true');", $script);
        self::assertStringContainsString('OrganizationViewDepartmentModalReturnFocus', $script);
        self::assertStringContainsString("if (event.key !== 'Escape') return;", $script);
        self::assertStringContainsString('returnFocus.isConnected', $script);
    }

    public function testClientProjectsAndPortalControlsRemainResponsiveAndClearlySeparated(): void
    {
        $client = $this->view('client/client-details.php');
        $organization = $this->view('organization/organization-view.php');

        self::assertStringContainsString('class="client-project__name"', $client);
        self::assertStringContainsString('.client-project__name { min-width:0;overflow-wrap:anywhere;', $client);
        self::assertStringContainsString('.client-project { flex-direction:column;gap:4px;', $client);
        self::assertStringContainsString('class="client-card client-danger-zone"', $client);
        self::assertStringContainsString('class="org-card org-danger-zone"', $organization);
        self::assertGreaterThan(
            strpos($client, 'class="client-view__layout"'),
            strpos($client, 'class="client-card client-danger-zone"')
        );
        self::assertGreaterThan(
            strpos($organization, 'class="org-view__layout"'),
            strpos($organization, 'class="org-card org-danger-zone"')
        );
    }

    private function view(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/' . $path);
    }
}
