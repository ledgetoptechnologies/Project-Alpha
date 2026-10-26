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
$token=(string)($_POST['token']??$_GET['token']??'');$manage=(string)($_POST['manage']??$_GET['manage']??'');$message='';$error='';
try {
    $context=$manage!==''?pa_autopay_management_context($pdo,$manage):pa_autopay_context($pdo,$token);$client=$context['client'];
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
        } elseif($action==='revoke'){
            pa_autopay_revoke($pdo,$client,$_SESSION,(int)($_POST['authorization_id']??0));$message='Authorization revoked. Payments already processing may still complete.';
        } else throw new DomainException('Choose a valid action.');
    }
}catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error='AutoPay could not be updated. Please try again later.';}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$fields='<input type="hidden" name="token" value="'.$esc($token).'"><input type="hidden" name="csrf" value="'.$esc(csrf_token()).'">';
$fields.='<input type="hidden" name="manage" value="'.$esc($manage).'">';
?>
<main class="container" style="max-width:720px;margin:2rem auto;padding:1rem">
<h1>AutoPay — test mode</h1>
<p>Save a card or US bank account securely with Stripe. Project Alpha does not receive card numbers or bank account details. Your signed agreement stays unchanged.</p>
<?php if($error): ?><p role="alert"><?= $esc($error) ?></p><?php endif ?>
<?php if($message): ?><p role="status"><?= $esc($message) ?></p><?php endif ?>
<?php if(isset($client)): ?>
<?php if(!pa_autopay_authority($client,$_SESSION)): ?>
<p>Verify access to the client email before setting up or changing automatic payments.</p>
<form method="post"><?= $fields ?><button name="action" value="send_code">Send verification code</button></form>
<form method="post"><?= $fields ?><label>Verification code <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" required></label><button name="action" value="verify">Verify email</button></form>
<?php else: ?>
<?php if(pa_autopay_enabled($appConfig)): ?>
<form method="post"><?= $fields ?>
<fieldset><legend>Invoices covered</legend>
<?php if($context['contract_id']): ?><label><input type="radio" name="scope" value="agreement" checked> Future invoices for this recurring agreement only</label><br><?php endif ?>
<label><input type="radio" name="scope" value="business" required> All future invoices for my client account with this business</label>
<p><label><input type="checkbox" name="business_consent" value="1"> If I choose all future invoices, I explicitly authorize that broader scope.</label></p></fieldset>
<p><label>Payment method <select name="method_type"><option value="us_bank_account">US bank account (ACH)</option><option value="card">Card</option></select></label></p>
<p><label><input type="checkbox" name="consent" value="1" required> I authorize automatic off-session payment of future finalized invoices within my chosen scope using the method I save with Stripe. Amounts follow my invoices. I may revoke future collection here.</label></p>
<p>Existing invoices are not charged by this setup. Bank verification must complete before AutoPay becomes active. To replace a saved method, complete a new setup; the previous authorization stays active until the replacement succeeds.</p>
<button name="action" value="setup">Continue to Stripe</button></form>
<?php else: ?><p>New automatic payments are paused. You can still revoke an existing authorization.</p><?php endif; ?>
<?php $s=$pdo->prepare('SELECT id,scope,contract_id,status,method_type FROM autopay_enrollments WHERE client_id=? AND installation_id=? AND processor_account_id=? AND status IN ("active","pending") ORDER BY id DESC');$s->execute([$client['id'],getenv('AUTOPAY_INSTALLATION_ID'),getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $auth): ?>
<form method="post"><?= $fields ?><input type="hidden" name="authorization_id" value="<?= (int)$auth['id'] ?>"><p><?= $esc($auth['scope']==='agreement'?'Agreement #'.$auth['contract_id']:'All future invoices') ?> — <?= $esc($auth['status']) ?> (<?= $esc($auth['method_type']) ?>) <button name="action" value="revoke">Revoke authorization</button></p></form>
<?php endforeach ?>
<?php endif;endif ?>
</main>
