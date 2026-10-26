<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/src/utils/stripe_financial_events.php';
final class StripeAchRefundIdentityTest extends TestCase
{
    private function intent(string $charge='py_SYNTHETIC'):array
    {
        return ['object'=>'payment_intent','id'=>'pi_SYNTHETIC','status'=>'succeeded','livemode'=>false,'customer'=>'cus_SYNTHETIC','amount_received'=>1500,'currency'=>'usd','latest_charge'=>['object'=>'charge','id'=>$charge,'status'=>'succeeded','payment_intent'=>'pi_SYNTHETIC','customer'=>'cus_SYNTHETIC','livemode'=>false,'amount'=>1500,'currency'=>'usd']];
    }
    public function testVerifiedAchAndCardChargesAreRefundable():void
    {
        foreach(['py_SYNTHETIC','ch_SYNTHETIC'] as $id)self::assertSame($id,stripe_refund_charge_id($this->intent($id),'pi_SYNTHETIC'));
        $partial=$this->intent('ch_SYNTHETIC');$partial['latest_charge']['amount']=2000;$partial['latest_charge']['amount_captured']=1500;
        self::assertSame('ch_SYNTHETIC',stripe_refund_charge_id($partial,'pi_SYNTHETIC'));
    }
    public function testWrongObjectPrefixOrBindingCannotBecomeRefundTarget():void
    {
        foreach(['object'=>'refund','id'=>'pm_SYNTHETIC','payment_intent'=>'pi_OTHER','customer'=>'cus_OTHER','livemode'=>true,'amount'=>1000,'currency'=>'eur','status'=>'failed'] as $key=>$value){
            $pi=$this->intent();$pi['latest_charge'][$key]=$value;
            try{stripe_refund_charge_id($pi,'pi_SYNTHETIC');self::fail('Invalid refund target accepted');}catch(DomainException){self::assertTrue(true);}
        }
        $this->expectException(DomainException::class);stripe_refund_charge_id($this->intent(),'pi_OTHER');
    }
    public function testCreatedBankRefundRequiresExactObjectAndTargetEvidence():void
    {
        $refund=['object'=>'refund','id'=>'pyr_SYNTHETIC','charge'=>'py_SYNTHETIC','payment_intent'=>'pi_SYNTHETIC','amount'=>100,'currency'=>'usd'];
        stripe_validate_created_refund($refund,'py_SYNTHETIC',100,$this->intent());self::assertTrue(true);
        foreach(['object'=>'charge','id'=>'py_SYNTHETIC','charge'=>'py_OTHER','payment_intent'=>'pi_OTHER','amount'=>200,'currency'=>'eur','livemode'=>true] as $key=>$value){$bad=$refund;$bad[$key]=$value;
            try{stripe_validate_created_refund($bad,'py_SYNTHETIC',100,$this->intent());self::fail('Unbound refund accepted');}catch(DomainException){self::assertTrue(true);}}
    }
}
