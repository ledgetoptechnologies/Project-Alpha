<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TaxImportBackendTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testImporterStreamsLargeBoundaryFilesThroughStaging(): void
    {
        $handler = file_get_contents($this->root . '/src/controllers/settings/tax-import-handler.php');
        $chunkHandler = file_get_contents($this->root . '/src/controllers/settings/tax_import_chunk.php');

        self::assertIsString($handler);
        self::assertIsString($chunkHandler);
        self::assertStringContainsString('TAX_IMPORT_BATCH_SIZE', $handler);
        self::assertStringContainsString('TAX_IMPORT_SCAN_LOG_INTERVAL', $handler);
        self::assertStringContainsString('set_time_limit(0)', $handler);
        self::assertStringContainsString('tax_boundaries_stage', $handler);
        self::assertStringContainsString('tax_import_runs', $handler);
        self::assertStringContainsString('taxImportLog', $handler);
        self::assertStringContainsString('[tax-import]', $handler);
        self::assertStringContainsString('Processed rate CSV row', $handler);
        self::assertStringContainsString('Scanned boundary CSV row', $handler);
        self::assertStringContainsString('Streamed ', $handler);
        self::assertStringContainsString("fgetcsv(\$handle, 0, ',', '\"', '\\\\')", $handler);
        self::assertStringContainsString('taxImportResolveChunkSource', $handler);
        self::assertStringContainsString('taxImportCleanupSources', $handler);
        self::assertStringContainsString('TAX_IMPORT_CHUNK_TTL_SECONDS', $handler);
        self::assertStringContainsString('taxImportChunkDir', (string)$chunkHandler);
        self::assertStringContainsString('session_hash', (string)$chunkHandler);
        self::assertStringContainsString('12 * 1024 * 1024', (string)$chunkHandler);
        self::assertStringContainsString('batch_key', $handler);
        self::assertStringNotContainsString('fgetcsv($handle)', $handler);
        self::assertStringContainsString('Upload at least one FIPS, tax rate, or boundary file.', $handler);
        self::assertStringNotContainsString('No complex zip ranges found in boundary file', $handler);
        self::assertStringContainsString('DELETE FROM tax_rates', $handler);
        self::assertStringContainsString('SELECT county_name', $handler);
        self::assertStringContainsString('rebuildCountyTaxRateMirror', $handler);
        self::assertStringContainsString('addCountyRateSanityWarnings', $handler);
        self::assertStringContainsString('$_POST[\'tax_state\']', $handler);
        self::assertStringContainsString('pa_tax_state_fips_for_hint', $handler);
        self::assertStringContainsString('$expectedStateFips', $handler);
        self::assertStringNotContainsString('UPDATE tax_rates SET rate = ?', $handler);
    }

    public function testTaxImportUiAllowsPartialSourceUploads(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/settings/taxes.php');

        self::assertIsString($view);
        self::assertStringContainsString('Upload one or more files', $view);
        self::assertStringContainsString('Reused when omitted', $view);
        self::assertStringContainsString('name="tax_county"', $view);
        self::assertStringContainsString('Search county...', $view);
        self::assertStringContainsString('No tax rates matched that county search.', $view);
        self::assertStringContainsString('name="tax_state"', $view);
        self::assertStringContainsString('data-tax-import-form', $view);
        self::assertStringContainsString('settings/tax-import-chunk', $view);
        self::assertStringContainsString('80 * 1024 * 1024', $view);
        self::assertStringContainsString('data-tax-import-file="boundary_file"', $view);
        self::assertStringContainsString('Imported State Coverage', $view);
        self::assertStringContainsString('Recent Import Runs', $view);
        self::assertStringContainsString('[tax-import]', $view);
        self::assertStringContainsString('PA imports and replaces rows only for this selected state.', $view);
        self::assertStringNotContainsString('name="fips_file" accept=".txt" required', $view);
        self::assertStringNotContainsString('name="rate_file" accept=".csv" required', $view);
    }

    public function testTaxLookupWorkflowIsWiredIntoDocumentPages(): void
    {
        $pages = [
            '/src/views/pages/quote/quotes-create.php',
            '/src/views/pages/quote/quotes-edit.php',
            '/src/views/pages/invoice/invoices-create.php',
            '/src/views/pages/invoice/invoices-edit.php',
            '/src/views/pages/contract/contracts-create.php',
            '/src/views/pages/contract/contracts-edit.php',
        ];

        foreach ($pages as $page) {
            $contents = file_get_contents($this->root . $page);
            self::assertIsString($contents);
            self::assertStringContainsString('tax_lookup_control.php', $contents, $page);
            self::assertStringContainsString('render_tax_lookup_control', $contents, $page);
            self::assertStringContainsString('tax-lookup-control.js', $contents, $page);
        }

        $router = file_get_contents($this->root . '/public/index.php');
        $acl = file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $clientSearch = file_get_contents($this->root . '/src/controllers/client/clients_search.php');
        self::assertStringContainsString('$page === \'tax-lookup\'', (string)$router);
        self::assertStringContainsString('src/controllers/tax_lookup.php', (string)$router);
        self::assertStringContainsString('settings/tax-import-chunk', (string)$router);
        self::assertStringContainsString('\'tax-lookup\'          => null', (string)$acl);
        self::assertStringContainsString('settings/tax-import-chunk', (string)$acl);
        self::assertStringContainsString('preferred_tax_zip', (string)$clientSearch);
        self::assertStringContainsString('preferred_tax_state', (string)$clientSearch);
        self::assertStringContainsString("COALESCE(NULLIF(o.postal_code, ''), NULLIF(c.postal_code, ''))", (string)$clientSearch);
        self::assertStringContainsString("COALESCE(NULLIF(o.state, ''), NULLIF(c.state, ''))", (string)$clientSearch);

        $component = file_get_contents($this->root . '/src/views/components/tax_lookup_control.php');
        $script = file_get_contents($this->root . '/public/assets/js/tax-lookup-control.js');
        $lookup = file_get_contents($this->root . '/src/controllers/tax_lookup.php');
        self::assertStringContainsString('pa-tax-lookup__input', (string)$component);
        self::assertStringContainsString('padding:10px', (string)$component);
        self::assertStringContainsString('padding-bottom:14px', (string)$component);
        self::assertStringContainsString('uniqueChoices', (string)$script);
        self::assertStringContainsString('fillZipFromSelectedClient', (string)$script);
        self::assertStringContainsString('data-tax-state-hint', (string)$script);
        self::assertStringContainsString('tax_exempt_file', (string)$script);
        self::assertStringContainsString('Automatic lookup was skipped', (string)$script);
        self::assertStringContainsString('AbortController', (string)$script);
        self::assertStringContainsString('[data-taxexempt][data-id], [data-client-id]', (string)$script);
        self::assertStringContainsString('form.addEventListener(\'change\'', (string)$script);
        self::assertStringContainsString("window.ProjectAlpha.registerPage", (string)$script);
        self::assertStringContainsString('$stateHint', (string)$lookup);
    }

    public function testTaxImportRateColumnsUseActualRateValue(): void
    {
        require_once $this->root . '/src/utils/tax_lookup.php';

        self::assertSame(0.9, pa_tax_rate_from_import_columns(['55', '00', '079', '0.009', '0.009', '0.009', '0.009', '20240101', '99991231']));
        self::assertSame(2.0, pa_tax_rate_from_import_columns(['55', '01', '53000', '0.02', '0.02', '0.02', '0.02', '20240101', '99991231']));
    }

    public function testTaxLookupDeduplicatesEquivalentChoices(): void
    {
        require_once $this->root . '/src/utils/tax_lookup.php';

        $choices = pa_tax_dedupe_choices([
            pa_tax_choice(5.5, 'Brown County, WI', 'zip'),
            pa_tax_choice(5.5, ' Brown   County, WI ', 'zip'),
            pa_tax_choice(5.5, 'Manitowoc County, WI', 'zip'),
        ]);

        self::assertCount(2, $choices);
        self::assertSame('Brown County, WI', $choices[0]['label']);
        self::assertSame('Manitowoc County, WI', $choices[1]['label']);
    }

    public function testZipBoundaryMatchingHandlesZip4RangeEndpoints(): void
    {
        require_once $this->root . '/src/utils/tax_lookup.php';

        $boundary = [
            'zip5_start' => '53200', 'zip4_start' => '5000',
            'zip5_end' => '53202', 'zip4_end' => '2500',
        ];

        self::assertTrue(pa_tax_boundary_contains_zip($boundary, '53201'));
        self::assertTrue(pa_tax_boundary_contains_zip($boundary, '53200', '5000'));
        self::assertTrue(pa_tax_boundary_contains_zip($boundary, '53202', '2500'));
        self::assertFalse(pa_tax_boundary_contains_zip($boundary, '53200', '4999'));
        self::assertFalse(pa_tax_boundary_contains_zip($boundary, '53202', '2501'));
        self::assertFalse(pa_tax_boundary_contains_zip($boundary, '53203', '0000'));
    }

    public function testBulkTaxChoiceResolutionKeepsCountyAndExtraRatesCorrect(): void
    {
        require_once $this->root . '/src/utils/tax_lookup.php';

        $choice = pa_tax_choice_from_maps(
            ['state_fips' => '55', 'county_fips' => '079', 'jurisdiction_code' => '53000'],
            ['55|079' => [
                'state_abbr' => 'WI', 'county_name' => 'Milwaukee',
                'state_rate' => '5.0', 'county_rate' => '0.9',
            ]],
            ['55|53000' => [[
                'jurisdiction_code' => '53000', 'jurisdiction_type' => 'city',
                'city_rate' => '2.0', 'special_rate' => '0', 'county_rate' => '0',
            ]]]
        );

        self::assertNotNull($choice);
        self::assertSame(7.9, $choice['rate']);
        self::assertSame('Milwaukee County, WI - City of Milwaukee', $choice['label']);
        self::assertSame('zip_complex', $choice['source']);
    }

    public function testZipLookupUsesBulkQueriesAndPerformanceIndexes(): void
    {
        $lookup = (string)file_get_contents($this->root . '/src/utils/tax_lookup.php');
        $migration = (string)file_get_contents($this->root . '/database/migrations/0048_service_catalog_tax_lookup_performance.sql');

        self::assertStringContainsString('pa_tax_choices_for_boundaries($pdo, $rows)', $lookup);
        self::assertStringContainsString('pa_tax_unique_boundaries', $lookup);
        self::assertStringContainsString('idx_tax_boundaries_zip_range', $migration);
        self::assertStringContainsString('idx_tax_jurisdiction_county_active', $migration);
        self::assertStringContainsString('idx_tax_jurisdiction_state_code', $migration);
    }

    public function testSchemaIncludesTaxImportSourceCacheTables(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0024_tax_import_source_cache.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');

        self::assertIsString($migration);
        self::assertIsString($baseline);

        foreach ([
            'fips_counties',
            'tax_jurisdictions',
            'tax_boundaries',
            'tax_boundaries_stage',
            'tax_zip_complexity',
            'tax_import_files',
        ] as $table) {
            self::assertStringContainsString($table, $migration);
            self::assertStringContainsString($table, $baseline);
        }

        self::assertStringContainsString('ADD COLUMN country', $migration);
        self::assertStringContainsString('ADD COLUMN is_active', file_get_contents($this->root . '/database/migrations/0025_tax_rates_active_column.sql'));
        self::assertStringContainsString('uq_tax_zip_complexity_state_zip', file_get_contents($this->root . '/database/migrations/0026_tax_zip_complexity_state_key.sql'));
        self::assertStringContainsString('country VARCHAR(100) NULL DEFAULT', $baseline);
        self::assertStringContainsString('is_active TINYINT(1) NOT NULL DEFAULT 1', $baseline);
        self::assertStringContainsString('uq_tax_zip_complexity_state_zip', $baseline);
        self::assertStringContainsString('tax_import_runs', $baseline);
        self::assertStringContainsString('tax_import_runs', file_get_contents($this->root . '/database/migrations/0027_tax_import_run_logging.sql'));
    }

    public function testTaxStateHelpersNormalizeNamesAndAbbreviations(): void
    {
        require_once $this->root . '/src/utils/tax_lookup.php';

        self::assertSame('55', pa_tax_state_fips_for_hint('WI'));
        self::assertSame('48', pa_tax_state_fips_for_hint('Texas'));
        self::assertSame('WI', pa_tax_state_abbr_for_fips('55'));
        self::assertNotEmpty(pa_tax_state_options());
    }

    public function testWisconsinFixtureKeepsCountyRateExceptions(): void
    {
        $fipsPath = $this->root . '/tests/tax_rates/st55_wi_cou2020.txt';
        $ratePath = $this->root . '/tests/tax_rates/WIR072026.csv';

        if (!is_readable($fipsPath) || !is_readable($ratePath)) {
            self::markTestSkipped('Wisconsin tax rate fixtures are not available.');
        }

        $countyNames = [];
        foreach (file($fipsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode('|', $line);
            if (count($parts) >= 5) {
                $countyNames[str_pad(trim($parts[2]), 3, '0', STR_PAD_LEFT)] = preg_replace('/\s+County$/i', '', trim($parts[4]));
            }
        }

        $rates = [];
        $handle = fopen($ratePath, 'rb');
        self::assertIsResource($handle);
        while (($parts = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($parts) < 9 || trim((string)$parts[0]) !== '55') {
                continue;
            }
            if (trim((string)$parts[1]) !== '00') {
                continue;
            }
            if ('20260708' < trim((string)$parts[7]) || '20260708' > trim((string)$parts[8])) {
                continue;
            }

            $countyFips = str_pad(trim((string)$parts[2]), 3, '0', STR_PAD_LEFT);
            $countyName = $countyNames[$countyFips] ?? null;
            if ($countyName === null) {
                continue;
            }
            require_once $this->root . '/src/utils/tax_lookup.php';
            $localRate = pa_tax_rate_from_import_columns($parts);
            $rates[$countyName] = round(5.0 + $localRate, 4);
        }
        fclose($handle);

        self::assertSame(5.9, $rates['Milwaukee'] ?? null);
        self::assertSame(5.0, $rates['Waukesha'] ?? null);
        self::assertSame(5.0, $rates['Winnebago'] ?? null);
        self::assertSame(5.5, $rates['Dane'] ?? null);
    }
}
