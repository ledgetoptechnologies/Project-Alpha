<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/** MySQL acceptance harness; see tools/README.md for the two-connection assertions. */
final class DirectoryCutoverGateMySqlTest extends TestCase
{
    public function testActivationWaitsForSharedSourceWriterAndThenDeniesTheNextLocalWriter(): void
    {
        if (getenv('DIRECTORY_CUTOVER_GATE_MYSQL_ALLOW_DESTRUCTIVE') !== 'isolated-disposable-only') {
            self::markTestSkipped('Set DIRECTORY_CUTOVER_GATE_MYSQL_ALLOW_DESTRUCTIVE=isolated-disposable-only on the disposable MySQL harness.');
        }

        self::markTestIncomplete('No reusable two-connection MySQL fixture exists. The documented harness must prove: activation blocks behind FOR SHARE; that writer commits before activation; the next local source writer is denied; and an API-authority writer remains allowed.');
    }
}
