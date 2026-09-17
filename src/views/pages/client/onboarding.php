<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/client_onboarding.php';
require_once __DIR__ . '/../../../utils/api_v2_directory_management.php';
$directoryManagementStatus=api_v2_directory_management_status($pdo);

client_onboarding_revoke_stale($pdo);

$organizationId = request_client_org_id();
$clients = $pdo->query('SELECT id,name,email FROM clients WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$organizations = $pdo->query('SELECT id,name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$userId = (int)($_SESSION['user']['id'] ?? 0);
$invitationWhere = $organizationId > 0
    ? '(i.organization_id=? OR (i.organization_id IS NULL AND i.created_by=?))'
    : 'i.organization_id IS NULL AND i.created_by=?';
$invitationParams = $organizationId > 0 ? [$organizationId, $userId] : [$userId];
$stmt = $pdo->prepare(
    'SELECT i.*,c.name AS client_name,o.name AS target_organization_name,
            s.id AS submission_id,s.proposed_data,s.status AS submission_status,s.created_at AS submitted_at
     FROM client_onboarding_invitations i
     LEFT JOIN clients c ON c.id=i.client_id
     LEFT JOIN organizations o ON o.id=i.target_organization_id
     LEFT JOIN client_onboarding_submissions s ON s.invitation_id=i.id
     WHERE ' . $invitationWhere . '
     ORDER BY i.created_at DESC LIMIT 100'
);
$stmt->execute($invitationParams);
$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$generatedLink = (string)($_SESSION['client_onboarding_link'] ?? '');
unset($_SESSION['client_onboarding_link']);
$notifyOnSubmitDefault = !array_key_exists('notify_client_onboarding_submit', $appConfig) || !empty($appConfig['notify_client_onboarding_submit']);
?>

<section style="max-width:1300px;margin:0 auto;padding:24px">
  <div class="page-head">
    <div><h2>Client Onboarding</h2></div>
    <a class="btn" href="/?page=client/clients-list">Back to Clients</a>
  </div>

  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
  <?php if (!empty($_GET['email_error'])): ?><div class="alert alert-danger">The link was created, but the invitation email could not be sent.</div><?php endif; ?>
  <?php if (!empty($_GET['revoked'])): ?><div class="alert alert-success">Onboarding invitation revoked.</div><?php endif; ?>
  <?php if (!empty($_GET['regenerated'])): ?><div class="alert alert-success">Onboarding link regenerated. Copy it below.</div><?php endif; ?>
  <?php if ($generatedLink !== ''): ?>
    <div style="padding:14px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:20px">
      <label class="label-muted" for="generatedOnboardingLink">New onboarding link</label>
      <div style="display:flex;gap:8px;margin-top:6px">
        <input id="generatedOnboardingLink" class="input" readonly value="<?php echo htmlspecialchars($generatedLink); ?>">
        <button type="button" class="btn" data-copy-onboarding-link="generatedOnboardingLink">Copy Link</button>
      </div>
    </div>
  <?php endif; ?>

  <style>
    .onboarding-invite-panel {
      display: grid;
      gap: 14px;
      padding: 18px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      margin-bottom: 24px;
    }
    .onboarding-invite-grid {
      display: grid;
      grid-template-columns: minmax(220px, 1.2fr) minmax(210px, 1fr) minmax(210px, 1fr) minmax(150px, 0.7fr);
      gap: 14px;
      align-items: start;
    }
    .onboarding-invite-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
      padding-top: 2px;
    }
    .onboarding-link-tools {
      display: grid;
      gap: 8px;
      min-width: min(100%, 340px);
    }
    .onboarding-link-row {
      display: grid;
      grid-template-columns: minmax(170px, 1fr) auto;
      gap: 6px;
      align-items: center;
    }
    .onboarding-link-row .input {
      min-width: 0;
      font-size: 12px;
    }
    .onboarding-row-actions {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    .onboarding-review-modal {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 1100;
      background: rgba(15, 23, 42, .45);
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .onboarding-review-modal.is-open { display: flex; }
    .onboarding-review-dialog {
      width: min(980px, 100%);
      max-height: 90vh;
      overflow: auto;
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 24px 60px rgba(15, 23, 42, .25);
      padding: 20px;
    }
    .onboarding-review-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .onboarding-review-box {
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px;
      background: #fbfcfd;
    }
    .onboarding-review-fields {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      font-size: 13px;
    }
    .onboarding-review-section {
      grid-column: 1 / -1;
      margin-top: 4px;
      padding-top: 8px;
      border-top: 1px solid var(--border);
    }
    .onboarding-review-section:first-child {
      margin-top: 0;
      padding-top: 0;
      border-top: 0;
    }
    .onboarding-review-section strong,
    .onboarding-review-section span {
      display: block;
    }
    .onboarding-review-section span {
      margin-top: 2px;
      color: var(--muted);
      font-size: 12px;
    }
    .onboarding-review-optional {
      grid-column: 1 / -1;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: #fff;
    }
    .onboarding-review-optional-title {
      grid-column: 1 / -1;
      color: var(--muted);
      font-size: 12px;
    }
    .onboarding-review-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
      margin-top: 14px;
    }
    .onboarding-merge-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 7px;
      margin-top: 10px;
      font-size: 13px;
    }
    .onboarding-merge-list label {
      display: flex;
      gap: 7px;
      align-items: flex-start;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 7px;
      background: #fff;
    }
    .onboarding-review-close {
      border: 0;
      background: transparent;
      font-size: 22px;
      line-height: 1;
      cursor: pointer;
    }
    @media (max-width: 900px) {
      .onboarding-invite-grid { grid-template-columns: 1fr 1fr; }
      .onboarding-review-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 620px) {
      .onboarding-invite-grid { grid-template-columns: 1fr; }
      .onboarding-invite-actions { justify-content: stretch; }
      .onboarding-invite-actions .btn { flex: 1; }
      .onboarding-link-row { grid-template-columns: 1fr; }
      .onboarding-review-fields, .onboarding-review-optional, .onboarding-merge-list { grid-template-columns: 1fr; }
    }
  </style>

  <form method="post" action="/?page=client/onboarding-invite" class="onboarding-invite-panel">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="create">
    <div class="onboarding-invite-grid">
      <label class="field">
        <span class="label-muted">Invited Email</span>
        <input type="email" class="input" name="email" aria-describedby="onboardingEmailHelp">
        <span id="onboardingEmailHelp" style="display:block;margin-top:5px;font-size:12px;color:var(--muted)">Optional for copyable links. Required when emailing the invitation.</span>
      </label>
      <label class="field"><span class="label-muted">Existing Client</span><select class="input" name="client_id" data-onboarding-client-select><option value="0">New client</option><?php foreach ($clients as $client): ?><option value="<?php echo (int)$client['id']; ?>" data-email="<?php echo htmlspecialchars((string)($client['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($client['name'] . (!empty($client['email']) ? ' - ' . $client['email'] : '')); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label-muted">Client Organization <span style="font-weight:400;color:var(--muted)">(optional)</span></span><select class="input" name="target_organization_id"><option value="0">No organization</option><?php foreach ($organizations as $organization): ?><option value="<?php echo (int)$organization['id']; ?>"><?php echo htmlspecialchars($organization['name']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label-muted">Expires In</span><select class="input" name="expires_hours"><option value="24">24 hours</option><option value="48">48 hours</option><option value="72">3 days</option><option value="168">7 days</option><option value="336" selected>14 days</option></select></label>
    </div>
    <label style="display:flex;gap:8px;align-items:flex-start">
      <input type="checkbox" name="notify_on_submit" value="1" <?php echo $notifyOnSubmitDefault ? 'checked' : ''; ?> style="margin-top:3px">
      <span><strong>Email on new submissions</strong><br><span style="font-size:12px;color:var(--muted)">Send admins and owners an email when this onboarding form is submitted. This stays synced with Settings > Notifications.</span></span>
    </label>
    <div class="onboarding-invite-actions">
      <button class="btn" type="submit" name="delivery" value="link">Generate Link</button>
      <button class="btn btn-primary" type="submit" name="delivery" value="email">Generate and Email</button>
    </div>
  </form>

  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead><tr><th>Email</th><th>Client</th><th>Organization</th><th>Status</th><th>Expires</th><th>Submitted Information</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$invitations): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:28px">No onboarding invitations.</td></tr><?php endif; ?>
      <?php foreach ($invitations as $invitation): ?>
        <?php $proposal = json_decode((string)($invitation['proposed_data'] ?? ''), true); $proposal = is_array($proposal) ? $proposal : []; ?>
        <tr>
          <td><?php echo !empty($invitation['invited_email']) ? htmlspecialchars((string)$invitation['invited_email']) : '<span class="muted">Not required</span>'; ?></td>
          <td><?php echo htmlspecialchars((string)($invitation['client_name'] ?: 'New client')); ?></td>
          <td><?php echo htmlspecialchars((string)($invitation['target_organization_name'] ?: 'None')); ?></td>
          <td><span class="status-pill status-pill--<?php echo htmlspecialchars((string)$invitation['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$invitation['status'])); ?></span></td>
          <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$invitation['expires_at']))); ?></td>
          <td>
            <?php if ($proposal): ?>
              <details><summary><?php echo htmlspecialchars((string)($proposal['name'] ?? 'Review')); ?></summary><div style="font-size:13px;line-height:1.55;margin-top:8px"><?php foreach ($proposal as $label => $value): ?><?php if ($value !== ''): ?><div><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $label))); ?>:</strong> <?php echo htmlspecialchars((string)$value); ?></div><?php endif; ?><?php endforeach; ?></div></details>
            <?php else: ?><span class="muted">Not submitted</span><?php endif; ?>
          </td>
          <td style="min-width:260px">
            <?php if (($invitation['submission_status'] ?? '') === 'pending'): ?>
              <?php
                $clientMatches = client_onboarding_find_client_matches($pdo, $proposal, $organizationId > 0 ? $organizationId : null);
                $orgMatches = client_onboarding_find_organization_matches($pdo, (string)($proposal['organization_name'] ?? ''));
                $modalId = 'onboardingReview' . (int)$invitation['submission_id'];
                $reviewFields = ['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'address_line1' => 'Address', 'address_line2' => 'Apartment / Suite / PO box', 'city' => 'City', 'state' => 'State', 'postal_code' => 'Postal code', 'country' => 'Country', 'client_type' => 'Client type'];
                $proposalClientType = in_array((string)($proposal['client_type'] ?? ''), ['business', 'consumer'], true) ? (string)$proposal['client_type'] : 'consumer';
              ?>
              <button type="button" class="btn btn-sm btn-primary" data-open-onboarding-review="<?php echo htmlspecialchars($modalId); ?>">Review</button>
              <div id="<?php echo htmlspecialchars($modalId); ?>" class="onboarding-review-modal" aria-hidden="true">
                <div class="onboarding-review-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo htmlspecialchars($modalId); ?>Title">
                  <div style="display:flex;justify-content:space-between;gap:12px;align-items:start;margin-bottom:14px">
                    <div>
                      <h3 id="<?php echo htmlspecialchars($modalId); ?>Title" style="margin:0">Review onboarding submission</h3>
                      <div class="muted" style="font-size:13px;margin-top:3px"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$invitation['submitted_at']))); ?></div>
                    </div>
                    <button type="button" class="onboarding-review-close" data-close-onboarding-review="<?php echo htmlspecialchars($modalId); ?>" aria-label="Close review">&times;</button>
                  </div>

                  <div class="onboarding-review-grid">
                    <div class="onboarding-review-box">
                      <strong>Submitted information</strong>
                      <div class="onboarding-review-fields" style="margin-top:10px">
                        <?php foreach ($reviewFields as $field => $label): ?>
                          <div><span class="muted"><?php echo htmlspecialchars($label); ?></span><br><?php echo htmlspecialchars((string)($proposal[$field] ?? '')); ?></div>
                        <?php endforeach; ?>
                        <?php if (!empty($proposal['organization_name'])): ?><div><span class="muted">Typed organization</span><br><?php echo htmlspecialchars((string)$proposal['organization_name']); ?></div><?php endif; ?>
                      </div>
                    </div>
                    <div class="onboarding-review-box">
                      <strong>Likely matches</strong>
                      <div style="display:grid;gap:8px;margin-top:10px;font-size:13px">
                        <?php if (!$clientMatches && !$orgMatches): ?>
                          <div class="muted">No close client or organization matches found.</div>
                        <?php endif; ?>
                        <?php foreach ($clientMatches as $match): ?>
                          <div style="border:1px solid var(--border);border-radius:6px;padding:8px;background:#fff">
                            <strong><?php echo htmlspecialchars((string)$match['name']); ?></strong>
                            <div class="muted">Client match: <?php echo htmlspecialchars(implode(', ', (array)$match['match_reasons'])); ?> (<?php echo (int)$match['match_score']; ?>)</div>
                            <?php if (!empty($match['email'])): ?><div><?php echo htmlspecialchars((string)$match['email']); ?></div><?php endif; ?>
                            <?php if (!empty($match['phone'])): ?><div><?php echo htmlspecialchars((string)$match['phone']); ?></div><?php endif; ?>
                            <?php if (!empty($match['organization_name'])): ?><div class="muted"><?php echo htmlspecialchars((string)$match['organization_name']); ?></div><?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                        <?php foreach ($orgMatches as $match): ?>
                          <div style="border:1px solid var(--border);border-radius:6px;padding:8px;background:#fff">
                            <strong><?php echo htmlspecialchars((string)$match['name']); ?></strong>
                            <div class="muted">Organization match: <?php echo htmlspecialchars(implode(', ', (array)$match['match_reasons'])); ?> (<?php echo (int)$match['match_score']; ?>)</div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>

                  <form method="post" action="/?page=client/onboarding-review" style="margin-top:16px">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="submission_id" value="<?php echo (int)$invitation['submission_id']; ?>">
                    <div class="onboarding-review-box">
                      <strong>Review and edit submitted values</strong>
                      <div class="onboarding-review-fields" style="margin-top:10px">
                        <div class="onboarding-review-section"><strong>Contact details</strong><span>Information for the person the business should contact.</span></div>
                        <label><span class="label-muted">Client type</span><select class="input" name="review_data[client_type]"><option value="consumer" <?php echo $proposalClientType === 'consumer' ? 'selected' : ''; ?>>Individual</option><option value="business" <?php echo $proposalClientType === 'business' ? 'selected' : ''; ?>>Organization</option></select></label>
                        <label><span class="label-muted">Contact name</span><input required class="input" name="review_data[name]" maxlength="150" value="<?php echo htmlspecialchars((string)($proposal['name'] ?? '')); ?>"></label>
                        <label><span class="label-muted">Contact email</span><input type="email" required class="input" name="review_data[email]" maxlength="255" value="<?php echo htmlspecialchars((string)($proposal['email'] ?? '')); ?>"></label>
                        <label><span class="label-muted">Contact phone</span><input class="input" name="review_data[phone]" maxlength="50" value="<?php echo htmlspecialchars((string)($proposal['phone'] ?? '')); ?>"></label>
                        <div class="onboarding-review-section"><strong>Organization details</strong><span>Company information remains separate from the contact above.</span></div>
                        <label><span class="label-muted">Organization name</span><input class="input" name="review_data[organization_name]" maxlength="150" value="<?php echo htmlspecialchars((string)($proposal['organization_name'] ?? '')); ?>"></label>
                        <div class="onboarding-review-optional">
                          <div class="onboarding-review-optional-title"><strong>General company contact (optional)</strong> Shared company channels only; these do not replace the contact's email or phone.</div>
                          <label><span class="label-muted">General company email (not required)</span><input type="email" class="input" name="review_data[organization_email]" maxlength="255" value="<?php echo htmlspecialchars((string)($proposal['organization_email'] ?? '')); ?>"></label>
                          <label><span class="label-muted">General company phone (not required)</span><input class="input" name="review_data[organization_phone]" maxlength="50" value="<?php echo htmlspecialchars((string)($proposal['organization_phone'] ?? '')); ?>"></label>
                        </div>
                        <div class="onboarding-review-section"><strong>Billing address</strong><span>For an organization, this address is saved to both the organization and its contact.</span></div>
                        <?php foreach (['address_line1' => 'Address', 'address_line2' => 'Apartment / Suite / PO box', 'city' => 'City', 'state' => 'State', 'postal_code' => 'Postal code', 'country' => 'Country'] as $field => $label): ?>
                          <label><span class="label-muted"><?php echo htmlspecialchars($label); ?></span><input class="input" name="review_data[<?php echo htmlspecialchars($field); ?>]" maxlength="<?php echo in_array($field, ['address_line1','address_line2'], true) ? '255' : '100'; ?>" value="<?php echo htmlspecialchars((string)($proposal[$field] ?? '')); ?>"></label>
                        <?php endforeach; ?>
                      </div>
                      <div class="muted" style="font-size:12px;margin-top:8px">For organization onboarding, this one reviewed address is always saved to both the organization and its contact.</div>
                    </div>
                    <div class="onboarding-review-box">
                      <strong>Organization decision</strong>
                      <div style="display:grid;gap:8px;margin-top:8px;font-size:13px">
                        <?php if (!empty($invitation['target_organization_name'])): ?>
                          <label><input type="radio" name="organization_resolution" value="" checked> Keep invited organization "<?php echo htmlspecialchars((string)$invitation['target_organization_name']); ?>"</label>
                        <?php elseif ($proposalClientType === 'consumer'): ?>
                          <label><input type="radio" name="organization_resolution" value="" checked> Keep this as an individual client</label>
                        <?php endif; ?>
                        <?php if ($orgMatches): ?>
                          <label>Match typed organization to
                            <select class="input" name="match_organization_id" style="margin-top:4px">
                              <option value="0">Choose organization</option>
                              <?php foreach ($orgMatches as $match): ?><option value="<?php echo (int)$match['id']; ?>"><?php echo htmlspecialchars((string)$match['name']); ?></option><?php endforeach; ?>
                            </select>
                          </label>
                          <label><input type="radio" name="organization_resolution" value="match"> Use selected organization match</label>
                        <?php endif; ?>
                        <?php if (!empty($proposal['organization_name'])): ?><label><input type="radio" name="organization_resolution" value="create" <?php echo empty($invitation['target_organization_name']) && $proposalClientType === 'business' ? 'checked' : ''; ?>> Create or update organization from the reviewed values</label><?php endif; ?>
                      </div>
                    </div>

                    <div class="onboarding-review-box" style="margin-top:12px">
                      <strong>Client decision</strong>
                      <?php if ($clientMatches): ?>
                        <label style="display:block;margin-top:8px">Existing client
                          <select class="input" name="match_client_id" style="margin-top:4px">
                            <option value="0">Choose client match</option>
                            <?php foreach ($clientMatches as $match): ?><option value="<?php echo (int)$match['id']; ?>"><?php echo htmlspecialchars((string)$match['name'] . (!empty($match['email']) ? ' - ' . $match['email'] : '')); ?></option><?php endforeach; ?>
                          </select>
                        </label>
                        <div class="onboarding-merge-list">
                          <?php foreach ($reviewFields as $field => $label): ?>
                            <label><input type="checkbox" name="merge_fields[]" value="<?php echo htmlspecialchars($field); ?>" <?php echo in_array($field, ['email','phone','address_line1','address_line2','city','state','postal_code','country'], true) ? 'checked' : ''; ?>> Use submitted <?php echo htmlspecialchars(strtolower($label)); ?></label>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <div class="muted" style="font-size:13px;margin-top:8px">No client matches were found. You can still create a new client or reject the submission.</div>
                      <?php endif; ?>
                      <label style="display:block;margin-top:10px"><span class="label-muted">Review notes</span><textarea class="input" name="review_notes" rows="2"></textarea></label>
                      <div class="onboarding-review-actions">
                        <button class="btn btn-danger" name="decision" value="reject" formnovalidate>Reject</button>
                        <?php if(!$directoryManagementStatus['effective']):?>
                        <button class="btn btn-primary" name="decision" value="approve" onclick="this.form.resolution.value='create'">Approve as New Client</button>
                        <?php if ($clientMatches): ?>
                          <button class="btn" name="decision" value="approve" onclick="this.form.resolution.value='keep_existing'">Keep Existing Client</button>
                          <button class="btn btn-primary" name="decision" value="approve" onclick="this.form.resolution.value='merge_existing'">Merge Selected Fields</button>
                        <?php endif; ?>
                        <?php endif; ?>
                      </div>
                      <input type="hidden" name="resolution" value="">
                    </div>
                  </form>
                </div>
              </div>
            <?php elseif (in_array((string)$invitation['status'], ['pending','verified','expired'], true)): ?>
              <?php $storedLink = client_onboarding_link_for_invitation($appConfig, $invitation); ?>
              <div class="onboarding-link-tools">
                <?php if ($storedLink !== ''): ?>
                  <div class="onboarding-link-row">
                    <input id="onboardingLink<?php echo (int)$invitation['id']; ?>" class="input" readonly value="<?php echo htmlspecialchars($storedLink); ?>">
                    <button type="button" class="btn btn-sm" data-copy-onboarding-link="onboardingLink<?php echo (int)$invitation['id']; ?>">Copy</button>
                  </div>
                <?php else: ?>
                  <span class="muted" style="font-size:12px">This older link cannot be recovered. Regenerate it to get a copyable link.</span>
                <?php endif; ?>
                <div class="onboarding-row-actions">
                  <form method="post" action="/?page=client/onboarding-invite" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="regenerate_link"><input type="hidden" name="id" value="<?php echo (int)$invitation['id']; ?>"><button class="btn btn-sm">Regenerate Link</button>
                  </form>
                  <form method="post" action="/?page=client/onboarding-invite" onsubmit="return confirm('Revoke this invitation?')" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo (int)$invitation['id']; ?>"><button class="btn btn-sm btn-danger">Revoke</button>
                  </form>
                </div>
              </div>
            <?php else: ?><span class="muted">Complete</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/client-onboarding.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
