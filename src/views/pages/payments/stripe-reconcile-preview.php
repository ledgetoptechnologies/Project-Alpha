<?php
declare(strict_types=1);
require_once __DIR__.'/../../../config/db.php';
require_once __DIR__.'/../../../config/app.php';
require_once __DIR__.'/../../../utils/acl.php';
require_once __DIR__.'/../../../utils/csrf.php';
require_once __DIR__.'/../../../utils/stripe_single_payment_reconciliation.php';

$userId=(int)($_SESSION['user']['id']??0);
if($userId<=0||!in_array(acl_user_role($pdo,$userId),['admin','owner'],true)
    ||!user_can($pdo,$userId,'settings.manage',0)||!user_can($pdo,$userId,'invoices.mark_paid',0)){
    http_response_code(403);echo 'Permission denied.';return;
}
$paymentId=filter_var($_GET['payment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$paymentId||!can_access_record($pdo,'payments',$paymentId,$userId)){
    http_response_code(404);echo 'Payment not found or permission denied.';return;
}
$stripe=StripeService::fromAppConfig($appConfig);
$report=$stripe?stripe_preview_existing_payment($pdo,$stripe,$paymentId):[
    'eligible'=>false,'reason'=>'provider_unavailable','message'=>stripe_reconciliation_reason('provider_unavailable')];
$token=stripe_reconciliation_preview_token($_SESSION,$paymentId,$report);
$money=static fn(?int $cents):string=>$cents===null?'Not verified':number_format($cents/100,2).' '.($report['currency']??'');
$rows=[
    'Invoice principal'=>$report['amounts']['payment_cents']??null,
    'Customer surcharge'=>$report['amounts']['surcharge_cents']??null,
    'Gross captured'=>$report['gross_cents']??null,
    'Stripe processor fee'=>$report['processor_fee_cents']??null,
    'Stripe net proceeds'=>$report['processor_net_cents']??null,
    'Stripe refunded amount'=>$report['refunded_cents']??null,
];
?>
<section style="max-width:760px;margin:auto">
  <a href="/?page=payments/payments-list">Back to payments</a>
  <h2>Stripe settlement preview</h2>
  <p>Existing payment #<?php echo (int)$paymentId; ?>. This preview reads Stripe and does not change the payment, invoice, public links or financial records.</p>
  <?php if(isset($report['invoice_label'])): ?><p><strong><?php echo htmlspecialchars($report['client_name']); ?></strong> · Invoice <?php echo htmlspecialchars($report['invoice_label']); ?> · Expected principal <?php echo htmlspecialchars($report['invoice_total'].' '.$report['invoice_currency']); ?></p><?php endif; ?>
  <p role="status"><strong><?php echo htmlspecialchars($report['message']); ?></strong><br>Check: <?php echo htmlspecialchars($report['reason']); ?></p>
  <p>These are fresh Stripe observations. Only a fully verified preview can be confirmed; a held observation is not a recorded PA payment.</p>
  <dl style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:18px;background:#fff;border:1px solid #ddd;border-radius:10px">
    <?php foreach($rows as $label=>$cents): ?><dt><?php echo htmlspecialchars($label); ?></dt><dd style="margin:0;text-align:right"><?php echo htmlspecialchars($money($cents)); ?></dd><?php endforeach; ?>
    <dt>Stripe dispute reported</dt><dd style="margin:0;text-align:right"><?php echo !isset($report['disputed'])?'Not verified':($report['disputed']?'Yes':'No'); ?></dd>
  </dl>
  <?php if($token!==null): ?>
    <p>Confirming credits only the verified principal to this invoice and records the customer surcharge separately. It updates this existing payment in place. No new charge, refund, email or payment row is created.</p>
    <form method="post" action="/?page=payments/stripe-reconcile">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="payment_id" value="<?php echo (int)$paymentId; ?>">
      <input type="hidden" name="preview_token" value="<?php echo htmlspecialchars($token); ?>">
      <button type="submit" style="padding:10px 14px;border:0;border-radius:8px;background:#f05000;color:#fff;font-weight:700">Confirm verified reconciliation</button>
    </form>
    <p>The confirmation expires after five minutes. Stripe evidence and locked records are checked again when you confirm.</p>
  <?php endif; ?>
</section>
