<?php
// Expects item, lines, invoiceSectionIndex and invoiceSectionTotalRows.
$invoiceSectionBackground = $invoiceSectionIndex % 2 === 0 ? '#ffffff' : '#f3f4f6';
$keepInvoiceSectionTogether = count($lines) <= 4;
?>
<div class="project-invoice-section" style="margin-bottom:16px;border:1px solid #d1d5db;border-radius:6px;padding:12px;background:<?php echo $invoiceSectionBackground; ?>;<?php echo $keepInvoiceSectionTogether ? 'page-break-inside:avoid' : 'page-break-inside:auto'; ?>">
  <table style="width:100%;border-collapse:collapse;margin-bottom:8px;page-break-after:avoid">
    <tr>
      <td style="vertical-align:top">
        <h3 style="font-size:14px;margin:0 0 4px">Invoice <?php echo htmlspecialchars(pa_invoice_label($item['invoice_doc_number'] ?? null, $item['invoice_type'] ?? 'regular', $item['invoice_id'])); ?></h3>
        <div style="font-size:11px;color:#4b5563">
          <?php echo htmlspecialchars((string)$item['client_name']); ?>
          <?php if (!empty($item['invoice_date'])): ?>
            <span> | <?php echo htmlspecialchars(date('M j, Y', strtotime($item['invoice_date']))); ?></span>
          <?php endif; ?>
        </div>
      </td>
      <td style="text-align:right;vertical-align:top;width:30%">
        <div style="font-size:10px;color:#4b5563">Included in statement</div>
        <strong style="font-size:14px">$<?php echo number_format((float)$item['amount_due_at_generation'], 2); ?></strong>
      </td>
    </tr>
  </table>
  <div style="margin-left:12px;border-left:2px solid #cbd5e1;padding-left:10px">
    <?php if ($lines): ?>
      <table style="width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed">
        <thead style="display:table-header-group">
          <tr style="background:#e5e7eb">
            <th scope="col" style="text-align:left;padding:7px;width:52%">Description<?php if (!$keepInvoiceSectionTogether): ?> - <?php echo htmlspecialchars(pa_invoice_label($item['invoice_doc_number'] ?? null, $item['invoice_type'] ?? 'regular', $item['invoice_id'])); ?><?php endif; ?></th>
            <th scope="col" style="text-align:right;padding:7px;width:16%">Qty / Unit</th>
            <th scope="col" style="text-align:right;padding:7px;width:16%">Rate</th>
            <th scope="col" style="text-align:right;padding:7px;width:16%">Line total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
            <tr style="page-break-inside:avoid">
              <td style="padding:7px;border-bottom:1px solid #d1d5db;vertical-align:top;overflow-wrap:break-word;word-wrap:break-word">
                <?php if (!empty($line['item'])): ?><strong<?php if (!empty($line['parent_item_id'])): ?> style="padding-left:1.25rem"<?php endif; ?>><?php echo htmlspecialchars((string)$line['item']); ?></strong><br><?php endif; ?>
                <?php echo nl2br(htmlspecialchars((string)($line['description'] ?? ''))); ?>
              </td>
              <td style="padding:7px;border-bottom:1px solid #d1d5db;text-align:right;vertical-align:top"><?php echo number_format((float)$line['quantity'], 2); ?> <?php echo htmlspecialchars($line['billing_unit'] ?? 'each'); ?></td>
              <td style="padding:7px;border-bottom:1px solid #d1d5db;text-align:right;vertical-align:top">$<?php echo number_format((float)$line['unit_price'], 2); ?></td>
              <td style="padding:7px;border-bottom:1px solid #d1d5db;text-align:right;vertical-align:top">$<?php echo number_format((float)$line['line_total'], 2); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p style="font-size:11px;color:#4b5563;margin:8px 0">No itemized details available.</p>
    <?php endif; ?>
    <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:11px;page-break-inside:avoid">
      <?php foreach ($invoiceSectionTotalRows as $totalRow): ?>
        <tr>
          <td style="padding:4px 7px;text-align:right;<?php echo !empty($totalRow['total']) ? 'font-weight:700;border-top:1px solid #cbd5e1' : ''; ?>"><?php echo htmlspecialchars($totalRow['label']); ?></td>
          <td style="padding:4px 7px;text-align:right;width:24%;<?php echo !empty($totalRow['total']) ? 'font-weight:700;border-top:1px solid #cbd5e1' : ''; ?>"><?php echo htmlspecialchars($totalRow['value']); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
