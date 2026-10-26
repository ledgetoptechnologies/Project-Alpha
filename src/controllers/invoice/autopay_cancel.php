<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../config/app.php';
require_once __DIR__.'/../../utils/acl.php';
require_once __DIR__.'/../../utils/csrf.php';
require_once __DIR__.'/../../utils/autopay_collection.php';
require_once __DIR__.'/../../utils/audit.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
if(!csrf_validate()){http_response_code(403);echo 'Reload the invoice before canceling collection.';exit;}
$type=(string)($_POST['document_type']??'');$id=(int)($_POST['document_id']??0);$actor=(int)($_SESSION['user']['id']??0);
if(!in_array($type,['invoice','project_invoice'],true)||$id<=0||!$actor||!user_can($pdo,$actor,'invoices.send',request_client_org_id()?:null)){http_response_code(403);exit;}
$table=pa_autopay_table($type);$s=$pdo->prepare("SELECT * FROM {$table} WHERE id=?");$s->execute([$id]);$document=$s->fetch(PDO::FETCH_ASSOC);
if(!$document || !can_access_record($pdo,$type==='invoice'?'invoices':'projects',$type==='invoice'?$id:(int)$document['project_id'],$actor)){http_response_code(403);exit;}
$redirect='/?page='.($type==='invoice'?'invoice/invoice-details':'project/project-invoice-details').'&id='.$id;
try {
    $s=$pdo->prepare('SELECT id FROM autopay_collections WHERE document_type=? AND document_id=?');$s->execute([$type,$id]);$collection=(int)($s->fetchColumn()?:0);
    if(!$collection)throw new DomainException('No automatic collection is available to cancel.');
    pa_autopay_cancel_collection($pdo,$collection,$appConfig);
    audit_log($pdo,'invoice.autopay_cancel',$type,$id,['collection_id'=>$collection],$actor);
    header('Location: '.$redirect.'&autopay_canceled=1');
} catch(Throwable $e) {
    $message=$e instanceof DomainException?$e->getMessage():'Cancellation was not confirmed. Review the collection before accepting another payment.';
    header('Location: '.$redirect.'&'.($type==='invoice'?'error':'payment_err').'='.rawurlencode($message));
}
exit;
