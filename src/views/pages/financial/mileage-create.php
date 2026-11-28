<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/mileage.php';

use App\Services\WorkforceAccessService;

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$canManageMileage = user_can($pdo, $userId, 'financial.manage', 0);
$workforceActor = (new WorkforceAccessService($pdo))->actor($userId);
$canLogOwnMileage = $canManageMileage || isset(($workforceActor['capabilities'] ?? [])['*']) || isset(($workforceActor['capabilities'] ?? [])['mileage.self']);
if (!$canLogOwnMileage) { http_response_code(403); echo '<div class="alert alert-danger">Mileage access is not enabled for this account.</div>'; return; }
$canSelectTraveler = $canManageMileage && acl_user_has_org_wide_scope($pdo, $userId, 0);
$travelerUsers = [];
if ($canSelectTraveler) {
    try {
        $travelerUsers = $pdo->query(
            'SELECT u.id,COALESCE(NULLIF(TRIM(CONCAT_WS(" ",ep.first_name,ep.last_name)),""),NULLIF(u.username,""),u.email) name,
                    wp.relationship_type
             FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN worker_profiles wp ON wp.user_id=u.id
             WHERE u.deleted_at IS NULL AND COALESCE(u.is_disabled,0)=0 ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $travelerUsers = [];
    }
}
$editMode = false;
$log = [
    'id'=>null,'entry_mode'=>'simple','trip_date'=>date('Y-m-d'),'start_location'=>'','end_location'=>'','miles'=>'',
    'traveler_user_id'=>$userId,'financial_treatment'=>'organization_mileage','purpose'=>'business','description'=>'','round_trip'=>!array_key_exists('default_mileage_include_return_trip',$appConfig)||!empty($appConfig['default_mileage_include_return_trip']) ? 1 : 0,
];
try {
    $profile = $pdo->prepare('SELECT relationship_type FROM worker_profiles WHERE user_id=? AND status="active"');
    $profile->execute([$userId]);
    $relationship = (string)($profile->fetchColumn() ?: '');
    $log['financial_treatment'] = mileage_financial_treatment_for_relationship($relationship);
} catch (Throwable $e) {}
$allocations = [];
$trackingSessionId=max(0,(int)($_GET['tracking_session_id']??0));
$trackingDraft=null;
$editId = max(0, (int)($_GET['id'] ?? 0));
if ($editId > 0) {
    [$scope,$params] = finance_scope_clause($pdo,'m',$userId,$orgId,'traveler_user_id');
    $stmt=$pdo->prepare('SELECT m.* FROM mileage_logs m WHERE m.id=? AND '.$scope);
    $stmt->execute(array_merge([$editId],$params));
    if ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $editMode=true; $log=array_merge($log,$row);
        $log['traveler_user_id']=(int)($row['traveler_user_id']??$row['user_id']??$userId);
        if($canManageMileage){$a=$pdo->prepare('SELECT * FROM mileage_charge_allocations WHERE mileage_log_id=? ORDER BY id');
        $a->execute([$editId]); $allocations=$a->fetchAll(PDO::FETCH_ASSOC);}
    }
}
if(!$editMode&&$trackingSessionId>0){
  try{$ts=$pdo->prepare('SELECT * FROM mileage_tracking_sessions WHERE id=? AND user_id=? AND status="draft_review"');$ts->execute([$trackingSessionId,$userId]);$trackingDraft=$ts->fetch(PDO::FETCH_ASSOC)?:null;if($trackingDraft){$log['entry_mode']='total_trip';$log['miles']=(float)$trackingDraft['calculated_miles'];$log['round_trip']=0;$log['trip_date']=substr((string)$trackingDraft['started_at'],0,10);}}catch(Throwable $e){$trackingDraft=null;}
}
$clients=$canManageMileage?$pdo->query('SELECT id,name FROM clients WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC):[];
$projects=$canManageMileage?$pdo->query('SELECT id,client_id,name FROM projects WHERE status NOT IN ("completed","cancelled") ORDER BY name')->fetchAll(PDO::FETCH_ASSOC):[];
$contracts=$canManageMileage?$pdo->query('SELECT id,client_id,doc_number,project_code FROM contracts WHERE status NOT IN ("cancelled","denied","void") ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC):[];
try { $locations=$canManageMileage?$pdo->query('SELECT id,client_id,project_id,name,city,state FROM service_locations WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC):[]; } catch(Throwable $e) { $locations=[]; }
try { $originsStmt=$pdo->prepare('SELECT id,label,is_default FROM user_mileage_origins WHERE user_id=? ORDER BY is_default DESC,label'); $originsStmt->execute([$userId]); $origins=$originsStmt->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) { $origins=[]; }
try { $distanceStmt=$pdo->prepare('SELECT d.origin_id,d.service_location_id,d.one_way_miles FROM travel_distance_cache d JOIN user_mileage_origins o ON o.id=d.origin_id WHERE o.user_id=?');$distanceStmt->execute([$userId]);$distanceRows=$distanceStmt->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) { $distanceRows=[]; }
try { if($canManageMileage){$ruleStmt=$pdo->prepare('SELECT * FROM travel_billing_rules WHERE organization_id IS NULL OR organization_id=? ORDER BY id');$ruleStmt->execute([$orgId]);$allRules=$ruleStmt->fetchAll(PDO::FETCH_ASSOC);}else{$allRules=[];} } catch(Throwable $e) { $allRules=[]; }

$payload=[
  'clients'=>$clients,'projects'=>$projects,'contracts'=>$contracts,'locations'=>$locations,'origins'=>$origins,'distances'=>$distanceRows,
  'clientRules'=>array_values(array_filter($allRules,static fn($r)=>$r['scope_type']==='client')),'contractRules'=>array_values(array_filter($allRules,static fn($r)=>$r['scope_type']==='contract')),
  'allocations'=>$allocations,
  'defaults'=>[
    'method'=>(string)($appConfig['default_mileage_charge_method']??'actual_trip'),
    'rate'=>(float)($appConfig['default_mileage_rate']??0.670),
    'included'=>(float)($appConfig['default_mileage_included_miles']??0),
    'chargeReturn'=>!empty($appConfig['default_mileage_bill_return_trip']),
  ],
];
?>
<section class="mileage-editor" data-mileage-editor data-mileage-payload="<?php echo htmlspecialchars(json_encode($payload),ENT_QUOTES,'UTF-8'); ?>">
  <div class="page-head">
    <div><h2><?php echo $editMode?'Edit Mileage Entry':($trackingDraft?'Review GPS Trip':'Log Mileage'); ?></h2><p class="muted"><?php echo $trackingDraft?'Confirm the tracked distance and client charges before finalizing this trip.':'Record the physical trip once, then add a separate charge for each client served.'; ?></p></div>
    <a href="<?php echo htmlspecialchars(mileage_list_url($pdo,(int)($_SESSION['user']['id']??0))); ?>" class="btn">Back to Mileage</a>
  </div>
  <?php if(!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
  <form id="mileageForm" method="post" action="/?page=financial/mileage-handler" style="display:grid;gap:18px;max-width:1050px">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('mileage')); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="<?php echo $editMode?'update':'create'; ?>">
    <?php if($trackingDraft): ?><input type="hidden" name="tracking_session_id" value="<?php echo (int)$trackingDraft['id']; ?>"><?php endif; ?>
    <?php if($editMode): ?><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>"><?php endif; ?>

    <div class="card">
      <div class="card-head"><h3 class="card-title">Physical trip</h3><span class="muted">This is the distance actually traveled.</span></div>
      <div class="grid grid-2">
        <label class="field"><span class="label">Trip date</span><input class="input" type="date" name="trip_date" required value="<?php echo htmlspecialchars((string)$log['trip_date']); ?>"></label>
        <label class="field"><span class="label">Entry type</span><select class="input" id="entry_mode" name="entry_mode"><option value="simple" <?php echo ($log['entry_mode']??'simple')==='simple'?'selected':''; ?>>Simple trip (enter one-way miles)</option><option value="total_trip" <?php echo ($log['entry_mode']??'')==='total_trip'?'selected':''; ?>>Total or multi-stop trip</option></select></label>
      </div>
      <?php if ($canSelectTraveler && !$trackingDraft): ?>
        <label class="field"><span class="label">Traveler</span><select class="input" name="traveler_user_id" required><?php foreach($travelerUsers as $traveler): ?><option value="<?php echo (int)$traveler['id']; ?>" <?php echo (int)$log['traveler_user_id']===(int)$traveler['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$traveler['name']); ?></option><?php endforeach; ?></select><span class="muted text-sm">The traveler owns this mileage record; the signed-in user is recorded separately as the person entering it.</span></label>
      <?php else: ?>
        <input type="hidden" name="traveler_user_id" value="<?php echo $userId; ?>">
      <?php endif; ?>
      <label class="field"><span class="label">Mileage treatment</span><?php $treatmentLabels=mileage_financial_treatment_labels(); if($canManageMileage): ?><select class="input" name="financial_treatment"><?php foreach($treatmentLabels as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo ($log['financial_treatment']??'organization_mileage')===$value?'selected':''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select><?php else: ?><input type="hidden" name="financial_treatment" value="<?php echo htmlspecialchars((string)$log['financial_treatment']); ?>"><strong><?php echo htmlspecialchars($treatmentLabels[(string)$log['financial_treatment']]??'Record only'); ?></strong><?php endif; ?><span class="muted text-sm">Contractor record-only mileage stays out of organization mileage totals. Client travel charges are handled separately.</span></label>
      <div class="grid grid-2">
        <label class="field"><span class="label" id="milesLabel">One-way miles</span><input class="input" id="miles" type="number" name="miles" min="0.001" step="0.001" required value="<?php echo $log['miles']!==''?htmlspecialchars(number_format((float)$log['miles'],3,'.','')):''; ?>"></label>
        <div class="field" id="returnLogField"><span class="label">Trip log</span><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="round_trip" name="round_trip" value="1" <?php echo !empty($log['round_trip'])?'checked':''; ?>> Include return miles in the trip log</label></div>
      </div>
      <div class="grid grid-2"><label class="field"><span class="label">Start location</span><input class="input" name="start_location" value="<?php echo htmlspecialchars((string)($log['start_location']??'')); ?>"></label><label class="field"><span class="label">End location</span><input class="input" name="end_location" value="<?php echo htmlspecialchars((string)($log['end_location']??'')); ?>"></label></div>
      <div class="grid grid-2"><label class="field"><span class="label">Purpose</span><select class="input" name="purpose"><?php foreach(['business','medical','moving','charitable','personal'] as $p): ?><option value="<?php echo $p; ?>" <?php echo ($log['purpose']??'business')===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?></select></label><label class="field"><span class="label">Description</span><input class="input" name="description" value="<?php echo htmlspecialchars((string)($log['description']??'')); ?>"></label></div>
      <div class="card card-tight" style="margin-top:12px"><span class="label-muted">Total logged mileage</span><strong style="font-size:22px"><span id="loggedMilesDisplay">0.000</span> miles</strong></div>
    </div>

    <?php if($canManageMileage): ?><div class="card">
      <div class="card-head"><div><h3 class="card-title">Client travel charges</h3><p class="muted" style="margin:4px 0 0">Optional. Each client is priced independently without duplicating the trip.</p></div><button type="button" class="btn btn-primary" id="addMileageAllocation">Add client travel charge</button></div>
      <div id="mileageAllocations" style="display:grid;gap:14px"></div>
      <div id="noMileageAllocations" class="muted" style="padding:18px;text-align:center">No client travel charges. Only the physical mileage will be recorded.</div>
      <div id="allocationGrandTotal" class="card card-tight" style="display:none;margin-top:14px"><span class="label-muted">Total client travel charges</span><strong style="font-size:22px">$<span>0.00</span></strong></div>
    </div><?php endif; ?>
    <div style="display:flex;gap:10px"><button class="btn btn-primary" type="submit"><?php echo $editMode?'Update Mileage':'Save Mileage'; ?></button><a class="btn" href="<?php echo htmlspecialchars(mileage_list_url($pdo,(int)($_SESSION['user']['id']??0))); ?>">Cancel</a></div>
  </form>
</section>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/mileage-editor.js'),ENT_QUOTES,'UTF-8'); ?>" defer></script>
