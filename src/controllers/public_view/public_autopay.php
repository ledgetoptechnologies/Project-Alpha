<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../config/app.php';
require_once __DIR__.'/../../utils/csrf.php';
require_once __DIR__.'/../../utils/rate_limiter.php';
require_once __DIR__.'/../../utils/autopay_enrollment.php';
require_once __DIR__.'/../../utils/security_headers.php';
send_security_headers(true);
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
if(!pa_autopay_test_context_enabled($appConfig)){http_response_code(404);echo 'AutoPay is unavailable.';exit;}
$token=(string)($_POST['token']??$_GET['token']??'');$manage=(string)($_POST['manage']??$_GET['manage']??'');$reviewToken=(string)($_POST['review']??$_GET['review']??'');$message='';$error='';
try {
    if($reviewToken!==''){
        require_once __DIR__.'/../../utils/autopay_review.php';
        $context=pa_autopay_invoice_review_context($pdo,$reviewToken,$appConfig);
    }else $context=$manage!==''?pa_autopay_management_context($pdo,$manage):pa_autopay_context($pdo,$token);
    $client=$context['client'];
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        if(!csrf_validate()){http_response_code(403);throw new DomainException('Invalid request. Reload this page.');}
        if(!rate_limit_check($pdo,'autopay_manage',15,900,false)){http_response_code(429);throw new DomainException('Please wait before trying again.');}
        $action=(string)($_POST['action']??'');
        if($action==='send_code'){
            require_once __DIR__.'/../../services/EmailService.php';
            $_SESSION['autopay_challenge']=pa_autopay_send_code($pdo,$client,session_id(),static function($email,$code){
                [$ok,$err]=EmailService::sendEmail($email,'Verify your AutoPay request','<p>Your AutoPay verification code is <strong>'.htmlspecialchars($code).'</strong>. It expires in ten minutes. Do not share it.</p>');
                if(!$ok)throw new DomainException('The verification email could not be sent. Please contact the business.');
            });$message='A verification code was sent to the client email on file.';
        } elseif($action==='verify'){
            if(!pa_autopay_verify_code($pdo,$client,(int)($_SESSION['autopay_challenge']??0),(string)($_POST['code']??''),session_id()))throw new DomainException('Invalid or expired verification code.');
            $_SESSION['autopay_verified']=['client_id'=>(int)$client['id'],'email'=>$client['email'],'until'=>time()+600,'verified_at'=>date('c'),'proof'=>bin2hex(random_bytes(16))];$message='Email verified. Choose what you want AutoPay to cover.';
        } elseif($action==='setup'){
            $stripe=StripeService::fromAppConfig($appConfig);
            $result=pa_autopay_enroll($pdo,$context,$_SESSION,$_POST,$appConfig,fn($method,$endpoint,$data,$key)=>$stripe->autoPayRequest($method,$endpoint,$data,$key));
            header('Location: '.$result['url'],true,303);exit;
        } elseif(in_array($action,['skip_invoice','question_invoice'],true)){
            if(!pa_autopay_authority($client,$_SESSION)){http_response_code(403);throw new DomainException('Verify your email before changing this invoice payment.');}
            if(empty($context['review']) || ($_POST['confirm_invoice_action']??'')!=='1')throw new DomainException('Confirm the change for this invoice.');
            $review=$context['review'];
            $note=trim((string)($_POST['question']??''));
            if($action==='question_invoice' && ($note==='' || mb_strlen($note)>1000))throw new DomainException('Describe your invoice question in 1 to 1000 characters.');
            pa_autopay_invoice_review_action($pdo,(string)$review['document_type'],(int)$review['document_id'],(int)$client['id'],$action==='skip_invoice'?'skip':'question',$appConfig,$note);
            $context=pa_autopay_invoice_review_context($pdo,$reviewToken,$appConfig);
            $message=$action==='skip_invoice'?'Automatic payment is off for this invoice only. Please pay it manually. AutoPay for future invoices is unchanged.':'Automatic collection is on hold while the business reviews your invoice question.';
        } elseif(in_array($action,['revoke','revoke_all'],true)){
            if(($_POST['confirm_disable']??'')!=='1')throw new DomainException('Confirm that you want to turn off automatic payments.');
            $authorizationId=$action==='revoke_all'?0:(int)($_POST['authorization_id']??0);
            if($action==='revoke' && $authorizationId<=0)throw new DomainException('Choose the authorization you want to turn off.');
            pa_autopay_revoke($pdo,$client,$_SESSION,$authorizationId);
            $message=$action==='revoke_all'
                ?'AutoPay is off for all agreements and future invoices on this client account. Payments already submitted may still complete. Invoices remain due and can be paid manually.'
                :'AutoPay is off for this billing scope. Other authorizations may still cover your invoices. Payments already submitted may still complete.';
        } else throw new DomainException('Choose a valid action.');
    }
}catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error='AutoPay could not be updated. Please try again later.';}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$fields='<input type="hidden" name="token" value="'.$esc($token).'"><input type="hidden" name="csrf" value="'.$esc(csrf_token()).'">';
$fields.='<input type="hidden" name="manage" value="'.$esc($manage).'">';
$fields.='<input type="hidden" name="review" value="'.$esc($reviewToken).'">';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage AutoPay — Project Alpha</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#172b45;font:16px/1.6 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.container{width:min(100% - 32px,720px);margin:40px auto;padding:32px;background:#fff;border:1px solid #d9e2ee;border-radius:16px;box-shadow:0 8px 28px #172b4508}h1{margin:0 0 12px;font-size:clamp(1.65rem,5vw,2rem);line-height:1.25;letter-spacing:-.025em}h2{margin:28px 0 12px;font-size:1.15rem}p{margin:12px 0 18px}form{margin:18px 0}fieldset{min-width:0;margin:0;padding:18px;border:1px solid #cbd7e6;border-radius:10px}legend{padding:0 6px;font-weight:650}label{display:block;cursor:pointer}fieldset label{padding:7px 0}input[type=checkbox],input[type=radio]{width:18px;height:18px;margin:0 8px 0 0;accent-color:#245bb1;vertical-align:-3px}input[name=code],select{display:block;width:100%;max-width:360px;margin:8px 0 16px;padding:11px 12px;border:1px solid #97abc3;border-radius:8px;background:#fff;color:#172b45;font:inherit}input[name=code]{letter-spacing:.15em;font-variant-numeric:tabular-nums}button{min-height:44px;padding:11px 18px;border:1px solid #245bb1;border-radius:8px;background:#245bb1;color:#fff;font:inherit;font-weight:600;cursor:pointer}button:hover{background:#19478e;border-color:#19478e}button:focus-visible,input:focus-visible,select:focus-visible,a:focus-visible{outline:3px solid #df9f25;outline-offset:3px}button[value=send_code],button[value=revoke]{background:#fff;color:#245bb1}button[value=send_code]:hover,button[value=revoke]:hover{background:#edf3fd}.notice{padding:14px 16px;border:1px solid;border-radius:8px}.notice-error{background:#fff2f2;border-color:#e8b0b0;color:#8c2525}.notice-status{background:#edf6ff;border-color:#a7c8ed;color:#174b80}.authorization{padding:16px;border:1px solid #d9e2ee;border-radius:10px;background:#f8fafc}.authorization p{margin:0 0 12px}.help{color:#50637b;font-size:.95rem}@media(max-width:480px){.container{margin:16px auto;padding:22px 18px;border-radius:12px}fieldset{padding:12px}button{width:100%}input[name=code],select{max-width:none}.authorization{padding:12px}}
</style>
</head>
<body>
<main class="container">
<h1>AutoPay — test mode</h1>
<p>Save a card or US bank account securely with Stripe. Full card and bank account numbers stay with Stripe. Your signed agreement stays unchanged.</p>
<?php if($error): ?><p class="notice notice-error" role="alert"><?= $esc($error) ?></p><?php endif ?>
<?php if($message): ?><p class="notice notice-status" role="status"><?= $esc($message) ?></p><?php endif ?>
<?php if(isset($client)): ?>
<?php if(!pa_autopay_authority($client,$_SESSION)): ?>
<h2>Verify your email</h2>
<p>Verify access to the client email before setting up or changing automatic payments.</p>
<form method="post"><?= $fields ?><button name="action" value="send_code">Send verification code</button></form>
<form method="post"><?= $fields ?><label>Verification code <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" required></label><button name="action" value="verify">Verify email</button></form>
<?php else: ?>
<?php if(!empty($context['review'])): $invoiceReview=$context['review'];
$publicReviewStatus=match(true){$invoiceReview['state']==='processing'=>'Payment processing',$invoiceReview['state']==='succeeded'=>'Paid',$invoiceReview['status']==='skipped'=>'Manual payment',$invoiceReview['state']==='failed'=>'Automatic payment is not queued',$invoiceReview['state']==='held'=>'On hold',default=>str_replace('_',' ',(string)$invoiceReview['status'])}; ?>
<section class="authorization" id="invoice-review" aria-label="Invoice automatic payment">
<h2>Review this invoice payment</h2>
<p><strong><?= $esc(strtoupper((string)$invoiceReview['currency']).' '.number_format((int)$invoiceReview['amount_cents']/100,2)) ?></strong> · <?= $esc($publicReviewStatus) ?></p>
<?php if(!empty($invoiceReview['scheduled_at']) && $invoiceReview['status']==='scheduled' && !empty($invoiceReview['can_act'])): ?><p>Scheduled on or after <?= $esc((new DateTimeImmutable((string)$invoiceReview['scheduled_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)($appConfig['timezone']??'UTC')))->format('F j, Y g:i a T')) ?>. If everything is correct, no action is needed.</p><?php endif; ?>
<?php if($invoiceReview['status']==='skipped'): ?><p>AutoPay is off for this invoice. Please pay it manually. Future invoices retain your existing AutoPay setting.</p><?php elseif(in_array($invoiceReview['status'],['question','held'],true)): ?><p>Automatic collection is on hold for staff review.</p><?php endif; ?>
<p>These controls affect only this invoice. Payments already submitted may still complete; contact the business before paying again.</p>
<?php if(!empty($invoiceReview['can_act'])): ?>
<form method="post"><?= $fields ?><label><input type="checkbox" name="confirm_invoice_action" value="1" required> Leave this invoice for manual payment.</label><p><button name="action" value="skip_invoice">Don’t automatically pay this invoice</button></p></form>
<form method="post"><?= $fields ?><label>Your invoice question <textarea name="question" maxlength="1000" required rows="4" style="display:block;width:100%;font:inherit;padding:10px"></textarea></label><label><input type="checkbox" name="confirm_invoice_action" value="1" required> Hold automatic collection while this question is reviewed.</label><p><button name="action" value="question_invoice">Question this invoice</button></p></form>
<?php endif; ?>
</section>
<?php endif; ?>
<?php
$s=$pdo->prepare('SELECT id,scope,contract_id,status,method_type FROM autopay_enrollments WHERE client_id=? AND installation_id=? AND processor_account_id=? AND livemode=0 AND status IN ("active","pending") ORDER BY id DESC');
$s->execute([$client['id'],getenv('AUTOPAY_INSTALLATION_ID'),getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);$authorizations=$s->fetchAll(PDO::FETCH_ASSOC);
?>
<h2 id="autopay-account">Manage automatic payments</h2>
<?php if($authorizations): ?>
<p>Turn off all AutoPay to stop future automatic collection for every agreement and invoice on this client account. Payments already submitted may still complete. Turning off AutoPay does not cancel your agreement or any invoice balance.</p>
<form method="post" class="authorization"><?= $fields ?>
<label><input type="checkbox" name="confirm_disable" value="1" required> I want to turn off all automatic payments for this client account.</label>
<p><button name="action" value="revoke_all">Turn off all AutoPay</button></p>
</form>
<?php else: ?><p class="notice notice-status">AutoPay is off. There are no active or pending authorizations for this client account. Payments already submitted may still complete.</p><?php endif; ?>
<?php foreach($authorizations as $auth): ?>
<form method="post" class="authorization"><?= $fields ?><input type="hidden" name="authorization_id" value="<?= (int)$auth['id'] ?>"><p><strong><?= $esc($auth['scope']==='agreement'?'Agreement #'.$auth['contract_id']:'All future invoices') ?></strong><br><?= $esc(ucfirst($auth['status'])) ?> · <?= $esc($auth['method_type']==='us_bank_account'?'US bank account (ACH)':'Card') ?></p><label><input type="checkbox" name="confirm_disable" value="1" required> Turn off this billing scope only. Other authorizations may still apply.</label><p><button name="action" value="revoke">Turn off this billing scope</button></p></form>
<?php endforeach ?>
<?php if(pa_autopay_enabled($appConfig)): ?>
<h2>Set up AutoPay for your client account</h2>
<form method="post"><?= $fields ?>
<fieldset><legend>Invoices covered</legend>
<input type="hidden" name="scope" value="business"><input type="hidden" name="consent" value="1">
<p>AutoPay covers all future invoices for your client account with this business, including regular, on-demand, recurring and project invoices.</p>
<p><label><input type="checkbox" name="business_consent" value="1" required> I authorize this account-wide coverage. Each invoice email will show its amount and charge date, with at least seven calendar days after delivery to review, skip or question that invoice. I can turn off all AutoPay.</label></p></fieldset>
<p><label>Payment method <select name="method_type"><option value="us_bank_account">US bank account (ACH)</option><option value="card">Card</option></select></label></p>
<p class="help">Existing invoices are not charged by this setup. Bank verification must complete before AutoPay becomes active. To replace a saved method, complete a new setup; the previous authorization stays active until the replacement succeeds.</p>
<button name="action" value="setup">Continue to Stripe</button></form>
<?php else: ?><p>New automatic payments are paused. You can still revoke an existing authorization.</p><?php endif; ?>
<?php endif;endif ?>
</main>
</body>
</html>
