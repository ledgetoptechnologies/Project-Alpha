<?php

declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class PortalRecoveryUiTest extends TestCase
{
    /** @param array<string,int> $counts */
    private function renderActions(array $counts): string
    {
        $portalStatus = ['configured'=>true, 'ready'=>true];
        $portalCounts = $counts;
        $h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        require_once dirname(__DIR__, 2) . '/src/utils/csrf.php';

        ob_start();
        require dirname(__DIR__, 2) . '/src/views/pages/settings/external-ops-recovery-actions.php';
        return (string)ob_get_clean();
    }

    public function testPortalAndBackfillRecoveryRenderWithoutFailedRevocations(): void
    {
        $html = $this->renderActions([
            'failed_revocations'=>0,
            'failed_portal'=>3,
            'failed_backfill'=>2,
        ]);

        self::assertStringContainsString('recover-client-portal-deliveries', $html);
        self::assertStringContainsString('Queue replacement snapshots', $html);
        self::assertStringContainsString('retry-client-portal-backfill', $html);
        self::assertStringContainsString('Retry failed historical roots (2)', $html);
        self::assertStringNotContainsString('retry-client-portal-revocations', $html);
    }

    public function testRevocationRetryRemainsConditional(): void
    {
        $html = $this->renderActions([
            'failed_revocations'=>1,
            'failed_portal'=>0,
            'failed_backfill'=>0,
        ]);

        self::assertStringContainsString('retry-client-portal-revocations', $html);
        self::assertStringNotContainsString('recover-client-portal-deliveries', $html);
        self::assertStringNotContainsString('retry-client-portal-backfill', $html);
    }
}
