<?php
require_once __DIR__ . '/../../utils/autopay_review.php';
require_once __DIR__ . '/../../utils/check_report_staff.php';
$reviewQueue = [];
if (pa_autopay_test_context_enabled($appConfig) && pa_autopay_review_available($pdo)) {
    $query = $pdo->prepare('SELECT c.document_type,c.document_id,r.status,r.question_text
        FROM autopay_collections c JOIN autopay_invoice_reviews r ON r.collection_id=c.id
        LEFT JOIN invoices i ON c.document_type="invoice" AND i.id=c.document_id AND i.public_id=r.document_public_id
        LEFT JOIN project_invoices pi ON c.document_type="project_invoice" AND pi.id=c.document_id AND pi.public_id=r.document_public_id
        JOIN clients owner ON owner.id=CASE WHEN c.document_type="invoice" THEN i.client_id ELSE pi.primary_client_id END
            AND owner.public_id=r.client_public_id AND owner.archived=0
        WHERE c.state="held" AND c.attempt_count=0 AND r.status IN ("question","held")
            AND c.installation_id=? AND c.processor_account_id=?
            AND r.id=(SELECT MAX(latest.id) FROM autopay_invoice_reviews latest WHERE latest.collection_id=c.id)
        ORDER BY r.id DESC LIMIT 50');
    $query->execute([getenv('AUTOPAY_INSTALLATION_ID'),getenv('AUTOPAY_STRIPE_ACCOUNT_ID')]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
        if (pa_check_report_staff_access($pdo, $item['document_type'], (int)$item['document_id'], (int)($_SESSION['user']['id'] ?? 0))) $reviewQueue[] = $item;
    }
}
?>
<?php if ($reviewQueue): ?>
<section style="margin:16px 0;padding:16px;border:1px solid #d4a345;border-radius:8px;background:#fffbeb">
<h3>AutoPay invoice questions awaiting review</h3>
<p>Automatic collection is held for these invoices. Open an invoice to review the client’s question.</p>
<?php foreach ($reviewQueue as $item): ?>
<p style="overflow-wrap:anywhere"><a href="/?page=<?= $item['document_type']==='invoice'?'invoice/invoice-details':'project/project-invoice-details' ?>&amp;id=<?= (int)$item['document_id'] ?>">Review <?= $item['document_type']==='invoice'?'invoice':'project invoice' ?> #<?= (int)$item['document_id'] ?></a><br><?= htmlspecialchars(mb_substr((string)$item['question_text'],0,500),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></p>
<?php endforeach; ?>
</section>
<?php endif; ?>
