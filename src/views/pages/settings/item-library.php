<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/workforce_availability.php';

$successMsg = !empty($_GET['created']) ? 'Service created.' : (!empty($_GET['updated']) ? 'Service updated.' : (!empty($_GET['purged']) ? 'Unused service permanently deleted.' : (!empty($_GET['deleted']) ? 'Service deactivated.' : '')));
$errorMsg = (string)($_GET['error'] ?? '');
$items = $pdo->query(
    'SELECT i.*,COUNT(c.id) work_component_count,MAX(c.id) linked_component_id,
            MAX(c.work_type_id) linked_work_type_id,MAX(wt.name) linked_work_type_name
     FROM item_library i
     LEFT JOIN catalog_work_components c ON c.item_library_id=i.id AND c.is_active=1
     LEFT JOIN work_types wt ON wt.id=c.work_type_id
     GROUP BY i.id ORDER BY i.is_active DESC,i.item_name'
)->fetchAll(PDO::FETCH_ASSOC);
$componentsByItem = [];
foreach ($pdo->query('SELECT * FROM catalog_work_components WHERE is_active=1 ORDER BY item_library_id,display_order,id')->fetchAll(PDO::FETCH_ASSOC) as $component) {
    $componentsByItem[(int)$component['item_library_id']][] = $component;
}
$bundleItemsByItem = [];
foreach ($pdo->query('SELECT bundle_item_library_id,child_item_library_id item_library_id,quantity FROM catalog_bundle_items ORDER BY bundle_item_library_id,display_order,child_item_library_id')->fetchAll(PDO::FETCH_ASSOC) as $bundleItem) {
    $bundleItemsByItem[(int)$bundleItem['bundle_item_library_id']][] = $bundleItem;
}
foreach ($items as &$item) {
    $itemId = (int)$item['id'];
    $item['work_components'] = $componentsByItem[$itemId] ?? [];
    $item['bundle_items'] = $bundleItemsByItem[$itemId] ?? [];
}
unset($item);
$workTypes = $pdo->query(
    'SELECT wt.id,wt.name,c.item_library_id linked_service_id,i.item_name linked_service_name
     FROM work_types wt
     LEFT JOIN catalog_work_components c ON c.work_type_id=wt.id AND c.is_active=1
     LEFT JOIN item_library i ON i.id=c.item_library_id
     WHERE wt.is_active=1 OR c.id IS NOT NULL ORDER BY wt.name'
)->fetchAll(PDO::FETCH_ASSOC);
$bundleChoices = array_values(array_map(
    static fn(array $item): array => [
        'id' => (int)$item['id'],
        'name' => (string)$item['item_name'],
        'description' => (string)($item['description'] ?? ''),
        'billing_unit' => (string)$item['billing_unit'],
        'unit_price' => (string)$item['unit_price'],
    ],
    array_filter($items, static fn(array $item): bool => (int)$item['is_active'] === 1 && (string)$item['entry_type'] !== 'bundle')
));
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$offeringLabel = static fn(string $type): string => $type === 'bundle' ? 'Package' : ($type === 'fee' ? 'Fee' : 'Service');
$billingUnitLabel = static fn(string $unit): string => match ($unit) {
    'each' => 'service unit',
    'hour' => 'hour',
    'day' => 'day',
    'mile' => 'mile',
    'project' => 'project',
    default => $unit,
};
$clientBillingLabels = [
    'hourly' => 'Hourly',
    'fixed_price_included' => 'Included in service price',
    'base_overage' => 'Service price + hourly overage',
    'internal' => 'Internal / not billed',
];
?>

<section class="settings-section catalog-settings" data-item-library-page>
  <div class="page-head">
    <div><h3>Service Library</h3><p class="muted">Define services, pricing and packages for client documents. Changes apply to future documents.</p></div>
    <button type="button" class="btn btn-primary" data-item-library-create>+ Add service</button>
  </div>

  <?php if ($successMsg): ?><div class="alert alert-success"><?= $h($successMsg) ?></div><?php endif; ?>
  <?php if ($errorMsg): ?><div class="alert alert-danger"><?= $h($errorMsg) ?></div><?php endif; ?>

  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead><tr><th>Service or package</th><th>Type</th><th>Standard price</th><?php if (pa_workforce_enabled()): ?><th>Work Activities</th><?php endif; ?><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr class="<?= !$item['is_active'] ? 'is-muted' : '' ?>">
          <td><strong><?= $h($item['item_name']) ?></strong><small><?= $h($item['description']) ?></small></td>
          <td><?= $h($offeringLabel((string)$item['entry_type'])) ?></td>
          <td>$<?= number_format((float)$item['unit_price'],2) ?> / <?= $h($billingUnitLabel((string)$item['billing_unit'])) ?><?php if (($item['client_pricing_model'] ?? '') === 'base_overage'): ?><small>Includes <?= (int)$item['client_included_minutes'] ?> min, then $<?= number_format((float)$item['client_overage_rate'],2) ?>/hr</small><?php endif; ?></td>
