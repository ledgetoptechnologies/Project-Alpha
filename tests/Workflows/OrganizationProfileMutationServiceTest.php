<?php

declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class OrganizationProfileMutationServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testNonUploadProfileWriterKeepsAllAuthoritativeEffectsInOneMutation(): void
    {
        $writer = (string) file_get_contents($this->root . '/src/services/OrganizationProfileMutationService.php');

        self::assertStringContainsString('portal_projection_mutate(', $writer);
        self::assertStringContainsString('source_version = ?', $writer);
        self::assertStringContainsString("api_v2_directory_record(\$pdo, 'organization', \$organizationId)", $writer);
        self::assertStringContainsString("address_book_save(\$pdo", $writer);
        self::assertLessThan(
            strpos($writer, "api_v2_directory_record(\$pdo, 'organization', \$organizationId)"),
            strpos($writer, 'address_book_save($pdo')
        );
        self::assertLessThan(
            strpos($writer, "},\n            static fn(): array => \$projection->organizationScopes"),
            strpos($writer, "api_v2_directory_record(\$pdo, 'organization', \$organizationId)")
        );
    }

    public function testInteractiveDefaultPathDelegatesButUploadBranchesStayLocal(): void
    {
        $controller = (string) file_get_contents($this->root . '/src/controllers/organization/organizations_update.php');

        self::assertStringContainsString('OrganizationProfileMutationService())->mutate', $controller);
        self::assertStringContainsString("if (!empty(\$_FILES['tax_exempt_file'])", $controller);
        self::assertStringContainsString("if (\$remove_tax)", $controller);
        self::assertSame(1, substr_count($controller, 'OrganizationProfileMutationService())->mutate'));
    }
}
