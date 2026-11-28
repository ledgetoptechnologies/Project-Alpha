<?php
require_once __DIR__.'/../../../config/app.php';require_once __DIR__.'/../../../utils/csrf_sf.php';
?>
<section data-mileage-tracker data-api="/?page=financial/mileage-tracking-api" data-token="<?php echo htmlspecialchars(csrf_sf_token('mileage_tracking'),ENT_QUOTES,'UTF-8'); ?>">
 <div class="page-head"><h2>Track Miles</h2><a class="btn" href="/?page=financial/expenses-list&amp;tab=mileage">Back to Mileage</a></div>
 <?php if(empty($appConfig['mileage_tracking_enabled'])): ?><div class="alert alert-warning">GPS mileage tracking is not enabled in Workflow settings.</div><?php else: ?>
 <div class="card" style="max-width:680px;text-align:center"><div id="trackerWarning" class="alert alert-warning" style="display:none"></div><div class="label-muted">Tracking status</div><div id="trackerStatus" style="font-size:24px;font-weight:700;margin:8px">Ready</div><div class="grid grid-2"><div class="card card-tight"><div class="label-muted">Elapsed</div><strong id="trackerElapsed">00:00:00</strong></div><div class="card card-tight"><div class="label-muted">Provisional distance</div><strong><span id="trackerMiles">0.000</span> mi</strong></div></div><div style="display:flex;gap:10px;justify-content:center;margin-top:18px"><button class="btn btn-primary" id="trackerStart">Start tracking</button><button class="btn" id="trackerStop" disabled>Stop and review</button><button class="btn btn-danger" id="trackerDiscard" style="display:none">Discard</button></div><p class="muted" id="trackerConnection">Online</p></div>
 <script src="<?php echo htmlspecialchars(asset_url('/assets/js/mileage-tracker.js'),ENT_QUOTES,'UTF-8'); ?>" defer></script>
 <?php endif; ?>
</section>
