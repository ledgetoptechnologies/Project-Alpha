<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/project_notes_access.php';
?>

<section>
    <?php
    $project_code = trim((string)($_GET['selected_project_code'] ?? $_GET['project_code'] ?? ''));
    if ($project_code === '') {
        echo '<h2>Job Details</h2><div style="color:var(--muted)">No job selected.</div>';
        return;
    }
    $actorId = (int)($_SESSION['user']['id'] ?? 0);
    $authorizedJob = project_notes_authorized_job($pdo, $project_code, $actorId);
    if (!$authorizedJob) {
        http_response_code(404);
        echo '<h2>Job not found.</h2>';
        return;
    }

    // Fetch project meta (notes)
    $metaStmt = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code = ? AND client_id = ?');
    $metaStmt->execute([$project_code, (int)$authorizedJob['client_id']]);
    $notes = (string)$metaStmt->fetchColumn();

    echo '<h2>Job ' . htmlspecialchars($project_code) . '</h2>';
    ?>
    <div style="margin-top:6px">
        <a href="/?page=jobs/jobs-list" style="padding:6px 10px;border-radius:8px;border:1px solid #ddd;background:#fff">Back to Jobs</a>
    </div>
    <div style="margin-top:12px;border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
        <h3>Notes</h3>
        <?php if (trim($notes) === ''): ?>
            <div style="color:var(--muted)">No notes yet for this job.</div>
        <?php else: ?>
            <div><?php echo nl2br(htmlspecialchars($notes)); ?></div>
        <?php endif; ?>
        <div style="margin-top:10px">
            <?php
            $clientId = (int)$authorizedJob['client_id'];
            ?>
            <form method="post" action="/?page=project-notes-update" style="margin-top:8px;display:grid;gap:8px">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
                <input type="hidden" name="project_code" value="<?php echo htmlspecialchars($project_code); ?>">
                <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                <input type="hidden" name="redirect" value="/?page=jobs/job-details&selected_project_code=<?php echo urlencode($project_code); ?>">
                <label>
                    <div>Edit Notes</div>
                    <textarea name="notes" rows="5" style="width:100%;padding:8px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
                </label>
                <div style="display:flex;gap:8px"><button type="submit" style="padding:6px 10px;border-radius:8px;background:var(--nav-accent);color:#fff;border:0">Save Notes</button></div>
            </form>
        </div>
    </div>

    <div style="margin-top:12px;display:grid;gap:12px">
        <div style="border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
            <h4>Quotes</h4>
            <?php
            $qstmt = $pdo->prepare('SELECT id, doc_number, client_id, total, status, created_at, subtotal, deposit_type, deposit_amount, discount_type, discount_value, tax_percent FROM quotes WHERE project_code = ? AND client_id = ? ORDER BY created_at DESC');
            $qstmt->execute([$project_code, $clientId]);
            $quotes = $qstmt->fetchAll();
            if (!$quotes):
            ?>
                <div style="color:var(--muted)">No quotes found for this job.</div>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;display:grid;gap:8px">
                    <?php foreach ($quotes as $q): ?>
                        <?php $qid = (int)$q['id']; ?>
                        <li style="display:block;border-bottom:1px solid #f3f4f6;padding:10px">
                            <div style="display:flex;gap:8px;align-items:center">
                                <div class="doc-toggle" data-type="quote" data-id="<?php echo $qid; ?>" style="flex:1;cursor:pointer">Q-<?php echo (int)($q['doc_number'] ?? $q['id']); ?> · $<?php echo number_format((float)($q['total'] ?? 0), 2); ?> · <?php echo htmlspecialchars($q['status']); ?> · <?php echo htmlspecialchars($q['created_at']); ?></div>
                                <div style="display:flex;gap:6px;align-items:center">
                                    <button type="button" class="doc-toggle" data-type="quote" data-id="<?php echo $qid; ?>" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;background:#fff">Details</button>
                                    <a href="/?page=quote/quotes-edit&id=<?php echo $qid; ?>" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;background:#fff">View Document</a>
                                </div>
                            </div>
                            <div class="doc-details" id="quote-details-<?php echo $qid; ?>" style="display:none;margin-top:8px;padding:8px;border-top:1px solid #eee">
                                <?php
                                $itemsSt = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
                                $itemsSt->execute([$qid]);
                                $items = $itemsSt->fetchAll();
                                ?>
                                <div style="font-weight:600;margin-bottom:6px">Items</div>
                                <?php if ($items): ?>
                                    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
                                        <?php foreach ($items as $it): ?>
                                            <li><?php echo htmlspecialchars($it['description']); ?> · <?php echo (float)$it['quantity']; ?> × $<?php echo number_format((float)$it['unit_price'], 2); ?> = $<?php echo number_format((float)$it['line_total'], 2); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div style="color:var(--muted)">No items listed.</div>
                                <?php endif; ?>
                                <div style="margin-top:6px">
                                    <div>Subtotal: $<?php echo number_format((float)($q['subtotal'] ?? 0), 2); ?></div>
                                    <div>Discount: <?php echo htmlspecialchars($q['discount_type']); ?> <?php echo number_format((float)($q['discount_value'] ?? 0), 2); ?></div>
                                    <div>Tax: <?php echo number_format((float)($q['tax_percent'] ?? 0), 2); ?>%</div>
                                    <div>Total: $<?php echo number_format((float)($q['total'] ?? 0), 2); ?></div>
                                    <?php if (!empty($q['deposit_type']) && $q['deposit_type'] !== 'none'): ?>
                                        <div>Deposit: <?php echo htmlspecialchars($q['deposit_type']); ?> <?php echo number_format((float)($q['deposit_amount'] ?? 0), 2); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div style="border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
            <h4>Contracts</h4>
            <?php
            // Show the most recent contract (primary) if applicable
            $primary = $pdo->prepare('SELECT id, doc_number, client_id, status, signed_pdf_path, created_at, subtotal, total, deposit_amount, deposit_paid FROM contracts WHERE project_code = ? AND client_id = ? ORDER BY created_at DESC LIMIT 1');
            $primary->execute([$project_code, $clientId]);
            $primaryContract = $primary->fetch();
            if ($primaryContract): ?>
                <div style="margin-bottom:10px;padding:8px;border:1px solid #eee;border-radius:8px;background:#fff">
                    <div style="font-weight:700">Primary Contract: C-<?php echo (int)($primaryContract['doc_number'] ?? $primaryContract['id']); ?></div>
                    <div style="color:var(--muted)">Status: <?php echo htmlspecialchars($primaryContract['status']); ?> · Created: <?php echo htmlspecialchars($primaryContract['created_at']); ?></div>
                    <?php if (!empty($primaryContract['signed_pdf_path'])): ?>
                        <?php $u_main = (string)$primaryContract['signed_pdf_path'];
                        $dl_main = $u_main . (strpos($u_main, '?') !== false ? '&download=1' : ''); ?>
                        <div style="margin-top:6px;display:flex;gap:6px">
                            <a href="<?php echo htmlspecialchars($u_main); ?>" target="_blank" style="padding:6px 10px;border-radius:6px;background:#3b82f6;color:#fff;text-decoration:none">View PDF</a>
                            <a href="<?php echo htmlspecialchars($dl_main); ?>" style="padding:6px 10px;border-radius:6px;background:#6366f1;color:#fff;text-decoration:none">Download</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif;
            $cstmt = $pdo->prepare('SELECT id, doc_number, client_id, status, signed_pdf_path, created_at, subtotal, total, deposit_amount, deposit_paid FROM contracts WHERE project_code = ? AND client_id = ? ORDER BY created_at DESC');
            $cstmt->execute([$project_code, $clientId]);
            $contracts = $cstmt->fetchAll();
            if (!$contracts):
            ?>
                <div style="color:var(--muted)">No contracts found for this job.</div>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;display:grid;gap:8px">
                    <?php foreach ($contracts as $con): ?>
                        <?php $coid = (int)$con['id']; ?>
                        <li style="display:block;border-bottom:1px solid #f3f4f6;padding:10px">
                            <div style="display:flex;gap:8px;align-items:center">
                                <div class="doc-toggle" data-type="contract" data-id="<?php echo $coid; ?>" style="flex:1;cursor:pointer">C-<?php echo (int)($con['doc_number'] ?? $con['id']); ?> · <?php echo htmlspecialchars($con['status']); ?> · <?php echo htmlspecialchars($con['created_at']); ?></div>
                                <div style="display:flex;gap:6px;align-items:center">
                                    <?php if (!empty($con['signed_pdf_path'])): ?>
                                        <?php $u = (string)$con['signed_pdf_path'];
                                        $dl = $u . (strpos($u, '?') !== false ? '&download=1' : ''); ?>
                                        <a href="<?php echo htmlspecialchars($u); ?>" target="_blank" style="padding:6px 10px;border-radius:6px;background:#3b82f6;color:#fff;text-decoration:none">View PDF</a>
                                        <a href="<?php echo htmlspecialchars($dl); ?>" style="padding:6px 10px;border-radius:6px;background:#6366f1;color:#fff;text-decoration:none;margin-left:6px;">Download</a>
                                    <?php endif; ?>
                                    <button type="button" class="doc-toggle" data-type="contract" data-id="<?php echo $coid; ?>" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;background:#fff">Details</button>
                                    <a href="/?page=contract/contracts-edit&id=<?php echo $coid; ?>" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;background:#fff">View Document</a>
                                </div>
                            </div>
                            <div class="doc-details" id="contract-details-<?php echo $coid; ?>" style="display:none;margin-top:8px;padding:8px;border-top:1px solid #eee">
                                <?php
                                $citems = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM contract_items WHERE contract_id=?');
                                $citems->execute([$coid]);
                                $citemsRows = $citems->fetchAll();
                                ?>
                                <div style="font-weight:600;margin-bottom:6px">Items</div>
                                <?php if ($citemsRows): ?>
                                    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
                                        <?php foreach ($citemsRows as $ci): ?>
                                            <li><?php echo htmlspecialchars($ci['description']); ?> · <?php echo (float)$ci['quantity']; ?> × $<?php echo number_format((float)$ci['unit_price'], 2); ?> = $<?php echo number_format((float)$ci['line_total'], 2); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div style="color:var(--muted)">No items listed.</div>
                                <?php endif; ?>
                                <div style="margin-top:6px">
                                    <div>Subtotal: $<?php echo number_format((float)($con['subtotal'] ?? 0), 2); ?></div>
                                    <div>Total: $<?php echo number_format((float)($con['total'] ?? 0), 2); ?></div>
                                    <?php if (!empty($con['deposit_amount']) && $con['deposit_amount'] > 0): ?>
                                        <div>Deposit: $<?php echo number_format((float)$con['deposit_amount'], 2); ?> (Paid: $<?php echo number_format((float)$con['deposit_paid'] ?? 0, 2); ?>)</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div style="border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
            <h4>Invoices</h4>
            <?php
            $istmt = $pdo->prepare('SELECT id, doc_number, invoice_type, client_id, total, status, created_at FROM invoices WHERE project_code = ? AND client_id = ? ORDER BY created_at DESC');
            $istmt->execute([$project_code, $clientId]);
            $invoices = $istmt->fetchAll();
            if (!$invoices):
            ?>
                <div style="color:var(--muted)">No invoices found for this job.</div>
            <?php else: ?>
                <ul style="list-style:none;padding:0;margin:0;display:grid;gap:8px">
                    <?php foreach ($invoices as $inv): ?>
                        <?php $ivId = (int)$inv['id']; ?>
                        <li style="display:block;border-bottom:1px solid #f3f4f6;padding:10px">
                            <div style="display:flex;gap:8px;align-items:center">
                                <div class="doc-toggle" data-type="invoice" data-id="<?php echo $ivId; ?>" style="flex:1;cursor:pointer"><?php echo htmlspecialchars(pa_invoice_label_from_row($inv)); ?> · $<?php echo number_format((float)($inv['total'] ?? 0), 2); ?> · <?php echo htmlspecialchars($inv['status']); ?> · <?php echo htmlspecialchars($inv['created_at']); ?></div>
                                <div style="display:flex;gap:6px;align-items:center">
                                    <button type="button" class="doc-toggle" data-type="invoice" data-id="<?php echo $ivId; ?>" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;background:#fff">Details</button>
                                    <a href="/?page=invoice/invoices-edit&id=<?php echo $ivId; ?>" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;background:#fff">View Document</a>
                                </div>
                            </div>
                            <div class="doc-details" id="invoice-details-<?php echo $ivId; ?>" style="display:none;margin-top:8px;padding:8px;border-top:1px solid #eee">
                                <?php
                                $it = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM invoice_items WHERE invoice_id=?');
                                $it->execute([$ivId]);
                                $itemsInv = $it->fetchAll();
                                ?>
                                <div style="font-weight:600;margin-bottom:6px">Items</div>
                                <?php if ($itemsInv): ?>
                                    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
                                        <?php foreach ($itemsInv as $ivi): ?>
                                            <li><?php echo htmlspecialchars($ivi['description']); ?> · <?php echo (float)$ivi['quantity']; ?> × $<?php echo number_format((float)$ivi['unit_price'], 2); ?> = $<?php echo number_format((float)$ivi['line_total'], 2); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div style="color:var(--muted)">No items listed.</div>
                                <?php endif; ?>
                                <div style="margin-top:6px">Total: $<?php echo number_format((float)($inv['total'] ?? 0), 2); ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div style="border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
            <h4>Project Document Mappings (manual Projects only)</h4>
            <?php
            // Attempt to find a manual Project with the same name as the job code
            $pcheck = $pdo->prepare('SELECT id FROM projects WHERE name = ? LIMIT 1');
            $pcheck->execute([$project_code]);
            $projId = (int)$pcheck->fetchColumn();
            if (!$projId) {
            ?>
                <div style="color:var(--muted)">This job is not mapped to a manual Project (no mappings).</div>
                <?php } else {
                $pd = $pdo->prepare('SELECT id, document_type, document_id FROM project_documents WHERE project_id = ? ORDER BY created_at DESC');
                $pd->execute([$projId]);
                $pdrows = $pd->fetchAll();
                if (!$pdrows):
                ?>
                    <div style="color:var(--muted)">No manual project document mappings found.</div>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:8px">
                        <?php foreach ($pdrows as $m): ?>
                            <li style="display:flex;gap:8px;align-items:center">
                                <div style="flex:1"><?php echo htmlspecialchars($m['document_type']); ?> #<?php echo (int)$m['document_id']; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php } ?>
        </div>
    </div>
</section>
<script>
    // Toggle document details in Job Details page
    document.addEventListener('click', function(e) {
        const t = e.target;
        if (!t.classList) return;
        if (t.classList.contains('doc-toggle')) {
            const dtype = t.getAttribute('data-type');
            const did = t.getAttribute('data-id');
            const el = document.getElementById(dtype + '-details-' + did);
            if (el) {
                if (el.style.display === 'none' || el.style.display === '') {
                    el.style.display = 'block';
                    t.textContent = 'Hide';
                } else {
                    el.style.display = 'none';
                    t.textContent = 'Details';
                }
            }
        }
    });
</script>
