<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../config/app.php';
require_once __DIR__.'/../../utils/acl.php';
require_once __DIR__.'/../../utils/csrf.php';
require_once __DIR__.'/../../utils/autopay_staff.php';
require_once __DIR__.'/../../utils/project_billing.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new DomainException('POST required.');}
    if(!csrf_validate()){http_response_code(403);throw new DomainException('Reload the invoice form.');}
    $actor=(int)($_SESSION['user']['id']??0);$id=(int)($_POST['invoice_id']??0);
    if(!$actor || !user_can($pdo,$actor,$id?'invoices.edit':'invoices.create',request_client_org_id()?:null)){http_response_code(403);throw new DomainException('Invoice permission required.');}
    if(!pa_autopay_enabled($appConfig)){echo json_encode(['success'=>true,'enabled'=>false]);exit;}
    $document=[];
    if($id){
        if(!can_access_record($pdo,'invoices',$id,$actor)){http_response_code(403);throw new DomainException('Invoice not available.');}
        $s=$pdo->prepare('SELECT * FROM invoices WHERE id=?');$s->execute([$id]);$document=$s->fetch(PDO::FETCH_ASSOC);
        if(!$document)throw new DomainException('Invoice not available.');
    }
    $clientId=(int)($_POST['client_id']??0);$projectId=(int)($_POST['project_id']??$document['project_id']??0);
    if(!$clientId){echo json_encode(['success'=>true,'enabled'=>true,'eligible'=>false,'state'=>'select_client','method'=>'','scope'=>'']);exit;}
    if(!can_access_record($pdo,'clients',$clientId,$actor)){http_response_code(403);throw new DomainException('Client not available.');}
    $s=$pdo->prepare('SELECT id FROM clients WHERE id=?');$s->execute([$clientId]);if(!$s->fetchColumn())throw new DomainException('Client not available.');
    if($projectId){
        if(!can_access_record($pdo,'projects',$projectId,$actor)){http_response_code(403);throw new DomainException('Project not available.');}
        $s=$pdo->prepare('SELECT client_id FROM projects WHERE id=?');$s->execute([$projectId]);if((int)$s->fetchColumn()!==$clientId)throw new DomainException('The project must belong to this client.');
    }
    // Resolve proposed recipients from owned records; never accept a submitted
    // authorization, contract scope or collection mode as authoritative.
    if((int)($document['client_id']??0)!==$clientId)$document['contract_id']=null;
    $document['client_id']=$clientId;
    $document['collection_mode']=$id ? (string)($document['collection_mode']??'direct') : project_invoice_billing_context($pdo,$projectId?:null,$appConfig)['collection_mode'];
    $document['recipient_presentation_mode']=($_POST['recipient_presentation_mode']??$document['recipient_presentation_mode']??'named')==='general'?'general':'named';
    $state=pa_autopay_staff_state($pdo,$document,$appConfig);
    echo json_encode(['success'=>true,'project_billing'=>$document['collection_mode']!=='direct']+array_intersect_key($state,array_flip(['enabled','eligible','state','method','scope'])),JSON_THROW_ON_ERROR);
}catch(DomainException $e){if(http_response_code()<400)http_response_code(409);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>'AutoPay preview is unavailable. The saved invoice will be checked before finalization.']);}
exit;