<?php if (pa_workforce_enabled()): ?>
          <td><?php if (!empty($item['linked_work_type_id'])): ?><a href="/?page=settings&amp;tab=work-types&amp;edit_work_type=<?= (int)$item['linked_work_type_id'] ?>"><?= $h($item['linked_work_type_name']) ?></a><small>Exclusive one-to-one link</small><?php else: ?><span class="muted">Not linked</span><?php endif; ?></td>
<?php endif; ?>
          <td><span class="status-pill status-pill--<?= $item['is_active'] ? 'active' : 'inactive' ?>"><?= $item['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="text-right">
            <button type="button" class="btn btn-sm" data-item-library-edit data-item='<?= $h(json_encode($item, JSON_UNESCAPED_SLASHES)) ?>'>Edit</button>
            <?php if ($item['is_active']): ?><form method="post" action="/?page=settings/item-library-handler" class="inline-form" onsubmit="return confirm('Deactivate this service? Existing documents and Jobs will keep their snapshots.');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-danger-outline">Deactivate</button></form><?php endif; ?>
            <form method="post" action="/?page=settings/item-library-handler" class="inline-form" onsubmit="return confirm('Permanently delete this service? This is allowed only when it has never been used on a document, Job, or package.');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="purge"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-danger-outline">Delete permanently</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="<?= pa_workforce_enabled() ? 6 : 5 ?>" class="empty-state">No services yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div id="itemModal" class="modal-backdrop" hidden>
  <div class="modal-card modal-card--wide" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-card__head"><div><h2 id="modalTitle">Add service</h2><p>Define the service and pricing the client receives.</p></div><button type="button" class="btn btn-sm" data-item-library-close aria-label="Close">Close</button></div>
    <form method="post" action="/?page=settings/item-library-handler" data-catalog-form>
      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" id="formAction" name="action" value="create"><input type="hidden" id="formId" name="id"><input type="hidden" id="linkedComponentId" name="linked_component_id"><input type="hidden" id="bundleItemsJson" name="bundle_items_json" value="[]">

      <fieldset class="settings-card"><legend>General information</legend>
        <div class="settings-form-grid"><label class="field"><span class="label">Service name *</span><input class="input" id="itemName" name="item_name" required maxlength="255"><small>The client-facing name shown when this service is added to a quote, contract, or invoice.</small></label><label class="field"><span class="label">Service type</span><select class="input" id="entryType" name="entry_type"><option value="service">Service</option><option value="fee">Fee</option><option value="bundle">Package</option></select><small>Use Package when one price combines multiple services or fees.</small></label><label class="field field--wide"><span class="label">Client-facing description</span><textarea class="input" id="itemDescription" name="description" rows="3"></textarea></label><label class="field"><span class="label">Status</span><span class="check-row"><input type="checkbox" id="isActive" name="is_active" value="1" checked> Available for new documents</span></label></div>
      </fieldset>

      <fieldset class="settings-card"><legend>Client pricing</legend>
        <div class="settings-form-grid"><label class="field"><span class="label">Pricing model</span><select class="input" id="pricingModel" name="client_pricing_model"><option value="fixed">Fixed service price</option><option value="hourly">Hourly</option><option value="base_overage">Base price + hourly overage</option></select><small>This controls client pricing only; worker compensation is configured separately.</small></label><label class="field"><span class="label" data-price-label>Client price *</span><input class="input" type="number" id="unitPrice" name="unit_price" min="0" step="0.01" required><small data-price-help>The normal client price; it can still be adjusted on an individual document.</small></label><label class="field" data-overage-field hidden><span class="label">Minutes included in base price</span><input class="input" type="number" id="clientIncludedMinutes" name="client_included_minutes" min="0"></label><label class="field" data-overage-field hidden><span class="label">Hourly overage rate</span><input class="input" type="number" id="clientOverageRate" name="client_overage_rate" min="0" step="0.0001"></label><label class="field"><span class="label">Billing unit</span><select class="input" id="billingUnit" name="billing_unit"><option value="each">Service unit</option><option value="hour">Hour</option><option value="day">Day</option><option value="mile">Mile</option><option value="project">Project</option></select><small>Hourly pricing always uses Hour. Other models may use the unit that best describes the service.</small></label><label class="field"><span class="label">Pricing currency</span><input class="input" id="pricingCurrency" name="pricing_currency" maxlength="3" value="USD" required></label><label class="field"><span class="label">Portal typical minimum</span><input class="input" type="number" id="portalTypicalMinimum" name="portal_pricing_typical_minimum" min="0" step="0.01"><small>Optional private guidance; set both range values.</small></label><label class="field"><span class="label">Portal typical maximum</span><input class="input" type="number" id="portalTypicalMaximum" name="portal_pricing_typical_maximum" min="0" step="0.01"></label></div>
        <p class="catalog-tax-note"><strong>Tax:</strong> Uses the document default automatically, so the client or organization&rsquo;s tax treatment controls the result.</p>
      </fieldset>

      <fieldset class="settings-card"><legend>Fulfillment</legend><label class="field"><span class="label">Internal fulfillment notes</span><textarea class="input" id="fulfillmentNotes" name="fulfillment_notes" rows="2" placeholder="These notes never appear on client documents."></textarea></label></fieldset>

      <fieldset class="settings-card" id="portalCatalogCard"><legend>Optional client portal listing</legend><p class="muted">Publishes only these sanitized fields. Internal pricing policy, fulfillment notes, compensation, raw files, and processing details are never included.</p><div class="settings-form-grid"><label class="field field--wide"><span class="check-row"><input type="checkbox" id="portalRequestable" name="portal_requestable" value="1"> Allow this service in configured client portals</span></label><label class="field"><span class="label">Portal category</span><input class="input" id="portalCategory" name="portal_category" maxlength="100"></label><label class="field"><span class="label">Display order</span><input class="input" type="number" id="portalDisplayOrder" name="portal_display_order" min="0" max="1000000" value="0"></label><label class="field"><span class="label">Geometry requirement</span><select class="input" id="portalGeometry" name="portal_geometry_requirement"><option value="none">None</option><option value="optional" selected>Optional</option><option value="required">Required</option></select></label><label class="field field--wide"><span class="label">Client-safe summary</span><textarea class="input" id="portalSummary" name="portal_summary" maxlength="1000" rows="2"></textarea></label><label class="field field--wide"><span class="label">Questions JSON</span><textarea class="input" id="portalQuestions" name="portal_questions_json" rows="5" spellcheck="false">[]</textarea><small>A JSON list with at most 10 strict, client-safe questions. Invalid or markup-bearing content is rejected.</small></label></div></fieldset>

      <fieldset class="settings-card" id="bundleContents" hidden><legend>Package contents</legend><p class="muted">A package has one client-facing price. Search the Service Library and add only the services or fees that belong in it.</p><label class="field catalog-bundle-search"><span class="label">Add a service or fee</span><input class="input" type="search" placeholder="Type to search the Service Library" autocomplete="off" data-bundle-search aria-controls="bundleSearchResults" aria-expanded="false"></label><div class="catalog-bundle-results" id="bundleSearchResults" data-bundle-results role="listbox" hidden></div><div class="catalog-bundle-selected" data-bundle-selected></div><p class="muted catalog-bundle-empty" data-bundle-empty>No services or fees added yet.</p></fieldset>

      <?php if (pa_workforce_enabled()): ?>
      <fieldset class="settings-card" id="activityLinkCard"><legend>Optional Work Activity link</legend>
        <p class="muted">Use this when the service and the internal activity represent the same work. The link is exclusive: one Service to one Work Activity. Names and rates remain independent.</p>
        <div class="settings-form-grid"><label class="field"><span class="label">Link behavior</span><select class="input" id="activityLinkMode" name="activity_link_mode"><option value="none">No linked Work Activity</option><option value="new">Create a matching Work Activity</option><option value="existing">Link an existing unlinked Work Activity</option></select><small>Packages cannot be linked; their contained Services provide any activity links.</small></label><label class="field" data-existing-activity hidden><span class="label">Available Work Activity</span><select class="input" id="linkedWorkTypeId" name="linked_work_type_id"><option value="">Choose an activity</option><?php foreach ($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>" data-linked-service-id="<?= (int)($type['linked_service_id'] ?? 0) ?>" data-linked-service-name="<?= $h($type['linked_service_name'] ?? '') ?>"><?= $h($type['name']) ?><?= !empty($type['linked_service_id']) ? ' — linked to '.$h($type['linked_service_name']) : '' ?></option><?php endforeach; ?></select><small>Activities linked to another Service must be unlinked there first.</small></label></div>
      </fieldset>

<?php endif; ?>
      <div class="modal-card__actions"><button type="button" class="btn" data-item-library-close>Cancel</button><button type="submit" class="btn btn-primary">Save service</button></div>
    </form>
  </div>
</div>

<script type="application/json" id="bundleChoicesData"><?= json_encode($bundleChoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
