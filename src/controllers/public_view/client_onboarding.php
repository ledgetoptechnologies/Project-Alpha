<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

client_onboarding_revoke_stale($pdo);

if (!rate_limit_check($pdo, 'client_onboarding_view', 30, 300, false)) {
    http_response_code(429);
    echo '<main><div class="auth-wrap"><h1>Please wait</h1><p>Too many requests were received.</p></div></main></body></html>';
    exit;
}

$error = trim((string)($_GET['error'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$submitted = !empty($_GET['submitted']);
$invitationId = (int)($_SESSION['client_onboarding_invitation_id'] ?? 0);
$invite = null;
$existingClient = [];

if ($invitationId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM client_onboarding_invitations WHERE id=? AND status="pending"');
    $stmt->execute([$invitationId]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($token !== '') {
    $invite = client_onboarding_find_invitation($pdo, $token);
    if (!$invite || ($invite['status'] ?? '') !== 'pending') {
        $invite = null;
    } else {
        session_regenerate_id(true);
        $_SESSION['client_onboarding_invitation_id'] = (int)$invite['id'];
    }
}
if ($invite && !empty($invite['client_id'])) {
    $clientStmt = $pdo->prepare('SELECT name,email,phone,address_line1,address_line2,city,state,postal_code,country,client_type FROM clients WHERE id=?');
    $clientStmt->execute([(int)$invite['client_id']]);
    $existingClient = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
$existingOrganization = [];
if ($invite && !empty($invite['target_organization_id'])) {
    $orgStmt = $pdo->prepare('SELECT name,general_email,general_phone,address_line1,address_line2,city,state,postal_code,country FROM organizations WHERE id=?');
    $orgStmt->execute([(int)$invite['target_organization_id']]);
    $existingOrganization = $orgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
$selectedClientType = (string)($existingClient['client_type'] ?? '');
if (!in_array($selectedClientType, ['business', 'consumer'], true)) {
    $selectedClientType = !empty($existingOrganization['name']) ? 'business' : 'consumer';
}
$sharedAddress = $selectedClientType === 'business' && array_filter([
    $existingOrganization['address_line1'] ?? '', $existingOrganization['address_line2'] ?? '',
    $existingOrganization['city'] ?? '', $existingOrganization['state'] ?? '',
    $existingOrganization['postal_code'] ?? '', $existingOrganization['country'] ?? '',
]) ? $existingOrganization : $existingClient;
$defaultState = (string)($sharedAddress['state'] ?? '');
if ($defaultState === '') {
    $defaultState = (string)($appConfig['primary_state'] ?? '');
}
?>
<main>
  <div class="auth-wrap" style="max-width:680px">
    <?php if ($submitted): ?>
      <h1 style="margin-top:0">Information submitted</h1>
      <p>Your information has been sent for review.</p>
    <?php elseif (!$invite): ?>
      <h1 style="margin-top:0">Invitation unavailable</h1>
      <p>This invitation is invalid, expired, or already used. Contact the business that sent it for a new link.</p>
    <?php else: ?>
      <h1 style="margin-top:0">Client information</h1>
      <?php if ($error): ?><div style="padding:10px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;margin-bottom:12px"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <style>
        .onboarding-type-switch{grid-column:1/-1;border:0;padding:0;margin:0 0 4px}.onboarding-type-switch legend{font-weight:700;margin-bottom:8px}.onboarding-type-options{display:inline-grid;grid-template-columns:1fr 1fr;gap:4px;padding:5px;border-radius:999px;background:#edf4fb}.onboarding-type-options label{position:relative;cursor:pointer}.onboarding-type-options input{position:absolute;opacity:0;pointer-events:none}.onboarding-type-options span{display:block;padding:10px 22px;border-radius:999px;font-weight:700;color:#5b6776}.onboarding-type-options input:checked+span{background:#fff;color:var(--nav-accent);box-shadow:0 2px 8px rgba(15,23,42,.12)}.onboarding-type-options input:focus-visible+span{outline:3px solid #93c5fd;outline-offset:2px}.onboarding-section{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:14px}.onboarding-section[hidden]{display:none}.onboarding-section-heading{grid-column:1/-1}.onboarding-section-heading strong{display:block;font-size:16px}.onboarding-section-heading span{display:block;margin-top:3px;color:var(--muted);font-size:13px;line-height:1.4}.onboarding-company-contact{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px;border:1px solid var(--border);border-radius:8px;background:#f8fafc}.onboarding-optional-label{display:flex;align-items:baseline;justify-content:space-between;gap:8px}.onboarding-optional-badge{font-size:11px;font-weight:600;color:var(--muted);white-space:nowrap}@media(max-width:620px){.onboarding-section,.onboarding-company-contact{grid-template-columns:1fr}.onboarding-type-options{width:100%}}
      </style>
      <form method="post" action="/?page=client-onboarding-submit" data-public-onboarding-form style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <fieldset class="onboarding-type-switch">
          <legend>Who are you onboarding?</legend>
          <div class="onboarding-type-options">
            <label><input type="radio" name="client_type" value="consumer" data-onboarding-type <?php echo $selectedClientType === 'consumer' ? 'checked' : ''; ?>><span>Individual</span></label>
            <label><input type="radio" name="client_type" value="business" data-onboarding-type <?php echo $selectedClientType === 'business' ? 'checked' : ''; ?>><span>Organization</span></label>
          </div>
        </fieldset>
        <div class="onboarding-section-heading" data-contact-details-heading>
          <strong>Your contact details</strong>
          <span>Enter the information for the person the business should contact.</span>
        </div>
        <label style="grid-column:1/-1"><span data-contact-name-label><?php echo $selectedClientType === 'business' ? 'Contact name' : 'Full name'; ?></span><input class="input" name="name" maxlength="150" required autocomplete="section-contact name" value="<?php echo htmlspecialchars((string)($existingClient['name'] ?? '')); ?>"></label>
        <label><span>Your email</span><input class="input" type="email" name="email" maxlength="255" required autocomplete="section-contact email" value="<?php echo htmlspecialchars((string)($existingClient['email'] ?? $invite['invited_email'] ?? '')); ?>"></label>
        <label><span>Your phone</span><input class="input" name="phone" maxlength="50" autocomplete="section-contact tel" value="<?php echo htmlspecialchars((string)($existingClient['phone'] ?? '')); ?>"></label>
        <div class="onboarding-section" data-organization-fields <?php echo $selectedClientType === 'business' ? '' : 'hidden'; ?>>
          <div class="onboarding-section-heading">
            <strong>Organization details</strong>
            <span>Tell us which organization this contact represents.</span>
          </div>
          <label style="grid-column:1/-1"><span>Organization name</span><input class="input" name="organization_name" maxlength="150" autocomplete="organization" value="<?php echo htmlspecialchars((string)($existingOrganization['name'] ?? '')); ?>"></label>
          <div class="onboarding-company-contact" data-company-contact-fields>
            <div class="onboarding-section-heading">
              <strong>General company contact <span class="onboarding-optional-badge">Optional</span></strong>
              <span>Use these only if the organization has a shared inbox or main phone number. They do not replace your contact details above.</span>
            </div>
            <label><span class="onboarding-optional-label"><span>General company email</span><span class="onboarding-optional-badge">Not required</span></span><input class="input" type="email" name="organization_email" maxlength="255" autocomplete="section-company email" value="<?php echo htmlspecialchars((string)($existingOrganization['general_email'] ?? '')); ?>"></label>
            <label><span class="onboarding-optional-label"><span>General company phone</span><span class="onboarding-optional-badge">Not required</span></span><input class="input" name="organization_phone" maxlength="50" autocomplete="section-company tel" value="<?php echo htmlspecialchars((string)($existingOrganization['general_phone'] ?? '')); ?>"></label>
          </div>
        </div>
        <div style="grid-column:1/-1;font-weight:700;margin-top:4px">Billing address</div>
        <label style="grid-column:1/-1"><span>Address</span><input class="input" name="address_line1" maxlength="255" autocomplete="address-line1" value="<?php echo htmlspecialchars((string)($sharedAddress['address_line1'] ?? '')); ?>"></label>
        <label style="grid-column:1/-1"><span>Apartment / Suite / PO box</span><input class="input" name="address_line2" maxlength="255" autocomplete="address-line2" value="<?php echo htmlspecialchars((string)($sharedAddress['address_line2'] ?? '')); ?>"></label>
        <label><span>City</span><input class="input" name="city" maxlength="100" autocomplete="address-level2" value="<?php echo htmlspecialchars((string)($sharedAddress['city'] ?? '')); ?>"></label>
        <label><span>State</span><input class="input" name="state" maxlength="100" autocomplete="address-level1" value="<?php echo htmlspecialchars($defaultState); ?>"></label>
        <label><span>Postal code</span><input class="input" name="postal_code" maxlength="32" autocomplete="postal-code" value="<?php echo htmlspecialchars((string)($sharedAddress['postal_code'] ?? '')); ?>"></label>
        <label><span>Country</span><input class="input" name="country" maxlength="100" autocomplete="country-name" value="<?php echo htmlspecialchars((string)($sharedAddress['country'] ?? 'US')); ?>"></label>
        <button type="submit" class="btn btn-primary" style="grid-column:1/-1">Submit for Review</button>
      </form>
      <script src="<?php echo htmlspecialchars(asset_url('/assets/js/public-client-onboarding.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endif; ?>
  </div>
</main>
</body></html>
