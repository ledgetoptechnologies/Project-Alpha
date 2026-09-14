<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';
require_once __DIR__ . '/../../utils/address_book.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';
require_once __DIR__ . '/../../utils/api_v2_directory_revision.php';

$organizationId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$submissionId = (int)($_POST['submission_id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$resolution = (string)($_POST['resolution'] ?? '');
$matchClientId = max(0, (int)($_POST['match_client_id'] ?? 0));
$matchOrganizationId = max(0, (int)($_POST['match_organization_id'] ?? 0));
$organizationResolution = (string)($_POST['organization_resolution'] ?? '');
$mergeFields = array_values(array_filter(array_map('strval', (array)($_POST['merge_fields'] ?? []))));
$notes = mb_substr(trim((string)($_POST['review_notes'] ?? '')), 0, 1000);
if ($userId <= 0 || $submissionId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Invalid review request.'));
    exit;
}

try {
    $pdo->beginTransaction();
    $ownerWhere = $organizationId > 0
        ? '(i.organization_id=? OR (i.organization_id IS NULL AND i.created_by=?))'
        : 'i.organization_id IS NULL AND i.created_by=?';
    $ownerParams = $organizationId > 0 ? [$organizationId, $userId] : [$userId];
    $stmt = $pdo->prepare(
        'SELECT s.*,i.organization_id,i.target_organization_id,i.client_id,i.invited_email,c.email AS current_client_email
         FROM client_onboarding_submissions s
         JOIN client_onboarding_invitations i ON i.id=s.invitation_id
         LEFT JOIN clients c ON c.id=i.client_id
         WHERE s.id=? AND ' . $ownerWhere . ' FOR UPDATE'
    );
    $stmt->execute(array_merge([$submissionId], $ownerParams));
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$submission || ($submission['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This submission is no longer pending.');
    }

    $data = json_decode((string)$submission['proposed_data'], true);
    if (!is_array($data) || empty($data['name'])) {
        throw new RuntimeException('The submitted client data is invalid.');
    }
    $submittedData = $data;
    if ($decision === 'approve') {
        $allowedClientMatchIds = array_map(
            static fn(array $match): int => (int)$match['id'],
            client_onboarding_find_client_matches($pdo, $submittedData, $organizationId > 0 ? $organizationId : null)
        );
        $allowedOrganizationMatchIds = array_map(
            static fn(array $match): int => (int)$match['id'],
            client_onboarding_find_organization_matches($pdo, (string)($submittedData['organization_name'] ?? ''))
        );
        if (in_array($resolution, ['keep_existing', 'merge_existing'], true)
            && !in_array($matchClientId, $allowedClientMatchIds, true)) {
            throw new RuntimeException('The selected client is not an allowed match for this submission.');
        }
        if ($organizationResolution === 'match'
            && !in_array($matchOrganizationId, $allowedOrganizationMatchIds, true)) {
            throw new RuntimeException('The selected organization is not an allowed match for this submission.');
        }
    }

    $clientId = (int)($submission['client_id'] ?? 0);
    $projection = new \App\Services\PortalProjectionMutationService();
    $portalBeforeScopes = [];
    $portalClientIds = array_values(array_unique(array_filter([$clientId, $matchClientId])));
    sort($portalClientIds, SORT_NUMERIC);
    $portalTargetOrganizationIds = array_values(array_unique(array_filter([
        (int)($submission['target_organization_id'] ?? 0),
        $matchOrganizationId,
    ])));
    $portalBeforeScopes = $projection->lockedClientScopesForIds(
        $pdo,
        $portalClientIds,
        $portalTargetOrganizationIds
    );
    if ($decision === 'approve') {
        $reviewData = is_array($_POST['review_data'] ?? null) ? $_POST['review_data'] : [];
        $reviewClientType = (string)($reviewData['client_type'] ?? 'consumer');
        if (!in_array($reviewClientType, ['business', 'consumer'], true)) {
            $reviewClientType = 'consumer';
        }
        $data = [
            'name' => client_onboarding_clean_text($reviewData['name'] ?? '', 150),
            'email' => client_onboarding_normalize_email($reviewData['email'] ?? ''),
            'phone' => client_onboarding_clean_text($reviewData['phone'] ?? '', 50),
            'organization_name' => $reviewClientType === 'business'
                ? client_onboarding_clean_text($reviewData['organization_name'] ?? '', 150)
                : '',
            'organization_email' => $reviewClientType === 'business'
                ? client_onboarding_normalize_email($reviewData['organization_email'] ?? '')
                : '',
            'organization_phone' => $reviewClientType === 'business'
                ? client_onboarding_clean_text($reviewData['organization_phone'] ?? '', 50)
                : '',
            'address_line1' => client_onboarding_clean_text($reviewData['address_line1'] ?? '', 255),
            'address_line2' => client_onboarding_clean_text($reviewData['address_line2'] ?? '', 255),
            'city' => client_onboarding_clean_text($reviewData['city'] ?? '', 100),
            'state' => client_onboarding_clean_text($reviewData['state'] ?? '', 100),
            'postal_code' => client_onboarding_clean_text($reviewData['postal_code'] ?? '', 32),
            'country' => client_onboarding_clean_text($reviewData['country'] ?? 'US', 100) ?: 'US',
            'client_type' => $reviewClientType,
        ];
        if ($data['name'] === '') {
            throw new RuntimeException('Contact name is required.');
        }
        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid contact email address is required.');
        }
        if ($data['organization_email'] !== '' && !filter_var($data['organization_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid general company email address.');
        }
        if ($reviewClientType === 'business' && $data['organization_name'] === '') {
            throw new RuntimeException('Organization name is required for an organization submission.');
        }
        $emailValue = $data['email'];
        $targetOrganizationId = $reviewClientType === 'business' && !empty($submission['target_organization_id'])
            ? (int)$submission['target_organization_id']
            : null;
        $submittedOrganizationName = client_onboarding_clean_text($data['organization_name'] ?? '', 150);
        if ($reviewClientType === 'business' && $organizationResolution === 'match') {
            if ($matchOrganizationId <= 0) {
                throw new RuntimeException('Choose an organization match.');
            }
            $orgStmt = $pdo->prepare('SELECT id FROM organizations WHERE id=?');
            $orgStmt->execute([$matchOrganizationId]);
            if (!$orgStmt->fetchColumn()) {
                throw new RuntimeException('The selected organization match is unavailable.');
            }
            $targetOrganizationId = $matchOrganizationId;
        } elseif ($reviewClientType === 'business' && ($organizationResolution === 'create' || !$targetOrganizationId)) {
            $orgInsert = $pdo->prepare(
                'INSERT INTO organizations
                 (name,general_email,general_phone,address_line1,address_line2,city,state,postal_code,country,source_version)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            try {
                $orgInsert->execute([
                    $submittedOrganizationName,
                    $data['organization_email'] ?: null,
                    $data['organization_phone'] ?: null,
                    $data['address_line1'] ?: null,
                    $data['address_line2'] ?: null,
                    $data['city'] ?: null,
                    $data['state'] ?: null,
                    $data['postal_code'] ?: null,
                    $data['country'] ?: 'US',
                    portal_projection_source_version(),
                ]);
                $targetOrganizationId = (int)$pdo->lastInsertId();
            } catch (Throwable $orgError) {
                $orgLookup = $pdo->prepare('SELECT id FROM organizations WHERE name=?');
                $orgLookup->execute([$submittedOrganizationName]);
                $targetOrganizationId = (int)($orgLookup->fetchColumn() ?: 0) ?: $targetOrganizationId;
            }
        }
        if ($reviewClientType === 'business') {
            if (!$targetOrganizationId) {
                throw new RuntimeException('Choose or create an organization before approval.');
            }
            $pdo->prepare(
                'UPDATE organizations
                 SET name=?,general_email=?,general_phone=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,source_version=?
                 WHERE id=?'
            )->execute([
                $submittedOrganizationName,
                $data['organization_email'] ?: null,
                $data['organization_phone'] ?: null,
                $data['address_line1'] ?: null,
                $data['address_line2'] ?: null,
                $data['city'] ?: null,
                $data['state'] ?: null,
                $data['postal_code'] ?: null,
                $data['country'] ?: 'US',
                portal_projection_source_version(),
                $targetOrganizationId,
            ]);
        }

        $values = [
            $data['name'],
            $emailValue !== '' ? $emailValue : null,
            $data['phone'] ?: null,
            $data['address_line1'] ?: null,
            $data['address_line2'] ?: null,
            $data['city'] ?: null,
            $data['state'] ?: null,
            $data['postal_code'] ?: null,
            $data['country'] ?: 'US',
            $data['client_type'] ?? 'unknown',
        ];
        if ($resolution === '') {
            $resolution = $clientId > 0 ? 'update_invited' : 'create';
        }
        if (in_array($resolution, ['keep_existing', 'merge_existing'], true)) {
            if ($matchClientId <= 0) {
                throw new RuntimeException('Choose an existing client match before approving this way.');
            }
            $existingStmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND archived=0 FOR UPDATE');
            $existingStmt->execute([$matchClientId]);
            $existingClient = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingClient) {
                throw new RuntimeException('The selected client match is unavailable.');
            }
            $clientId = $matchClientId;
            if ($resolution === 'merge_existing') {
                $fields = ['name','email','phone','address_line1','address_line2','city','state','postal_code','country','client_type'];
                $merged = [];
                foreach ($fields as $field) {
                    $sourceData = $data;
                    if ($field === 'email') {
                        $sourceData['email'] = $emailValue;
                    }
                    $merged[$field] = client_onboarding_merge_value($sourceData, $existingClient, $field, $mergeFields);
                }
                $update = $pdo->prepare(
                    'UPDATE clients SET name=?,email=?,phone=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,client_type=?,organization_id=COALESCE(?, organization_id),source_version=? WHERE id=?'
                );
                $update->execute([
                    $merged['name'] ?: $existingClient['name'],
                    $merged['email'],
                    $merged['phone'],
                    $merged['address_line1'],
                    $merged['address_line2'],
                    $merged['city'],
                    $merged['state'],
                    $merged['postal_code'],
                    $merged['country'] ?: 'US',
                    $merged['client_type'] ?: 'unknown',
                    $targetOrganizationId,
                    portal_projection_source_version(),
                    $clientId,
                ]);
            }
            $pdo->prepare('UPDATE client_onboarding_invitations SET client_id=?,target_organization_id=COALESCE(?, target_organization_id) WHERE id=?')
                ->execute([$clientId, $targetOrganizationId, (int)$submission['invitation_id']]);
        } elseif ($clientId > 0) {
            $update = $pdo->prepare(
                'UPDATE clients SET name=?,email=?,phone=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,client_type=?,organization_id=?,source_version=? WHERE id=?'
            );
            $update->execute(array_merge($values, [$targetOrganizationId, portal_projection_source_version(), $clientId]));
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO clients
                 (name,email,phone,address_line1,address_line2,city,state,postal_code,country,client_type,organization_id,source_version,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute(array_merge($values, [
                $targetOrganizationId,
                portal_projection_source_version(),
                $userId,
            ]));
            $clientId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE client_onboarding_invitations SET client_id=?,target_organization_id=? WHERE id=?')
            ->execute([$clientId, $targetOrganizationId, (int)$submission['invitation_id']]);
        if ($reviewClientType === 'business' && $targetOrganizationId) {
            // Organization onboarding has one shared business address. Even an
            // explicitly matched client must not retain a separate private
            // address under this approval path.
            $pdo->prepare(
                'UPDATE clients
                 SET organization_id=?,client_type="business",address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,source_version=?
                 WHERE id=?'
            )->execute([
                $targetOrganizationId,
                $data['address_line1'] ?: null,
                $data['address_line2'] ?: null,
                $data['city'] ?: null,
                $data['state'] ?: null,
                $data['postal_code'] ?: null,
                $data['country'] ?: 'US',
                portal_projection_source_version(),
                $clientId,
            ]);
        }
        $sharedAddress = [
            'label' => 'Billing address',
            'address_line1' => $data['address_line1'],
            'address_line2' => $data['address_line2'],
            'city' => $data['city'],
            'state' => $data['state'],
            'postal_code' => $data['postal_code'],
            'country' => $data['country'],
            'source' => 'manual',
        ];
        address_book_save($pdo, $sharedAddress, 'client', $clientId, 'billing', true, $userId);
        if ($reviewClientType === 'business' && $targetOrganizationId) {
            address_book_save($pdo, $sharedAddress, 'organization', $targetOrganizationId, 'billing', true, $userId);
        }
        if ($reviewClientType === 'business' && $targetOrganizationId) {
            api_v2_directory_record($pdo, 'organization', $targetOrganizationId);
        }
        if ($resolution !== 'keep_existing' || ($reviewClientType === 'business' && $targetOrganizationId)) {
            api_v2_directory_record($pdo, 'client', $clientId);
        }
        $pdo->prepare('UPDATE client_onboarding_submissions SET proposed_data=? WHERE id=?')
            ->execute([json_encode($data, JSON_UNESCAPED_SLASHES), $submissionId]);
    }

    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $pdo->prepare('UPDATE client_onboarding_submissions SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=? WHERE id=?')
        ->execute([$status, $userId, $notes ?: null, $submissionId]);
    $pdo->prepare('UPDATE client_onboarding_invitations SET status=? WHERE id=?')
        ->execute([$status, (int)$submission['invitation_id']]);
    if ($decision === 'approve' && $clientId > 0) {
        $projection->afterMutation($pdo, array_merge($portalBeforeScopes, $projection->clientScopes($pdo, $clientId)));
    }
    $pdo->commit();

    audit_log($pdo, 'client_onboarding.' . $status, 'client_onboarding_submission', $submissionId, [
        'organization_id' => !empty($submission['organization_id']) ? (int)$submission['organization_id'] : null,
        'client_id' => $clientId ?: null,
        'resolution' => $resolution ?: null,
    ]);
    header('Location: /?page=client/onboarding&reviewed=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[client_onboarding] Review failed: ' . $e->getMessage());
    header('Location: /?page=client/onboarding&error=' . urlencode($e->getMessage()));
}
exit;
