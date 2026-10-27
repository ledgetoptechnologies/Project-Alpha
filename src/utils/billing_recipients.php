<?php
declare(strict_types=1);

require_once __DIR__ . '/document_organization.php';

/** Support pre-migration read-only views and reduced legacy test fixtures. Writes require the migration. */
function pa_billing_table_exists(PDO $pdo, string $table): bool
{
    $allowed = ['organization_billing_preferences','organization_billing_recipients','invoice_billing_preferences',
        'invoice_billing_recipients','project_invoice_recipients','payment_receipt_deliveries','projects'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Unsupported billing table.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $s = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    } else {
        $s = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    }
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

function pa_billing_organization_preferences(PDO $pdo, int $organizationId): ?array
{
    if (!pa_billing_table_exists($pdo, 'organization_billing_preferences')) return null;
    $s = $pdo->prepare('SELECT copy_working_contact FROM organization_billing_preferences WHERE organization_id=?');
    $s->execute([$organizationId]); $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $s = $pdo->prepare('SELECT r.client_id AS id,c.name,c.email FROM organization_billing_recipients r LEFT JOIN clients c ON c.id=r.client_id WHERE r.organization_id=? ORDER BY r.client_id');
    $s->execute([$organizationId]); $contacts = $s->fetchAll(PDO::FETCH_ASSOC);
    return ['copy_working_contact'=>(bool)$row['copy_working_contact'],
        'recipient_ids'=>array_map('intval', array_column($contacts, 'id')), 'recipients'=>$contacts];
}

function pa_billing_invoice_preferences(PDO $pdo, int $invoiceId): ?array
{
    if (!pa_billing_table_exists($pdo, 'invoice_billing_preferences')) return null;
    $s = $pdo->prepare('SELECT copy_working_contact FROM invoice_billing_preferences WHERE invoice_id=?');
    $s->execute([$invoiceId]); $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $s = $pdo->prepare('SELECT client_id FROM invoice_billing_recipients WHERE invoice_id=? ORDER BY client_id');
    $s->execute([$invoiceId]);
    return ['mode'=>'custom', 'copy_working_contact'=>(bool)$row['copy_working_contact'],
        'recipient_ids'=>array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN))];
}

function pa_billing_contact_ids(array $ids): array
{
    if (count($ids) > 100) throw new InvalidArgumentException('Select at most 100 billing contacts.');
    $clean = [];
    foreach ($ids as $id) {
        if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
            throw new InvalidArgumentException('Select saved billing contacts.');
        }
        $clean[(int)$id] = (int)$id;
    }
    return array_values($clean);
}

/** Only saved, active contacts of the effective organization may receive billing. */
function pa_billing_contacts(PDO $pdo, array $ids, ?int $organizationId, int $workingClientId, bool $strict = false): array
{
    $ids = pa_billing_contact_ids($ids);
    if (!$ids) return [];
    $s = $pdo->prepare('SELECT id,name,email,organization_id,archived FROM clients WHERE id IN (' . implode(',', array_fill(0,count($ids),'?')) . ') ORDER BY id');
    $s->execute($ids); $contacts = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $inScope = $organizationId !== null ? (int)$c['organization_id'] === $organizationId : (int)$c['id'] === $workingClientId;
        if (!$inScope || !empty($c['archived']) || !filter_var(trim((string)$c['email']), FILTER_VALIDATE_EMAIL)) continue;
        $contacts[] = ['id'=>(int)$c['id'], 'name'=>(string)$c['name'], 'email'=>trim((string)$c['email'])];
    }
    if ($strict && count($contacts) !== count($ids)) throw new InvalidArgumentException('Billing contacts must be active contacts with valid emails in this organization.');
    return $contacts;
}

