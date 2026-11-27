<?php
require_once __DIR__ . '/../../src/utils/acl_middleware.php';

use PHPUnit\Framework\TestCase;

/**
 * Pages missing from page_permission_map() are refused for every non-admin
 * user. Admins skip the check, so a missing entry is easy to miss by hand.
 * Every page the app links to must be mapped or deliberately public.
 */
class PagePermissionCoverageTest extends TestCase
{
    /** Pages reached without a signed-in staff session (handled before the ACL map). */
    private const PUBLIC_OR_AUTH_PAGES = [
        'login', 'account-deleted', 'session-status', 'serve-upload',
        'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update',
        'client-onboarding', 'client-onboarding-submit',
        'public-doc', 'public-doc-pdf', 'public-redirect', 'public-autopay',
        'public-check-payment', 'public-quote-action', 'payment-receipt',
        'legal/terms-of-service', 'legal/privacy-policy', 'legal/acceptable-use-policy',
        'legal/dmca-policy', 'legal/data-retention-policy',
    ];

    public function testEveryLinkedPageIsMappedOrPublic(): void
    {
        $root = dirname(__DIR__, 2);
        $map = page_permission_map();
        $missing = [];

        foreach ([$root . '/src', $root . '/public/assets'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!preg_match('/\.(php|js|twig|html)$/', $file->getFilename())) {
                    continue;
                }
                // A page name ends at a quote, &, # or a PHP tag; partial names
                // built at runtime (e.g. "financial/<?php ...") end in "/" and are skipped.
                preg_match_all('#\?page=([a-z0-9][a-z0-9_/-]*[a-z0-9])(?=[\'"&\#<\s]|$)#i', (string)file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $page) {
                    if (!array_key_exists($page, $map) && !in_array($page, self::PUBLIC_OR_AUTH_PAGES, true)) {
                        $missing[$page] = substr($file->getPathname(), strlen($root) + 1);
                    }
                }
            }
        }
        unset($missing['X']); // placeholder in acl_middleware.php comments

        $this->assertSame([], $missing, 'Linked pages missing from page_permission_map(): ' . json_encode($missing, JSON_PRETTY_PRINT));
    }

    public function testExpenseExportIsRouted(): void
    {
        $front = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $this->assertStringContainsString("\$page === 'financial/expense-export'", $front);
    }

    public function testAuditLinkAndTabRequireAuditPermission(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame('financial.audit', page_permission_map()['financial/audit']);
        $header = (string)file_get_contents($root . '/src/views/partials/header.php');
        $this->assertMatchesRegularExpression("#nav_can\('financial\.audit'\)\): \?><li><a href=\"/\?page=financial/audit\"#", $header);
        $hub = (string)file_get_contents($root . '/src/views/pages/financial/expenses-list.php');
        $this->assertStringContainsString("unset(\$tabs['audit'])", $hub);
    }
}
