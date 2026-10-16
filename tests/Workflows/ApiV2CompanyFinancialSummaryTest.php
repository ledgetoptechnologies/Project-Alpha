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

    public function testReportingCurrencyRequiresAdminSettingsPermissionAndCsrf(): void
    {
        self::assertNull(\financial_reporting_currency_change('',true,true,'owner',true));
        self::assertSame('USD',\financial_reporting_currency_change('USD',true,true,'admin',true));
        foreach([[false,true,'admin',true],[true,false,'admin',true],[true,true,'employee',true],[true,true,'admin',false]] as [$system,$permission,$role,$csrf]){
            try{\financial_reporting_currency_change('USD',$system,$permission,$role,$csrf);self::fail('Unauthorized setting accepted');}catch(\RuntimeException){self::assertTrue(true);}
        }
        foreach(['usd',' USD','US','USDD',[],"USD\0"] as $value){
            try{\financial_reporting_currency_change($value,true,true,'admin',true);self::fail('Invalid currency accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}
        }
        $root=dirname(__DIR__,2);$handler=file_get_contents($root.'/src/controllers/settings_handler.php');
        self::assertStringContainsString("'settings.manage'",$handler);self::assertStringContainsString('csrf_validate()',$handler);
        self::assertStringContainsString("financial_reporting_currency_change(\$_POST['financial_base_currency'], \$isSystemTab",$handler);
        $front=file_get_contents($root.'/public/index.php');self::assertStringContainsString('csrf_verify_post_or_redirect($page)',$front);
        require_once $root.'/src/utils/csrf.php';$oldPost=$_POST;$oldSession=$_SESSION??[];
        try{
            $_SESSION['csrf']='known-session-token';$_POST['csrf']='wrong-token';
            self::assertFalse(\csrf_validate());
            try{\financial_reporting_currency_change('USD',true,true,'admin',\csrf_validate());self::fail('Bad CSRF accepted');}catch(\RuntimeException){self::assertTrue(true);}
            $_POST['csrf']='known-session-token';self::assertSame('USD',\financial_reporting_currency_change('USD',true,true,'admin',\csrf_validate()));
        }finally{$_POST=$oldPost;$_SESSION=$oldSession;}
    }
}
