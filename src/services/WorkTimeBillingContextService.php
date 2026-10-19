<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../utils/workforce_availability.php';

use App\Modules\Timekeeping\AuditRecorder;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Synchronizes invoice context from PA's legacy billing-time projection back to
 * the canonical Workforce entry without rewriting immutable approval records.
 */
final class WorkTimeBillingContextService
{
    private readonly AuditRecorder $audit;

    public function __construct(private readonly PDO $pdo, ?AuditRecorder $audit = null)
    {
        $this->audit = $audit ?? new AuditRecorder($pdo);
    }

    /**
     * @param array<int,int> $workTypeByBillingEntry Optional billing-row to Work Type map.
     *        A Work Type is applied only when every supplied value for the same
     *        canonical entry resolves to one distinct active Work Type.
     */
    public function synchronizeInvoice(
        int $invoiceId,
        int $actorId,
        array $workTypeByBillingEntry = []
    ): int {
        if (!\pa_workforce_enabled()) return 0;
        if ($invoiceId <= 0) {
            throw new DomainException('Choose an invoice before linking tracked time.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $invoiceStmt = $this->pdo->prepare(
                'SELECT id,client_id,project_id,job_id,created_by FROM invoices WHERE id=? FOR UPDATE'
            );
            $invoiceStmt->execute([$invoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new DomainException('The invoice used for tracked time is unavailable.');
            }

            $mappingStmt = $this->pdo->prepare(
                'SELECT DISTINCT c.billing_time_entry_id,s.time_entry_id AS work_time_entry_id
                 FROM work_billing_consumptions c
                 JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id
                 JOIN time_entries te ON te.id=c.billing_time_entry_id
                 LEFT JOIN invoice_items ii ON ii.id=te.invoice_item_id
                 WHERE c.consumption_type IN (\'approved\',\'correction\')
                   AND (te.invoice_id=? OR ii.invoice_id=?)
                 ORDER BY s.time_entry_id,c.billing_time_entry_id'
            );
            $mappingStmt->execute([$invoiceId, $invoiceId]);

            /** @var array<string,array<int,int>> $billingRowsByWorkEntry */
            $billingRowsByWorkEntry = [];
            foreach ($mappingStmt->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
                $workEntryId = (string)($mapping['work_time_entry_id'] ?? '');
                $billingEntryId = (int)($mapping['billing_time_entry_id'] ?? 0);
                if ($workEntryId === '' || $billingEntryId <= 0) {
                    continue;
                }
                $billingRowsByWorkEntry[$workEntryId][] = $billingEntryId;
            }

            $changed = 0;
            foreach ($billingRowsByWorkEntry as $workEntryId => $billingEntryIds) {
                $suppliedWorkTypes = [];
                foreach (array_unique($billingEntryIds) as $billingEntryId) {
                    $candidate = (int)($workTypeByBillingEntry[$billingEntryId] ?? 0);
                    if ($candidate > 0) {
                        $suppliedWorkTypes[$candidate] = true;
                    }
                }
                $workTypeId = count($suppliedWorkTypes) === 1
                    ? (int)array_key_first($suppliedWorkTypes)
                    : null;

                $changed += $this->synchronizeWorkEntry(
                    $workEntryId,
                    $invoice,
                    $actorId,
                    $workTypeId,
                    array_values(array_unique($billingEntryIds))
                );
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $changed;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $invoice @param array<int,int> $billingEntryIds */
    private function synchronizeWorkEntry(
        string $workEntryId,
        array $invoice,
        int $actorId,
        ?int $suppliedWorkTypeId,
        array $billingEntryIds
    ): int {
        $entryStmt = $this->pdo->prepare('SELECT * FROM work_time_entries WHERE id=? FOR UPDATE');
        $entryStmt->execute([$workEntryId]);
        $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            throw new RuntimeException('The canonical Workforce time entry is unavailable.');
        }

        if ($suppliedWorkTypeId !== null) {
            $workTypeStmt = $this->pdo->prepare('SELECT 1 FROM work_types WHERE id=? AND is_active=1');
            $workTypeStmt->execute([$suppliedWorkTypeId]);
            if (!$workTypeStmt->fetchColumn()) {
                throw new DomainException('The supplied Work Type is unavailable.');
            }
        }

        $target = [
            'client_id' => $this->nullableInt($invoice['client_id'] ?? null),
            'project_id' => $this->nullableInt($invoice['project_id'] ?? null),
            'job_id' => $this->nullableInt($invoice['job_id'] ?? null),
            'invoice_id' => (int)$invoice['id'],
            'work_type_id' => $suppliedWorkTypeId ?? $this->nullableInt($entry['work_type_id'] ?? null),
        ];

        foreach (['client_id','project_id','job_id','invoice_id'] as $field) {
            $current = $this->nullableInt($entry[$field] ?? null);
            $incoming = $this->nullableInt($target[$field] ?? null);
            if ($current !== null && $incoming !== null && $current !== $incoming) {
                throw new DomainException('The tracked time has conflicting ' . str_replace('_id', '', $field) . ' context. Confirm the move before changing its billing context.');
            }
        }

        $changed = false;
        foreach ($target as $field => $value) {
            if ($this->nullableInt($entry[$field] ?? null) !== $value) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return 0;
        }

        $revisionActor = $actorId > 0
            ? $actorId
            : ((int)($invoice['created_by'] ?? 0) ?: (int)$entry['user_id']);
        if ($revisionActor <= 0) {
            throw new DomainException('An authenticated actor is required to link tracked time.');
        }

        $revision = max(1, (int)($entry['revision'] ?? 1));
        $snapshot = json_encode(
            $entry,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $revisionId = self::uuid();
        $this->pdo->prepare(
            'INSERT INTO work_time_revisions
             (id,time_entry_id,revision,snapshot,reason,created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $revisionId,
            $workEntryId,
            $revision,
            $snapshot,
            'Linked tracked time to invoice ' . (int)$invoice['id'],
            $revisionActor,
        ]);

        $update = $this->pdo->prepare(
            'UPDATE work_time_entries
             SET client_id=?,project_id=?,job_id=?,invoice_id=?,work_type_id=?,revision=revision+1
             WHERE id=? AND revision=?'
        );
        $update->execute([
            $target['client_id'],
            $target['project_id'],
            $target['job_id'],
            $target['invoice_id'],
            $target['work_type_id'],
            $workEntryId,
            $revision,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The Workforce time entry changed while it was being linked.');
        }

        // When the entry is being linked to an invoice, sync any ready billing
        // allocation to 'invoiced' so the eligibility service can find it via
        // the allocation path as well as the billing-projection path.
        if ($target['invoice_id'] !== null) {
            $billingItemStmt = $this->pdo->prepare(
                'SELECT te.invoice_item_id
                 FROM work_billing_consumptions c
                 JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id
                 JOIN time_entries te ON te.id=c.billing_time_entry_id
                 WHERE s.time_entry_id=? AND c.consumption_type IN (\'approved\',\'correction\')
                   AND te.billed=1 AND te.invoice_id=? AND te.invoice_item_id IS NOT NULL
                 ORDER BY te.id DESC LIMIT 1'
            );
            $billingItemStmt->execute([$workEntryId, $target['invoice_id']]);
            $linkedInvoiceItemId = (int)($billingItemStmt->fetchColumn() ?: 0);
            if ($linkedInvoiceItemId > 0) {
                $this->pdo->prepare(
                    "UPDATE work_time_billing_allocations
                     SET status='invoiced', invoice_id=?, invoice_item_id=?
                     WHERE time_entry_id=? AND treatment='hourly'
                       AND entry_revision=?
                       AND status IN ('ready','rate_needed')
                       AND (invoice_id IS NULL OR invoice_id=?)"
                )->execute([
                    $target['invoice_id'],
                    $linkedInvoiceItemId,
                    $workEntryId,
                    (int)$entry['revision'],
                    $target['invoice_id'],
                ]);
            }
        }

        $after = $entry;
        foreach ($target as $field => $value) {
            $after[$field] = $value;
        }
        $after['revision'] = $revision + 1;
        $auditAfter = $after;
        $auditAfter['_billing_time_entry_ids'] = $billingEntryIds;
        $this->audit->record(
            'time_entry.billing_context_linked',
            'work_time_entry',
            $workEntryId,
            $revisionActor,
            $entry,
            $auditAfter,
            $revisionId
        );

        return 1;
    }

    private function nullableInt(mixed $value): ?int
    {
        $integer = (int)($value ?? 0);
        return $integer > 0 ? $integer : null;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
