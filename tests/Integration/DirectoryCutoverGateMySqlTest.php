<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Pending release gate: a reusable two-connection MySQL fixture is not available. */
final class DirectoryCutoverGateMySqlTest extends TestCase
{
    public function testActivationWaitsForSharedSourceWriterAndThenDeniesTheNextLocalWriter(): void
    {
        self::markTestSkipped('Pending release gate: add a disposable two-connection MySQL fixture before treating the cutover race as acceptance-tested.');
    }
}
