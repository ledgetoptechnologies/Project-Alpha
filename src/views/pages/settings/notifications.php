<?php
// src/views/pages/settings/notifications.php
?>

  <!-- ============================================================================ -->
  <!-- SYSTEM AUTOMATION -->
  <!-- ============================================================================ -->

  <fieldset data-settings-notifications><!-- data-settings-notifications required by settings-notifications.js -->
    <legend>System Automation</legend>
    
    <!-- Recurring Invoice Generation -->
    <div style="margin-bottom:20px">
      <h3 style="margin:0 0 8px 0;font-size:15px">Recurring Invoice Generation</h3>
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Automatically generate invoices for active long-term contracts</p>
      
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="cron_enabled" value="1" <?php echo !empty($appConfig['cron_enabled']) ? 'checked' : ''; ?>>
        <span class="font-600">Enable Automatic Invoice Generation</span>
      </label>

      <div id="cronScheduleSection" style="<?php echo empty($appConfig['cron_enabled']) ? 'display:none' : ''; ?>">
        <label>
          <div style="margin-bottom:4px;font-weight:500">Schedule</div>
          <select name="cron_schedule" style="width:100%;max-width:400px;padding:10px;border-radius:8px;border:1px solid #ddd">
            <?php $cronSched = $appConfig['cron_schedule'] ?? 'daily_2am'; ?>
            <option value="hourly" <?php echo $cronSched === 'hourly' ? 'selected' : ''; ?>>Every Hour</option>
            <option value="every_6hours" <?php echo $cronSched === 'every_6hours' ? 'selected' : ''; ?>>Every 6 Hours</option>
            <option value="daily_midnight" <?php echo $cronSched === 'daily_midnight' ? 'selected' : ''; ?>>Daily at Midnight</option>
            <option value="daily_2am" <?php echo $cronSched === 'daily_2am' ? 'selected' : ''; ?>>Daily at 2:00 AM (Recommended)</option>
            <option value="daily_6am" <?php echo $cronSched === 'daily_6am' ? 'selected' : ''; ?>>Daily at 6:00 AM</option>
            <option value="daily_noon" <?php echo $cronSched === 'daily_noon' ? 'selected' : ''; ?>>Daily at Noon</option>
            <option value="custom" <?php echo $cronSched === 'custom' ? 'selected' : ''; ?>>Custom Cron Expression</option>
          </select>
        </label>
        
        <div id="customCronSection" style="margin-top:12px;<?php echo $cronSched !== 'custom' ? 'display:none' : ''; ?>">
          <label>
            <div style="margin-bottom:4px;font-weight:500">Custom Cron Expression</div>
            <input type="text" name="cron_custom" value="<?php echo htmlspecialchars($appConfig['cron_custom'] ?? '0 2 * * *'); ?>" style="width:100%;max-width:400px;padding:10px;border-radius:8px;border:1px solid #ddd;font-family:monospace" placeholder="0 2 * * *">
            <div style="margin-top:4px;color:var(--muted);font-size:12px">Format: minute hour day month weekday (e.g., "0 2 * * *" = 2:00 AM daily)</div>
          </label>
        </div>
        
        <div style="margin-top:12px;padding:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:13px">
          <strong>Current Status:</strong>
          <div style="margin-top:4px">
            <?php 
              $lastRun = $appConfig['cron_last_run'] ?? null;
              if ($lastRun) {
                echo 'Last run: ' . date('M j, Y g:i A', strtotime($lastRun));
              } else {
                echo 'Never run';
              }
            ?>
          </div>
        </div>
        
        <div style="margin-top:12px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;font-size:12px;line-height:1.5">
          <strong>✅ Automatically Configured:</strong> The cron job runs automatically in the Docker container using the schedule above.
        </div>
      </div>
    </div>

    <!-- Contract Auto-Termination -->
    <div style="padding-top:16px;border-top:1px solid #eee">
      <h3 style="margin:0 0 8px 0;font-size:15px">Contract Auto-Termination</h3>
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Automatically terminate contracts when end date is reached</p>
      
      <label style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" name="auto_terminate_contracts" value="1" <?php echo !empty($appConfig['auto_terminate_contracts']) ? 'checked' : ''; ?>>
        <span class="font-600">Enable Auto-Termination</span>
      </label>
    </div>

    <!-- Link Expiration Checker -->
    <div style="padding-top:16px;border-top:1px solid #eee;margin-top:16px">
      <h3 style="margin:0 0 8px 0;font-size:15px">Link Expiration Checker</h3>
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Daily check for expired client/organization links</p>
      
      <label style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" name="link_expiration_checker" value="1" <?php echo !empty($appConfig['link_expiration_checker']) ? 'checked' : ''; ?>>
        <span class="font-600">Enable Link Expiration Checker</span>
      </label>
    </div>
  </fieldset>

  <!-- ============================================================================ -->
  <!-- EMAIL NOTIFICATIONS -->
  <!-- ============================================================================ -->

  <fieldset>
    <legend>Email Notifications</legend>

    <div style="margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Payment Receipts</h3>

      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="payment_receipts_enabled" value="1" <?php echo !array_key_exists('payment_receipts_enabled', $appConfig) || !empty($appConfig['payment_receipts_enabled']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Enable payment receipts</div>
          <div style="font-size:13px;color:var(--muted)">Create receipts for successful payments and email them when selected.</div>
        </div>
      </label>
    </div>

    <div style="padding-top:16px;border-top:1px solid #eee;margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Invoice Paid Admin Emails</h3>

      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="notify_invoice_paid" value="1" <?php echo !array_key_exists('notify_invoice_paid', $appConfig) || !empty($appConfig['notify_invoice_paid']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Email admins when an invoice is paid through Stripe</div>
          <div style="font-size:13px;color:var(--muted)">Manual payment entries do not trigger this email.</div>
        </div>
      </label>

      <div style="margin-left:28px;display:grid;gap:10px">
        <label style="display:flex;align-items:start;gap:10px">
          <input type="checkbox" name="notify_invoice_paid_regular" value="1" <?php echo !array_key_exists('notify_invoice_paid_regular', $appConfig) || !empty($appConfig['notify_invoice_paid_regular']) ? 'checked' : ''; ?> style="margin-top:3px">
          <div>
            <div class="font-600">Regular invoices</div>
            <div style="font-size:13px;color:var(--muted)">Single invoices created from the invoice workflow.</div>
          </div>
        </label>

        <label style="display:flex;align-items:start;gap:10px">
          <input type="checkbox" name="notify_invoice_paid_on_demand" value="1" <?php echo !array_key_exists('notify_invoice_paid_on_demand', $appConfig) || !empty($appConfig['notify_invoice_paid_on_demand']) ? 'checked' : ''; ?> style="margin-top:3px">
          <div>
            <div class="font-600">On-demand invoices</div>
            <div style="font-size:13px;color:var(--muted)">Invoices generated from on-demand contracts.</div>
          </div>
        </label>

        <label style="display:flex;align-items:start;gap:10px">
          <input type="checkbox" name="notify_invoice_paid_long_term" value="1" <?php echo !array_key_exists('notify_invoice_paid_long_term', $appConfig) || !empty($appConfig['notify_invoice_paid_long_term']) ? 'checked' : ''; ?> style="margin-top:3px">
          <div>
            <div class="font-600">Long-term invoices</div>
            <div style="font-size:13px;color:var(--muted)">Invoices generated from long-term contract billing.</div>
          </div>
        </label>

        <label style="display:flex;align-items:start;gap:10px">
          <input type="checkbox" name="notify_invoice_paid_project" value="1" <?php echo !array_key_exists('notify_invoice_paid_project', $appConfig) || !empty($appConfig['notify_invoice_paid_project']) ? 'checked' : ''; ?> style="margin-top:3px">
          <div>
            <div class="font-600">Project invoices</div>
            <div style="font-size:13px;color:var(--muted)">Aggregate project invoices paid through Stripe.</div>
          </div>
        </label>
      </div>
    </div>

    <div style="padding-top:16px;border-top:1px solid #eee;margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Automated Email Notice</h3>

      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="email_no_reply_notice_enabled" value="1" <?php echo !empty($appConfig['email_no_reply_notice_enabled']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Mark outgoing emails as no-reply</div>
          <div style="font-size:13px;color:var(--muted)">All system emails show an automated label and message header. Enable this to add NO-REPLY to the sender and show the notice below. Documents with a reply contact keep that address and explain where replies go.</div>
        </div>
      </label>
      <label style="display:block">
        <div style="margin-bottom:4px;font-weight:500">Notice text</div>
        <input type="text" name="email_no_reply_notice_text" value="<?php echo htmlspecialchars($appConfig['email_no_reply_notice_text'] ?? 'This is an automated message. Please do not reply to this email.'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>

    <div style="padding-top:16px;border-top:1px solid #eee;margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Client Onboarding Admin Emails</h3>

      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="notify_client_onboarding_submit" value="1" <?php echo !array_key_exists('notify_client_onboarding_submit', $appConfig) || !empty($appConfig['notify_client_onboarding_submit']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Email admins when an onboarding form is submitted</div>
          <div style="font-size:13px;color:var(--muted)">This is the same default used by the checkbox on the client onboarding invite page.</div>
        </div>
      </label>
    </div>
    
    <!-- Invoice Reminders -->
    <div style="margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Invoice Reminders</h3>
      
      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="invoice_auto_send_due_7days" value="1" <?php echo !empty($appConfig['invoice_auto_send_due_7days']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Send reminder 7 days before due</div>
          <div style="font-size:13px;color:var(--muted)">Email clients one week before an invoice is due</div>
        </div>
      </label>

      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="invoice_auto_send_overdue_weekly" value="1" <?php echo !empty($appConfig['invoice_auto_send_overdue_weekly']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Send weekly reminders for overdue invoices</div>
          <div style="font-size:13px;color:var(--muted)">Email clients for overdue invoices at most once every 7 days</div>
        </div>
      </label>
    </div>

    <!-- Contract Notifications -->
    <div style="padding-top:16px;border-top:1px solid #eee;margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Contract Notifications</h3>
      
      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="contract_expiring_warning" value="1" <?php echo !empty($appConfig['contract_expiring_warning']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div style="flex:1">
          <div class="font-600">Contract expiration warning</div>
          <div style="font-size:13px;color:var(--muted);margin-bottom:6px">Notify when contracts are approaching end date</div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:13px">Send warning</span>
            <input type="number" name="contract_expiring_days" value="<?php echo $appConfig['contract_expiring_days'] ?? 30; ?>" min="1" max="90" style="width:70px;padding:6px;border-radius:4px;border:1px solid #ddd">
            <span style="font-size:13px">days before expiration</span>
          </div>
        </div>
      </label>

      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="contract_expired_alert" value="1" <?php echo !empty($appConfig['contract_expired_alert']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Contract expired alert</div>
          <div style="font-size:13px;color:var(--muted)">Send notification when contract has expired</div>
        </div>
      </label>

      <label style="display:flex;align-items:start;gap:10px;margin-top:12px">
        <input type="checkbox" name="notify_signed_contract_uploaded" value="1" <?php echo !array_key_exists('notify_signed_contract_uploaded', $appConfig) || !empty($appConfig['notify_signed_contract_uploaded']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div class="font-600">Email admins when a signed contract is uploaded through a public link</div>
          <div style="font-size:13px;color:var(--muted)">Manual signed-contract uploads do not trigger this email.</div>
        </div>
      </label>
    </div>

    <!-- Link Notifications -->
    <div style="padding-top:16px;border-top:1px solid #eee">
      <h3 style="margin:0 0 12px 0;font-size:15px">Link Expiration Warnings</h3>
      
      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="link_expiration_warning" value="1" <?php echo !empty($appConfig['link_expiration_warning']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div style="flex:1">
          <div class="font-600">Notify before link expiration</div>
          <div style="font-size:13px;color:var(--muted);margin-bottom:6px">Send warning before client/org links expire</div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:13px">Send warning</span>
            <input type="number" name="link_expiration_warning_days" value="<?php echo $appConfig['link_expiration_warning_days'] ?? 30; ?>" min="1" max="90" style="width:70px;padding:6px;border-radius:4px;border:1px solid #ddd">
            <span style="font-size:13px">days before expiration</span>
          </div>
        </div>
      </label>
    </div>
  </fieldset>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/settings-notifications.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
