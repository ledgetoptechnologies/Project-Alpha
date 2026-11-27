<?php
// src/controllers/document_date_update_handler.php
// Update document_date to current timestamp

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../services/DocumentPolicy.php';
require_once __DIR__ . '/../utils/document_pricing_carry_forward.php';
require_once __DIR__ . '/../utils/invoice_due_dates.php';

// CSRF is already verified by the router (index.php)
// No need to verify again here

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$requestedDate = trim((string)($_POST['document_date'] ?? date('Y-m-d')));

if ($id <= 0 || !in_array($type, ['quote', 'contract', 'invoice', 'long_term_contract', 'on_demand_contract'])) {
    header('Location: /?page=dashboard&error=Invalid%20document%20type%20or%20ID');
    exit;
}

try {
    $pdo->beginTransaction();
    $policyType=$type==='quote'?'quote':(in_array($type,['contract','long_term_contract','on_demand_contract'],true)?'contract':'invoice');
    DocumentPolicy::assertMutable($pdo,$policyType,$id,'administrative',true);
    require_record_ownership($pdo,['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$policyType],$id);
    switch ($type) {
        case 'quote':
            $pdo->prepare("UPDATE quotes SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            $qt = $pdo->prepare('SELECT quote_type FROM quotes WHERE id=? LIMIT 1');
            $qt->execute([$id]);
            $quoteType = (string)($qt->fetchColumn() ?: '');
            $redirectPage = $quoteType === 'long_term' ? 'quote/long-term-quote-details' : 'quote/quote-details';
            break;

        case 'contract':
            $pdo->prepare("UPDATE contracts SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            $ct = $pdo->prepare('SELECT contract_type FROM contracts WHERE id=? LIMIT 1');
            $ct->execute([$id]);
            $contractType = (string)($ct->fetchColumn() ?: '');
            $redirectPage = $contractType === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
            break;

        case 'invoice':
            invoice_update_document_date($pdo, $id, $requestedDate);
            $redirectPage = 'invoice/invoice-details';
            break;

        case 'long_term_contract':
            $pdo->prepare("UPDATE contracts SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=? AND contract_type='long_term'")
                ->execute([$id]);
            $redirectPage = 'contract/long-term-contract-details';
            break;

        case 'on_demand_contract':
            $pdo->prepare("UPDATE contracts SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=? AND contract_type='on_demand'")
                ->execute([$id]);
            $redirectPage = 'contract/contracts-list&kind=as_needed';
            break;

        default:
            throw new Exception('Invalid document type');
    }

    $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$policyType];
    $organization=$pdo->prepare("SELECT organization_id FROM {$table} WHERE id=?");
    $organization->execute([$id]);
    $organizationId=$organization->fetchColumn();
    pricing_carry_forward_document_revision(
        $pdo,
        $organizationId===null||$organizationId===false?null:(int)$organizationId,
        $policyType,
        $id,
        (int)($_SESSION['user']['id']??0),
    );
    $pdo->commit();
    header("Location: /?page={$redirectPage}&id={$id}&date_updated=1");
    exit;

} catch (DocumentLockedException $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'code'=>'document_locked','message'=>$e->getMessage(),'request_id'=>bin2hex(random_bytes(8))]);exit;
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    $errorMsg=$e instanceof InvalidArgumentException?'Invalid document date.':'Could not update the document date.';
    $requestedRedirect=(string)($_POST['redirect']??'');
    $allowedRedirects=['quote/quote-details','quote/long-term-quote-details','contract/contract-details','contract/long-term-contract-details','contract/contracts-list&kind=as_needed','invoice/invoice-details'];
    $redirectPage=in_array($requestedRedirect,$allowedRedirects,true)?$requestedRedirect:'dashboard';
    header('Location: /?page='.$redirectPage.'&error='.rawurlencode($errorMsg));
    exit;
}
