<?php
// src/views/pages/financial/receipt-detail.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$receiptId = (int)($_GET['id'] ?? 0);
$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);

// Get existing stores for edit modal
$storeStmt = $pdo->prepare('SELECT DISTINCT name FROM vendors ORDER BY name');
$storeStmt->execute();
$stores = $storeStmt->fetchAll(PDO::FETCH_COLUMN);

if (!$receiptId) {
    header('Location: /?page=financial/expenses-list&tab=receipts');
    exit;
}

// Fetch receipt details
[$receiptScopeWhere, $receiptScopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');
$stmt = $pdo->prepare('
    SELECT r.*, rs.name as store_name
    FROM receipts r
    LEFT JOIN vendors rs ON r.store_id = rs.id
    WHERE r.id = ? AND ' . $receiptScopeWhere . '
');
$stmt->execute(array_merge([$receiptId], $receiptScopeParams));
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    header('Location: /?page=financial/expenses-list&tab=receipts');
    exit;
}

$fileExt = strtolower(pathinfo($receipt['file_path'], PATHINFO_EXTENSION));
$isPdf = $fileExt === 'pdf';
?>

<div style="max-width:1200px;margin:0 auto;padding:24px">
    <div style="margin-bottom:24px">
        <a href="/?page=financial/expenses-list&tab=receipts" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Receipts
        </a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 400px;gap:32px;align-items:start">
        <!-- Receipt Preview -->
        <div style="min-width:0">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
                <?php 
                $fileParam = str_replace('/src/uploads/', '', $receipt['file_path']);
                $fileUrl = '/?page=serve-upload&file=' . urlencode($fileParam);
                ?>
                <?php if ($isPdf): ?>
                    <div style="height:800px;max-height:800px;min-height:800px">
                        <iframe src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                style="width:100%;height:800px;max-height:800px;border:0;display:block"></iframe>
                    </div>
                <?php else: ?>
                    <img src="<?php echo htmlspecialchars($fileUrl); ?>" 
                         alt="Receipt" 
                         style="width:100%;height:auto;display:block">
                <?php endif; ?>
            </div>
        </div>

        <!-- Receipt Info & Actions -->
        <div style="width:400px;max-width:400px;min-width:400px">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:16px">
                <h1 style="margin:0 0 16px 0;font-size:24px"><?php echo htmlspecialchars($receipt['description'] ?? 'Receipt'); ?></h1>
                
                <div style="display:grid;gap:16px">
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Amount</div>
                        <div style="font-size:32px;font-weight:700;color:var(--nav-accent)">
                            $<?php echo number_format($receipt['amount'], 2); ?>
                        </div>
                    </div>

                    <?php if (!empty($receipt['store_name'])): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Store</div>
                        <div class="font-600">
                            <?php echo htmlspecialchars($receipt['store_name']); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="padding-top:16px;border-top:1px solid #e5e7eb">
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Receipt Date</div>
                        <div class="font-600">
                            <?php echo date('F j, Y', strtotime($receipt['receipt_date'])); ?>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Uploaded</div>
                        <div class="font-600">
                            <?php echo date('F j, Y', strtotime($receipt['created_at'])); ?>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">File Type</div>
                        <div style="font-weight:600;text-transform:uppercase">
                            <?php echo htmlspecialchars($fileExt); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px">
                <div style="font-weight:600;margin-bottom:12px">Actions</div>
                
                <div class="grid">
                    <!-- Download -->
                    <a href="<?php echo htmlspecialchars($fileUrl . '&download=1'); ?>" 
                       download
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        📥 Download
                    </a>

                    <!-- View in New Tab -->
                    <a href="<?php echo htmlspecialchars($fileUrl); ?>" 
                       target="_blank"
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        🔗 View in New Tab
                    </a>

                    <!-- Create Expense -->
                    <a href="/?page=financial/expense-create&receipt_id=<?php echo (int)$receipt['id']; ?>&amount=<?php echo htmlspecialchars($receipt['amount']); ?>&date=<?php echo htmlspecialchars($receipt['receipt_date']); ?>&vendor=<?php echo urlencode($receipt['store_name'] ?? ''); ?>"
                       class="btn btn-sm" style="width:100%;text-align:center;margin-bottom:4px">
                        Create Expense
                    </a>

                    <!-- Edit -->
                    <button onclick="showEditModal()" class="btn btn-sm" style="width:100%;text-align:center">
                        Edit Details
                    </button>

                    <!-- Delete -->
                    <button onclick="confirmDelete()" class="btn btn-sm" style="width:100%;text-align:center;background:#fef2f2;border-color:#fca5a5;color:#991b1b;margin-top:8px">
                        Delete Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0">Edit Receipt</h3>
        
        <form id="editForm" method="post" action="/?page=receipts-handler" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="receipt_id" value="<?php echo $receiptId; ?>">
            
            <div style="display:grid;gap:16px">
                <div>
                    <label style="display:block;margin-bottom:4px;font-weight:600">Store Name</label>
                    <input type="text" name="store_name" value="<?php echo htmlspecialchars($receipt['store_name'] ?? ''); ?>" list="editStoresList"
                           placeholder="e.g., Home Depot, Walmart"
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <datalist id="editStoresList">
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo htmlspecialchars($store); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div>
                    <label style="display:block;margin-bottom:4px;font-weight:600">Description *</label>
                    <input type="text" name="description" value="<?php echo htmlspecialchars($receipt['description'] ?? ''); ?>" required
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                </div>

                <div>
                    <label style="display:block;margin-bottom:4px;font-weight:600">Receipt Date *</label>
                    <input type="date" name="receipt_date" value="<?php echo htmlspecialchars($receipt['receipt_date']); ?>" required
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                </div>

                <div>
                    <label style="display:block;margin-bottom:4px;font-weight:600">Amount *</label>
                    <div style="position:relative">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted)">$</span>
                        <input type="number" name="amount" value="<?php echo htmlspecialchars($receipt['amount']); ?>" required step="0.01" min="0"
                               style="width:100%;padding:10px 10px 10px 24px;border:1px solid #ddd;border-radius:8px">
                    </div>
                </div>

                <div>
                    <label style="display:block;margin-bottom:4px;font-weight:600">Replace File (Optional)</label>
                    <input type="file" name="receipt_file" accept="image/*,.pdf"
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                        Leave empty to keep current file
                    </div>
                </div>

                <div style="display:flex;gap:12px;margin-top:8px">
                    <button type="submit" id="updateBtn"
                            style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                        Update
                    </button>
                    <button type="button" onclick="closeEditModal()"
                            style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                        Cancel
                    </button>
                </div>
            </div>

            <div id="editMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
        </form>
    </div>
</div>

<script>
    window.receiptCsrfToken = <?php echo json_encode(csrf_token()); ?>;
    window.receiptId = <?php echo (int)$receiptId; ?>;
</script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/receipt-detail-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