function pa_billing_atomic(PDO $pdo, callable $action): void
{
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try { $action(); if ($owns) $pdo->commit(); }
    catch (Throwable $e) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function pa_billing_organization_save(PDO $pdo, int $organizationId, array $clientIds, bool $copyWorkingContact): void
{
    if (!pa_billing_table_exists($pdo,'organization_billing_preferences')) throw new RuntimeException('Apply the billing-recipient migration before changing billing preferences.');
    $ids = pa_billing_contact_ids($clientIds);
    if (!$ids && !$copyWorkingContact) throw new InvalidArgumentException('Select a billing contact or explicitly include the working contact.');
    pa_billing_atomic($pdo, static function () use ($pdo,$organizationId,$ids,$copyWorkingContact): void {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? '' : ' FOR UPDATE';
        $s=$pdo->prepare('SELECT id FROM organizations WHERE id=?'.$lock); $s->execute([$organizationId]);
        if (!$s->fetchColumn()) throw new InvalidArgumentException('Organization not found.');
        pa_billing_contacts($pdo,$ids,$organizationId,0,true);
        $pdo->prepare('DELETE FROM organization_billing_recipients WHERE organization_id=?')->execute([$organizationId]);
        $pdo->prepare('DELETE FROM organization_billing_preferences WHERE organization_id=?')->execute([$organizationId]);
        $pdo->prepare('INSERT INTO organization_billing_preferences(organization_id,copy_working_contact) VALUES (?,?)')->execute([$organizationId,(int)$copyWorkingContact]);
        $insert=$pdo->prepare('INSERT INTO organization_billing_recipients(organization_id,client_id) VALUES (?,?)');
        foreach($ids as $id) $insert->execute([$organizationId,$id]);
    });
}

function pa_billing_organization_reset(PDO $pdo, int $organizationId): void
{
    pa_billing_atomic($pdo, static function () use ($pdo,$organizationId): void {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $s=$pdo->prepare('SELECT id FROM organizations WHERE id=?'.$lock);$s->execute([$organizationId]);
        if(!$s->fetchColumn())throw new InvalidArgumentException('Organization not found.');
        $pdo->prepare('DELETE FROM organization_billing_recipients WHERE organization_id=?')->execute([$organizationId]);
        $pdo->prepare('DELETE FROM organization_billing_preferences WHERE organization_id=?')->execute([$organizationId]);
    });
}

function pa_billing_invoice_save(PDO $pdo, int $invoiceId, string $mode, array $clientIds, bool $copyWorkingContact): void
{
    if (!in_array($mode,['inherit','custom'],true)) throw new InvalidArgumentException('Invalid invoice billing recipient mode.');
    if (!pa_billing_table_exists($pdo,'invoice_billing_preferences')) throw new RuntimeException('Apply the billing-recipient migration before changing invoice recipients.');
    $ids=pa_billing_contact_ids($clientIds);
    pa_billing_atomic($pdo,static function () use($pdo,$invoiceId,$mode,$ids,$copyWorkingContact):void {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $s=$pdo->prepare('SELECT * FROM invoices WHERE id=?'.$lock);$s->execute([$invoiceId]);$invoice=$s->fetch(PDO::FETCH_ASSOC);
        if(!$invoice || ($invoice['status']??'')!=='draft' || !empty($invoice['finalized_at']))throw new DomainException('Billing recipients can only be changed on a draft invoice.');
        if(($invoice['recipient_presentation_mode']??'named')==='general' && $mode==='custom')throw new DomainException('General-recipient invoices cannot configure automatic email delivery.');
        if($mode==='custom') {
            if(!$ids&&!$copyWorkingContact)throw new InvalidArgumentException('Select a billing contact or explicitly include the working contact.');
            $org=pa_billing_context($pdo,(int)$invoice['client_id'],(int)($invoice['project_id']??0)?:null,(int)($invoice['organization_id']??0)?:null)['organization_id'];
            pa_billing_contacts($pdo,$ids,$org,(int)$invoice['client_id'],true);
        }
        $pdo->prepare('DELETE FROM invoice_billing_recipients WHERE invoice_id=?')->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM invoice_billing_preferences WHERE invoice_id=?')->execute([$invoiceId]);
        if($mode==='custom') {
            $pdo->prepare('INSERT INTO invoice_billing_preferences(invoice_id,copy_working_contact) VALUES (?,?)')->execute([$invoiceId,(int)$copyWorkingContact]);
            $insert=$pdo->prepare('INSERT INTO invoice_billing_recipients(invoice_id,client_id) VALUES (?,?)');
            foreach($ids as $id)$insert->execute([$invoiceId,$id]);
        }
    });
}

/** Match document_organization.php precedence, including legacy standalone fallback. */
function pa_billing_context(PDO $pdo,int $clientId,?int $projectId,?int $organizationId):array
{
    $s=$pdo->prepare('SELECT * FROM clients WHERE id=?');$s->execute([$clientId]);$client=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $project=null;
    if($projectId && pa_billing_table_exists($pdo,'projects')) {
        $s=$pdo->prepare('SELECT * FROM projects WHERE id=?');$s->execute([$projectId]);$project=$s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    $org=(int)($project['organization_id']??$organizationId??$client['organization_id']??0);
    return ['client'=>$client,'project'=>$project,'organization_id'=>$org>0?$org:null];
}

function pa_billing_unique_recipients(array $contacts):array
{
    $result=[];
    foreach($contacts as $c){$key=strtolower(trim((string)$c['email']));if(filter_var($key,FILTER_VALIDATE_EMAIL)&&!isset($result[$key]))$result[$key]=$c;}
    return array_values($result);
}

function pa_billing_preview(PDO $pdo,int $clientId,?int $projectId,?int $organizationId,?array $override=null):array
{
    $context=pa_billing_context($pdo,$clientId,$projectId,$organizationId);$org=$context['organization_id'];$client=$context['client'];
    $result=['source'=>'legacy','configured'=>false,'organization_id'=>$org,'working_contact_id'=>$clientId,'recipients'=>[],'reason'=>null];
    $organizationPolicy=$org?pa_billing_organization_preferences($pdo,$org):null;
    if($override!==null && ($override['mode']??'inherit')==='custom') {
        $policy=$override; $result['source']='invoice';
    } elseif($organizationPolicy!==null && $projectId && pa_billing_table_exists($pdo,'project_invoice_recipients')) {
        // Once organization routing is opted in, retain explicit project recipients.
        // Unconfigured organizations keep all legacy ordinary-invoice routing.
        $s=$pdo->prepare('SELECT r.client_id,r.organization_id,c.organization_id AS client_organization_id,c.name,c.email,c.archived,o.name AS organization_name,o.general_email
            FROM project_invoice_recipients r LEFT JOIN clients c ON c.id=r.client_id LEFT JOIN organizations o ON o.id=r.organization_id
            WHERE r.project_id=? AND (r.client_id IS NOT NULL OR r.organization_id IS NOT NULL) ORDER BY r.sort_order,r.id');
        $s->execute([$projectId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if($rows){
            $result['source']='project';$result['configured']=true;
            foreach($rows as $row){
                if($row['client_id']!==null && empty($row['archived']) && (int)$row['client_organization_id']===$org)$result['recipients'][]=['id'=>(int)$row['client_id'],'name'=>(string)$row['name'],'email'=>(string)$row['email']];
                elseif($row['organization_id']!==null && (int)$row['organization_id']===$org)$result['recipients'][]=['id'=>0,'name'=>(string)$row['organization_name'],'email'=>(string)$row['general_email']];
            }
            $result['recipients']=pa_billing_unique_recipients($result['recipients']);
            if(!$result['recipients'])$result['reason']='No active project billing recipients have a valid email.';
            return $result;
        }
        $policy=$organizationPolicy;
    } else $policy=$organizationPolicy;
    if($policy!==null){
        if($result['source']!=='invoice')$result['source']='organization';$result['configured']=true;
        $result['recipients']=pa_billing_contacts($pdo,$policy['recipient_ids']??[],$org,$clientId);
        if(!empty($policy['copy_working_contact']))$result['recipients']=array_merge($result['recipients'],pa_billing_contacts($pdo,[$clientId],$org,$clientId));
        $result['recipients']=pa_billing_unique_recipients($result['recipients']);
        if(!$result['recipients'])$result['reason']='No active billing recipients have a valid email. Review the organization or invoice billing settings.';
    } else {
        $email=trim((string)($client['email']??''));
        if(filter_var($email,FILTER_VALIDATE_EMAIL))$result['recipients']=[['id'=>$clientId,'name'=>(string)($client['name']??''),'email'=>$email]];
        else $result['reason']='Missing or invalid client email.';
    }
    return $result;
}

function pa_billing_resolve(PDO $pdo,string $type,int $documentId):array
{
    if(!in_array($type,['invoice','project_invoice'],true))throw new InvalidArgumentException('Invalid billing document type.');
    $table=$type==='invoice'?'invoices':'project_invoices';
    $s=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$s->execute([$documentId]);$doc=$s->fetch(PDO::FETCH_ASSOC);
    if(!$doc)return ['source'=>'legacy','configured'=>false,'recipients'=>[],'reason'=>'Invoice not found.','organization_id'=>null,'working_contact_id'=>0];
    $clientId=(int)($doc[$type==='invoice'?'client_id':'primary_client_id']??0);
    $projectId=(int)($doc['project_id']??0)?:null;
    if(!$clientId&&$projectId){$s=$pdo->prepare('SELECT client_id FROM projects WHERE id=?');$s->execute([$projectId]);$clientId=(int)$s->fetchColumn();}
    $result=pa_billing_preview($pdo,$clientId,$projectId,(int)($doc['organization_id']??0)?:null,$type==='invoice'?pa_billing_invoice_preferences($pdo,$documentId):null);
    if(($doc['recipient_presentation_mode']??'named')==='general'){$result['recipients']=[];$result['reason']='General-recipient invoices are shared manually and cannot be emailed.';}
    return $result;
}
