<?php
// The enclosing form/controller retains its existing ownership and CSRF checks.
$issuerChoiceRow = $issuerChoiceRow ?? [];
$issuerChoiceMode = (string)($issuerChoiceRow['issuer_mode'] ?? ($issuerChoiceRow ? 'legacy' : 'default'));
$issuerChoiceLocked = $issuerChoiceRow && ((string)($issuerChoiceRow['status'] ?? '') !== 'draft' || !empty($issuerChoiceRow['last_sent_revision']) || !empty($issuerChoiceRow['finalized_at']) || !empty($issuerChoiceRow['signed_at']));
?>
<label class="field"><span class="label">Document issuer</span>
  <select class="input" name="document_issuer_mode" <?php echo $issuerChoiceLocked ? 'disabled' : ''; ?>>
    <?php if ($issuerChoiceMode === 'legacy'): ?><option value="legacy" selected>Keep existing issuer</option><?php endif; ?>
    <option value="default" <?php echo $issuerChoiceMode === 'default' ? 'selected' : ''; ?>>Company default</option>
    <option value="company" <?php echo $issuerChoiceMode === 'company' ? 'selected' : ''; ?>>Company only</option>
    <option value="creator" <?php echo $issuerChoiceMode === 'creator' ? 'selected' : ''; ?>>Original creator and company</option>
  </select>
  <small><?php echo $issuerChoiceLocked ? 'The issuer is fixed after this document is issued.' : 'The original creator is retained when another person edits or sends this document.'; ?></small>
</label>
<?php unset($issuerChoiceRow, $issuerChoiceMode, $issuerChoiceLocked); ?>
