<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

require_once __DIR__ . '/../utils/address_book.php';
require_once __DIR__ . '/../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../utils/portal_projection_hooks.php';

/**
 * The authoritative, non-credential writer for a client's shared profile.
 *
 * Browser and API callers deliberately share this transaction boundary: the
 * client row, reusable billing address, directory revision, and optional
 * projection work either all commit or all roll back together.  Callers pass
 * relationship and private values explicitly; the API command passes the
 * locked existing values and therefore cannot alter them.
 */
final class ClientProfileMutationService
{
    /**
     * @param array{name:string,email?:?string,phone?:?string,organization_id?:?int,notes?:?string,address?:array<string,mixed>,google_place_id?:?string,address_label?:?string,address_id?:?int,actor_id?:?int} $profile
     */
    public function mutate(PDO $pdo, int $clientId, array $profile): void
    {
        if ($clientId < 1 || trim((string) ($profile['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('A valid client profile is required.');
        }

        $name = trim((string) $profile['name']);
        $email = trim((string) ($profile['email'] ?? ''));
        $phone = trim((string) ($profile['phone'] ?? ''));
        $organizationId = max(0, (int) ($profile['organization_id'] ?? 0));
        $notes = trim((string) ($profile['notes'] ?? ''));
        $address = is_array($profile['address'] ?? null) ? $profile['address'] : [];
        $googlePlaceId = trim((string) ($profile['google_place_id'] ?? ''));
        $addressLabel = trim((string) ($profile['address_label'] ?? '')) ?: 'Billing address';
        $addressId = max(0, (int) ($profile['address_id'] ?? 0));
        $actorId = (int) ($profile['actor_id'] ?? 0);
        $projection = new PortalProjectionMutationService();

        portal_projection_mutate(
            $pdo,
            static fn(): array => $projection->lockedClientScopes($pdo, $clientId, $organizationId > 0 ? $organizationId : null),
            static function () use ($pdo, $clientId, $name, $email, $phone, $organizationId, $notes, $address, $googlePlaceId, $addressLabel, $addressId, $actorId): void {
                $stmt = $pdo->prepare('UPDATE clients SET name=?, email=?, phone=?, organization_id=?, notes=?, address_line1=?, address_line2=?, city=?, state=?, postal_code=?, country=?, source_version=? WHERE id=?');
                $stmt->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $organizationId > 0 ? $organizationId : null,
                    $notes !== '' ? $notes : null,
                    ($value = trim((string) ($address['address_line1'] ?? ''))) !== '' ? $value : null,
                    ($value = trim((string) ($address['address_line2'] ?? ''))) !== '' ? $value : null,
                    ($value = trim((string) ($address['city'] ?? ''))) !== '' ? $value : null,
                    ($value = trim((string) ($address['state'] ?? ''))) !== '' ? $value : null,
                    ($value = trim((string) ($address['postal_code'] ?? $address['postal'] ?? ''))) !== '' ? $value : null,
                    ($value = trim((string) ($address['country'] ?? ''))) !== '' ? $value : null,
                    \portal_projection_source_version(),
                    $clientId,
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new \DomainException('Client changed while preparing profile mutation.');
                }
                \address_book_save($pdo, [
                    'label' => $addressLabel,
                    'google_place_id' => $googlePlaceId,
                ] + $address, 'client', $clientId, 'billing', true, $actorId, $addressId ?: null);
                \api_v2_directory_record($pdo, 'client', $clientId);
            },
            static fn(): array => $projection->clientScopes($pdo, $clientId),
            true
        );
    }
}
