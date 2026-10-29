<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_enrollment.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_collection.php';
require_once dirname(__DIR__, 2) . '/src/utils/autopay_staff_management.php';

final class AutoPayDisableDatabaseTest extends TestCase
{
    private PDO $pdo;
    private array $client;
    private array $clients = [];
    private array $contracts = [];
    private array $invoices = [];
    private array $users = [];
    private array $env = [];
    private array $config = ['_stripe_secret_key' => 'sk_test_synthetic', 'app_host' => 'https://example.invalid', 'workforce_currency' => 'usd'];

    protected function setUp(): void
    {
        if (getenv('AUTOPAY_DISABLE_ALLOW') !== 'isolated-disposable-only') self::markTestSkipped('Explicit disposable AutoPay disable database required.');
        if (!preg_match('/^pa_autopay_disable_[a-f0-9]{16}$/D', (string)getenv('MYSQL_DATABASE')) || getenv('DB_HOST') !== '127.0.0.1') self::fail('Isolated loopback AutoPay disable database required.');
        $this->pdo = migration_connection();
        $this->pdo->query('SELECT management_token FROM autopay_enrollments LIMIT 0');
        foreach (['APP_ENV' => 'test', 'AUTOPAY_BETA_ENABLED' => 'true', 'AUTOPAY_INSTALLATION_ID' => 'synthetic-disable-test', 'AUTOPAY_STRIPE_ACCOUNT_ID' => 'acct_disableTEST'] as $key => $value) {
            $this->env[$key] = getenv($key); putenv($key . '=' . $value);
        }
        $this->client = $this->client();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($this->invoices as $id) {
                $this->pdo->prepare('DELETE FROM public_links WHERE document_type="invoice" AND document_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
            }
            foreach ($this->clients as $id) {
                $this->pdo->prepare('DELETE FROM autopay_collections WHERE client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM autopay_enrollments WHERE client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM autopay_email_challenges WHERE client_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM system_audit WHERE entity_type="client" AND entity_id=?')->execute([$id]);
            }
            foreach ($this->contracts as $id) $this->pdo->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
            foreach ($this->clients as $id) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
            foreach ($this->users as $id) {
                $this->pdo->prepare('DELETE FROM system_audit WHERE user_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            }
            $this->pdo->exec('DELETE FROM rate_limits WHERE identifier="127.0.0.24:autopay_manage"');
            $this->pdo->exec('DELETE FROM rate_limits WHERE identifier="127.0.0.24:public_doc"');
        }
        foreach ($this->env as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    private function client(): array
    {
        $email = 'synthetic-disable-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $this->pdo->prepare('INSERT INTO clients(name,email) VALUES ("Synthetic disable client",?)')->execute([$email]);
        $id = (int)$this->pdo->lastInsertId(); $this->clients[] = $id;
        return ['id' => $id, 'email' => $email, 'name' => 'Synthetic disable client'];
    }

    private function proof(?array $client = null): array
    {
        $client ??= $this->client;
        return ['autopay_verified' => ['client_id' => $client['id'], 'email' => $client['email'], 'until' => time() + 600, 'proof' => 'synthetic']];
    }

    private function authorization(array $override = []): int
    {
        $row = array_replace(['client_id' => $this->client['id'], 'contract_id' => null, 'scope' => 'business', 'status' => 'active', 'processor_account_id' => 'acct_disableTEST', 'installation_id' => 'synthetic-disable-test', 'livemode' => 0, 'stripe_customer_id' => 'cus_disable', 'payment_method_id' => 'pm_disable', 'method_type' => 'card', 'currency' => 'usd', 'consent_version' => 'synthetic', 'consent_text' => 'Synthetic test consent', 'verified_email' => $this->client['email'], 'consent_at' => '2026-01-01 00:00:00', 'consent_proof' => str_repeat('a', 64), 'verified_at' => '2026-01-01 00:00:00', 'effective_from' => '2026-01-01 00:00:00'], $override);
        $sql = 'INSERT INTO autopay_enrollments (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    private function row(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM autopay_enrollments WHERE id=?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC);
    }

    public function testVerifiedClientAuthorityMustBeCurrentAndClientBound(): void
    {
        $id = $this->authorization();
        $expired = $this->proof(); $expired['autopay_verified']['until'] = time() - 1;
        foreach ([[], $expired, $this->proof($this->client())] as $proof) {
            try { pa_autopay_revoke($this->pdo, $this->client, $proof, $id); self::fail('Unverified disable accepted.'); }
            catch (DomainException $error) { self::assertStringContainsString('Verify your email', $error->getMessage()); }
            self::assertSame('active', $this->row($id)['status']);
        }
        $this->pdo->prepare('UPDATE clients SET email="changed@example.invalid" WHERE id=?')->execute([$this->client['id']]);
        try { pa_autopay_revoke($this->pdo, $this->client, $this->proof(), $id); self::fail('Stale verified email accepted.'); }
        catch (DomainException $error) { self::assertStringContainsString('current client email', $error->getMessage()); }
        self::assertSame('active', $this->row($id)['status']);
    }

    public function testSingleScopePreservesFallbackAndAllDisablesBothScopes(): void
    {
        $this->pdo->prepare('INSERT INTO contracts(client_id,contract_type,status) VALUES (?,"long_term","pending")')->execute([$this->client['id']]);
        $contract = (int)$this->pdo->lastInsertId(); $this->contracts[] = $contract;
        $business = $this->authorization();
        $agreement = $this->authorization(['scope' => 'agreement', 'contract_id' => $contract]);
        $replacement = $this->authorization(['scope' => 'agreement', 'contract_id' => $contract, 'status' => 'pending']);
        $invoice = ['client_id' => $this->client['id'], 'contract_id' => $contract, 'currency' => 'usd'];
        $this->pdo->beginTransaction(); $selected = pa_autopay_authorization_for_invoice($this->pdo, $invoice, $this->config); $this->pdo->commit();
        self::assertSame($agreement, (int)$selected['id']);
        self::assertTrue(pa_autopay_revoke_authorization($this->pdo, $this->client, $agreement));
        self::assertSame('revoked', $this->row($replacement)['status']);
        self::assertSame('active', $this->row($business)['status']);
        $this->pdo->beginTransaction(); $selected = pa_autopay_authorization_for_invoice($this->pdo, $invoice, $this->config); $this->pdo->commit();
        self::assertSame($business, (int)$selected['id']);
        self::assertTrue(pa_autopay_revoke_authorization($this->pdo, $this->client, 0));
        $this->pdo->beginTransaction(); $selected = pa_autopay_authorization_for_invoice($this->pdo, $invoice, $this->config); $this->pdo->commit();
        self::assertNull($selected);
    }

    public function testUnknownForeignClientInstallationAccountAndLiveIdsCannotBeRevoked(): void
    {
        $other = $this->client();
        $ids = [$this->authorization(['client_id' => $other['id'], 'verified_email' => $other['email']]), $this->authorization(['installation_id' => 'another-installation']), $this->authorization(['processor_account_id' => 'acct_other']), $this->authorization(['livemode' => 1])];
        foreach ([...$ids, PHP_INT_MAX] as $id) {
            try { pa_autopay_revoke_authorization($this->pdo, $this->client, $id); self::fail('Foreign authorization accepted.'); }
            catch (DomainException $error) { self::assertStringContainsString('unavailable', $error->getMessage()); }
        }
        $own = $this->authorization();
        self::assertTrue(pa_autopay_revoke_authorization($this->pdo, $this->client, 0));
        self::assertSame('revoked', $this->row($own)['status']);
        foreach ($ids as $id) self::assertSame('active', $this->row($id)['status']);
    }

    public function testRepeatDisablePreservesOriginalRevocationTime(): void
    {
        $id = $this->authorization();
        self::assertTrue(pa_autopay_revoke_authorization($this->pdo, $this->client, $id));
        $this->pdo->prepare('UPDATE autopay_enrollments SET revoked_at="2026-01-01 00:00:00" WHERE id=?')->execute([$id]);
        self::assertFalse(pa_autopay_revoke_authorization($this->pdo, $this->client, $id));
        self::assertFalse(pa_autopay_revoke_authorization($this->pdo, $this->client, 0));
        self::assertSame('2026-01-01 00:00:00', $this->row($id)['revoked_at']);
    }

    public function testAlreadyReservedAndProcessingCollectionsAreNotCanceledOrMarkedPaid(): void
    {
        $auth = $this->authorization();
        foreach (['pending', 'processing', 'requires_action', 'held'] as $index => $state) {
            $this->pdo->prepare('INSERT INTO autopay_collections(document_type,document_id,authorization_id,client_id,amount_cents,currency,processor_account_id,installation_id,stripe_customer_id,payment_method_id,method_type,idempotency_key,stripe_payment_intent_id,state,financial_hold,source) VALUES ("invoice",?,?,?,?,"usd","acct_disableTEST","synthetic-disable-test","cus_disable","pm_disable","card",?,?,?,1,"synthetic")')->execute([100000 + $index, $auth, $this->client['id'], 35000, 'synthetic-disable-' . $auth . '-' . $index, 'pi_disable_' . $auth . '_' . $index, $state]);
        }
        $s = $this->pdo->prepare('SELECT * FROM autopay_collections WHERE client_id=? ORDER BY id'); $s->execute([$this->client['id']]); $before = $s->fetchAll(PDO::FETCH_ASSOC);
        pa_autopay_revoke_authorization($this->pdo, $this->client, 0);
        $s->execute([$this->client['id']]); self::assertSame($before, $s->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('revoked', $this->row($auth)['status']);
    }

    public function testLateSetupCallbackCannotReactivateRevokedPendingAuthorization(): void
    {
        $id = $this->authorization(['status' => 'pending']);
        pa_autopay_revoke_authorization($this->pdo, $this->client, 0);
        $event = ['type' => 'setup_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'seti_disable', 'metadata' => ['pa_autopay_authorization_id' => (string)$id]]]];
        $clientId = $this->client['id'];
        $transport = static fn($method, $endpoint) => str_starts_with($endpoint, 'payment_methods/')
            ? ['id' => 'pm_disable', 'customer' => 'cus_disable', 'type' => 'card', 'livemode' => false, 'card' => ['last4' => '4242']]
            : ['id' => 'seti_disable', 'payment_method' => 'pm_disable', 'customer' => 'cus_disable', 'status' => 'succeeded', 'usage' => 'off_session', 'livemode' => false,
                'metadata' => ['pa_autopay_authorization_id' => (string)$id, 'pa_client_id' => (string)$clientId, 'pa_autopay_installation_id' => 'synthetic-disable-test', 'pa_autopay_account_id' => 'acct_disableTEST']];
        self::assertFalse(pa_autopay_setup_event($this->pdo, $event, $this->config, $transport));
        self::assertSame('revoked', $this->row($id)['status']);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM autopay_enrollment_notifications WHERE enrollment_id=' . $id)->fetchColumn());
    }

    public function testDisableDuringCustomerCreationCannotLeaveNewPendingConsentAfterward(): void
    {
        $transport = function ($method, $endpoint) {
            if ($endpoint === 'customers') {
                $otherConnection = migration_connection();
                pa_autopay_revoke_authorization($otherConnection, $this->client, 0);
                return ['id' => 'cus_setup_race', 'livemode' => false];
            }
            if ($endpoint === 'checkout/sessions') return ['id' => 'cs_test_race', 'url' => 'https://checkout.stripe.com/c/pay/synthetic', 'livemode' => false];
            throw new RuntimeException('Unexpected processor request.');
        };
        try {
            pa_autopay_enroll($this->pdo, ['client' => $this->client, 'contract_id' => 0], $this->proof(), ['scope' => 'business', 'consent' => 1, 'business_consent' => 1, 'method_type' => 'card', 'token' => 'synthetic'], $this->config, $transport);
        } catch (DomainException $expectedRevocation) {
            self::assertNotEmpty($expectedRevocation->getMessage());
        }
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM autopay_enrollments WHERE client_id=? AND status IN ("pending","active")'); $s->execute([$this->client['id']]);
        self::assertSame(0, (int)$s->fetchColumn(), 'A setup already in flight must not recreate consent after Turn off all returns.');
    }

    public function testTestModeSetupNeverSupersedesLiveAuthorizations(): void
    {
        $liveActive = $this->authorization(['livemode' => 1]);
        $livePending = $this->authorization(['livemode' => 1, 'status' => 'pending']);
        $before = [$this->row($liveActive), $this->row($livePending)];
        $transport = static fn($method, $endpoint) => $endpoint === 'customers'
            ? ['id' => 'cus_mode_isolation', 'livemode' => false]
            : ['id' => 'cs_test_mode_isolation', 'url' => 'https://checkout.stripe.com/c/pay/synthetic', 'livemode' => false];
        pa_autopay_enroll($this->pdo, ['client' => $this->client, 'contract_id' => 0], $this->proof(), ['scope' => 'business', 'consent' => 1, 'business_consent' => 1, 'method_type' => 'card', 'token' => 'synthetic'], $this->config, $transport);
        self::assertSame($before, [$this->row($liveActive), $this->row($livePending)]);
        $s = $this->pdo->prepare('SELECT id FROM autopay_enrollments WHERE client_id=? AND livemode=0 ORDER BY id DESC LIMIT 1'); $s->execute([$this->client['id']]); $id = (int)$s->fetchColumn();
        $clientId = $this->client['id'];
        $verified = static fn($method, $endpoint) => str_starts_with($endpoint, 'payment_methods/')
            ? ['id' => 'pm_mode_isolation', 'customer' => 'cus_mode_isolation', 'type' => 'card', 'livemode' => false, 'card' => ['last4' => '4242']]
            : ['id' => 'seti_mode_isolation', 'payment_method' => 'pm_mode_isolation', 'customer' => 'cus_mode_isolation', 'status' => 'succeeded', 'usage' => 'off_session', 'livemode' => false,
                'metadata' => ['pa_autopay_authorization_id' => (string)$id, 'pa_client_id' => (string)$clientId, 'pa_autopay_installation_id' => 'synthetic-disable-test', 'pa_autopay_account_id' => 'acct_disableTEST']];
        $event = ['type' => 'setup_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'seti_mode_isolation', 'metadata' => ['pa_autopay_authorization_id' => (string)$id]]]];
        self::assertTrue(pa_autopay_setup_event($this->pdo, $event, $this->config, $verified));
        self::assertSame('active', $this->row($id)['status']);
        self::assertSame($before, [$this->row($liveActive), $this->row($livePending)]);
    }

    public function testDisableDuringCheckoutCreationCannotReturnAnActiveSetupLink(): void
    {
        $checkoutCalled = false;
        $transport = function ($method, $endpoint) use (&$checkoutCalled) {
            if ($endpoint === 'customers') return ['id' => 'cus_checkout_race', 'livemode' => false];
            if ($endpoint === 'checkout/sessions') {
                $checkoutCalled = true;
                pa_autopay_revoke_authorization(migration_connection(), $this->client, 0);
                return ['id' => 'cs_test_checkout_race', 'url' => 'https://checkout.stripe.com/c/pay/synthetic', 'livemode' => false];
            }
            throw new RuntimeException('Unexpected processor request.');
        };
        try {
            pa_autopay_enroll($this->pdo, ['client' => $this->client, 'contract_id' => 0], $this->proof(), ['scope' => 'business', 'consent' => 1, 'business_consent' => 1, 'method_type' => 'card', 'token' => 'synthetic'], $this->config, $transport);
            self::fail('Setup URL returned after the client disabled all automatic payments.');
        } catch (DomainException $expectedRevocation) {
            self::assertNotEmpty($expectedRevocation->getMessage());
        }
        self::assertTrue($checkoutCalled);
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM autopay_enrollments WHERE client_id=? AND status IN ("pending","active")'); $s->execute([$this->client['id']]);
        self::assertSame(0, (int)$s->fetchColumn());
    }

    public function testPublicControllerRequiresCsrfEmailProofAndExplicitConfirmation(): void
    {
        $id = $this->authorization(['management_token' => bin2hex(random_bytes(32))]);
        $cases = [
            ['method' => 'GET', 'proof' => $this->proof(), 'post' => [], 'expected' => 'AutoPay'],
            ['proof' => $this->proof(), 'post' => ['csrf' => 'wrong'], 'expected' => 'Invalid request'],
            ['proof' => [], 'post' => [], 'expected' => 'Verify your email'],
            ['proof' => $this->proof($this->client()), 'post' => [], 'expected' => 'Verify your email'],
            ['proof' => ['autopay_verified' => ['client_id' => $this->client['id'], 'email' => $this->client['email'], 'until' => time() - 1]], 'post' => [], 'expected' => 'Verify your email'],
            ['proof' => $this->proof(), 'post' => ['confirm_disable' => ''], 'expected' => 'Confirm that you want'],
        ];
        foreach ($cases as $case) {
            $output = $this->publicController($this->row($id)['management_token'], $case);
            self::assertStringContainsString($case['expected'], $output);
            if (($case['method'] ?? '') === 'GET') {
                self::assertStringContainsString('Turn off all AutoPay', $output);
                self::assertStringContainsString('Turn off this billing scope', $output);
                self::assertStringContainsString('Other authorizations may still apply', $output);
            }
            self::assertSame('active', $this->row($id)['status']);
        }
        $this->authorization(['status' => 'pending']);
        putenv('AUTOPAY_BETA_ENABLED=false');
        $output = $this->publicController($this->row($id)['management_token'], ['proof' => $this->proof(), 'post' => []]);
        self::assertStringContainsString('AutoPay is off for all agreements', $output);
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM autopay_enrollments WHERE client_id=? AND status IN ("pending","active")'); $s->execute([$this->client['id']]);
        self::assertSame(0, (int)$s->fetchColumn());
    }

    private function publicController(string $token, array $case): string
    {
        $root = dirname(__DIR__, 2);
        $payload = base64_encode(json_encode([$token, $case, $this->config], JSON_THROW_ON_ERROR));
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/src/config/app.php', true) . ';[$token,$case,$config]=json_decode(base64_decode(' . var_export($payload, true) . '),true);$appConfig=array_replace($appConfig,$config);session_id("synthetic-disable-".bin2hex(random_bytes(8)));session_start();register_shutdown_function(static function(){if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});$_SESSION=["csrf"=>"synthetic-csrf"]+$case["proof"];$_SERVER["REQUEST_METHOD"]=$case["method"]??"POST";$_SERVER["REMOTE_ADDR"]="127.0.0.24";$_GET=["manage"=>$token];$_POST=($case["post"]??[])+["manage"=>$token,"csrf"=>"synthetic-csrf","action"=>"revoke_all","confirm_disable"=>"1"];require ' . var_export($root . '/src/controllers/public_view/public_autopay.php', true) . ';';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        return $output;
    }

    public function testPublicInvoiceKeepsManagementLinkWhenNewEnrollmentIsPaused(): void
    {
        $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,balance_due,finalized_at,finalization_source) VALUES (?,"unpaid",10,10,NOW(),"manual_finalize")')->execute([$this->client['id']]);
        $invoice = (int)$this->pdo->lastInsertId(); $this->invoices[] = $invoice;
        $token = bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO public_links(document_type,document_id,token,expires_at,expire_when_paid) VALUES ("invoice",?,?,DATE_ADD(NOW(),INTERVAL 1 DAY),0)')->execute([$invoice, $token]);
        foreach ([['test', 'true', 'Set up or manage AutoPay (test mode)'], ['test', 'false', 'Manage or turn off AutoPay (test mode)'], ['production', 'false', null]] as [$environment, $flag, $label]) {
            putenv('APP_ENV=' . $environment); putenv('AUTOPAY_BETA_ENABLED=' . $flag);
            $root = dirname(__DIR__, 2);
            $payload = base64_encode(json_encode([$token, $this->config], JSON_THROW_ON_ERROR));
            $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/src/config/app.php', true) . ';[$token,$config]=json_decode(base64_decode(' . var_export($payload, true) . '),true);$appConfig=array_replace($appConfig,$config);session_id("synthetic-doc-disable-".bin2hex(random_bytes(8)));session_start();register_shutdown_function(static function(){if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});$_SERVER["REMOTE_ADDR"]="127.0.0.24";$_SERVER["REQUEST_METHOD"]="GET";$_GET=["token"=>$token];require ' . var_export($root . '/src/controllers/public_view/public_doc.php', true) . ';';
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($process); fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            if ($label === null) self::assertStringNotContainsString('page=public-autopay', $output);
            else self::assertStringContainsString($label, $output);
        }
    }

    public function testStaffDisableRequiresBothPermissionsAndRecordsOneAuditEvent(): void
    {
        $id = $this->authorization();
        $this->pdo->prepare('INSERT INTO users(username,email,password_hash,role) VALUES (?,?,"synthetic-disabled-password","admin")')->execute(['synthetic-disable-' . bin2hex(random_bytes(6)), 'staff-disable@example.invalid']);
        $actor = (int)$this->pdo->lastInsertId(); $this->users[] = $actor;
        $this->pdo->prepare('UPDATE clients SET created_by=? WHERE id=?')->execute([$actor, $this->client['id']]);
        self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, $this->client['id'], 0));
        try { pa_autopay_staff_disable_client($this->pdo, $this->client['id'], 0, $this->config); self::fail('Unauthenticated staff disabled AutoPay.'); }
        catch (DomainException $error) { self::assertStringContainsString('permission', $error->getMessage()); }
        self::assertSame('active', $this->row($id)['status']);
        putenv('AUTOPAY_BETA_ENABLED=false');
        self::assertTrue(pa_autopay_staff_can_manage_client($this->pdo, $this->client['id'], $actor));
        self::assertTrue(pa_autopay_staff_disable_client($this->pdo, $this->client['id'], $actor, $this->config));
        self::assertFalse(pa_autopay_staff_disable_client($this->pdo, $this->client['id'], $actor, $this->config));
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM system_audit WHERE action="client.autopay_disabled" AND entity_id=?'); $s->execute([$this->client['id']]);
        self::assertSame(1, (int)$s->fetchColumn());
        self::assertSame('revoked', $this->row($id)['status']);
    }

    public function testStaffNeedsBothClientEditAndInvoiceSendPermissions(): void
    {
        $id = $this->authorization();
        foreach ([[1, 0], [0, 1]] as [$edit, $send]) {
            $suffix = bin2hex(random_bytes(6));
            $this->pdo->prepare('INSERT INTO users(username,email,password_hash,role) VALUES (?,?,"synthetic-disabled-password","member")')->execute(['synthetic-permissions-' . $suffix, 'staff-permissions-' . $suffix . '@example.invalid']);
            $actor = (int)$this->pdo->lastInsertId(); $this->users[] = $actor;
            $this->pdo->prepare('UPDATE clients SET created_by=? WHERE id=?')->execute([$actor, $this->client['id']]);
            $s = $this->pdo->prepare('INSERT INTO user_permissions_overrides(user_id,organization_id,permission,allowed) VALUES (?,NULL,?,?)');
            $s->execute([$actor, 'clients.edit', $edit]); $s->execute([$actor, 'invoices.send', $send]);
            self::assertFalse(pa_autopay_staff_can_manage_client($this->pdo, $this->client['id'], $actor));
            try { pa_autopay_staff_disable_client($this->pdo, $this->client['id'], $actor, $this->config); self::fail('Partial permissions disabled AutoPay.'); }
            catch (DomainException $error) { self::assertStringContainsString('permission', $error->getMessage()); }
            self::assertSame('active', $this->row($id)['status']);
        }
    }

    public function testStaffControllerRejectsGetBadCsrfAndMissingConfirmation(): void
    {
        $id = $this->authorization();
        $suffix = bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO users(username,email,password_hash,role) VALUES (?,?,"synthetic-disabled-password","admin")')->execute(['synthetic-controller-' . $suffix, 'staff-controller-' . $suffix . '@example.invalid']);
        $actor = (int)$this->pdo->lastInsertId(); $this->users[] = $actor;
        $cases = [
            ['method' => 'GET', 'post' => [], 'status' => 405],
            ['method' => 'POST', 'post' => ['csrf' => 'wrong'], 'status' => 403],
            ['method' => 'POST', 'post' => ['confirm_disable_autopay' => ''], 'status' => 303],
            ['method' => 'POST', 'post' => [], 'status' => 303, 'changed' => true],
        ];
        foreach ($cases as $case) {
            $root = dirname(__DIR__, 2);
            $payload = base64_encode(json_encode([$case, $this->client['id'], $actor, $this->config], JSON_THROW_ON_ERROR));
            $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/src/config/app.php', true) . ';[$case,$client,$actor,$config]=json_decode(base64_decode(' . var_export($payload, true) . '),true);$appConfig=array_replace($appConfig,$config);session_id("synthetic-staff-disable-".bin2hex(random_bytes(8)));session_start();register_shutdown_function(static function(){echo "\\nSYNTHETIC_STATUS=".http_response_code();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});$_SESSION=["csrf"=>"synthetic-csrf","user"=>["id"=>$actor,"role"=>"admin"]];$_SERVER["REQUEST_METHOD"]=$case["method"];$_POST=$case["post"]+["client_id"=>$client,"csrf"=>"synthetic-csrf","confirm_disable_autopay"=>"1"];require ' . var_export($root . '/src/controllers/client/autopay_disable.php', true) . ';';
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($process); fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertStringContainsString('SYNTHETIC_STATUS=' . $case['status'], $output);
            self::assertSame(!empty($case['changed']) ? 'revoked' : 'active', $this->row($id)['status']);
        }
    }
}
