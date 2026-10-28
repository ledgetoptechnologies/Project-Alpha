<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StateChangingGetTest extends TestCase
{
    public function testStripeChargeGetStopsBeforeLoadingServicesOrMutatingInvoice(): void
    {
        $controller = dirname(__DIR__, 2) . '/src/controllers/stripe/stripe_charge.php';
        $script = '$_SERVER["REQUEST_METHOD"]="GET";'
            . 'register_shutdown_function(static function(){fwrite(STDERR,"STATUS=".http_response_code());});'
            . 'require ' . var_export($controller, true) . ';';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $body = stream_get_contents($pipes[1]);
        $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('Checkout creation requires POST.', $body);
        self::assertStringContainsString('STATUS=405', $diagnostic);

        $paymentForm = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/payments_create.php');
        self::assertStringContainsString("require __DIR__ . '/stripe/stripe_charge.php'", $paymentForm);
        self::assertStringNotContainsString('Location: /?page=stripe-charge&invoice_id=', $paymentForm);
    }
}
