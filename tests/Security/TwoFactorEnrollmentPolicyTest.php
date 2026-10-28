<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/two_factor_enrollment.php';

use PHPUnit\Framework\TestCase;

final class TwoFactorEnrollmentPolicyTest extends TestCase
{
    public function testAnEnabledFactorCannotBeReplacedOrDisplayedThroughSetup(): void
    {
        $enabled = ['enabled' => 1, 'secret' => 'existing-secret'];
        self::assertFalse(totp_enrollment_can_start($enabled));
        self::assertFalse(totp_enrollment_is_pending(
            ['user_id' => 42, 'expires_at' => 1100], 42, $enabled, 1000
        ));
    }

    public function testOnlyTheCurrentUsersUnexpiredPendingSeedCanBeDisplayed(): void
    {
        $factor = ['enabled' => 0, 'secret' => 'new-secret'];
        $pending = ['user_id' => 42, 'expires_at' => 1100];
        self::assertTrue(totp_enrollment_can_start($factor));
        self::assertTrue(totp_enrollment_is_pending($pending, 42, $factor, 1000));
        self::assertFalse(totp_enrollment_is_pending($pending, 43, $factor, 1000));
        self::assertFalse(totp_enrollment_is_pending($pending, 42, $factor, 1101));
        self::assertFalse(totp_enrollment_is_pending(null, 42, $factor, 1000));
        self::assertFalse(totp_enrollment_is_pending($pending, 42, ['enabled' => 0], 1000));
    }
}
