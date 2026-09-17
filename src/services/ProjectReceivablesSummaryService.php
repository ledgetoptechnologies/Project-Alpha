<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Produces the single collectible-receivables definition used by close-out and UI. */
final class ProjectReceivablesSummaryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{has_outstanding:bool,total_minor:int,sources:array{direct_invoices:array{count:int,amount_minor:int},pending_project_charges:array{count:int,amount_minor:int},project_invoices:array{count:int,amount_minor:int}}}
     */
    public function summarize(int $projectId): array
    {
        return $this->summarizeFor($projectId);
    }

    /** @return array{has_outstanding:bool,total_minor:int,sources:array{direct_invoices:array{count:int,amount_minor:int},pending_project_charges:array{count:int,amount_minor:int},project_invoices:array{count:int,amount_minor:int}}} */
    public function summarizeAll(): array
    {
        return $this->summarizeFor(null);
    }

    /**
     * Batch summary for list views. It always performs at most three balance
     * queries and returns a zero summary for every requested Project id.
     *
     * @param list<int> $projectIds
     * @return array<int,array{has_outstanding:bool,total_minor:int,sources:array{direct_invoices:array{count:int,amount_minor:int},pending_project_charges:array{count:int,amount_minor:int},project_invoices:array{count:int,amount_minor:int}}}>
     */
    public function summarizeProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $id): bool => $id > 0)));
        sort($projectIds, SORT_NUMERIC);
        $summaries = [];
        foreach ($projectIds as $projectId) {
            $summaries[$projectId] = self::emptySummary();
        }
        if ($projectIds === []) {
            return $summaries;
        }
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $sources = [
            'direct_invoices' => "SELECT project_id,balance_due FROM invoices
                WHERE project_id IN ({$placeholders}) AND collection_mode='direct' AND finalized_at IS NOT NULL
                  AND status IN ('sent','unpaid','partial','overdue') AND balance_due>0.005",
            'pending_project_charges' => "SELECT i.project_id,i.balance_due FROM invoices i
                WHERE i.project_id IN ({$placeholders}) AND i.collection_mode='project_aggregate'
                  AND i.finalized_at IS NOT NULL AND i.status IN ('sent','unpaid','partial','overdue')
                  AND i.balance_due>0.005
                  AND NOT EXISTS (SELECT 1 FROM project_invoice_items pii WHERE pii.invoice_id=i.id)",
            'project_invoices' => "SELECT project_id,balance_due FROM project_invoices
                WHERE project_id IN ({$placeholders}) AND finalized_at IS NOT NULL
                  AND status IN ('sent','unpaid','partial','overdue') AND balance_due>0.005",
        ];
        foreach ($sources as $source => $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($projectIds);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $projectId = (int)$row['project_id'];
                if (!isset($summaries[$projectId])) {
                    continue;
                }
                $minor = self::minorUnits((string)$row['balance_due']);
                if ($minor <= 0) {
                    continue;
                }
                $summaries[$projectId]['sources'][$source]['count']++;
                $summaries[$projectId]['sources'][$source]['amount_minor'] += $minor;
                $summaries[$projectId]['total_minor'] += $minor;
                $summaries[$projectId]['has_outstanding'] = true;
            }
        }
        return $summaries;
    }

    /**
     * @return array{has_outstanding:bool,total_minor:int,sources:array{direct_invoices:array{count:int,amount_minor:int},pending_project_charges:array{count:int,amount_minor:int},project_invoices:array{count:int,amount_minor:int}}}
     */
    private function summarizeFor(?int $projectId): array
    {
        $projectPredicate = $projectId === null ? '1=1' : 'project_id=?';
        $parameters = $projectId === null ? [] : [$projectId];
        $direct = $this->collect(
            "SELECT balance_due FROM invoices
             WHERE {$projectPredicate} AND collection_mode='direct' AND finalized_at IS NOT NULL
               AND status IN ('sent','unpaid','partial','overdue') AND balance_due>0.005"
        , $parameters);
        $pendingAggregate = $this->collect(
            "SELECT i.balance_due FROM invoices i
             WHERE " . ($projectId === null ? '1=1' : 'i.project_id=?') . "
               AND i.collection_mode='project_aggregate' AND i.finalized_at IS NOT NULL
               AND i.status IN ('sent','unpaid','partial','overdue') AND i.balance_due>0.005
               AND NOT EXISTS (SELECT 1 FROM project_invoice_items pii WHERE pii.invoice_id=i.id)"
        , $parameters);
        $aggregate = $this->collect(
            "SELECT balance_due FROM project_invoices
             WHERE {$projectPredicate} AND finalized_at IS NOT NULL
               AND status IN ('sent','unpaid','partial','overdue') AND balance_due>0.005"
        , $parameters);
        $totalMinor = $direct['amount_minor'] + $pendingAggregate['amount_minor'] + $aggregate['amount_minor'];
        return [
            'has_outstanding' => $totalMinor > 0,
            'total_minor' => $totalMinor,
            'sources' => [
                'direct_invoices' => $direct,
                'pending_project_charges' => $pendingAggregate,
                'project_invoices' => $aggregate,
            ],
        ];
    }

    /** @return array{count:int,amount_minor:int} */
    private function collect(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $count = 0;
        $amountMinor = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $balance) {
            $minor = self::minorUnits((string)$balance);
            if ($minor <= 0) {
                continue;
            }
            $count++;
            $amountMinor += $minor;
        }
        return ['count' => $count, 'amount_minor' => $amountMinor];
    }

    private static function minorUnits(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('Receivable balance is not an exact two-decimal amount.');
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        return ((int)$matches[1] * 100) + (int)$fraction;
    }

    /** @return array{has_outstanding:bool,total_minor:int,sources:array{direct_invoices:array{count:int,amount_minor:int},pending_project_charges:array{count:int,amount_minor:int},project_invoices:array{count:int,amount_minor:int}}} */
    private static function emptySummary(): array
    {
        return [
            'has_outstanding' => false,
            'total_minor' => 0,
            'sources' => [
                'direct_invoices' => ['count' => 0, 'amount_minor' => 0],
                'pending_project_charges' => ['count' => 0, 'amount_minor' => 0],
                'project_invoices' => ['count' => 0, 'amount_minor' => 0],
            ],
        ];
    }
}
