<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';
require_once __DIR__ . '/../../utils/notifications.php';

if (!rate_limit_check($pdo, 'client_onboarding_submit', 5, 900, false)) {
    http_response_code(429);
    echo 'Please wait before submitting again.';
    exit;
}

$invitationId = (int)($_SESSION['client_onboarding_invitation_id'] ?? 0);
$token = trim((string)($_POST['token'] ?? ''));
$name = client_onboarding_clean_text($_POST['name'] ?? '', 150);
if (($invitationId <= 0 && $token === '') || $name === '') {
    header('Location: /?page=client-onboarding&error=' . urlencode('Name is required.'));
    exit;
}

$state = client_onboarding_clean_text($_POST['state'] ?? '', 100);
$email = client_onboarding_normalize_email($_POST['email'] ?? '');
if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=client-onboarding&error=' . urlencode('Enter your email address.'));
    exit;
}
$clientType = (string)($_POST['client_type'] ?? 'consumer');
if (!in_array($clientType, ['business', 'consumer'], true)) {
    $clientType = 'consumer';
}
$organizationName = $clientType === 'business'
    ? client_onboarding_clean_text($_POST['organization_name'] ?? '', 150)
    : '';
$organizationEmail = $clientType === 'business'
    ? client_onboarding_normalize_email($_POST['organization_email'] ?? '')
    : '';
if ($clientType === 'business' && $organizationName === '') {
    header('Location: /?page=client-onboarding&error=' . urlencode('Organization name is required.'));
    exit;
}
if ($organizationEmail !== '' && (mb_strlen($organizationEmail) > 255 || !filter_var($organizationEmail, FILTER_VALIDATE_EMAIL))) {
    header('Location: /?page=client-onboarding&error=' . urlencode('Enter a valid general company email address.'));
    exit;
}
$data = [
    'name' => $name,
    'email' => $email,
    'phone' => client_onboarding_clean_text($_POST['phone'] ?? '', 50),
    'organization_name' => $organizationName,
    'organization_email' => $organizationEmail,
    'organization_phone' => $clientType === 'business'
        ? client_onboarding_clean_text($_POST['organization_phone'] ?? '', 50)
        : '',
    'address_line1' => client_onboarding_clean_text($_POST['address_line1'] ?? '', 255),
    'address_line2' => client_onboarding_clean_text($_POST['address_line2'] ?? '', 255),
    'city' => client_onboarding_clean_text($_POST['city'] ?? '', 100),
    'state' => $state,
    'postal_code' => client_onboarding_clean_text($_POST['postal_code'] ?? '', 32),
    'country' => client_onboarding_clean_text($_POST['country'] ?? 'US', 100) ?: 'US',
    'client_type' => $clientType,
];

try {
    $pdo->beginTransaction();
    if ($token !== '') {
        $invite = client_onboarding_find_invitation($pdo, $token, true);
    } else {
        $inviteStmt = $pdo->prepare('SELECT * FROM client_onboarding_invitations WHERE id=? FOR UPDATE');
        $inviteStmt->execute([$invitationId]);
        $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$invite || ($invite['status'] ?? '') !== 'pending' || strtotime((string)$invite['expires_at']) < time()) {
        throw new RuntimeException('This onboarding session is no longer available.');
    }
    $invitationId = (int)$invite['id'];
    $pdo->prepare(
        'INSERT INTO client_onboarding_submissions (invitation_id,proposed_data,status)
         VALUES (?,? ,"pending")
         ON DUPLICATE KEY UPDATE proposed_data=VALUES(proposed_data),status="pending",reviewed_by=NULL,reviewed_at=NULL,review_notes=NULL'
    )->execute([$invitationId, json_encode($data, JSON_UNESCAPED_SLASHES)]);
    $submissionId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE client_onboarding_invitations SET status="submitted", consumed_at=NOW() WHERE id=? AND status="pending"')->execute([$invitationId]);
    $pdo->commit();

    unset($_SESSION['client_onboarding_invitation_id']);
    audit_log($pdo, 'client_onboarding.submitted', 'client_onboarding_submission', $submissionId ?: null, [
        'organization_id' => (int)$invite['organization_id'],
        'invitation_id' => $invitationId,
    ]);
    if (!empty($invite['notify_on_submit'])) {
        send_admin_notification(
            $pdo,
            $appConfig,
            'New client onboarding submission',
            '<p>A client onboarding form was submitted by <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars(client_onboarding_base_url($appConfig) . '/?page=client/onboarding', ENT_QUOTES, 'UTF-8') . '">Review onboarding submissions</a></p>'
        );
    }
    header('Location: /?page=client-onboarding&submitted=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[client_onboarding] Submission failed: ' . $e->getMessage());
    header('Location: /?page=client-onboarding&error=' . urlencode($e->getMessage()));
}
exit;
