<?php
// src/views/pages/financial/_receipts_tab.php (Expenses > Receipts)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);

// Get filter parameters with defaults to current year/month
$filterStore = $_GET['store'] ?? '';
$filterMonth = $_GET['month'] ?? date('n'); // Current month (1-12)
$filterYear = $_GET['year'] ?? date('Y'); // Current year
$filterMinAmount = $_GET['min_amount'] ?? '';
$filterMaxAmount = $_GET['max_amount'] ?? '';

// Build WHERE clause
[$receiptScopeWhere, $receiptScopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');
$whereClauses = [$receiptScopeWhere];
$params = $receiptScopeParams;

if (!empty($filterStore)) {
    $whereClauses[] = 'rs.name = ?';
    $params[] = $filterStore;
}
if (!empty($filterMonth) && !empty($filterYear)) {
    $whereClauses[] = 'YEAR(r.receipt_date) = ? AND MONTH(r.receipt_date) = ?';
    $params[] = $filterYear;
    $params[] = $filterMonth;
} elseif (!empty($filterYear)) {
    $whereClauses[] = 'YEAR(r.receipt_date) = ?';
    $params[] = $filterYear;
}
if (!empty($filterMinAmount)) {
    $whereClauses[] = 'r.amount >= ?';
    $params[] = $filterMinAmount;
}
if (!empty($filterMaxAmount)) {
    $whereClauses[] = 'r.amount <= ?';
    $params[] = $filterMaxAmount;
}

$whereSQL = implode(' AND ', $whereClauses);

// Fetch filtered receipts with expense status
$stmt = $pdo->prepare("
    SELECT r.*, v.name as store_name, e.id as expense_id
    FROM receipts r
    LEFT JOIN vendors v ON r.store_id = v.id
    LEFT JOIN expenses e ON e.receipt_id = r.id AND e.status != 'void'
    WHERE {$whereSQL}
    ORDER BY r.receipt_date DESC, r.created_at DESC
");
$stmt->execute($params);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = array_sum(array_column($receipts, 'amount'));

// Get all stores for filter dropdown
$storeStmt = $pdo->prepare('SELECT DISTINCT name FROM vendors ORDER BY name');
$storeStmt->execute();
$stores = $storeStmt->fetchAll(PDO::FETCH_COLUMN);

// Get available years
$yearStmt = $pdo->prepare('SELECT DISTINCT YEAR(r.receipt_date) as year FROM receipts r WHERE ' . $receiptScopeWhere . ' ORDER BY year DESC');
$yearStmt->execute($receiptScopeParams);
$years = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

// Always include current year if not present
$currentYear = (int)date('Y');
if (!in_array($currentYear, $years)) {
    array_unshift($years, $currentYear);
}
?>

<section class="expense-ledger receipt-ledger">
    <?php if (!empty($_GET['created'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px">Receipt uploaded.</div>
    <?php elseif (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px">Receipt deleted.</div>
    <?php elseif (!empty($_GET['error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:16px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <?php
    // Prepare store options
    $storeOptions = [['value' => '', 'label' => 'All Stores']];
    foreach ($stores as $store) {
        $storeOptions[] = ['value' => $store, 'label' => $store];
    }
    
    // Prepare year options
    $yearOptions = [['value' => '', 'label' => 'All Years']];
    foreach ($years as $year) {
        $yearOptions[] = ['value' => (string)$year, 'label' => (string)$year];
    }
    
    // Prepare month options
    $monthOptions = [['value' => '', 'label' => 'All Months']];
    for ($m = 1; $m <= 12; $m++) {
        $monthOptions[] = ['value' => (string)$m, 'label' => date('F', mktime(0, 0, 0, $m, 1))];
    }
    
    $filterConfig = [
        'page' => 'financial/expenses-list',
        'hidden' => ['tab' => 'receipts'],
        'filters' => [
            'store' => [
                'type' => 'select',
                'label' => 'Store',
                'value' => $filterStore,
                'options' => $storeOptions
            ],
            'year' => [
                'type' => 'select',
                'label' => 'Year',
                'value' => (string)$filterYear,
                'options' => $yearOptions
            ],
            'month' => [
                'type' => 'select',
                'label' => 'Month',
                'value' => (string)$filterMonth,
                'options' => $monthOptions
            ],
            'min_amount' => [
                'type' => 'number',
                'label' => 'Min Amount ($)',
                'value' => $filterMinAmount,
                'step' => '0.01',
                'placeholder' => '0.00'
            ],
            'max_amount' => [
                'type' => 'number',
                'label' => 'Max Amount ($)',
                'value' => $filterMaxAmount,
                'step' => '0.01',
                'placeholder' => '999.99'
            ]
        ]
    ];
    echo render_template('components/document-filter.html.twig', $filterConfig);
    ?>

    <!-- Summary Card -->
    <div style="margin-bottom:24px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
            <div>
                <div style="color:var(--muted);font-size:13px;margin-bottom:4px">Total Receipts</div>
                <div style="font-size:24px;font-weight:700"><?php echo count($receipts); ?></div>
            </div>
            <div>
                <div style="color:var(--muted);font-size:13px;margin-bottom:4px">Total Amount</div>
                <div style="font-size:24px;font-weight:700">$<?php echo number_format($totalAmount, 2); ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($receipts)): ?>
        <div style="text-align:center;padding:64px 24px;border:2px dashed #e5e7eb;border-radius:12px">
            <div style="font-size:48px;margin-bottom:16px">📄</div>
            <h2 style="margin:0 0 8px 0;font-size:20px">No receipts yet</h2>
            <p style="margin:0;color:var(--muted)">Upload your first business expense receipt to get started.</p>
        </div>
    <?php else: ?>
        <!-- Receipts Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px">
            <?php foreach ($receipts as $receipt): 
                $fileExt = strtolower(pathinfo($receipt['file_path'], PATHINFO_EXTENSION));
                $isPdf = $fileExt === 'pdf';
            ?>
                <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff">
                    <!-- Receipt Preview -->
                    <div style="height:200px;background:#f9fafb;display:flex;align-items:center;justify-content:center;overflow:hidden">
                        <?php if ($isPdf): ?>
                            <div style="text-align:center;color:var(--muted)">
                                <div style="font-size:48px;margin-bottom:8px">📄</div>
                                <div style="font-size:12px;font-weight:600">PDF Document</div>
                            </div>
                        <?php else: ?>
                            <?php 
                            $fileParam = str_replace('/src/uploads/', '', $receipt['file_path']);
                            ?>
                            <img src="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>" 
                                 alt="Receipt preview" 
                                 style="width:100%;height:100%;object-fit:cover"
                                 loading="lazy">
                        <?php endif; ?>
                    </div>
                    
                    <!-- Receipt Info -->
                    <div style="padding:12px">
                        <div style="font-weight:600;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo htmlspecialchars($receipt['description'] ?? 'Receipt'); ?>
                        </div>
                        <?php if (!empty($receipt['store_name'])): ?>
                            <div style="font-size:13px;color:var(--muted);margin-bottom:4px">
                                <?php echo htmlspecialchars($receipt['store_name']); ?>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <div style="font-size:20px;font-weight:700;color:var(--nav-accent)">
                                $<?php echo number_format($receipt['amount'], 2); ?>
                            </div>
                            <div style="font-size:13px;color:var(--muted)">
                                <?php echo date('M j, Y', strtotime($receipt['receipt_date'])); ?>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                            <?php 
                            $fileParam = str_replace('/src/uploads/', '', $receipt['file_path']);
                            ?>
                            <a href="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>&download=1" download class="btn btn-sm">Download</a>
                            <a href="/?page=financial/receipt-detail&id=<?php echo $receipt['id']; ?>" class="btn btn-sm btn-primary">View</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
