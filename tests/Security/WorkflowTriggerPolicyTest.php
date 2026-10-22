<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorkflowTriggerPolicyTest extends TestCase
{
    private const SCAN_GUARD = "    if: github.ref == 'refs/heads/main' || github.event_name == 'pull_request'";

    /** Accept only the repository's explicit trigger syntax, never inline bypasses. */
    private function validTriggers(string $source, bool $allowSchedule = false): bool
    {
        if (preg_match_all('/^on:/m', $source) !== 1
            || !preg_match('/^on:\n((?:[ \t].*\n|\n)*)/m', $source, $match)) {
            return false;
        }
        $event = null;
        $seen = [];
        $main = false;
        $cron = false;
        foreach (explode("\n", rtrim($match[1])) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^  \S/', $line)) {
                if (!preg_match('/^  (push|pull_request|schedule):$/', $line, $eventMatch)
                    || isset($seen[$eventMatch[1]])) {
                    return false;
                }
                $event = $eventMatch[1];
                $seen[$event] = true;
                if ($event === 'schedule' && !$allowSchedule) {
                    return false;
                }
            } elseif ($event === 'push' && $line === '    branches: [main]' && !$main) {
                $main = true;
            } elseif ($event === 'schedule' && $line === "    - cron: '0 4 * * 1'" && !$cron) {
                $cron = true;
            } else {
                return false;
            }
        }
        return isset($seen['push']) && $main
            && (!isset($seen['schedule']) || ($cron && str_contains($source, self::SCAN_GUARD . "\n")));
    }

    public function testEveryActiveWorkflowLimitsPushesToMainAndRetainsPrChecks(): void
    {
        $directory = dirname(__DIR__, 2) . '/.github/workflows';
        $files = array_merge(glob($directory . '/*.yml'), glob($directory . '/*.yaml'));
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $name = basename($file);
            $source = str_replace("\r\n", "\n", (string)file_get_contents($file));
            self::assertTrue($this->validTriggers($source, $name === 'codeql.yml'), $name);
            if ($name === 'docker-publish.yml') {
                self::assertStringNotContainsString("  pull_request:\n", $source);
                self::assertStringContainsString(
                    "    if: github.event_name == 'push' && github.ref == 'refs/heads/main'\n", $source
                );
            } else {
                self::assertStringContainsString("  pull_request:\n", $source, $name);
            }
            if ($name === 'codeql.yml') {
                self::assertStringContainsString("  schedule:\n    - cron: '0 4 * * 1'", $source);
                self::assertStringContainsString("  analyze:\n" . self::SCAN_GUARD, $source);
            }
        }
    }

    public function testMixedInlineEventsAndUnrestrictedBranchTriggersAreRejected(): void
    {
        foreach ([
            "  push:\n",
            "  push:\n    branches: [dev]\n",
            "  push:\n    branches: [main, dev]\n",
            "  push:\n    branches: [main]\n    tags: ['v*']\n",
            "  push:\n    branches: [main]\n  workflow_dispatch:\n",
            "  pull_request:\n  push: {branches: [dev]}\n",
            "  push:\n    branches: [main]\n  workflow_dispatch: {}\n",
            "  push:\n    branches: [main]\n  repository_dispatch: {}\n",
            "  push:\n    branches: [main]\n  workflow_call: {}\n",
            "  push:\n    branches: [main]\n  schedule: [{cron: '0 4 * * 1'}]\n",
        ] as $triggers) {
            self::assertFalse($this->validTriggers("on:\n" . $triggers . "\njobs:\n", true), $triggers);
        }
        self::assertFalse($this->validTriggers("on: [push, pull_request]\njobs:\n"));
        self::assertFalse($this->validTriggers("on:\n  push:\n    branches: [main]\n  schedule:\n    - cron: '0 4 * * 1'\njobs:\n", true));
    }
}
