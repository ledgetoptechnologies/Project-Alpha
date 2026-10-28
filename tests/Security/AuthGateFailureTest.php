<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/auth_gate_checks.php';

final class AuthGateFailureTest extends TestCase
{
    public function testTwoFactorLookupDistinguishesNoEnrollmentFromDatabaseFailure(): void
    {
        $pdo = $this->database();
        $pdo->exec('CREATE TABLE user_2fa(user_id INTEGER,enabled INTEGER)');
        self::assertFalse(pa_auth_two_factor_enabled($pdo, 7));
        $pdo->exec('INSERT INTO user_2fa VALUES(7,1)');
        self::assertTrue(pa_auth_two_factor_enabled($pdo, 7));
        $pdo->exec('DROP TABLE user_2fa');
        $this->expectException(PDOException::class);
        pa_auth_two_factor_enabled($pdo, 7);
    }

    public function testBothRecoveryFlagsFailWhenTheirQueriesFail(): void
    {
        foreach (['force_password_reset', 'totp_reenroll_required'] as $flag) {
            $pdo = $this->database();
            try {
                pa_auth_recovery_flag($pdo, 7, $flag);
                self::fail('Missing users table allowed ' . $flag);
            } catch (PDOException) {
                self::assertTrue(true);
            }
            $other = $flag === 'force_password_reset' ? 'totp_reenroll_required' : 'force_password_reset';
            $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, {$other} INTEGER); INSERT INTO users VALUES(7,0)");
            try {
                pa_auth_recovery_flag($pdo, 7, $flag);
                self::fail('Missing recovery column allowed ' . $flag);
            } catch (PDOException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMissingRecoveryUserFailsClosed(): void
    {
        $pdo = $this->database();
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,force_password_reset INTEGER,totp_reenroll_required INTEGER)');
        foreach (['force_password_reset', 'totp_reenroll_required'] as $flag) {
            try {
                pa_auth_recovery_flag($pdo, 7, $flag);
                self::fail('Missing user allowed ' . $flag);
            } catch (RuntimeException $error) {
                self::assertSame('Recovery state unavailable', $error->getMessage());
            }
        }
        $pdo->exec('INSERT INTO users VALUES(7,1,0)');
        self::assertTrue(pa_auth_recovery_flag($pdo, 7, 'force_password_reset'));
        self::assertFalse(pa_auth_recovery_flag($pdo, 7, 'totp_reenroll_required'));
    }

    public function testFrontControllerAndLoginDenyOnGateExceptions(): void
    {
        $root = dirname(__DIR__, 2);
        $front = (string)file_get_contents($root . '/public/index.php');
        $login = (string)file_get_contents($root . '/src/controllers/auth/auth_handler.php');
        self::assertStringContainsString("pa_auth_two_factor_enabled(\$pdo, (int)\$u['id'])", $login);
        self::assertStringContainsString("exit('Authentication state unavailable')", $front);
        self::assertStringContainsString("exit('Authorization unavailable')", $front);
        self::assertStringNotContainsString('allow through if check fails', $front);
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
