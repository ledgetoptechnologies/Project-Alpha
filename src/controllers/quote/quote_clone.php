<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/mileage.php';
require_once __DIR__ . '/../../utils/quote_numbers.php';
require_once __DIR__ . '/../../utils/document_pricing_adjustments.php';
require_once __DIR__ . '/../../utils/document_organization.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';

$id=(int)($_POST['id']??0);if($id<=0){http_response_code(422);exit('Invalid quote.');}require_record_ownership($pdo,'quotes',$id);
$pdo->beginTransaction();
try{
  $sourceStatement=$pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');$sourceStatement->execute([$id]);$source=$sourceStatement->fetch(PDO::FETCH_ASSOC);if(!$source)throw new RuntimeException('Quote not found.');
  if(in_array(strtolower((string)$source['status']),['draft','pending'],true))throw new RuntimeException('This quote is still editable and does not need to be cloned.');
  $columns=$pdo->query('SHOW COLUMNS FROM quotes')->fetchAll(PDO::FETCH_COLUMN);
  $excluded=['id','public_id','source_version','status','doc_number','revision_number','last_sent_revision','revision_updated_at','created_at','updated_at','approved_at','rejected_at','expired_at'];
  $copyColumns=array_values(array_filter($columns,static fn($column)=>!in_array($column,$excluded,true)&&array_key_exists($column,$source)));
  $values=array_map(static fn($column)=>$source[$column],$copyColumns);
  $copyColumns[]='status';$values[]='draft';$copyColumns[]='revision_number';$values[]=1;$copyColumns[]='created_at';$values[]=date('Y-m-d H:i:s');
  $placeholders=implode(',',array_fill(0,count($copyColumns),'?'));
  $insert=$pdo->prepare('INSERT INTO quotes (`'.implode('`,`',$copyColumns).'`) VALUES ('.$placeholders.')');$insert->execute($values);$newId=(int)$pdo->lastInsertId();
  $effectiveOrganizationId=pa_document_effective_organization_id($pdo,'quote',$newId);$pdo->prepare('UPDATE quotes SET organization_id=? WHERE id=?')->execute([$effectiveOrganizationId,$newId]);
  $pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?')->execute([pa_next_quote_doc_number($pdo,(string)($source['quote_type']??'regular')),$newId]);
  $items=$pdo->prepare('SELECT * FROM quote_items WHERE quote_id=? ORDER BY id');$items->execute([$id]);foreach($items->fetchAll(PDO::FETCH_ASSOC) as $item){unset($item['id']);$item['quote_id']=$newId;$itemColumns=array_keys($item);$itemInsert=$pdo->prepare('INSERT INTO quote_items (`'.implode('`,`',$itemColumns).'`) VALUES ('.implode(',',array_fill(0,count($itemColumns),'?')).')');$itemInsert->execute(array_values($item));}
  $ruleStatement=$pdo->prepare('SELECT * FROM travel_billing_rules WHERE scope_type="quote" AND quote_id=? ORDER BY id DESC LIMIT 1');$ruleStatement->execute([$id]);$rule=$ruleStatement->fetch(PDO::FETCH_ASSOC);if($rule)mileage_save_document_rule($pdo,'quote',$newId,$effectiveOrganizationId,(int)$source['client_id'],(int)($_SESSION['user']['id']??0),$rule);
  pricing_finalize_document_revision($pdo,$effectiveOrganizationId,'quote',$newId,(int)($_SESSION['user']['id']??0),false,(string)($appConfig['workforce_currency']??'USD'));$pdo->commit();header('Location: /?page=quote/quotes-edit&id='.$newId.'&cloned=1');exit;
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();header('Location: /?page=quote/quote-details&id='.$id.'&error='.rawurlencode($error->getMessage()));exit;}
