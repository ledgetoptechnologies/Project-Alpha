<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2).'/src/utils/stripe_financial_events.php';

final class StripeRefundOrderingDatabaseTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT project_invoice_payment_id FROM stripe_refunds LIMIT 0');
        } catch (Throwable) {
            self::markTestSkipped('Migrated MySQL required.');
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo?->inTransaction()) $this->pdo->rollBack();
    }

    public function testOlderPendingRefundCannotRestoreOrdinaryOrProjectInvoiceBalance(): void
    {
        $this->pdo->exec("INSERT INTO clients(name,email) VALUES('Synthetic refund ordering','never@example.invalid')");
        $client = (int)$this->pdo->lastInsertId();
        foreach ([false, true] as $project) {
            $suffix = bin2hex(random_bytes(8));
            $pi = 'pi_refund_order_'.$suffix;
            $this->pdo->prepare('INSERT INTO invoices(client_id,status,total,amount_paid,balance_due,finalized_at) VALUES (?,"paid",100,100,0,NOW())')->execute([$client]);
            $invoice = (int)$this->pdo->lastInsertId();
            $projectPayment = null;
            if ($project) {
                $this->pdo->prepare('INSERT INTO projects(name,client_id) VALUES ("Synthetic refund ordering",?)')->execute([$client]);
                $projectId = (int)$this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO project_invoices(project_id,primary_client_id,status,billing_period_start,billing_period_end,total,amount_paid,balance_due) VALUES (?,?,"paid",CURDATE(),CURDATE(),100,100,0)')->execute([$projectId,$client]);
                $statement = (int)$this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO project_invoice_items(project_invoice_id,invoice_id,invoice_total,amount_due_at_generation) VALUES (?,?,100,100)')->execute([$statement,$invoice]);
                $this->pdo->prepare('INSERT INTO project_invoice_payments(project_invoice_id,amount,payment_method,status,payment_date,stripe_payment_intent_id) VALUES (?,100,"stripe","succeeded",CURDATE(),?)')->execute([$statement,$pi]);
                $projectPayment = (int)$this->pdo->lastInsertId();
            }
            $this->pdo->prepare('INSERT INTO payments(client_id,invoice_id,amount,payment_method,status,payment_date,stripe_payment_intent_id,project_invoice_payment_id) VALUES (?,?,100,"stripe","succeeded",CURDATE(),?,?)')->execute([$client,$invoice,$project ? null : $pi,$projectPayment]);
            $payment = (int)$this->pdo->lastInsertId();
            $refund = ['object'=>'refund','id'=>'re_order_'.$suffix,'payment_intent'=>$pi,'amount'=>4000,'status'=>'succeeded'];
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $refund);
            $current = $refund;
            $refund['status'] = 'pending';
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $current);
            self::assertSame('40.00',$this->pdo->query('SELECT refunded_amount FROM payments WHERE id='.$payment)->fetchColumn());
            self::assertSame('40.00',$this->pdo->query('SELECT balance_due FROM invoices WHERE id='.$invoice)->fetchColumn());
            if ($project) self::assertSame('40.00',$this->pdo->query('SELECT balance_due FROM project_invoices WHERE id='.$statement)->fetchColumn());
        }
    }

    public function testActualAchChargeWrapperUsesBankRefundIdentityAndDeduplicates():void
    {
        $suffix=bin2hex(random_bytes(8));$refund=['object'=>'refund','id'=>'pyr_'.$suffix,'payment_intent'=>'pi_'.$suffix,'amount'=>100,'status'=>'succeeded'];
        $charge=['object'=>'charge','id'=>'py_'.$suffix,'refunds'=>['data'=>[$refund]]];$reads=[];
        $retrieve=static function(string $id)use($refund,&$reads):array{$reads[]=$id;return $refund;};
        stripe_record_refund_event($this->pdo,$charge,$retrieve);stripe_record_refund_event($this->pdo,$charge,$retrieve);
        stripe_record_refund($this->pdo,$charge);
        self::assertSame([$refund['id'],$refund['id']],$reads);
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM stripe_refunds WHERE stripe_refund_id=?');$s->execute([$refund['id']]);self::assertSame(1,(int)$s->fetchColumn());
        $charge['object']='refund';$this->expectException(DomainException::class);stripe_record_refund_event($this->pdo,$charge,$retrieve);
    }

    public function testOlderPendingSnapshotsDoNotUndoSettlementOrFailure(): void
    {
        foreach (['succeeded','failed','canceled'] as $terminal) {
            $refund = ['object'=>'refund','id'=>'re_order_'.bin2hex(random_bytes(8)),'payment_intent'=>'pi_order_'.bin2hex(random_bytes(8)),'amount'=>1000,'status'=>'pending'];
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $refund);
            $refund['status'] = $terminal;
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $refund);
            $current = $refund;
            $refund['status'] = 'pending';
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $current);
            $s=$this->pdo->prepare('SELECT status FROM stripe_refunds WHERE stripe_refund_id=?');
            $s->execute([$refund['id']]);
            self::assertSame($terminal,$s->fetchColumn());
        }
    }

    public function testSettledRefundCanStillBeReturnedByTheBank(): void
    {
        foreach (['requires_action','failed','canceled'] as $next) {
            $refund = ['object'=>'refund','id'=>'re_order_'.bin2hex(random_bytes(8)),'payment_intent'=>'pi_order_'.bin2hex(random_bytes(8)),'amount'=>1000,'status'=>'succeeded'];
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $refund);
            $refund['status']=$next;
            stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $refund);
            $s=$this->pdo->prepare('SELECT status FROM stripe_refunds WHERE stripe_refund_id=?');
            $s->execute([$refund['id']]);
            self::assertSame($next,$s->fetchColumn());
        }
    }

    public function testProviderFailureDoesNotApplyStaleDelivery(): void
    {
        $refund=['object'=>'refund','id'=>'re_order_'.bin2hex(random_bytes(8)),'amount'=>1000,'status'=>'succeeded'];
        stripe_record_refund_event($this->pdo,$refund,static fn(string $id): array => $refund);
        $refund['status']='pending';
        try {
            stripe_record_refund_event($this->pdo,$refund,static function(string $id): array { throw new RuntimeException('Synthetic provider unavailable'); });
            self::fail('Provider failure was acknowledged');
        } catch (RuntimeException $e) {
            self::assertSame('Synthetic provider unavailable',$e->getMessage());
        }
        $s=$this->pdo->prepare('SELECT status FROM stripe_refunds WHERE stripe_refund_id=?');
        $s->execute([$refund['id']]);
        self::assertSame('succeeded',$s->fetchColumn());
    }
}
