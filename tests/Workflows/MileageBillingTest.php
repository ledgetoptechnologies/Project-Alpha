<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/src/utils/mileage.php';

use PHPUnit\Framework\TestCase;

final class MileageBillingTest extends TestCase
{
    public function testSimpleRoundTripLogsBothDirectionsButBillsOutboundAfterAllowance(): void
    {
        self::assertSame(60.0,mileage_logged_miles('simple',30,true));
        $charge=mileage_calculate_charge('origin_distance',30,20,false,1.0);
        self::assertSame(10.0,$charge['billable_miles']);
        self::assertSame(10.0,$charge['client_charge']);
    }

    public function testReturnPricingAppliesAllowanceBeforeDirectionMultiplier(): void
    {
        $charge=mileage_calculate_charge('origin_distance',30,20,true,1.0);
        self::assertSame(20.0,$charge['billable_miles']);
        self::assertSame(20.0,$charge['client_charge']);
    }

    public function testTotalTripUsesOnePhysicalDistanceAndReviewedPricingDistance(): void
    {
        self::assertSame(135.0,mileage_logged_miles('total_trip',135,true));
        $charge=mileage_calculate_charge('actual_trip',75,20,false,1.0,0,true);
        self::assertSame(55.0,$charge['billable_miles']);
    }

    public function testTwoClientsArePricedIndependentlyWithoutDuplicatingTripMiles(): void
    {
        $physicalMiles=mileage_logged_miles('total_trip',128.5,false);
        $clientA=mileage_calculate_charge('origin_distance',60,20,false,1.0);
        $clientB=mileage_calculate_charge('origin_distance',60,20,false,1.0);
        self::assertSame(128.5,$physicalMiles);
        self::assertSame(40.0,$clientA['client_charge']);
        self::assertSame(40.0,$clientB['client_charge']);
        self::assertSame(80.0,$clientA['client_charge']+$clientB['client_charge']);
    }

    public function testFixedTravelFeeHasNoArtificialMileage(): void
    {
        $charge=mileage_calculate_charge('fixed_fee',100,20,true,1.0,75);
        self::assertSame(0.0,$charge['billable_miles']);
        self::assertSame(75.0,$charge['client_charge']);
    }

    public function testVariableDocumentTravelLineDoesNotCreateAnEstimateTotal(): void
    {
        $item=mileage_document_travel_item(['charge_method'=>'origin_distance','mileage_rate'=>1,'included_miles'=>20,'charge_return'=>0,'fixed_amount'=>0,'estimated_one_way_miles'=>null,'terms_text'=>null]);
        self::assertNotNull($item);
        self::assertSame('variable',$item['pricing_status']);
        self::assertSame(0.0,$item['line_total']);
    }

    public function testGpsDistanceUsesMiles(): void
    {
        $miles=mileage_haversine_miles(43.0389,-87.9065,43.0731,-89.4012);
        self::assertGreaterThan(70,$miles);
        self::assertLessThan(80,$miles);
    }

    public function testNonbillableRoundTripHasNoPricingAllocation(): void
    {
        self::assertSame(60.0,mileage_logged_miles('simple',30,true));
        self::assertSame([],mileage_parse_allocations([], 'simple', 60));
    }

    public function testTotalTripCanCarryIndependentClientAllocations(): void
    {
        $rows=mileage_parse_allocations([
            'allocation_client_id'=>[0=>101,1=>202],
            'allocation_charge_method'=>[0=>'origin_distance',1=>'origin_distance'],
            'allocation_pricing_distance'=>[0=>60,1=>60],
            'allocation_included_miles'=>[0=>20,1=>20],
            'allocation_mileage_rate'=>[0=>1,1=>1],
            'allocation_charge_return'=>[],
        ],'total_trip',130);
        self::assertCount(2,$rows);
        self::assertSame(40.0,$rows[0]['client_charge']);
        self::assertSame(40.0,$rows[1]['client_charge']);
    }

    public function testEstimatedTravelIsClearlyExcludedFromAutomaticActualBilling(): void
    {
        $item=mileage_document_travel_item(['charge_method'=>'origin_distance','mileage_rate'=>1,'included_miles'=>20,'charge_return'=>0,'fixed_amount'=>0,'estimated_one_way_miles'=>60,'terms_text'=>null]);
        self::assertSame('estimate',$item['pricing_status']);
        self::assertSame(40.0,$item['line_total']);
        self::assertStringContainsString('Estimate only',$item['description']);
    }

    public function testFutureRoutingProviderHasAnExplicitAdapterBoundary(): void
    {
        self::assertNull((new NullMileageRoutingAdapter())->oneWayDistance([],[]));
        $actor=new SessionMileageActorAdapter(7,3);
        self::assertSame(7,$actor->userId());
        self::assertSame(3,$actor->organizationId());
    }

    public function testGpsAndAjaxFoundationsAreWired(): void
    {
        $root=dirname(__DIR__,2);
        $migration=file_get_contents($root.'/database/migrations/0042_mileage_allocations_and_tracking.sql');
        $api=file_get_contents($root.'/src/controllers/financial/mileage_tracking_api.php');
        $editor=file_get_contents($root.'/public/assets/js/mileage-editor.js');
        $purge=file_get_contents($root.'/src/cron/purge_mileage_tracking_points.php');
        self::assertStringContainsString('UNIQUE KEY uq_tracking_point_sequence',$migration);
        self::assertStringContainsString("status ENUM('active','draft_review','finalized','discarded')",$migration);
        self::assertStringContainsString('INSERT IGNORE INTO mileage_tracking_points',$api);
        self::assertStringContainsString("registerPage('financial/mileage-create'",$editor);
        self::assertStringContainsString('INTERVAL 90 DAY',$purge);
    }

    public function testOnlyStatusCanUseGetOnTrackingApi(): void
    {
        self::assertTrue(mileage_tracking_method_allowed('GET', 'status'));
        foreach (['start', 'points', 'stop', 'discard'] as $action) {
            self::assertFalse(mileage_tracking_method_allowed('GET', $action));
            self::assertTrue(mileage_tracking_method_allowed('POST', $action));
        }
        self::assertFalse(mileage_tracking_method_allowed('PUT', 'status'));
        $api = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/financial/mileage_tracking_api.php');
        self::assertStringContainsString('mileage_tracking_method_allowed($method,$action)', (string)$api);
        self::assertStringContainsString("if(\$method==='POST')", (string)$api);
    }

    public function testMileageScreensDoNotUseDeductionOrReimbursementLabels(): void
    {
        $root=dirname(__DIR__,2);
        foreach(['src/views/pages/financial/mileage-create.php','src/views/pages/financial/mileage-list.php','src/views/pages/financial/_overview_tab.php'] as $file){
            $source=strtolower((string)file_get_contents($root.'/'.$file));
            self::assertStringNotContainsString('deduct',$source);
            self::assertStringNotContainsString('reimburse',$source);
        }
    }

    public function testMileageHubUsesCanonicalWorkerNamesAndStaysInsideTheHub(): void
    {
        $root=dirname(__DIR__,2);
        $source=(string)file_get_contents($root.'/src/views/pages/financial/mileage-list.php');
        self::assertStringContainsString('LEFT JOIN worker_profiles wp', $source);
        self::assertStringNotContainsString('LEFT JOIN employee_profiles ep', $source);
        self::assertStringContainsString('GROUP BY a.mileage_log_id', $source);
        self::assertStringNotContainsString("GROUP BY m.id ORDER BY", $source);
        self::assertStringContainsString('name="page" value="financial/expenses-list"', $source);
        self::assertStringContainsString('name="tab" value="mileage"', $source);
    }
}
