<?php
// src/views/pages/payments-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/payment_accounting.php';

$canReconcileStripe = in_array(acl_user_role($pdo, (int)$_SESSION['user']['id']), ['admin', 'owner'], true)
    && user_can($pdo, (int)$_SESSION['user']['id'], 'settings.manage', 0)
    && user_can($pdo, (int)$_SESSION['user']['id'], 'invoices.mark_paid', 0);

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$showReversed = !empty($_GET['show_reversed']);
$where=[];$p=[];
if($client_id>0){$where[]='c.id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($start!==''){$where[]='p.payment_date>=?';$p[]=$start;}
if($end!==''){$where[]='p.payment_date<=?';$p[]=$end;}
if(!$showReversed){$where[]='p.status<>"reversed"';}

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'p', (int)$_SESSION['user']['id']);
if ($scopeWhere) {
    $where[] = $scopeWhere;
    $p = array_merge($p, $scopeParams);
}

$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$fromSql = ' FROM payments p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN invoices i ON i.id=p.invoice_id LEFT JOIN jobs j ON j.id=p.job_id LEFT JOIN processor_payment_transactions ppt ON ppt.payment_id=p.id';
$sqlCount = 'SELECT COUNT(*)' . $fromSql;
if($where){$sqlCount.=' WHERE '.implode(' AND ',$where);} $stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql = 'SELECT p.id, p.amount, p.refunded_amount, p.disputed_amount, p.status, p.payment_date, p.created_at,
               p.payment_method, p.reference_number, p.surcharge_paid, p.surcharge_refunded, p.surcharge_refund_amount,
               p.processor_provider, p.processor_gross_amount, p.processor_fee_amount, p.processor_net_amount,
               p.processor_payment_id, p.stripe_payment_intent_id, p.stripe_session_id, p.project_invoice_payment_id,
               p.reversed_at, p.reversal_reason,
               p.processor_fee_policy, p.processor_fee_source, i.id AS invoice_id, i.doc_number, i.invoice_type, i.collection_mode,
               p.job_id,j.job_code,c.name AS client,ppt.payer_name,ppt.payer_email,
               EXISTS (
                   SELECT 1
                   FROM invoices correction_target
                   WHERE correction_target.client_id = i.client_id
                     AND correction_target.id <> i.id
                     AND COALESCE(correction_target.collection_mode, "direct") = "direct"
                     AND correction_target.status NOT IN ("draft", "void", "cancelled")
               ) AS has_correction_target
        ' . $fromSql;
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=" ORDER BY p.payment_date DESC, p.created_at DESC LIMIT $per OFFSET $offset";
$rows = $pdo->prepare($sql); $rows->execute($p); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <style>.payment-reverse-dialog::backdrop{background:rgba(15,23,42,.48)}</style>
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <h2 style="margin:0">Payments</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <a href="/?page=payments-list<?php echo $showReversed ? '' : '&show_reversed=1'; ?>" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block;font-size:small"><?php echo $showReversed ? 'Hide reversed corrections' : 'Show reversed corrections'; ?></a>
      <a href="/?page=payments/payments-create" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block;font-size:small">Record Payment</a>
    </div>
  </div>
  <?php if (!empty($_GET['saved'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Payment saved.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['stripe_reconciled'])): ?>
    <div role="status" style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46">Stripe settlement verified. The existing payment and invoice are reconciled; no charge or email was sent.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['refunded'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Refund recorded.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['stripe_refunded'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Stripe accepted the refund. PA has recorded the Stripe refund and will continue reconciling webhook updates.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['manual_reversed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Manual payment entry reversed. It no longer affects invoice totals and is hidden from the normal payment list.</div>
  <?php endif; ?>
  <?php if ($showReversed): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db">Showing reversed accounting corrections. These entries remain for audit history but do not count as income or invoice payments.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="payments-list">
    <input type="hidden" name="client_id" id="clientIdPL" value="<?php echo (int)$client_id; ?>">
    <label style="position:relative"><div>Client</div>
      <input type="text" name="client" id="clientInputPL" value="<?php echo htmlspecialchars($client_name); ?>" placeholder="Type client name..." style="padding:8px;border-radius:8px;border:1px solid #ddd">
      <div id="clientSuggestPL" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
    </label>
    <label><div>Start</div><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>End</div><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small">Filter</button>
    <a href="/?page=payments-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block;font-size:small">Reset</a>
  </form>
  <script>
    (function(){
      var input = document.getElementById('clientInputPL');
      var hid = document.getElementById('clientIdPL');
      var sug = document.getElementById('clientSuggestPL');
      input.addEventListener('input', function(){
        hid.value='';
        var t=this.value.trim(); if(!t){sug.style.display='none';sug.innerHTML='';return;}
        fetch('/?page=clients-search&term='+encodeURIComponent(t)).then(r=>r.json()).then(list=>{
          if(!Array.isArray(list)||list.length===0){sug.style.display='none';sug.innerHTML='';return;}
          sug.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
          Array.from(sug.children).forEach(el=>{ el.addEventListener('click', function(){ input.value=this.dataset.name; hid.value=this.dataset.id; sug.style.display='none'; }); });
          sug.style.display='block';
        }).catch(()=>{sug.style.display='none'});
      });
      document.addEventListener('click', function(e){ if(!sug.contains(e.target) && e.target!==input){ sug.style.display='none'; } });
    })();
  </script>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">ID</th>
          <th style="padding:10px">Invoice</th>
          <th style="padding:10px">Service Job</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Method</th>
          <th style="padding:10px;text-align:right">Amount</th>
          <th style="padding:10px;text-align:right">Fee</th>
          <th style="padding:10px;text-align:right">Refunded</th>
          <th style="padding:10px;text-align:right">Net Received</th>
          <th style="padding:10px">Fee Source</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Payment Date</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $amount = (float)$r['amount'];
          $refunded = (float)$r['refunded_amount'];
          $disputed = (float)$r['disputed_amount'];
          $appliedNet = max(0, $amount - $refunded - $disputed);
          $processorFee = $r['processor_fee_amount'] !== null ? (float)$r['processor_fee_amount'] : 0.0;
          $isSucceeded = strtolower((string)$r['status']) === 'succeeded';
          $net = $isSucceeded ? payment_accounting_net_income($r) : 0.0;
          $feeSource = (string)($r['processor_fee_source'] ?? 'unknown');
          $isProcessorBacked = strtolower((string)$r['payment_method']) === 'stripe'
            || trim((string)($r['processor_provider'] ?? '')) !== ''
            || trim((string)($r['processor_payment_id'] ?? '')) !== ''
            || trim((string)($r['stripe_payment_intent_id'] ?? '')) !== ''
            || trim((string)($r['stripe_session_id'] ?? '')) !== '';
          $canRecordRefund = $isSucceeded && !$isProcessorBacked && $appliedNet > 0.005;
          $canReverseManual = $isSucceeded
            && !$isProcessorBacked
            && !empty($r['invoice_id'])
            && empty($r['project_invoice_payment_id'])
            && $disputed <= 0.005;
          $processorGross = $r['processor_gross_amount'] !== null
            ? (float)$r['processor_gross_amount']
            : $amount + max(0.0, (float)$r['surcharge_paid']);
          $processorRefundRemaining = max(0.0, $processorGross - $refunded - $disputed);
          $canCorrect = $isSucceeded
            && !empty($r['invoice_id'])
            && (string)($r['collection_mode'] ?? 'direct') === 'direct'
            && !empty($r['has_correction_target'])
            && empty($r['project_invoice_payment_id'])
            && ($refunded <= 0.005 || $isProcessorBacked)
            && $disputed <= 0.005;
        ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px">#<?php echo (int)$r['id']; ?></td>
            <td style="padding:10px">
              <?php if (!empty($r['invoice_id'])): ?>
                Invoice <?php echo htmlspecialchars(pa_invoice_label_from_row($r)); ?>
              <?php elseif (!empty($r['processor_provider'])): ?>
                Processor income
              <?php else: ?>
                Manual
              <?php endif; ?>
            </td>
            <td style="padding:10px">
              <?php if (!empty($r['job_id'])): ?>
                <?php echo htmlspecialchars((string)($r['job_code'] ?: ('Job #' . (int)$r['job_id']))); ?>
              <?php else: ?>
                <span style="color:var(--muted)">—</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['client'] ?: ($r['payer_name'] ?: ($r['payer_email'] ?: 'No client'))); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$r['payment_method'])); ?></td>
            <td style="padding:10px;text-align:right">$<?php echo number_format($amount, 2); ?></td>
            <td style="padding:10px;text-align:right">$<?php echo number_format($processorFee, 2); ?></td>
            <td style="padding:10px;text-align:right">$<?php echo number_format($refunded + $disputed, 2); ?></td>
            <td style="padding:10px;text-align:right;font-weight:600">$<?php echo number_format($net, 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $feeSource)); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo $r['payment_date'] ? date('m/d/Y', strtotime($r['payment_date'])) : ''; ?></td>
            <td style="padding:10px">
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:190px">
              <?php if ($canReconcileStripe && $r['status'] === 'failed' && $amount === 0.0
                && $r['payment_method'] === 'stripe' && !empty($r['invoice_id']) && !empty($r['stripe_payment_intent_id'])
                && empty($r['project_invoice_payment_id'])): ?>
                <a href="/?page=payments/stripe-reconcile-preview&amp;payment_id=<?php echo (int)$r['id']; ?>" title="Preview fresh Stripe settlement without changing this payment." style="padding:6px 9px;border-radius:6px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8">Preview Stripe settlement</a>
              <?php endif; ?>
              <?php if ($canCorrect): ?>
                <a href="/?page=payments/payment-correction&payment_id=<?php echo (int)$r['id']; ?>" style="padding:6px 9px;border-radius:6px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;white-space:nowrap">Correct allocation</a>
              <?php elseif ($isSucceeded && !empty($r['invoice_id']) && (string)($r['collection_mode'] ?? 'direct') === 'direct' && empty($r['project_invoice_payment_id']) && empty($r['has_correction_target'])): ?>
                <span title="Allocation corrections can only move a payment to another invoice for the same client." style="color:var(--muted);font-size:12px">No other invoice for this client</span>
              <?php endif; ?>
              <?php if ($canRecordRefund): ?>
                <form method="post" action="/?page=payments/payment-refund" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Record this refund only after money has been returned to the client outside Project Alpha. Continue?');">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="payment_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="number" name="amount" step="0.01" min="0.01" max="<?php echo htmlspecialchars(number_format($appliedNet, 2, '.', '')); ?>" placeholder="0.00" style="width:92px;padding:6px;border-radius:6px;border:1px solid #ddd">
                  <button type="submit" style="padding:6px 9px;border-radius:6px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;white-space:nowrap">Record refund</button>
                </form>
              <?php endif; ?>
              <?php if ($canReverseManual): ?>
                <?php $reverseDialogId = 'reversePaymentDialog' . (int)$r['id']; ?>
                <button type="button" onclick="document.getElementById('<?php echo $reverseDialogId; ?>').showModal()" style="padding:6px 9px;border-radius:6px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;white-space:nowrap">Reverse entry</button>
                <dialog id="<?php echo $reverseDialogId; ?>" class="payment-reverse-dialog" style="width:min(440px,calc(100vw - 32px));box-sizing:border-box;padding:0;border:1px solid #fcd34d;border-radius:12px;box-shadow:0 24px 60px rgba(15,23,42,.28)">
                  <form method="post" action="/?page=payments/payment-reverse" style="display:grid;gap:12px;padding:18px;margin:0" onsubmit="return confirm('Reverse this manual accounting entry? No money will move.');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="payment_id" value="<?php echo (int)$r['id']; ?>">
                    <div><div style="font-size:18px;font-weight:700;color:#111827">Reverse manual payment #<?php echo (int)$r['id']; ?></div><div style="margin-top:4px;font-size:13px;color:#6b7280">Invoice <?php echo htmlspecialchars(pa_invoice_label_from_row($r)); ?> &middot; $<?php echo number_format($amount, 2); ?> &middot; <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$r['payment_method']))); ?></div></div>
                    <?php if ($refunded > 0.005): ?><div style="padding:9px;border:1px solid #fde68a;border-radius:7px;background:#fffbeb;color:#92400e;font-size:13px">This row has $<?php echo number_format($refunded, 2); ?> recorded as refunded. Reversing it preserves that history but removes the mistaken entry from active payment reporting.</div><?php endif; ?>
                    <label style="font-size:13px;font-weight:600;color:#374151">Reason<textarea name="reason" maxlength="500" required rows="4" placeholder="Example: Duplicate manual entry created during payment correction" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:9px;border:1px solid #d1d5db;border-radius:7px;resize:vertical"></textarea></label>
                    <div style="font-size:12px;color:#6b7280"><strong>No money moves.</strong> Use a refund instead if an actual client payment still needs to be returned.</div>
                    <div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" onclick="document.getElementById('<?php echo $reverseDialogId; ?>').close()" style="padding:8px 11px;border:1px solid #d1d5db;border-radius:7px;background:#fff">Cancel</button><button type="submit" style="padding:8px 11px;border:1px solid #b45309;border-radius:7px;background:#b45309;color:#fff">Confirm Reverse Entry</button></div>
                  </form>
                </dialog>
              <?php endif; ?>
              <?php if ($isProcessorBacked && $isSucceeded && $processorRefundRemaining > 0.005): ?>
                <details style="position:relative">
                  <summary style="padding:6px 9px;border-radius:6px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;cursor:pointer;white-space:nowrap;list-style:none">Refund via Stripe</summary>
                  <form method="post" action="/?page=payments/payment-refund" style="position:absolute;z-index:30;right:0;top:calc(100% + 6px);width:280px;display:grid;gap:8px;padding:12px;background:#fff;border:1px solid #fecaca;border-radius:8px;box-shadow:0 10px 25px rgba(15,23,42,.16)" onsubmit="return confirm('This sends real money back to the client through Stripe and cannot be undone. Continue?');">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="payment_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="processor_refund" value="1">
                    <input type="hidden" name="refund_request_token" value="<?php echo bin2hex(random_bytes(16)); ?>">
                    <label><span style="display:block;font-size:12px;margin-bottom:3px">Amount to return (max $<?php echo number_format($processorRefundRemaining, 2); ?>)</span><input type="number" name="amount" step="0.01" min="0.01" max="<?php echo htmlspecialchars(number_format($processorRefundRemaining, 2, '.', '')); ?>" value="<?php echo htmlspecialchars(number_format($processorRefundRemaining, 2, '.', '')); ?>" required style="width:100%;padding:7px;box-sizing:border-box"></label>
                    <label><span style="display:block;font-size:12px;margin-bottom:3px">Reason</span><select name="refund_reason" style="width:100%;padding:7px"><option value="requested_by_customer">Requested by customer</option><option value="duplicate">Duplicate charge</option><option value="fraudulent">Fraudulent</option></select></label>
                    <div style="font-size:12px;color:#991b1b">This is a real Stripe refund, not an accounting correction.</div>
                    <button type="submit" style="padding:7px 9px;border:1px solid #be123c;border-radius:6px;background:#be123c;color:#fff">Send Stripe refund</button>
                  </form>
                </details>
              <?php elseif (!$canCorrect && !$canRecordRefund && !$canReverseManual): ?>
                <span style="color:var(--muted);font-size:13px">-</span>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'payments-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="payments-list">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" style="padding:6px;border-radius:8px;border:1px solid #ddd">
            <option value="50" <?php echo $per===50?'selected':''; ?>>50</option>
            <option value="100" <?php echo $per===100?'selected':''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div style="display:flex;gap:8px">
      <?php if($pageN>1): ?><a href="<?php echo $base.'&p='.($pageN-1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Prev</a><?php endif; ?>
      <div style="padding:6px 10px;color:var(--muted)">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo $base.'&p='.($pageN+1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Next</a><?php endif; ?>
    </div>
  </div>
</section>
