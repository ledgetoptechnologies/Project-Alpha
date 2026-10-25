<?php
// Authenticated agreement workspace only; never included in public or PDF output.
$serviceEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$serviceDate = static fn($value): string => $value ? date('M j, Y', strtotime((string)$value)) : 'Not scheduled';
?>
<section id="recurring-services" class="no-print service-workspace" aria-labelledby="service-workspace-title">
  <style>
    .service-workspace{margin:18px 0;padding:18px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;scroll-margin-top:20px}
    .service-workspace .service-card{padding:14px;margin:12px 0;background:#fff;border:1px solid #dbe2ea;border-radius:8px}
    .service-workspace .service-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:12px;margin-top:12px}
    .service-workspace label{display:grid;gap:5px;font-size:13px}
    .service-workspace input:not([type=checkbox]),.service-workspace select{width:100%;min-width:0;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:6px}
    .service-workspace .service-wide{grid-column:1/-1}
    .service-workspace .service-help{font-size:13px;color:#475569;line-height:1.5}
    .service-workspace .service-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
    .service-workspace summary{cursor:pointer;font-weight:600}
    .service-workspace .service-preview{padding:10px;background:#eff6ff;border-radius:6px}
  </style>
  <h3 id="service-workspace-title" style="margin:0">Services &amp; billing schedule</h3>
  <p class="service-help">The signed agreement stays unchanged. Approved billing changes start at the next renewal, after the current billed period. Invoices retain their original rates and coverage.</p>
  <a class="btn btn-sm" href="/?page=invoice/recurring-invoices-list&amp;status=all&amp;contract_id=<?= (int)$id ?>#invoice-history">Invoices &amp; payment history</a>
  <?php if (($contract['pricing_type'] ?? '') !== 'per_invoice'): ?>
    <p class="service-card">This agreement uses a fixed total paid in installments. Its existing installment schedule is preserved; monthly service rates apply to recurring service agreements.</p>
  <?php else: ?>
    <?php foreach ($recurringServices as $service): ?>
      <?php $serviceId = (int)$service['id']; $serviceChanges = array_filter($scheduledServiceChanges ?? [], static fn($change) => (int)$change['recurring_service_id'] === $serviceId); ?>
      <article class="service-card">
        <strong><?= $serviceEscape($service['name']) ?></strong>
        <p class="service-help"><?= $serviceEscape($service['description'] ?? '') ?></p>
        <div class="service-grid">
          <div><strong>$<?= number_format((float)$service['amount'], 2) ?></strong> every <?= (int)$service['billing_interval_count'] ?> <?= $serviceEscape($service['billing_interval_unit']) ?>(s)
            <?php if (($service['rate_basis'] ?? '') === 'monthly'): ?><div class="service-help">Based on $<?= number_format((float)$service['monthly_rate'], 2) ?> per month</div><?php endif; ?>
          </div>
          <div>Next renewal: <strong><?= $serviceEscape($serviceDate($service['next_invoice_date'])) ?></strong></div>
          <div><?= $serviceEscape(ucfirst($service['status'])) ?> · <?= $serviceEscape(ucfirst($service['approval_status'])) ?></div>
        </div>
        <?php foreach ($serviceChanges as $change): ?>
          <?php $proposed = json_decode((string)$change['new_values'], true) ?: []; ?>
          <div class="service-card" style="background:#eff6ff">
            <strong><?= $change['state'] === 'approved' ? 'Scheduled change' : 'Awaiting client approval' ?></strong>
            <p>From <?= $serviceEscape($serviceDate($change['effective_date'])) ?>: $<?= number_format((float)($proposed['amount'] ?? 0), 2) ?> every <?= (int)($proposed['billing_interval_count'] ?? 1) ?> <?= $serviceEscape($proposed['billing_interval_unit'] ?? '') ?>(s).</p>
            <div class="service-actions">
              <?php foreach (($change['state'] === 'pending' ? ['approve_change' => 'Record client approval', 'cancel_change' => 'Cancel proposed change'] : ['cancel_change' => 'Cancel scheduled change']) as $action => $label): ?>
                <form method="post" action="/?page=long-term-recurring-service-action">
                  <input type="hidden" name="csrf" value="<?= $serviceEscape(csrf_token()) ?>"><input type="hidden" name="contract_id" value="<?= (int)$id ?>"><input type="hidden" name="service_id" value="<?= $serviceId ?>"><input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>"><input type="hidden" name="service_action" value="<?= $action ?>">
                  <button class="btn btn-sm" type="submit"><?= $label ?></button>
                </form>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($service['status'] !== 'ended'): ?>
          <div class="service-actions">
            <?php
              $serviceActions = [];
              if ($service['approval_status'] !== 'approved') $serviceActions['approve'] = 'Record client approval';
              if ($service['status'] === 'active') $serviceActions['pause'] = 'Pause';
              if ($service['status'] === 'paused' && $contractStatus === 'active') $serviceActions['resume'] = 'Resume';
              $serviceActions['end'] = 'End service';
            ?>
            <?php foreach ($serviceActions as $action => $label): ?>
              <form method="post" action="/?page=long-term-recurring-service-action" <?= $action === 'end' ? 'onsubmit="return confirm(\'End this service and stop future charges?\')"' : '' ?>>
                <input type="hidden" name="csrf" value="<?= $serviceEscape(csrf_token()) ?>"><input type="hidden" name="contract_id" value="<?= (int)$id ?>"><input type="hidden" name="service_id" value="<?= $serviceId ?>"><input type="hidden" name="service_action" value="<?= $action ?>"><button class="btn btn-sm" type="submit"><?= $label ?></button>
              </form>
            <?php endforeach; ?>
          </div>
          <?php if (empty($service['next_invoice_date'])): ?>
            <p class="service-help">This service has no scheduled renewal. Set its initial terms in the draft agreement before starting billing, or add a new service for a new coverage period.</p>
          <?php elseif (!$serviceChanges): ?>
            <details style="margin-top:14px"><summary>Change rate or billing frequency at renewal</summary>
              <?php $serviceFormRow = $service; require __DIR__ . '/recurring_service_form.php'; ?>
            </details>
          <?php else: ?>
            <p class="service-help">Cancel the queued change before replacing it. Current service terms continue until an approved change takes effect.</p>
          <?php endif; ?>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <details class="service-card" <?= !$recurringServices ? 'open' : '' ?>><summary>Add a recurring service</summary>
      <?php $serviceFormRow = []; require __DIR__ . '/recurring_service_form.php'; ?>
    </details>
  <?php endif; ?>
  <?php if ($contractAmendments): ?>
    <details class="service-card"><summary>Change history</summary>
      <?php foreach ($contractAmendments as $amendment): ?>
        <p><strong><?= $serviceEscape($amendment['summary']) ?></strong><br><span class="service-help"><?= $serviceEscape($serviceDate($amendment['effective_date'])) ?> · <?= $serviceEscape($amendment['approval_status']) ?></span>
          <?php if (!empty($amendment['signed_document_path'])): ?> · <a href="<?= $serviceEscape($amendment['signed_document_path']) ?>" target="_blank" rel="noopener">Signed addendum</a><?php endif; ?>
        </p>
      <?php endforeach; ?>
    </details>
  <?php endif; ?>
</section>
