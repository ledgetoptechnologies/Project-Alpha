<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils/address_book.php';
require_once __DIR__ . '/../utils/document_organization.php';

final class DocumentRevisionService
{
    private const TABLES = ['quote' => 'quotes', 'contract' => 'contracts', 'invoice' => 'invoices'];
    private const ITEM_TABLES = ['quote' => 'quote_items', 'contract' => 'contract_items', 'invoice' => 'invoice_items'];
    private const ITEM_KEYS = ['quote' => 'quote_id', 'contract' => 'contract_id', 'invoice' => 'invoice_id'];

    public static function snapshotAndSave(PDO $pdo, string $type, int $id, ?int $userId, bool $increment = true): int
    {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) {
            throw new InvalidArgumentException('Invalid document type.');
        }
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rowStmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=?" . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $rowStmt->execute([$id]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Document not found.');
        }
        $revision = max(1, (int)($row['revision_number'] ?? 1));
        if ($increment) {
            $revision++;
            $pdo->prepare("UPDATE {$table} SET revision_number=?,revision_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$revision, $id]);
            $row['revision_number'] = $revision;
            $row['revision_updated_at'] = date('Y-m-d H:i:s');
        }
        $itemStmt = $pdo->prepare('SELECT * FROM ' . self::ITEM_TABLES[$type] . ' WHERE ' . self::ITEM_KEYS[$type] . '=? ORDER BY id');
        $itemStmt->execute([$id]);
        $snapshot = ['document' => $row, 'items' => $itemStmt->fetchAll(PDO::FETCH_ASSOC)];
        if ($type === 'invoice') {
            $adjustments = $pdo->prepare('SELECT * FROM invoice_adjustments WHERE invoice_id=? AND superseded_at IS NULL ORDER BY id');
            $adjustments->execute([$id]);
            $snapshot['adjustments'] = $adjustments->fetchAll(PDO::FETCH_ASSOC);
        }
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $revisionSql='INSERT INTO document_revisions (document_type,document_id,revision_number,snapshot,content_hash,created_by) VALUES (?,?,?,?,?,?)';
        $revisionSql.=$driver==='sqlite'
            ? ' ON CONFLICT(document_type,document_id,revision_number) DO UPDATE SET snapshot=excluded.snapshot,content_hash=excluded.content_hash,created_by=excluded.created_by'
            : ' ON DUPLICATE KEY UPDATE snapshot=VALUES(snapshot),content_hash=VALUES(content_hash),created_by=VALUES(created_by)';
        $pdo->prepare($revisionSql)
            ->execute([$type, $id, $revision, $json, hash('sha256', $json), $userId ?: null]);
        self::snapshotAddresses($pdo, $type, $id, $revision, $row);
        return $revision;
    }

    public static function markDelivered(PDO $pdo, string $type, int $id, ?string $recipient, ?int $emailDeliveryId = null): int
    {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new InvalidArgumentException('Invalid document type.');
        $stmt = $pdo->prepare("SELECT revision_number,status FROM {$table} WHERE id=? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Document not found.');
        $revision = max(1, (int)$row['revision_number']);
        $pdo->prepare("UPDATE {$table} SET last_sent_revision=? WHERE id=?")->execute([$revision, $id]);
        $pdo->prepare('INSERT INTO document_deliveries (document_type,document_id,revision_number,email_delivery_id,recipient,delivered_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)')
            ->execute([$type, $id, $revision, $emailDeliveryId, $recipient]);
        if (in_array($type, ['quote','contract'], true) && strtolower((string)$row['status']) === 'draft') {
            $pdo->prepare("UPDATE {$table} SET status='pending' WHERE id=?")->execute([$id]);
        }
        return $revision;
    }

    public static function requiresResend(array $document): bool
    {
        $lastSent = isset($document['last_sent_revision']) ? (int)$document['last_sent_revision'] : 0;
        return $lastSent > 0 && (int)($document['revision_number'] ?? 1) > $lastSent;
    }

    private static function snapshotAddresses(PDO $pdo, string $type, int $id, int $revision, array $row): void
    {
        $organizationId = (int)(pa_document_effective_organization_id($pdo, $type, $id) ?? 0);
        $billing = $organizationId > 0
            ? address_book_default_for_entity($pdo, 'organization', $organizationId, 'billing')
            : null;
        if (!$billing) {
            $billing = address_book_default_for_entity($pdo, 'client', (int)($row['client_id'] ?? 0), 'billing');
        }
        $serviceId = (int)($row['service_location_id'] ?? 0);
        $service = $serviceId > 0 ? address_book_for_service_location($pdo, $serviceId) : null;
        foreach (['billing' => $billing, 'service' => $service] as $purpose => $address) {
            if (!$address) continue;
            $json = json_encode(address_book_public_snapshot($address), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $pdo->prepare('INSERT INTO document_address_snapshots (document_type,document_id,revision_number,purpose,address_id,snapshot) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE address_id=VALUES(address_id),snapshot=VALUES(snapshot)')
                ->execute([$type, $id, $revision, $purpose, (int)$address['id'], $json]);
        }
    }
}
