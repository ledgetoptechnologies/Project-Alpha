<?php
// src/views/pages/invoice/notifications-list.php
// Admin UI for viewing sent invoice reminders
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
?>

<div class="container content-wrapper">
    <div class="page-header">
        <h1>Invoice Delivery Log</h1>
        <p class="text-muted">View generated-invoice emails, reminders, retries, suppressions, and delivery errors</p>
    </div>

    <!-- Filter Form -->
    <div class="card mb-3">
        <div class="card-header">
            <h5>Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="form-inline gap-2 flex-wrap">
                <input type="hidden" name="page" value="invoice/notifications-list">
                
                <div class="form-group">
                    <label for="type" class="form-label me-2">Type:</label>
                    <select name="type" id="type" class="form-control form-control-sm">
                        <option value="">All Types</option>
                        <option value="on_generate">Generated recurring invoice</option>
                        <option value="on_demand_generate">Generated on-demand invoice</option>
                        <option value="on_finalize">Finalized invoice</option>
                        <option value="due_7" <?php echo ($_GET['type'] ?? '') === 'due_7' ? 'selected' : ''; ?>>
                            Due in 7 Days
                        </option>
                        <option value="overdue_weekly" <?php echo ($_GET['type'] ?? '') === 'overdue_weekly' ? 'selected' : ''; ?>>
                            Overdue Weekly
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="invoice_id" class="form-label me-2">Invoice ID:</label>
                    <input type="number" name="invoice_id" id="invoice_id" class="form-control form-control-sm" 
                           value="<?php echo htmlspecialchars($_GET['invoice_id'] ?? ''); ?>" placeholder="Enter ID">
                </div>

                <div class="form-group">
                    <label for="date_from" class="form-label me-2">From:</label>
                    <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" 
                           value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="date_to" class="form-label me-2">To:</label>
                    <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" 
                           value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="?page=invoice/notifications-list" class="btn btn-secondary btn-sm">Clear</a>
            </form>
        </div>
    </div>

    <!-- Results Summary -->
    <div class="alert alert-info">
        <strong><?php echo $totalCount; ?></strong> delivery action(s) found
        <?php if ($totalCount > $perPage): ?>
            (showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $perPage, $totalCount); ?>)
        <?php endif; ?>
    </div>

    <!-- Notifications Table -->
    <?php if (empty($notifications)): ?>
        <div class="alert alert-warning">
            <strong>No reminders found.</strong> Try adjusting your filters or check back later.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Delivery Type</th>
                        <th>Delivery Status</th>
                        <th>Sent / Next Attempt</th>
                        <th>Client Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $n): ?>
                        <tr>
                            <td>
                                <a href="/?page=invoice/invoice-details&id=<?php echo (int)$n['invoice_id']; ?>">
                                    <?php echo htmlspecialchars(pa_invoice_label_from_row($n)); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($n['client_name']); ?></td>
                            <td>$<?php echo number_format((float)$n['total'], 2); ?></td>
                            <td>
                                <?php 
                                    $dueDate = new DateTime($n['due_date']);
                                    $today = new DateTime();
                                    $isOverdue = $dueDate < $today;
                                    $class = $isOverdue ? 'text-danger' : '';
                                ?>
                                <span class="<?php echo $class; ?>">
                                    <?php echo $dueDate->format('M d, Y'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo match($n['status']) {
                                        'draft' => 'secondary',
                                        'sent' => 'info',
                                        'viewed' => 'primary',
                                        'partial' => 'warning',
                                        'paid' => 'success',
                                        default => 'light'
                                    };
                                ?>">
                                    <?php echo ucfirst($n['status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($n['notification_type']))); ?></td>
                            <td>
                                <span class="badge bg-<?php echo match($n['delivery_status']) { 'sent'=>'success', 'retry'=>'danger', 'suppressed'=>'secondary', 'processing'=>'warning', default=>'info' }; ?>">
                                    <?php echo htmlspecialchars(ucfirst($n['delivery_status'])); ?>
                                </span>
                                <?php if ((int)$n['attempt_count'] > 0): ?><small>attempt <?php echo (int)$n['attempt_count']; ?></small><?php endif; ?>
                                <?php if (!empty($n['last_error'])): ?><div class="text-danger small"><?php echo htmlspecialchars($n['last_error']); ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php $eventTime = $n['sent_at'] ?: $n['next_attempt_at']; ?>
                                <?php echo $eventTime ? (new DateTime($eventTime))->format('M d, Y H:i') : '&mdash;'; ?>
                            </td>
                            <td>
                                <code class="text-muted"><?php echo htmlspecialchars($n['email_to'] ?: $n['client_email']); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Pagination">
                <ul class="pagination justify-content-center">
                    <?php if ($pageNum > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=invoice/notifications-list&p=1<?php 
                                echo (!empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '') .
                                     (!empty($_GET['invoice_id']) ? '&invoice_id=' . urlencode($_GET['invoice_id']) : '') .
                                     (!empty($_GET['date_from']) ? '&date_from=' . urlencode($_GET['date_from']) : '') .
                                     (!empty($_GET['date_to']) ? '&date_to=' . urlencode($_GET['date_to']) : '');
                            ?>">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?page=invoice/notifications-list&p=<?php echo $pageNum - 1; ?><?php 
                                echo (!empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '') .
                                     (!empty($_GET['invoice_id']) ? '&invoice_id=' . urlencode($_GET['invoice_id']) : '') .
                                     (!empty($_GET['date_from']) ? '&date_from=' . urlencode($_GET['date_from']) : '') .
                                     (!empty($_GET['date_to']) ? '&date_to=' . urlencode($_GET['date_to']) : '');
                            ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $pageNum ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=invoice/notifications-list&p=<?php echo $i; ?><?php 
                                echo (!empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '') .
                                     (!empty($_GET['invoice_id']) ? '&invoice_id=' . urlencode($_GET['invoice_id']) : '') .
                                     (!empty($_GET['date_from']) ? '&date_from=' . urlencode($_GET['date_from']) : '') .
                                     (!empty($_GET['date_to']) ? '&date_to=' . urlencode($_GET['date_to']) : '');
                            ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($pageNum < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=invoice/notifications-list&p=<?php echo $pageNum + 1; ?><?php 
                                echo (!empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '') .
                                     (!empty($_GET['invoice_id']) ? '&invoice_id=' . urlencode($_GET['invoice_id']) : '') .
                                     (!empty($_GET['date_from']) ? '&date_from=' . urlencode($_GET['date_from']) : '') .
                                     (!empty($_GET['date_to']) ? '&date_to=' . urlencode($_GET['date_to']) : '');
                            ?>">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?page=invoice/notifications-list&p=<?php echo $totalPages; ?><?php 
                                echo (!empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '') .
                                     (!empty($_GET['invoice_id']) ? '&invoice_id=' . urlencode($_GET['invoice_id']) : '') .
                                     (!empty($_GET['date_from']) ? '&date_from=' . urlencode($_GET['date_from']) : '') .
                                     (!empty($_GET['date_to']) ? '&date_to=' . urlencode($_GET['date_to']) : '');
                            ?>">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
    .form-inline {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    
    .form-inline .form-group {
        display: flex;
        align-items: center;
        margin-right: 1rem;
        margin-bottom: 0.5rem;
    }
    
    .form-inline .form-label {
        margin-bottom: 0;
        white-space: nowrap;
    }
    
    @media (max-width: 768px) {
        .form-inline .form-group {
            flex: 0 0 100%;
            margin-right: 0;
            margin-bottom: 1rem;
        }
    }
</style>
