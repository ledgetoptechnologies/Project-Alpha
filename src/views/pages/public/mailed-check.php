<?php
$esc = static fn($value)=>htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$verified = $context !== null && pa_check_report_public_authority($context, $token, $_SESSION);
$hidden = '<input type="hidden" name="token" value="' . $esc($token) . '"><input type="hidden" name="csrf" value="' . $esc(csrf_token()) . '">';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Report a mailed check — Project Alpha</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#172b45;font:16px/1.6 system-ui,sans-serif}main{width:min(100% - 32px,680px);margin:32px auto;padding:28px;background:white;border:1px solid #d9e2ee;border-radius:14px}h1{font-size:clamp(1.6rem,5vw,2rem);line-height:1.2}h2{font-size:1.15rem}form{margin:18px 0}label{display:block}input{display:block;width:100%;max-width:360px;padding:11px;margin:7px 0 14px;border:1px solid #94a3b8;border-radius:7px;font:inherit}button{padding:11px 18px;min-height:44px;border:0;border-radius:7px;background:#245bb1;color:white;font:inherit;font-weight:600;cursor:pointer}.notice{padding:13px;border:1px solid #cbd5e1;border-radius:8px;overflow-wrap:anywhere}.error{color:#8c2525;background:#fff2f2}.status{background:#edf6ff}.warning{background:#fffbeb;color:#92400e}button:focus-visible,input:focus-visible,a:focus-visible{outline:3px solid #df9f25;outline-offset:3px}@media(max-width:480px){main{padding:18px}button,input{max-width:none;width:100%}}</style></head>
<body><main><h1>Report a mailed check</h1>
<?php if ($error): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="notice status" role="status"><?= $esc($message) ?></p><?php endif; ?>
<?php if ($context !== null): ?>
<p>Reporting a check does not mark this invoice paid or record income. The business must confirm the check has arrived.</p>
<?php if (!$verified): ?>
<h2>Verify your email</h2><p>Verify access to the client email on file before reporting a check or pausing automatic collection.</p>
<form method="post"><?= $hidden ?><button name="action" value="send_code">Send verification code</button></form>
<form method="post"><?= $hidden ?><label>Verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" maxlength="8" required></label><button name="action" value="verify">Verify email</button></form>
<?php elseif ($reportNeedsReview): ?>
<p class="notice warning">A mailed-check hold needs staff review before this invoice can accept another report. Contact the business.</p>
<?php elseif ($report): ?>
<section class="notice <?= !empty($report['overdue']) ? 'warning' : 'status' ?>">
<h2><?= $esc($report['status'] === 'received' ? 'Check receipt confirmed' : ($report['status'] === 'rejected' ? 'Check report closed — contact the business' : (!empty($report['overdue']) ? 'Check not yet received — review needed' : 'Check reported — awaiting receipt'))) ?></h2>
<p>Check number: <strong><?= $esc($report['check_number']) ?></strong><br>Reported amount: <strong><?= $esc(pa_check_report_money((int)$report['amount_cents'], (string)$report['currency'])) ?></strong><br>Expected by: <strong><?= $esc(pa_check_report_display_date((string)$report['expected_by'], $appConfig)) ?></strong></p>
<?php if ($report['status'] === 'pending'): ?><p>The receipt window is ten calendar days from your original report. Reporting again does not extend it. This invoice remains unpaid until receipt is confirmed. Automatic collection stays on hold for review if the check has not arrived by the deadline.</p><?php endif; ?>
</section>
<?php else: ?>
<p>Current invoice balance: <strong><?= $esc(pa_check_report_money((int)round(100 * max(0, (float)$context['document']['total']-(float)($context['document']['amount_paid'] ?? 0))), (string)($context['document']['currency'] ?? $appConfig['workforce_currency'] ?? 'usd'))) ?></strong></p>
<p class="notice warning">Only report a check you have mailed. The business will allow ten calendar days from submission for it to arrive. If electronic payment is already processing, reporting a check cannot stop it and your report will be refused. Contact the business before paying again.</p>
<form method="post"><?= $hidden ?><label>Check number<input name="check_number" type="text" maxlength="64" autocomplete="off" required></label><button name="action" value="report">Report mailed check</button></form>
<?php endif; ?>
<p><a href="/?page=public-doc&amp;token=<?= $esc(rawurlencode($token)) ?>">Return to invoice</a></p>
<?php endif; ?>
</main></body></html>
