<?php
// src/utils/document_sender.php
// Optional per-user sender profile for quote, contract, and invoice documents.
require_once __DIR__ . '/email_identity.php';

function document_sender_defaults(array $appConfig): array
{
    $company = trim((string)($appConfig['from_company'] ?? ''));
    if ($company === '') {
        $company = (string)($appConfig['brand_name'] ?? 'Project Alpha');
    }

    return [
        'enabled' => false,
        'name' => ($appConfig['from_name'] ?? '') ?: pa_email_sender_name($appConfig, false),
        'company' => $company,
        'address_line1' => $appConfig['from_address_line1'] ?? '',
        'address_line2' => $appConfig['from_address_line2'] ?? '',
        'city' => $appConfig['from_city'] ?? '',
        'state' => $appConfig['from_state'] ?? '',
        'postal' => $appConfig['from_postal'] ?? '',
        'country' => $appConfig['from_country'] ?? '',
        'phone' => $appConfig['from_phone'] ?? '',
        'email' => $appConfig['from_email'] ?? '',
    ];
}

function document_sender_for_creator(PDO $pdo, array $appConfig, ?int $creatorId): array
{
    $defaults = document_sender_defaults($appConfig);
    if (!$creatorId) {
        return $defaults;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT document_sender_enabled, document_sender_name, document_sender_company,
                    document_sender_address_line1, document_sender_address_line2,
                    document_sender_city, document_sender_state, document_sender_postal,
                    document_sender_country, document_sender_phone, document_sender_email
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$creatorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $defaults;
    }

    if (!$row || empty($row['document_sender_enabled'])) {
        return $defaults;
    }

    $sender = $defaults;
    $map = [
        'name' => 'document_sender_name',
        'company' => 'document_sender_company',
        'address_line1' => 'document_sender_address_line1',
        'address_line2' => 'document_sender_address_line2',
        'city' => 'document_sender_city',
        'state' => 'document_sender_state',
        'postal' => 'document_sender_postal',
        'country' => 'document_sender_country',
        'phone' => 'document_sender_phone',
        'email' => 'document_sender_email',
    ];

    foreach ($map as $key => $column) {
        $value = trim((string)($row[$column] ?? ''));
        if ($value !== '') {
            $sender[$key] = $value;
        }
    }
    $sender['enabled'] = true;

    return $sender;
}

function document_sender_lines(array $sender): array
{
    $lines = [];
    $name = trim((string)($sender['name'] ?? ''));
    $company = trim((string)($sender['company'] ?? ''));
    if ($name !== '') {
        $lines[] = $name;
    }
    if ($company !== '' && strcasecmp($company, $name) !== 0) {
        $lines[] = $company;
    }

    foreach (['address_line1', 'address_line2'] as $key) {
        $value = trim((string)($sender[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $value;
        }
    }

    $cityParts = [];
    foreach (['city', 'state', 'postal'] as $key) {
        $value = trim((string)($sender[$key] ?? ''));
        if ($value !== '') {
            $cityParts[] = $value;
        }
    }
    if (!empty($cityParts)) {
        $lines[] = implode(', ', $cityParts);
    }

    $country = trim((string)($sender['country'] ?? ''));
    if ($country !== '') {
        $lines[] = $country;
    }

    return $lines;
}

/** Resolve a saved issuer snapshot; legacy rows retain their pre-migration policy. */
function document_sender_for_document(PDO $pdo, array $appConfig, array $document): array
{
    if (!empty($document['issuer_snapshot'])) {
        $snapshot = json_decode((string)$document['issuer_snapshot'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot['sender'] ?? null)) throw new RuntimeException('Invalid saved document issuer.');
        return $snapshot['sender'];
    }
    if (($document['issuer_mode'] ?? 'legacy') === 'legacy') {
        return document_sender_for_creator($pdo, $appConfig, !empty($document['created_by']) ? (int)$document['created_by'] : null);
    }
    return document_issuer_resolve($pdo, $appConfig, $document)['sender'];
}

function document_issuer_resolve(PDO $pdo, array $appConfig, array $document): array
{
    require_once __DIR__ . '/../services/DocumentIssuerPolicy.php';
    $creator = null;
    if (!empty($document['created_by'])) {
        $statement = $pdo->prepare('SELECT id,username,email,document_sender_enabled,document_sender_name,document_sender_phone,document_sender_email FROM users WHERE id=? LIMIT 1');
        $statement->execute([(int)$document['created_by']]);
        $creator = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return \App\Services\DocumentIssuerPolicy::resolve($appConfig, $creator, (string)($document['issuer_mode'] ?? 'default'));
}

/** Must execute in the same transaction as document creation/edit and revision. */
function document_issuer_capture(PDO $pdo, array $appConfig, string $type, int $id): ?array
{
    $table = ['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices','project_invoice'=>'project_invoices'][$type] ?? null;
    if (!$table) throw new InvalidArgumentException('Unsupported issuer document type.');
    $statement = $pdo->prepare("SELECT * FROM {$table} WHERE id=?" . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);
    $document = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new RuntimeException('Document not found.');
    // Older schemas remain readable while the release migration is pending.
    if (!array_key_exists('issuer_mode', $document) || $document['issuer_mode']==='legacy') return null;
    if (!$pdo->inTransaction()) throw new LogicException('Issuer capture requires a document transaction.');
    if (!empty($document['issuer_snapshot'])) return json_decode((string)$document['issuer_snapshot'], true, 32, JSON_THROW_ON_ERROR);
    if (trim((string)($appConfig['from_company'] ?? '')) === '' && trim((string)($appConfig['brand_name'] ?? '')) === '') throw new LogicException('Document issuer requires loaded company configuration.');
    $issuer = document_issuer_resolve($pdo, $appConfig, $document);
    $json = json_encode($issuer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare("UPDATE {$table} SET issuer_snapshot=? WHERE id=?")->execute([$json,$id]);
    return $issuer;
}

/** Existing controller ownership/CSRF checks remain authoritative. */
function document_issuer_apply_post(PDO $pdo, array $appConfig, string $type, int $id, array $post): void
{
    if (!array_key_exists('document_issuer_mode', $post)) return;
    require_once __DIR__ . '/../services/DocumentIssuerPolicy.php';
    $requested = (string)$post['document_issuer_mode'];
    if (!in_array($requested, ['default','company','creator','legacy'], true)) throw new DomainException('Invalid document issuer choice.');
    $table = ['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices','project_invoice'=>'project_invoices'][$type] ?? null;
    if (!$table || !$pdo->inTransaction()) throw new LogicException('Issuer changes require a document transaction.');
    $statement = $pdo->prepare("SELECT * FROM {$table} WHERE id=?" . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);$row=$statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Document not found.');
    if (!array_key_exists('issuer_mode',$row)) throw new DomainException('Document issuer settings require the database update.');
    if ($requested === (string)$row['issuer_mode']) return;
    // Issued/signed/paid documents keep their historical issuer presentation.
    if (strtolower((string)($row['status']??'')) !== 'draft' || !empty($row['last_sent_revision']) || !empty($row['finalized_at']) || !empty($row['signed_at'])) {
        throw new DomainException('The issuer can only be changed before this document is issued.');
    }
    if ($requested==='legacy') throw new DomainException('Legacy issuer settings can only be preserved.');
    \App\Services\DocumentIssuerPolicy::mode($requested, (string)($appConfig['document_issuer_mode'] ?? 'company'));
    $pdo->prepare("UPDATE {$table} SET issuer_mode=?,issuer_snapshot=NULL WHERE id=?")->execute([$requested,$id]);
}
