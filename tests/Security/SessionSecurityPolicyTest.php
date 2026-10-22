<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/request_security.php';

use App\Security\AccountSessionPolicy;
use App\Security\SessionPolicy;
use PHPUnit\Framework\TestCase;

final class SessionSecurityPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAuthenticationHasFixedSevenDayDeadlineAndInitialActivity(): void
    {
        $_SESSION = [];
        SessionPolicy::completeAuthentication('password', 1_000_000);

        self::assertSame(1_000_000, $_SESSION['last_activity']);
        self::assertSame(1_000_000, $_SESSION['authn']['authenticated_at']);
        self::assertSame(1_604_800, $_SESSION['authn']['absolute_expires_at']);
        self::assertSame(1_604_800, SessionPolicy::authenticationDeadline(1_300_000));
    }

    public function testActivityClassificationIsExplicit(): void
    {
        self::assertFalse(SessionPolicy::isIntentionalActivity('session-status'));
        self::assertFalse(SessionPolicy::isIntentionalActivity('financial/mileage-tracking-api', ['action' => 'status']));
        self::assertFalse(SessionPolicy::isIntentionalActivity('financial/mileage-tracking-api', ['action' => 'points']));
        self::assertTrue(SessionPolicy::isIntentionalActivity('financial/mileage-tracking-api', ['action' => 'start']));
        self::assertTrue(SessionPolicy::isIntentionalActivity('invoice/invoice-details'));
    }

    public function testOrdinaryViewsReleaseTheSessionLockButSessionMutationsDoNot(): void
    {
        $session = [
            'user' => ['id' => 42, 'role' => 'admin'],
            'csrf' => str_repeat('a', 64),
            'last_activity' => time(),
        ];

        self::assertTrue(SessionPolicy::canReleaseBeforeViewRendering('GET', 'home', $session));
        self::assertTrue(SessionPolicy::canReleaseBeforeViewRendering('GET', 'settings', $session));
        self::assertFalse(SessionPolicy::canReleaseBeforeViewRendering('POST', 'home', $session));
        self::assertFalse(SessionPolicy::canReleaseBeforeViewRendering('GET', 'home', []));
        self::assertFalse(SessionPolicy::canReleaseBeforeViewRendering('GET', 'account', $session));
        self::assertFalse(SessionPolicy::canReleaseBeforeViewRendering('GET', 'payments/stripe-reconcile-preview', $session));
        self::assertTrue(SessionPolicy::canReleaseBeforeViewRendering('GET', 'payments/payments-list', $session));

        foreach (['flash_api_key', 'flash_backup', 'flash_general_recipient_link',
                  'flash_quote_approve', 'client_onboarding_link', 'tax_import_summary'] as $key) {
            self::assertFalse(
                SessionPolicy::canReleaseBeforeViewRendering('GET', 'home', $session + [$key => null]),
                $key
            );
        }
    }

    public function testFrontControllerReleasesTheSessionAfterTheShellAndBeforeTheView(): void
    {
        $front = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $legacyCsrf = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/csrf.php');
        $symfonyCsrf = (string)file_get_contents(dirname(__DIR__, 2) . '/src/utils/csrf_sf.php');
        $linksView = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/settings/links.php');
        $taxesView = (string)file_get_contents(dirname(__DIR__, 2) . '/src/views/pages/settings/taxes.php');
        $header = strrpos($front, "require_once __DIR__ . '/../src/views/partials/header.php';");
        $release = strpos($front, 'SessionPolicy::canReleaseBeforeViewRendering', (int)$header);
        $close = strpos($front, 'session_write_close();', (int)$release);
        $view = strpos($front, '$view = resolve_view_path($page);', (int)$close);

        self::assertNotFalse($header);
        self::assertNotFalse($release);
        self::assertNotFalse($close);
        self::assertNotFalse($view);
        self::assertLessThan($release, $header);
        self::assertLessThan($close, $release);
        self::assertLessThan($view, $close);
        self::assertStringContainsString("!defined('PA_SESSION_READ_ONLY')", $legacyCsrf);
        self::assertStringContainsString("!defined('PA_SESSION_READ_ONLY')", $symfonyCsrf);
        self::assertStringContainsString("!defined('PA_SESSION_READ_ONLY')", $linksView);
        self::assertStringContainsString("!defined('PA_SESSION_READ_ONLY')", $taxesView);
    }

    public function testOnlySecuritySensitiveAccountChangesRequireGlobalRevocation(): void
    {
        $before = ['email' => 'user@example.test', 'username' => 'user', 'role' => 'member', 'is_disabled' => 0, 'force_password_reset' => 0];
        self::assertFalse(AccountSessionPolicy::requiresGlobalRevocation($before, $before, false));

        $documentOnly = $before + ['document_sender_name' => 'Updated sender'];
        self::assertFalse(AccountSessionPolicy::requiresGlobalRevocation($before, $documentOnly, false));

        foreach (['email', 'username', 'role', 'is_disabled', 'force_password_reset'] as $field) {
            $after = $before;
            $after[$field] = $field === 'role' ? 'staff' : 'changed';
            self::assertTrue(AccountSessionPolicy::requiresGlobalRevocation($before, $after, false), $field);
        }
        self::assertTrue(AccountSessionPolicy::requiresGlobalRevocation($before, $before, true));
    }

    public function testPasswordAndAccountSecurityChangesWireDeliberateRevocation(): void
    {
        $root = dirname(__DIR__, 2);
        $selfPassword = (string)file_get_contents($root . '/src/controllers/auth/account_update.php');
        $tokenReset = (string)file_get_contents($root . '/src/controllers/auth/reset_update.php');
        $adminAccount = (string)file_get_contents($root . '/src/controllers/accounts/accounts_update.php');
        $totpSetup = (string)file_get_contents($root . '/src/controllers/auth/two_factor_setup.php');

        self::assertStringContainsString('revokeUserSessions($pdo, $uid, session_id())', $selfPassword);
        self::assertStringContainsString('SessionPolicy::rotateAuthenticatedId()', $selfPassword);
        self::assertStringContainsString('revokeUserSessions($pdo, $uid)', $tokenReset);
        self::assertStringContainsString('auth_version = auth_version + ?', $adminAccount);
        self::assertStringContainsString('if ($securitySensitiveChange)', $adminAccount);
        self::assertStringContainsString("(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'", $totpSetup);
        self::assertStringNotContainsString("\$_GET['action']", $totpSetup);
    }

    public function testHttpsDetectionTrustsOnlyDirectTlsOrTrustedExactProxyValues(): void
    {
        $originalTrustedProxies = getenv('TRUSTED_PROXIES');
        try {
            putenv('TRUSTED_PROXIES');
            self::assertTrue(request_is_https(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.5']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_PROTO' => 'https']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https']));

            putenv('TRUSTED_PROXIES=10.0.0.0/24,::1');
            self::assertTrue(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '172.20.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https,http']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_FORWARDED' => 'for=198.51.100.7;proto=https, for=10.0.0.4;proto=https']));
            self::assertTrue(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_FORWARDED' => 'for=198.51.100.7;proto=https, for=10.0.0.4;proto=https', 'HTTP_X_FORWARDED_PROTO' => 'https']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https.evil']));
            self::assertTrue(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_VISITOR' => '{"scheme":"https"}']));
            self::assertFalse(request_is_https(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_VISITOR' => '{"note":"https"}']));
            self::assertTrue(request_is_https(['REMOTE_ADDR' => '::1', 'HTTP_FORWARDED' => 'for=192.0.2.1;proto=https']));
        } finally {
            if ($originalTrustedProxies === false) {
                putenv('TRUSTED_PROXIES');
            } else {
                putenv('TRUSTED_PROXIES=' . $originalTrustedProxies);
            }
        }
    }

    public function testFrontControllerAndOauthUseCrossSiteSafeSessionContract(): void
    {
        $root = dirname(__DIR__, 2);
        $front = (string)file_get_contents($root . '/public/index.php');
        $dropbox = (string)file_get_contents($root . '/src/controllers/settings/dropbox_oauth.php');
        $gmail = (string)file_get_contents($root . '/src/controllers/settings/gmail_oauth.php');
        $linksView = (string)file_get_contents($root . '/src/views/pages/settings/links.php');
        $systemView = (string)file_get_contents($root . '/src/views/pages/settings/system.php');
        $header = (string)file_get_contents($root . '/src/views/partials/header.php');

        self::assertStringContainsString("'samesite' => 'Lax'", $front);
        self::assertStringContainsString("session.use_strict_mode", $front);
        self::assertStringContainsString("if (\$page === 'settings/gmail-oauth')", $front);
        self::assertStringNotContainsString("\$page === 'settings/gmail-oauth' && \$_SERVER['REQUEST_METHOD'] === 'GET'", $front);
        self::assertStringContainsString("REQUEST_METHOD", $front);
        self::assertStringContainsString("hash_equals((string)\$_SESSION['csrf']", $front);
        self::assertStringContainsString('SessionPolicy::rotateAuthenticatedId()', $dropbox);
        self::assertStringContainsString("(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'", $dropbox);
        self::assertStringContainsString("(\$_POST['csrf'] ?? '')", $dropbox);
        self::assertStringContainsString('$stateAge > 600', $dropbox);
        self::assertStringNotContainsString('pa_dropbox_oauth_state', $dropbox);
        self::assertStringNotContainsString('session_regenerate_id(false)', $dropbox);
        self::assertStringContainsString('SessionPolicy::rotateAuthenticatedId()', $gmail);
        self::assertStringContainsString("(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'", $gmail);
        self::assertStringContainsString("(\$_POST['csrf'] ?? '')", $gmail);
        self::assertStringContainsString('formaction="/?page=settings/dropbox-oauth&amp;action=start"', $linksView);
        self::assertStringContainsString('formaction="/?page=settings/dropbox-oauth&amp;action=disconnect"', $linksView);
        self::assertStringNotContainsString('<form method="post" action="/?page=settings/dropbox-oauth', $linksView);
        self::assertStringContainsString('formaction="/?page=settings/gmail-oauth&amp;action=connect"', $systemView);
        self::assertStringNotContainsString('<form method="post" action="/?page=settings/gmail-oauth', $systemView);
        self::assertStringNotContainsString('location.reload()', $header);
    }
}
