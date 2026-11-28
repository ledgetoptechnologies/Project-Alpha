<?php
// Shared layout for tabbed Financial pages (Expenses, Reports, Setup):
// title, the active tab's actions, tabs, then the active tab's partial.
//
// Expects $shellPage (route), $shellTitle and $shellTabs:
//   [id => ['label' => ..., 'file' => partial, 'actions' => [[label, href, primary?], ...]]]
// An action with an 'onclick' key renders as a button instead of a link.
// Optional $shellMessages: [[tone (ok|info|bad), text], ...] shown above the tabs.

$shellActive = (string)($_GET['tab'] ?? '');
if (!isset($shellTabs[$shellActive])) {
    $shellActive = (string)array_key_first($shellTabs);
}
$shellTab = $shellTabs[$shellActive];
?>
<section class="fin-page">
  <div class="page-head">
    <h2><?php echo htmlspecialchars($shellTitle); ?></h2>
    <?php if (!empty($shellTab['actions'])): ?>
      <div class="finance-actions">
        <?php foreach ($shellTab['actions'] as $shellAction):
          $shellClass = 'btn' . (!empty($shellAction[2]) ? ' btn-primary' : '');
        ?>
          <?php if (isset($shellAction['onclick'])): ?>
            <button type="button" class="<?php echo $shellClass; ?>" onclick="<?php echo htmlspecialchars($shellAction['onclick']); ?>"><?php echo htmlspecialchars($shellAction[0]); ?></button>
          <?php else: ?>
            <a href="<?php echo htmlspecialchars($shellAction[1]); ?>" class="<?php echo $shellClass; ?>"><?php echo htmlspecialchars($shellAction[0]); ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php foreach ($shellMessages ?? [] as [$shellTone, $shellText]): ?>
    <div class="doc-flash doc-flash--<?php echo htmlspecialchars($shellTone); ?>" role="status"><?php echo htmlspecialchars($shellText); ?></div>
  <?php endforeach; ?>

  <nav class="doc-tabs" aria-label="<?php echo htmlspecialchars($shellTitle); ?> sections">
    <?php foreach ($shellTabs as $shellId => $shellItem): ?>
      <a href="/?page=<?php echo htmlspecialchars($shellPage); ?>&amp;tab=<?php echo htmlspecialchars($shellId); ?>"
         class="<?php echo $shellId === $shellActive ? 'active' : ''; ?>"<?php echo $shellId === $shellActive ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($shellItem['label']); ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="fin-page__panel">
    <?php
    $shellBufferLevel = ob_get_level();
    ob_start();
    try {
        include __DIR__ . '/' . $shellTab['file'];
        echo ob_get_clean();
    } catch (Throwable $error) {
        while (ob_get_level() > $shellBufferLevel) {
            ob_end_clean();
        }
        error_log(sprintf('Financial tab "%s/%s" failed: %s', $shellPage, $shellActive, $error->getMessage()));
        http_response_code(500);
        ?>
        <div class="alert alert-danger" role="alert">
          <strong>This section could not be loaded.</strong>
          <span>Please try again. If the problem continues, an administrator can review the application log for the <?php echo htmlspecialchars($shellTab['label']); ?> section.</span>
        </div>
        <?php
    }
    ?>
  </div>
</section>
