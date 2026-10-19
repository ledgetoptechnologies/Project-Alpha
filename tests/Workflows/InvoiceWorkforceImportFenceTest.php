<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/src/utils/workforce_availability.php';
require_once dirname(__DIR__, 2) . '/src/services/WorkTimeBillingContextService.php';

final class InvoiceWorkforceImportFenceTest extends TestCase
{
    public function testStaleControllerImportsStopBeforeDatabaseBootstrap(): void
    {
        foreach (['invoices_create', 'invoices_update'] as $controller) {
            foreach (['time_entry_ids' => [[17]], 'time_entry_id' => [17], 'mileage_allocation_ids' => [[19]]] as $key => $value) {
                $result = $this->runChild('$_POST=' . var_export([$key=>$value], true) . ';require ' . var_export(dirname(__DIR__,2).'/src/controllers/invoice/'.$controller.'.php',true) . ';');
                self::assertStringContainsString('status=409', $result);
                self::assertStringContainsString('workforce_imports_paused', $result);
                self::assertStringNotContainsString('Database connection', $result);
            }
        }
    }

    public function testImportFeedsStopBeforeDatabaseAndManualHourlyInputsContinue(): void
    {
        foreach (['time-tracking/time_entries_unbilled', 'financial/mileage_unbilled'] as $route) {
            $result=$this->runChild('require '.var_export(dirname(__DIR__,2).'/src/controllers/'.$route.'.php',true).';');
            self::assertStringContainsString('status=404', $result);
            self::assertStringContainsString('temporarily unavailable', $result);
        }
        pa_workforce_require_no_invoice_imports(['billing_mode'=>'hourly', 'time_entry_ids'=>[['',0]], 'time_entry_id'=>0, 'mileage_allocation_ids'=>[]]);
        self::assertFalse(pa_workforce_enabled());
    }

    public function testBillingContextServiceDoesNoDatabaseWork(): void
    {
        $pdo = new class extends PDO {
            public function __construct() {}
            public function inTransaction(): bool { throw new LogicException('Unexpected transaction access'); }
            public function prepare(string $query, array $options=[]): PDOStatement|false { throw new LogicException('Unexpected SQL'); }
        };
        self::assertSame(0, (new App\Services\WorkTimeBillingContextService($pdo))->synchronizeInvoice(42,1));
    }

    private function runChild(string $body): string
    {
        $code='register_shutdown_function(static function(){echo " status=".http_response_code();});'.$body;
        $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
        self::assertSame(0,proc_close($process),$out);return $out;
    }
}
