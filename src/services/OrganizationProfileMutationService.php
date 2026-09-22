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
     * @param array{name:string,general_email?:?string,general_phone?:?string,notes?:?string,address?:array<string,mixed>,google_place_id?:?string,address_label?:?string,actor_id?:?int} $profile
     */
    public function mutate(PDO $pdo, int $organizationId, array $profile, ?array $existingAddressColumns = null, bool $localDirectoryAuthority = true): void
    {
        if ($organizationId < 1 || trim((string)($profile['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('A valid organization profile is required.');
        }

        $address = is_array($profile['address'] ?? null) ? $profile['address'] : [];
        // API writers pass a preflighted schema snapshot so this authoritative
        // transaction never attempts runtime DDL (which would implicitly commit
        // on MySQL). Interactive callers retain the legacy self-healing path.
        $addressColumns = $existingAddressColumns ?? \pa_ensure_organization_address_columns($pdo);
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
        $addressLabel = trim((string)($profile['address_label'] ?? '')) ?: 'Billing address';
        $actorId = (int)($profile['actor_id'] ?? 0);
        $addressSql = $addressAssignments === [] ? '' : ', ' . implode(', ', $addressAssignments);
        $projection = new PortalProjectionMutationService();

        \portal_projection_mutate(
            $pdo,
            $projection->organizationScopes($pdo, $organizationId),
            static function () use ($pdo, $organizationId, $name, $generalEmail, $generalPhone, $notes, $addressSql, $addressParams, $address, $googlePlaceId, $addressLabel, $actorId, $localDirectoryAuthority): void {
                $stmt = $pdo->prepare('UPDATE organizations SET name = ?, general_email = ?, general_phone = ?, notes = ?' . $addressSql . ', source_version = ? WHERE id = ?');
                $stmt->execute(array_merge([$name, $generalEmail ?: null, $generalPhone ?: null, $notes ?: null], $addressParams, [\portal_projection_source_version(), $organizationId]));

                \address_book_save($pdo, [
                    'label' => $addressLabel,
                    'google_place_id' => $googlePlaceId,
                ] + $address, 'organization', $organizationId, 'billing', true, $actorId);
                \api_v2_directory_record($pdo, 'organization', $organizationId, $localDirectoryAuthority);
            },
            static fn(): array => $projection->organizationScopes($pdo, $organizationId),
            false,
            static fn() => \api_v2_directory_management_acquire_shared_gate($pdo, $localDirectoryAuthority)
        );
    }
}
