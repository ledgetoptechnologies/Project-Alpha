<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/workforce_availability.php';
require_once __DIR__ . '/../../services/PortalProjectionMutationService.php';
require_once __DIR__ . '/../../services/PortalIntegrationContract.php';
require_once __DIR__ . '/../../services/PortalSourceVersion.php';

$redirect = '/?page=settings&tab=item-library';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_validate()) {
    http_response_code(403);
    header("Location: {$redirect}&error=" . rawurlencode('The request could not be verified.'));
    exit;
}

$action = (string)($_POST['action'] ?? '');
$types = ['service','fee','bundle'];
$billingUnits = ['each','hour','day','mile','project'];
$pricingModels = ['fixed','hourly','base_overage'];
$methods = ['nonpayable','hourly','fixed','base_overage','percentage'];
$bases = ['gross_line','net_line','cash_collected'];
$triggers = ['completed_approved','delivered','invoice_paid','manual_release'];
$clientBillingTreatments = ['hourly','fixed_price_included','base_overage','internal'];
$userId = (int)($_SESSION['user']['id'] ?? 0);

$uniqueWorkActivityCode = static function (PDO $pdo, string $name): string {
    $base = trim(strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $name)), '_');
    $base = substr($base !== '' ? $base : 'WORK_ACTIVITY', 0, 54);
    $candidate = $base;
    $suffix = 1;
    $check = $pdo->prepare('SELECT 1 FROM work_types WHERE code=?');
    while (true) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) return $candidate;
        $candidate = substr($base, 0, 54) . '_' . ++$suffix;
    }
};

