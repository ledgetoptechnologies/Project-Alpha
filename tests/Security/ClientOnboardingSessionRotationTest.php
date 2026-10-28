<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/client_onboarding.php';

final class ClientOnboardingSessionRotationTest extends TestCase
{
    private array $originalSession;

    protected function setUp(): void
    {
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
    }

    public function testRegeneratedLinkInvalidatesExistingVisitorSession(): void
    {
        $original = ['id' => 42, 'token_hash' => hash('sha256', 'first-link')];
        client_onboarding_grant_session($original);
        self::assertTrue(client_onboarding_session_matches($original));

        $rotated = ['id' => 42, 'token_hash' => hash('sha256', 'replacement-link')];
        self::assertFalse(client_onboarding_session_matches($rotated));

        client_onboarding_grant_session($rotated);
        self::assertTrue(client_onboarding_session_matches($rotated));
        client_onboarding_clear_session();
        self::assertFalse(client_onboarding_session_matches($rotated));
    }

    public function testLegacyIdOnlySessionCannotAuthorizeInvitation(): void
    {
        $_SESSION['client_onboarding_invitation_id'] = 42;
        self::assertFalse(client_onboarding_session_matches([
            'id' => 42,
            'token_hash' => hash('sha256', 'replacement-link'),
        ]));
    }
}
