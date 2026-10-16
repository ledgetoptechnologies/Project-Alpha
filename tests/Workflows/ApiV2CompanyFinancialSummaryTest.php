<?php
declare(strict_types=1);
namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class ApiV2CompanyFinancialSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__,2).'/src/utils/api_v2_financial_summary.php';
    }

    public function testExactDecimalNormalizationNeverUsesFloatingPoint(): void
    {
        foreach(['000'=>'0.00','-0.00'=>'0.00','1.2'=>'1.20','9007199254740993.01'=>'9007199254740993.01','-100.05'=>'-100.05'] as $input=>$expected){
            self::assertSame($expected,\api_v2_financial_summary_decimal($input));
        }
        foreach(['1e2','NaN','1.234',' 1.00','+1.00'] as $input){
            try{\api_v2_financial_summary_decimal($input);self::fail('Invalid decimal accepted');}catch(\RuntimeException){self::assertTrue(true);}
        }
    }

    public function testPeriodCalendarAndMaximumYearBoundary(): void
    {
        self::assertSame(['from'=>'2024-02-29','to'=>'2025-02-28','dateBasis'=>'stored-calendar-date'],\api_v2_financial_summary_period('from=2024-02-29&to=2025-02-28'));
        foreach(['from=2023-02-29&to=2023-03-01','from=2024-01-01&to=2025-01-01','from=0000-01-01&to=0000-01-02','from=2026-01-01','from=2026-01-01%00&to=2026-01-02','from=2026-01-01&to=2026-01-02&%66rom=2026-01-01','from=2026-01-01&to=2026-01-02&selectorType=client'] as $query){
            try{\api_v2_financial_summary_period($query);self::fail('Invalid date/query accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}
        }
    }
}
