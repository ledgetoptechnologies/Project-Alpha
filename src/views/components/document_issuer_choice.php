<?php
// The enclosing form/controller retains its existing ownership and CSRF checks.
$issuerChoiceRow = $issuerChoiceRow ?? [];
$issuerChoiceMode = (string)($issuerChoiceRow['issuer_mode'] ?? ($issuerChoiceRow ? 'legacy' : 'default'));
$issuerChoiceLocked = $issuerChoiceRow && ((string)($issuerChoiceRow['status'] ?? '') !== 'draft' || !empty($issuerChoiceRow['last_sent_revision']) || !empty($issuerChoiceRow['finalized_at']) || !empty($issuerChoiceRow['signed_at']));
$issuerChoiceCompany = trim((string)($appConfig['from_company'] ?? '')) ?: (trim((string)($appConfig['brand_name'] ?? '')) ?: 'Company');
$issuerChoiceSummaries = [
    'legacy' => 'Existing issuer',
    'default' => (($appConfig['document_issuer_mode'] ?? 'company') === 'creator' ? 'Original creator · ' : '') . $issuerChoiceCompany . ' (default)',
    'company' => $issuerChoiceCompany,
    'creator' => 'Original creator · ' . $issuerChoiceCompany,
];
$issuerChoiceSummary = $issuerChoiceSummaries[$issuerChoiceMode] ?? 'Existing issuer';
// Historical identity comes from the saved snapshot, never the current editor.
$issuerChoiceSnapshot = json_decode((string)($issuerChoiceRow['issuer_snapshot'] ?? ''), true);
if (is_array($issuerChoiceSnapshot['sender'] ?? null)) {
    $issuerChoiceNames = array_filter([
        trim((string)($issuerChoiceSnapshot['sender']['name'] ?? '')),
        trim((string)($issuerChoiceSnapshot['sender']['company'] ?? '')),
    ], static fn (string $value): bool => $value !== '');
    if ($issuerChoiceNames) $issuerChoiceSummary = implode(' · ', array_unique($issuerChoiceNames));
}
?>
<details class="document-issuer-choice" style="font-size:14px;min-width:0">
  <summary style="cursor:pointer;padding:4px 0;overflow-wrap:anywhere">
    Issued by: <span data-issuer-summary><?php echo htmlspecialchars($issuerChoiceSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
    <span style="margin-left:8px;text-decoration:underline"><?php echo $issuerChoiceLocked ? 'Details' : 'Change'; ?></span>
  </summary>
  <label class="field" style="display:block;max-width:440px;margin-top:8px"><span class="label">Document issuer</span>
  <select class="input" name="document_issuer_mode" <?php echo $issuerChoiceLocked ? 'disabled' : ''; ?> onchange="this.closest('details').querySelector('[data-issuer-summary]').textContent = this.selectedOptions[0].dataset.summary">
    <?php if ($issuerChoiceMode === 'legacy'): ?><option value="legacy" data-summary="<?php echo htmlspecialchars($issuerChoiceSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" selected>Keep existing issuer</option><?php endif; ?>
    <option value="default" data-summary="<?php echo htmlspecialchars($issuerChoiceSummaries['default'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $issuerChoiceMode === 'default' ? 'selected' : ''; ?>>Company default</option>
    <option value="company" data-summary="<?php echo htmlspecialchars($issuerChoiceSummaries['company'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $issuerChoiceMode === 'company' ? 'selected' : ''; ?>>Company only</option>
    <option value="creator" data-summary="<?php echo htmlspecialchars($issuerChoiceSummaries['creator'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $issuerChoiceMode === 'creator' ? 'selected' : ''; ?>>Original creator and company</option>
  </select>
  <small><?php echo $issuerChoiceLocked ? 'The issuer is fixed after this document is issued.' : 'The original creator is retained when another person edits or sends this document.'; ?></small>
  </label>
</details>
<?php unset($issuerChoiceRow, $issuerChoiceMode, $issuerChoiceLocked, $issuerChoiceCompany, $issuerChoiceSummaries, $issuerChoiceSummary, $issuerChoiceSnapshot, $issuerChoiceNames); ?>
