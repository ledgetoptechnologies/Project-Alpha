<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

require_once __DIR__ . '/../utils/address_book.php';
require_once __DIR__ . '/../utils/api_v2_directory_revision.php';
require_once __DIR__ . '/../utils/organization_schema.php';
require_once __DIR__ . '/../utils/portal_projection_hooks.php';

/**
 * The authoritative, non-upload writer for an organization profile.
 *
 * Keeping the organization row, its reusable billing address, directory
 * revision, and portal projection in the same transaction gives browser and
 * API writers one consistent mutation boundary.  Tax-document changes remain
 * in the legacy controller because file-system side effects cannot be rolled
 * back by this database transaction.
 */
final class OrganizationProfileMutationService
{
    /**
     * @param array{name:string,general_email?:?string,general_phone?:?string,notes?:?string,address?:array<string,mixed>,google_place_id?:?string,actor_id?:?int} $profile
     */
    public function mutate(PDO $pdo, int $organizationId, array $profile): void
    {
        if ($organizationId < 1 || trim((string)($profile['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('A valid organization profile is required.');
        }

        $address = is_array($profile['address'] ?? null) ? $profile['address'] : [];
        $addressColumns = \pa_ensure_organization_address_columns($pdo);
        $addressAssignments = [];
        $addressParams = [];
        foreach (\pa_organization_address_definitions() as $column => $_definition) {
            if (!isset($addressColumns[$column])) {
                continue;
            }
            $addressAssignments[] = $column . ' = ?';
            $value = trim((string)($address[$column] ?? ''));
            $addressParams[] = $value !== '' ? $value : null;
        }

        $name = trim((string)$profile['name']);
        $generalEmail = trim((string)($profile['general_email'] ?? ''));
        $generalPhone = trim((string)($profile['general_phone'] ?? ''));
        $notes = trim((string)($profile['notes'] ?? ''));
        $googlePlaceId = trim((string)($profile['google_place_id'] ?? ''));
        $actorId = (int)($profile['actor_id'] ?? 0);
        $addressSql = $addressAssignments === [] ? '' : ', ' . implode(', ', $addressAssignments);
        $projection = new PortalProjectionMutationService();

        \portal_projection_mutate(
            $pdo,
            $projection->organizationScopes($pdo, $organizationId),
            static function () use ($pdo, $organizationId, $name, $generalEmail, $generalPhone, $notes, $addressSql, $addressParams, $address, $googlePlaceId, $actorId): void {
                $stmt = $pdo->prepare('UPDATE organizations SET name = ?, general_email = ?, general_phone = ?, notes = ?' . $addressSql . ', source_version = ? WHERE id = ?');
                $stmt->execute(array_merge([$name, $generalEmail ?: null, $generalPhone ?: null, $notes ?: null], $addressParams, [\portal_projection_source_version(), $organizationId]));

                \address_book_save($pdo, [
                    'label' => 'Billing address',
                    'google_place_id' => $googlePlaceId,
                ] + $address, 'organization', $organizationId, 'billing', true, $actorId);
                \api_v2_directory_record($pdo, 'organization', $organizationId);
            },
            static fn(): array => $projection->organizationScopes($pdo, $organizationId)
        );
    }
}
