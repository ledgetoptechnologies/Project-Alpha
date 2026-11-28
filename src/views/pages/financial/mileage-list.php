<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/mileage.php';

$orgId=request_client_org_id(); $userId=(int)($_SESSION['user']['id']??0);
$start=(string)($_GET['start']??''); $end=(string)($_GET['end']??''); $purpose=(string)($_GET['purpose']??'');
$clientId=max(0,(int)($_GET['client_id']??0)); $billable=(string)($_GET['billable']??'');
$mileageViewer=mileage_viewer($pdo,$userId);$canManageMileage=$mileageViewer['can_manage'];$canViewAllDrivers=$mileageViewer['all_drivers'];
if(!$mileageViewer['can_log']){http_response_code(403);echo '<div class="alert alert-danger">Mileage access is not enabled for this account.</div>';return;}
// Inside Expenses the list is a tab; on its own it is the page for staff without financial.view.
$mileageInHub=($shellPage??'')==='financial/expenses-list';
$mileageListUrl=$mileageInHub?'/?page=financial/expenses-list&tab=mileage':'/?page=financial/mileage-list';
$mileageResult=mileage_log_rows($pdo,$userId,$orgId,$_GET,$canViewAllDrivers);$driver=$mileageResult['driver'];$logs=$mileageResult['rows'];
$totalMiles=$businessMiles=$otherMiles=$billableMiles=$clientCharges=0.0;
foreach($logs as $log){$lm=(float)$log['canonical_logged_miles'];$includeInOrganizationTotals=$driver!=='all'||!in_array((string)($log['financial_treatment']??'organization_mileage'),mileage_organization_total_exclusions(),true);if($includeInOrganizationTotals){$totalMiles+=$lm;if($log['purpose']==='business')$businessMiles+=$lm;else $otherMiles+=$lm;}$billableMiles+=(float)$log['client_billable_miles'];$clientCharges+=(float)$log['client_charge_total'];}
$clients=$canManageMileage?$pdo->query('SELECT id,name FROM clients WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC):[];
$drivers=$canViewAllDrivers?mileage_driver_options($pdo,$userId):[];
$driverContext='My mileage';
if($driver==='all')$driverContext='All drivers';
elseif($driver!=='mine'){foreach($drivers as $driverRow){if((int)$driverRow['id']===(int)$driver){$driverContext=(string)$driverRow['name'];break;}}}
?>
<section>
 <?php if(!$mileageInHub): ?><div class="page-head"><h2>Mileage</h2><a href="/?page=financial/mileage-create" class="btn btn-primary">+ Log trip</a></div><?php endif; ?>
 <?php foreach(['created'=>'Mileage entry created.','updated'=>'Mileage entry updated.','deleted'=>'Mileage entry deleted.'] as $key=>$message): if(!empty($_GET[$key])): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; endforeach; ?>
 <?php if(!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
 <div style="margin:0 0 8px;color:var(--muted);font-size:13px">Totals shown for: <strong><?php echo htmlspecialchars($driverContext); ?></strong></div>
 <div class="grid <?php echo $canManageMileage?'grid-4':'grid-2'; ?>" style="margin-bottom:20px"><div class="card card-tight"><div class="label-muted">Total Logged Miles</div><strong style="font-size:20px"><?php echo number_format($totalMiles,2); ?></strong></div><div class="card card-tight"><div class="label-muted">Business Miles</div><strong style="font-size:20px"><?php echo number_format($businessMiles,2); ?></strong></div><?php if($canManageMileage): ?><div class="card card-tight"><div class="label-muted">Client-Billable Miles</div><strong style="font-size:20px"><?php echo number_format($billableMiles,2); ?></strong></div><div class="card card-tight"><div class="label-muted">Client Travel Charges</div><strong style="font-size:20px">$<?php echo number_format($clientCharges,2); ?></strong></div><?php endif; ?></div>
 <div class="card" style="margin-bottom:20px">
  <form method="get" class="expense-filters">
   <input type="hidden" name="page" value="<?php echo $mileageInHub?'financial/expenses-list':'financial/mileage-list'; ?>"><?php if($mileageInHub): ?><input type="hidden" name="tab" value="mileage"><?php endif; ?>
   <?php if($canViewAllDrivers): ?><label class="field"><span class="label">Driver</span><select class="input input-sm" name="driver"><option value="mine" <?php echo $driver==='mine'?'selected':''; ?>>My mileage</option><option value="all" <?php echo $driver==='all'?'selected':''; ?>>All drivers</option><?php foreach($drivers as $driverRow): if((int)$driverRow['id']===$userId)continue; ?><option value="<?php echo (int)$driverRow['id']; ?>" <?php echo (string)$driver===(string)$driverRow['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$driverRow['name']); ?></option><?php endforeach; ?></select></label><?php endif; ?>
   <label class="field"><span class="label">From</span><input class="input input-sm" type="date" name="start" value="<?php echo htmlspecialchars($start); ?>"></label>
   <label class="field"><span class="label">To</span><input class="input input-sm" type="date" name="end" value="<?php echo htmlspecialchars($end); ?>"></label>
   <label class="field"><span class="label">Purpose</span><select class="input input-sm" name="purpose"><option value="">All</option><?php foreach(['business','medical','moving','charitable','personal'] as $p): ?><option value="<?php echo $p; ?>" <?php echo $purpose===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?></select></label>
   <?php if($canManageMileage): ?><label class="field"><span class="label">Client</span><select class="input input-sm" name="client_id"><option value="">All</option><?php foreach($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $clientId===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?></select></label><label class="field"><span class="label">Client charge</span><select class="input input-sm" name="billable"><option value="">All</option><option value="1" <?php echo $billable==='1'?'selected':''; ?>>Has client charges</option><option value="0" <?php echo $billable==='0'?'selected':''; ?>>No client charges</option></select></label><?php endif; ?>
   <div class="field filter-actions"><button class="btn btn-primary">Filter</button><a class="btn" href="<?php echo htmlspecialchars($mileageListUrl); ?>">Clear</a></div>
  </form>
 </div>
 <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Date</th><th>Driver</th><th>Route</th><th>Source</th><th>Logged Miles</th><th>Purpose</th><?php if($canManageMileage): ?><th>Clients</th><th>Billable Miles</th><th>Client Charges</th><th>Status</th><?php endif; ?><th class="text-right">Actions</th></tr></thead><tbody>
 <?php if(!$logs): ?><tr><td colspan="<?php echo $canManageMileage?11:7; ?>" class="muted" style="text-align:center">No mileage entries found.</td></tr><?php endif; ?>
 <?php foreach($logs as $log): ?><tr><td><?php echo htmlspecialchars($log['trip_date']); ?></td><td><?php echo htmlspecialchars((string)$log['driver_name']); ?></td><td><?php echo htmlspecialchars(trim((string)($log['start_location']??''))?:'—'); ?> → <?php echo htmlspecialchars(trim((string)($log['end_location']??''))?:'—'); ?></td><td><?php echo ($log['source']??'manual')==='gps'?'GPS':'Manual'; ?><div class="muted text-sm"><?php echo ($log['entry_mode']??'simple')==='total_trip'?'Total / multi-stop':'Simple'; ?></div></td><td><?php echo number_format((float)$log['canonical_logged_miles'],3); ?></td><td><?php echo htmlspecialchars(ucfirst($log['purpose'])); ?></td><?php if($canManageMileage): ?><td><?php echo htmlspecialchars($log['client_names']?:'—'); ?><div class="muted text-sm"><?php echo (int)$log['allocation_count']; ?> charge<?php echo (int)$log['allocation_count']===1?'':'s'; ?></div></td><td><?php echo number_format((float)$log['client_billable_miles'],3); ?></td><td>$<?php echo number_format((float)$log['client_charge_total'],2); ?></td><td><?php echo (int)$log['allocation_count']===0?'Logged':((int)$log['billed_allocations']>0?'Invoiced':'Ready to invoice'); ?></td><?php endif; ?><td class="text-right"><a class="btn btn-sm" href="/?page=financial/mileage-create&id=<?php echo (int)$log['id']; ?>">Edit</a> <form method="post" action="/?page=financial/mileage-handler" style="display:inline" onsubmit="return confirm('Delete this mileage entry and its unbilled client charges?')"><input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('mileage')); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>"><button class="btn btn-sm btn-danger">Delete</button></form></td></tr><?php endforeach; ?>
 </tbody></table></div>
</section>
