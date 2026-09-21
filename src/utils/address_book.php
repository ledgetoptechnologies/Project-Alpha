<?php

declare(strict_types=1);

function address_book_public_snapshot(array $address): array
{
    return [
        'label' => $address['label'] ?? null,
        'address_line1' => $address['address_line1'] ?? null,
        'address_line2' => $address['address_line2'] ?? null,
        'city' => $address['city'] ?? null,
        'state' => $address['state'] ?? null,
        'postal_code' => $address['postal_code'] ?? null,
        'country' => $address['country'] ?? 'US',
    ];
}

function address_book_default_for_entity(PDO $pdo, string $type, int $id, string $purpose, bool $lock = false): ?array
{
    if ($id <= 0 || !in_array($type, ['client','organization','project','job'], true)) return null;
    $sql = 'SELECT a.* FROM address_assignments x JOIN addresses a ON a.id=x.address_id WHERE x.entity_type=? AND x.entity_id=? AND x.purpose=? AND a.archived=0 ORDER BY x.is_default DESC,x.id LIMIT 1';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type, $id, $purpose]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function address_book_for_service_location(PDO $pdo, int $serviceLocationId): ?array
{
    $stmt = $pdo->prepare('SELECT a.* FROM service_locations s JOIN addresses a ON a.id=s.address_id WHERE s.id=? AND s.archived=0');
    $stmt->execute([$serviceLocationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function address_book_save(
    PDO $pdo,
    array $input,
    ?string $entityType = null,
    ?int $entityId = null,
    string $purpose = 'other',
    bool $isDefault = false,
    ?int $userId = null,
    ?int $id = null
): int
{
    $values = [
        trim((string)($input['label'] ?? '')) ?: null,
        trim((string)($input['address_line1'] ?? '')) ?: null,
        trim((string)($input['address_line2'] ?? '')) ?: null,
        trim((string)($input['city'] ?? '')) ?: null,
        trim((string)($input['state'] ?? '')) ?: null,
        trim((string)($input['postal_code'] ?? $input['postal'] ?? '')) ?: null,
        trim((string)($input['country'] ?? 'US')) ?: 'US',
        trim((string)($input['google_place_id'] ?? '')) ?: null,
        trim((string)($input['google_place_id'] ?? '')) !== '' ? 'google' : 'manual',
    ];
    if ($id && $id > 0) {
        $pdo->prepare('UPDATE addresses SET label=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,google_place_id=?,source=? WHERE id=?')
            ->execute([...$values, $id]);
        $addressId = $id;
    } else {
        $pdo->prepare('INSERT INTO addresses (label,address_line1,address_line2,city,state,postal_code,country,google_place_id,source,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$values, $userId ?: null]);
        $addressId = (int)$pdo->lastInsertId();
    }
    if ($entityType !== null && $entityId && in_array($entityType, ['client','organization','project','job'], true)
        && in_array($purpose, ['billing','mailing','service','other'], true)) {
        if ($isDefault) {
            $pdo->prepare('UPDATE address_assignments SET is_default=0 WHERE entity_type=? AND entity_id=? AND purpose=?')
                ->execute([$entityType, $entityId, $purpose]);
        }
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO address_assignments (address_id,entity_type,entity_id,purpose,is_default) VALUES (?,?,?,?,?) ON CONFLICT(entity_type,entity_id,purpose,address_id) DO UPDATE SET is_default=excluded.is_default'
            : 'INSERT INTO address_assignments (address_id,entity_type,entity_id,purpose,is_default) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_default=VALUES(is_default),updated_at=NOW()';
        $pdo->prepare($sql)->execute([$addressId, $entityType, $entityId, $purpose, $isDefault ? 1 : 0]);
    }
    return $addressId;
}