try {
    if ($action === 'purge') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new DomainException('Invalid service ID.');
        $pdo->beginTransaction();
        $exists = $pdo->prepare('SELECT item_name FROM item_library WHERE id=? FOR UPDATE');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) throw new DomainException('Service not found.');
        $referenceChecks = [
            ['quote_items', 'item_library_id'],
            ['contract_items', 'item_library_id'],
            ['invoice_items', 'item_library_id'],
            ['catalog_bundle_items', 'child_item_library_id'],
        ];
        foreach ($referenceChecks as [$table, $column]) {
            $reference = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1");
            $reference->execute([$id]);
            if ($reference->fetchColumn()) {
                throw new DomainException('This service has already been used. Deactivate it to preserve historical documents and Jobs.');
            }
        }
        $jobReference = $pdo->prepare(
            'SELECT 1 FROM job_work_components jwc
             WHERE jwc.item_library_id=? OR jwc.catalog_work_component_id IN (
               SELECT id FROM catalog_work_components WHERE item_library_id=?
             ) LIMIT 1'
        );
        $jobReference->execute([$id, $id]);
        if ($jobReference->fetchColumn()) {
            throw new DomainException('This service has already been used by a Job. Deactivate it to preserve history.');
        }
        if (!pa_workforce_enabled()) {
            $retainedWork = $pdo->prepare('SELECT 1 FROM catalog_work_components WHERE item_library_id=? LIMIT 1');
            $retainedWork->execute([$id]);
            if ($retainedWork->fetchColumn()) {
                throw new DomainException('This service has retained work history. Deactivate it instead.');
            }
        }
        $linkedActivity = $pdo->prepare('SELECT work_type_id FROM catalog_work_components WHERE item_library_id=? AND is_active=1 LIMIT 1 FOR UPDATE');
        $linkedActivity->execute([$id]);
        $linkedWorkTypeId = (int)($linkedActivity->fetchColumn() ?: 0);
        if ($linkedWorkTypeId > 0) {
            foreach ([['work_time_entries','work_type_id'],['job_work_components','work_type_id'],['worker_compensation_rules','work_type_id']] as [$table,$column]) {
                $reference = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1");
                $reference->execute([$linkedWorkTypeId]);
                if ($reference->fetchColumn()) throw new DomainException('The linked Work Activity has already been used. Deactivate the pair to preserve workforce history.');
            }
        }
        $pdo->prepare('DELETE FROM catalog_bundle_items WHERE bundle_item_library_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM catalog_work_components WHERE item_library_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM item_library WHERE id=?')->execute([$id]);
        if ($linkedWorkTypeId > 0) $pdo->prepare('DELETE FROM work_types WHERE id=?')->execute([$linkedWorkTypeId]);
        (new App\Services\PortalProjectionMutationService())->queueCatalogChanges($pdo);
        $pdo->commit();
        header("Location: {$redirect}&purged=1");
        exit;
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new DomainException('Invalid service ID.');
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE item_library SET is_active=0 WHERE id=?')->execute([$id]);
        $linked = $pdo->prepare('SELECT work_type_id FROM catalog_work_components WHERE item_library_id=? AND is_active=1 FOR UPDATE');
        $linked->execute([$id]);
        $linkedWorkTypeIds = array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN));
        if (pa_workforce_enabled() && $linkedWorkTypeIds) {
            $placeholders = implode(',', array_fill(0, count($linkedWorkTypeIds), '?'));
            $pdo->prepare("UPDATE work_types SET is_active=0 WHERE id IN ({$placeholders})")->execute($linkedWorkTypeIds);
        }
        (new App\Services\PortalProjectionMutationService())->queueCatalogChanges($pdo);
        $pdo->commit();
        header("Location: {$redirect}&deleted=1");
        exit;
    }
    if (!in_array($action, ['create','update'], true)) throw new DomainException('Invalid action.');

    $id = (int)($_POST['id'] ?? 0);
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $unitPrice = round((float)($_POST['unit_price'] ?? 0), 2);
    $pricingModel = (string)($_POST['client_pricing_model'] ?? (((string)($_POST['billing_unit'] ?? 'each')) === 'hour' ? 'hourly' : 'fixed'));
    $clientIncludedMinutes = trim((string)($_POST['client_included_minutes'] ?? '')) === '' ? null : max(0, (int)$_POST['client_included_minutes']);
    $clientOverageRate = trim((string)($_POST['client_overage_rate'] ?? '')) === '' ? null : max(0, (float)$_POST['client_overage_rate']);
    $pricingCurrency = strtoupper(trim((string)($_POST['pricing_currency'] ?? 'USD')));
    $typicalMinimum=trim((string)($_POST['portal_pricing_typical_minimum']??''));$typicalMaximum=trim((string)($_POST['portal_pricing_typical_maximum']??''));
    $typicalMinimum=$typicalMinimum===''?null:$typicalMinimum;$typicalMaximum=$typicalMaximum===''?null:$typicalMaximum;
    $entryType = (string)($_POST['entry_type'] ?? 'service');
    $billingUnit = (string)($_POST['billing_unit'] ?? 'each');
    $fulfillmentNotes = trim((string)($_POST['fulfillment_notes'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $portalRequestable=isset($_POST['portal_requestable'])?1:0;$portalSummary=trim((string)($_POST['portal_summary']??''));$portalCategory=trim((string)($_POST['portal_category']??''));$portalDisplayOrder=max(0,min(1000000,(int)($_POST['portal_display_order']??0)));$portalGeometry=(string)($_POST['portal_geometry_requirement']??'optional');
    try{$portalQuestions=json_decode(trim((string)($_POST['portal_questions_json']??''))?:'[]',true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new DomainException('Portal questions must be valid JSON.');}
    if ($itemName === '') throw new DomainException('Service name is required.');
    if ($action === 'update' && $id <= 0) throw new DomainException('Invalid service ID.');
    if ($unitPrice < 0) throw new DomainException('Client price cannot be negative.');
    if (!in_array($entryType, $types, true) || !in_array($billingUnit, $billingUnits, true) || !in_array($pricingModel, $pricingModels, true)) {
        throw new DomainException('Choose valid service settings.');
    }
    if($portalRequestable&&$entryType!=='service')throw new DomainException('Only individual services may be published to a portal.');if(!in_array($portalGeometry,['none','optional','required'],true))throw new DomainException('Choose a valid portal geometry requirement.');if(!is_array($portalQuestions)||!array_is_list($portalQuestions))throw new DomainException('Portal questions must be a JSON list.');if($portalRequestable&&$portalCategory==='')throw new DomainException('A client-safe portal category is required.');
    if (!preg_match('/^[A-Z]{3}$/', $pricingCurrency)) throw new DomainException('Pricing currency must use a three-letter code.');
    if(($typicalMinimum===null)!==($typicalMaximum===null))throw new DomainException('Portal typical pricing requires both a minimum and maximum.');
    if($typicalMinimum!==null&&(preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',$typicalMinimum)!==1||preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',(string)$typicalMaximum)!==1||(float)$typicalMinimum>(float)$typicalMaximum))throw new DomainException('Choose a valid portal typical pricing range.');
    if ($pricingModel === 'base_overage' && ($clientIncludedMinutes === null || $clientOverageRate === null)) {
        throw new DomainException('Base-plus-overage pricing requires included minutes and an hourly overage rate.');
    }
    if ($pricingModel !== 'base_overage') { $clientIncludedMinutes = null; $clientOverageRate = null; }
    if ($pricingModel === 'hourly') $billingUnit = 'hour';
    $components = [];
    if (pa_workforce_enabled()) {
    $components = json_decode((string)($_POST['components_json'] ?? '[]'), true);
    if (!is_array($components)) throw new DomainException('Work Activity settings are invalid.');
    if (array_key_exists('activity_link_mode', $_POST)) {
        $linkMode = (string)$_POST['activity_link_mode'];
        if (!in_array($linkMode, ['none','new','existing'], true)) throw new DomainException('Choose a valid Work Activity link.');
        if ($entryType === 'bundle' && $linkMode !== 'none') throw new DomainException('Packages use the Work Activity links from their contained Services.');
        $components = [];
        if ($linkMode !== 'none') {
            $requestedWorkType = $linkMode === 'new' ? 'new' : (string)max(0, (int)($_POST['linked_work_type_id'] ?? 0));
            if ($requestedWorkType !== 'new' && (int)$requestedWorkType <= 0) throw new DomainException('Choose an available Work Activity.');
            $components[] = [
                'id' => max(0, (int)($_POST['linked_component_id'] ?? 0)),
                'work_type_id' => $requestedWorkType,
                'name' => $itemName,
                'description' => null,
                'quantity_behavior' => 'per_line',
                'expected_duration_minutes' => null,
                'assignment_required' => 1,
                'client_billing_treatment' => match ($pricingModel) {
                    'hourly' => 'hourly',
                    'base_overage' => 'base_overage',
                    default => 'fixed_price_included',
                },
                'client_billing_rate' => $pricingModel === 'hourly' ? $unitPrice : null,
                'client_included_minutes' => $clientIncludedMinutes,
                'client_overage_rate' => $clientOverageRate,
                'client_billing_currency' => $pricingCurrency,
                // Compatibility snapshots only. Work Activity/effective-dated rules remain authoritative for pay.
                'compensation_method' => 'nonpayable',
                'percentage_basis' => 'net_line',
                'eligibility_trigger' => 'completed_approved',
                'currency' => $pricingCurrency,
            ];
        }
    }
    } // Ignore stale Workforce controls without changing retained activity links.
    $bundleItems = json_decode((string)($_POST['bundle_items_json'] ?? '[]'), true);
    if (!is_array($bundleItems)) throw new DomainException('Bundle contents are invalid.');

    $pdo->beginTransaction();
    $portalPublicId=null;if($action==='update'){$existingPortal=$pdo->prepare('SELECT portal_public_id FROM item_library WHERE id=? FOR UPDATE');$existingPortal->execute([$id]);$portalPublicId=$existingPortal->fetchColumn()?:null;}if($portalRequestable&&$portalPublicId===null)$portalPublicId=bin2hex(random_bytes(16));
    $portalVisible=['publicId'=>(string)($portalPublicId??'unpublished-service'),'name'=>$itemName,'summary'=>$portalSummary!==''?$portalSummary:null,'category'=>$portalCategory!==''?$portalCategory:'Uncategorized','displayOrder'=>$portalDisplayOrder,'geometryRequirement'=>$portalGeometry,'questions'=>$portalQuestions];$portalVersion=App\Services\PortalSourceVersion::from($portalVisible);if($portalRequestable)App\Services\PortalIntegrationContract::validateCatalogItem(['publicId'=>$portalVisible['publicId'],'sourceVersion'=>$portalVersion]+array_slice($portalVisible,1,null,true));
    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO item_library (item_name,description,entry_type,unit_price,client_pricing_model,client_included_minutes,client_overage_rate,pricing_currency,portal_pricing_typical_minimum,portal_pricing_typical_maximum,billing_unit,tax_behavior,fulfillment_notes,category,is_active,portal_public_id,portal_source_version,portal_requestable,portal_summary,portal_category,portal_display_order,portal_geometry_requirement,portal_questions_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$itemName,$description ?: null,$entryType,$unitPrice,$pricingModel,$clientIncludedMinutes,$clientOverageRate,$pricingCurrency,$typicalMinimum,$typicalMaximum,$billingUnit,'inherit',$fulfillmentNotes ?: null,$billingUnit === 'hour' ? 'Hourly' : null,$isActive,$portalPublicId,$portalVersion,$portalRequestable,$portalSummary!==''?$portalSummary:null,$portalCategory!==''?$portalCategory:null,$portalDisplayOrder,$portalGeometry,json_encode($portalQuestions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            'UPDATE item_library SET item_name=?,description=?,entry_type=?,unit_price=?,client_pricing_model=?,client_included_minutes=?,client_overage_rate=?,pricing_currency=?,portal_pricing_typical_minimum=?,portal_pricing_typical_maximum=?,billing_unit=?,tax_behavior=?,fulfillment_notes=?,category=?,is_active=?,portal_public_id=?,portal_source_version=?,portal_requestable=?,portal_summary=?,portal_category=?,portal_display_order=?,portal_geometry_requirement=?,portal_questions_json=? WHERE id=?'
        );
        $stmt->execute([$itemName,$description ?: null,$entryType,$unitPrice,$pricingModel,$clientIncludedMinutes,$clientOverageRate,$pricingCurrency,$typicalMinimum,$typicalMaximum,$billingUnit,'inherit',$fulfillmentNotes ?: null,$billingUnit === 'hour' ? 'Hourly' : null,$isActive,$portalPublicId,$portalVersion,$portalRequestable,$portalSummary!==''?$portalSummary:null,$portalCategory!==''?$portalCategory:null,$portalDisplayOrder,$portalGeometry,json_encode($portalQuestions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$id]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM item_library WHERE id=?'); $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new DomainException('Service not found.');
        }
    }

    if (pa_workforce_enabled()) {
    $retained = [];
    foreach (array_values($components) as $order => $component) {
        if (!is_array($component)) continue;
        $componentId = (int)($component['id'] ?? 0);
        $requestedWorkType = (string)($component['work_type_id'] ?? '');
        $workTypeId = ctype_digit($requestedWorkType) ? (int)$requestedWorkType : 0;
        $name = trim((string)($component['name'] ?? ''));
        $clientBillingTreatment = (string)($component['client_billing_treatment'] ?? 'fixed_price_included');
        $clientBillingRate = ($component['client_billing_rate'] ?? '') === '' ? null : (float)$component['client_billing_rate'];
        $clientIncludedMinutes = ($component['client_included_minutes'] ?? '') === '' ? null : (int)$component['client_included_minutes'];
        $clientOverageRate = ($component['client_overage_rate'] ?? '') === '' ? null : (float)$component['client_overage_rate'];
        $clientBillingCurrency = strtoupper(trim((string)($component['client_billing_currency'] ?? 'USD')));
        $method = (string)($component['compensation_method'] ?? 'nonpayable');
        $basis = (string)($component['percentage_basis'] ?? 'net_line');
        $trigger = (string)($component['eligibility_trigger'] ?? 'completed_approved');
        $quantityBehavior = in_array(($component['quantity_behavior'] ?? ''), ['per_line','per_unit','fixed'], true) ? $component['quantity_behavior'] : 'per_line';
        if ($name === '') throw new DomainException('Every service activity needs a name.');
        if ($workTypeId <= 0 && $requestedWorkType !== 'new') throw new DomainException('Choose an existing Work Activity or create a matching one.');
        if (!in_array($clientBillingTreatment, $clientBillingTreatments, true)) throw new DomainException('A service activity has an invalid client billing treatment.');
        if (!preg_match('/^[A-Z]{3}$/', $clientBillingCurrency)) throw new DomainException('Client billing currency must use a three-letter code.');
        foreach ([$clientBillingRate,$clientOverageRate] as $clientRate) if ($clientRate !== null && $clientRate < 0) throw new DomainException('Client billing rates cannot be negative.');
        if ($clientIncludedMinutes !== null && $clientIncludedMinutes < 0) throw new DomainException('Included client minutes cannot be negative.');
        if ($clientBillingTreatment === 'base_overage' && ($clientIncludedMinutes === null || $clientOverageRate === null)) throw new DomainException('Base-plus-overage client billing requires included minutes and an hourly overage rate.');
        if ($clientBillingTreatment !== 'hourly') $clientBillingRate = null;
        if ($clientBillingTreatment !== 'base_overage') { $clientIncludedMinutes = null; $clientOverageRate = null; }
        if (!in_array($clientBillingTreatment, ['hourly','base_overage'], true)) $clientBillingCurrency = 'USD';
        if (!in_array($method, $methods, true) || !in_array($basis, $bases, true) || !in_array($trigger, $triggers, true)) throw new DomainException('A Work Activity has an invalid compensation rule.');
        if ($basis === 'cash_collected' && $method === 'percentage' && $trigger !== 'invoice_paid') throw new DomainException('Cash-collected percentages require the invoice-paid trigger.');

        if ($requestedWorkType === 'new') {
            $existingActivity = $pdo->prepare('SELECT id FROM work_types WHERE LOWER(name)=LOWER(?) ORDER BY is_active DESC,id LIMIT 1');
            $existingActivity->execute([$name]);
            $workTypeId = (int)($existingActivity->fetchColumn() ?: 0);
            if ($workTypeId > 0) {
                $pdo->prepare('UPDATE work_types SET is_active=1 WHERE id=?')->execute([$workTypeId]);
            } else {
                $code = $uniqueWorkActivityCode($pdo, $name);
                $pdo->prepare(
                    'INSERT INTO work_types (name,code,description,is_active,default_compensation_method,default_amount,default_base_minutes,default_overage_rate,default_percentage,default_percentage_basis,default_eligibility_trigger,currency,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $name,$code,null,1,$method,
                    ($component['compensation_amount'] ?? '') === '' ? null : max(0,(float)$component['compensation_amount']),
                    ($component['included_minutes'] ?? '') === '' ? null : max(0,(int)$component['included_minutes']),
                    ($component['overage_rate'] ?? '') === '' ? null : max(0,(float)$component['overage_rate']),
                    ($component['percentage'] ?? '') === '' ? null : min(100,max(0,(float)$component['percentage'])),
                    $basis,$trigger,strtoupper((string)($component['currency'] ?? 'USD')),$userId ?: null,
                ]);
                $workTypeId = (int)$pdo->lastInsertId();
                $workTypeTreatment = 'undecided';
                $pdo->prepare('INSERT INTO work_type_billing_defaults (work_type_id,default_treatment,default_billing_rate,currency,created_by,updated_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$workTypeId,$workTypeTreatment,null,$clientBillingCurrency,$userId ?: null,$userId ?: null]);
            }
        }
        $pdo->prepare('UPDATE work_types SET is_active=1 WHERE id=?')->execute([$workTypeId]);
        $exclusive = $pdo->prepare(
            'SELECT c.id,i.item_name FROM catalog_work_components c
             JOIN item_library i ON i.id=c.item_library_id
             WHERE c.work_type_id=? AND c.is_active=1 AND c.item_library_id<>? LIMIT 1 FOR UPDATE'
        );
        $exclusive->execute([$workTypeId,$id]);
        if ($linkedElsewhere = $exclusive->fetch(PDO::FETCH_ASSOC)) {
            throw new DomainException('That Work Activity is already linked to ' . (string)$linkedElsewhere['item_name'] . '. Unlink it before creating another one-to-one link.');
        }
        $values = [
            $workTypeId,$name,trim((string)($component['description'] ?? '')) ?: null,$quantityBehavior,
            ($component['fixed_quantity'] ?? '') === '' ? null : max(0,(float)$component['fixed_quantity']),
            ($component['expected_duration_minutes'] ?? '') === '' ? null : max(0,(int)$component['expected_duration_minutes']),
            !empty($component['assignment_required']) ? 1 : 0,
            $clientBillingTreatment,$clientBillingRate,$clientIncludedMinutes,$clientOverageRate,$clientBillingCurrency,$method,
            ($component['compensation_amount'] ?? '') === '' ? null : max(0,(float)$component['compensation_amount']),
            ($component['included_minutes'] ?? '') === '' ? null : max(0,(int)$component['included_minutes']),
            ($component['overage_rate'] ?? '') === '' ? null : max(0,(float)$component['overage_rate']),
            ($component['percentage'] ?? '') === '' ? null : min(100,max(0,(float)$component['percentage'])),
            $basis,$trigger,strtoupper((string)($component['currency'] ?? 'USD')),$order,
        ];
        if ($componentId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE catalog_work_components SET work_type_id=?,name=?,description=?,quantity_behavior=?,fixed_quantity=?,expected_duration_minutes=?,assignment_required=?,client_billing_treatment=?,client_billing_rate=?,client_included_minutes=?,client_overage_rate=?,client_billing_currency=?,compensation_method=?,compensation_amount=?,included_minutes=?,overage_rate=?,percentage=?,percentage_basis=?,eligibility_trigger=?,currency=?,display_order=?,is_active=1 WHERE id=? AND item_library_id=?'
            );
            $stmt->execute(array_merge($values,[$componentId,$id]));
            if ($stmt->rowCount() === 0) {
                $check=$pdo->prepare('SELECT 1 FROM catalog_work_components WHERE id=? AND item_library_id=?');$check->execute([$componentId,$id]);
                if(!$check->fetchColumn()) throw new DomainException('A Work Activity does not belong to this service.');
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO catalog_work_components (item_library_id,work_type_id,name,description,quantity_behavior,fixed_quantity,expected_duration_minutes,assignment_required,client_billing_treatment,client_billing_rate,client_included_minutes,client_overage_rate,client_billing_currency,compensation_method,compensation_amount,included_minutes,overage_rate,percentage,percentage_basis,eligibility_trigger,currency,display_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute(array_merge([$id],$values));
            $componentId = (int)$pdo->lastInsertId();
        }
        $retained[] = $componentId;
    }
    $sql = 'UPDATE catalog_work_components SET is_active=0 WHERE item_library_id=?';
    $params = [$id];
    if ($retained) {
        $sql .= ' AND id NOT IN (' . implode(',',array_fill(0,count($retained),'?')) . ')';
        $params = array_merge($params,$retained);
    }
    $pdo->prepare($sql)->execute($params);
    } // Ordinary Service Library saves preserve shelved work and compensation rules.
    $pdo->prepare('DELETE FROM catalog_bundle_items WHERE bundle_item_library_id=?')->execute([$id]);
    if ($entryType === 'bundle') {
        $bundleInsert = $pdo->prepare('INSERT INTO catalog_bundle_items (bundle_item_library_id,child_item_library_id,quantity,display_order) VALUES (?,?,?,?)');
        foreach (array_values($bundleItems) as $order => $bundleItem) {
            $childId = (int)($bundleItem['item_library_id'] ?? 0);
            $childQuantity = (float)($bundleItem['quantity'] ?? 0);
            if ($childId <= 0 || $childId === $id || $childQuantity <= 0) throw new DomainException('Choose valid bundle contents and quantities.');
            $child = $pdo->prepare("SELECT 1 FROM item_library WHERE id=? AND is_active=1 AND entry_type<>'bundle'");
            $child->execute([$childId]);
            if (!$child->fetchColumn()) throw new DomainException('Packages may contain active services or fees, but not another package.');
            $bundleInsert->execute([$id,$childId,$childQuantity,$order]);
        }
    }
    (new App\Services\PortalProjectionMutationService())->queueCatalogChanges($pdo);
    $pdo->commit();
    header("Location: {$redirect}&" . ($action === 'create' ? 'created' : 'updated') . '=1');
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $error instanceof DomainException ? $error->getMessage() : 'The service could not be saved.';
    header("Location: {$redirect}&error=" . rawurlencode($message));
    exit;
}
