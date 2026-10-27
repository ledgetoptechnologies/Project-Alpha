<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/billing_recipients.php';
require_once __DIR__ . '/../../utils/audit.php';

$organizationId = (int)($_POST['organization_id'] ?? 0);
$actor = (int)($_SESSION['user']['id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if (!csrf_validate() || !$actor || !user_can($pdo, $actor, 'invoices.edit', $organizationId)
    || !can_access_record($pdo, 'organizations', $organizationId, $actor)) {
    http_response_code(403); echo 'Organization billing permission required.'; exit;
}
try {
    $pdo->beginTransaction();
    if (($_POST['billing_policy_mode'] ?? '') === 'legacy') {
        pa_billing_organization_reset($pdo, $organizationId);
    } elseif (($_POST['billing_policy_mode'] ?? '') === 'accounting') {
        if (!is_array($_POST['billing_recipient_ids'] ?? [])) throw new DomainException('Select saved billing contacts.');
        pa_billing_organization_save($pdo, $organizationId, $_POST['billing_recipient_ids'] ?? [], !empty($_POST['billing_copy_working_contact']));
    } else {
        throw new DomainException('Select a billing delivery policy.');
    }
    audit_log($pdo, 'organization.billing_policy.update', 'organization', $organizationId);
    $pdo->commit();
    header('Location: /?page=organization/organization-view&id=' . $organizationId . '&billing_saved=1#organization-billing');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $error instanceof DomainException || $error instanceof InvalidArgumentException
        ? $error->getMessage() : 'Billing preferences could not be saved.';
    header('Location: /?page=organization/organization-view&id=' . $organizationId . '&error=' . rawurlencode($message) . '#organization-billing');
}
exit;
