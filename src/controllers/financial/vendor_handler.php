<?php
// src/controllers/financial/vendor_handler.php
// POST controller for vendor CRUD: create, update, deactivate, merge.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

function vendor_handler_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function vendor_handler_finish(array $response, int $status = 200, string $fallback = '/?page=financial/vendor-form'): void
{
    if (vendor_handler_is_ajax()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (!empty($response['success'])) {
        $redirect = (string)($response['redirect'] ?? '/?page=financial/setup&tab=vendors');
        $join = str_contains($redirect, '?') ? '&' : '?';
        header('Location: ' . $redirect . $join . 'success=' . rawurlencode((string)($response['message'] ?? 'Vendor saved')));
        exit;
    }

    $join = str_contains($fallback, '?') ? '&' : '?';
    header('Location: ' . $fallback . $join . 'error=' . rawurlencode((string)($response['message'] ?? 'Vendor request failed')));
    exit;
}

$response = ['success' => false, 'message' => ''];

// Auth required
if (empty($_SESSION['user']['id'])) {
    $response['message'] = 'Authentication required';
    vendor_handler_finish($response, 401, '/?page=login');
}

$userId = (int)$_SESSION['user']['id'];
$orgId  = request_client_org_id();
if (!user_can($pdo, $userId, 'financial.manage', 0)) {
    $response['message'] = 'Permission denied';
    vendor_handler_finish($response, 403, '/?page=financial/setup&tab=vendors');
}

// CSRF required: accept legacy 'csrf' or Symfony '_token'
$csrfOk = false;
$submitted = $_POST['_token'] ?? '';
if (is_string($submitted) && $submitted !== '') {
    $csrfOk = csrf_sf_is_valid('vendor', $submitted);
} else {
    $csrfOk = csrf_validate();
}
if (!$csrfOk) {
    $response['message'] = 'Invalid request (CSRF validation failed)';
    vendor_handler_finish($response, 400, '/?page=financial/vendor-form');
}

$action = $_POST['action'] ?? '';

function jsonResponse(bool $success, string $message, array $extra = []): void {
    vendor_handler_finish(array_merge(['success' => $success, 'message' => $message], $extra), $success ? 200 : 400);
}

function trimNullable(string $value): ?string {
    $value = trim($value);
    return $value === '' ? null : $value;
}

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                jsonResponse(false, 'Vendor name is required');
            }

            $email = trimNullable($_POST['email'] ?? '');
            $phone = trimNullable($_POST['phone'] ?? '');
            $website = trimNullable($_POST['website'] ?? '');
            $taxId = trimNullable($_POST['tax_id'] ?? '');
            $defaultCategoryId = isset($_POST['default_category_id']) && $_POST['default_category_id'] !== ''
                ? (int)$_POST['default_category_id']
                : null;
            $notes = trimNullable($_POST['notes'] ?? '');
            $address = trimNullable($_POST['address'] ?? '');

            $stmt = $pdo->prepare('
                INSERT INTO vendors
                    (organization_id, name, email, phone, website, tax_id, default_category_id, notes, address, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ');
            $stmt->execute([$orgId > 0 ? $orgId : null, $name, $email, $phone, $website, $taxId, $defaultCategoryId, $notes, $address]);

            $vendorId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'vendor.create', 'vendor', $vendorId, [
                'organization_id' => $orgId,
                'name' => $name,
                'default_category_id' => $defaultCategoryId,
            ]);

            jsonResponse(true, 'Vendor created successfully', ['id' => $vendorId]);

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid vendor ID');
            }

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                jsonResponse(false, 'Vendor name is required');
            }

            $email = trimNullable($_POST['email'] ?? '');
            $phone = trimNullable($_POST['phone'] ?? '');
            $website = trimNullable($_POST['website'] ?? '');
            $taxId = trimNullable($_POST['tax_id'] ?? '');
            $defaultCategoryId = isset($_POST['default_category_id']) && $_POST['default_category_id'] !== ''
                ? (int)$_POST['default_category_id']
                : null;
            $notes = trimNullable($_POST['notes'] ?? '');
            $address = trimNullable($_POST['address'] ?? '');

            $stmt = $pdo->prepare('
                UPDATE vendors
                SET name = ?, email = ?, phone = ?, website = ?, tax_id = ?,
                    default_category_id = ?, notes = ?, address = ?, updated_at = NOW()
                WHERE id = ? AND is_active = 1
            ');
            $stmt->execute([$name, $email, $phone, $website, $taxId, $defaultCategoryId, $notes, $address, $id]);

            if ($stmt->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM vendors WHERE id = ?');
                $exists->execute([$id]);
                if (!$exists->fetch()) {
                    jsonResponse(false, 'Vendor not found');
                }
            }

            audit_log($pdo, 'vendor.update', 'vendor', $id, [
                'organization_id' => $orgId,
                'name' => $name,
                'default_category_id' => $defaultCategoryId,
            ]);

            jsonResponse(true, 'Vendor updated successfully', ['id' => $id]);

        case 'deactivate':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid vendor ID');
            }

            $stmt = $pdo->prepare('
                UPDATE vendors
                SET is_active = 0, updated_at = NOW()
                WHERE id = ? AND is_active = 1
            ');
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                jsonResponse(false, 'Vendor not found or already inactive');
            }

            audit_log($pdo, 'vendor.deactivate', 'vendor', $id, ['organization_id' => $orgId]);

            jsonResponse(true, 'Vendor deactivated successfully');

        case 'merge':
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $targetId = (int)($_POST['target_id'] ?? 0);

            if ($sourceId <= 0 || $targetId <= 0) {
                jsonResponse(false, 'Source and target vendor IDs are required');
            }
            if ($sourceId === $targetId) {
                jsonResponse(false, 'Source and target vendors must be different');
            }

            // Verify both vendors belong to this organization
            $check = $pdo->prepare('SELECT id, is_active FROM vendors WHERE id IN (?, ?)');
            $check->execute([$sourceId, $targetId]);
            $found = $check->fetchAll();
            if (count($found) !== 2) {
                jsonResponse(false, 'One or both vendors not found');
            }
            foreach ($found as $v) {
                if ((int)$v['is_active'] !== 1) {
                    jsonResponse(false, 'Cannot merge inactive vendors');
                }
            }

            // Reassign expenses
            $updateExpenses = $pdo->prepare('UPDATE expenses SET vendor_id = ? WHERE vendor_id = ?');
            $updateExpenses->execute([$targetId, $sourceId]);
            $reassigned = (int)$updateExpenses->rowCount();

            // Deactivate source vendor
            $deactivate = $pdo->prepare('
                UPDATE vendors
                SET is_active = 0, updated_at = NOW()
                WHERE id = ?
            ');
            $deactivate->execute([$sourceId]);

            audit_log($pdo, 'vendor.merge', 'vendor', $targetId, [
                'organization_id' => $orgId,
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'reassigned_expenses' => $reassigned,
            ]);
            audit_log($pdo, 'vendor.deactivate', 'vendor', $sourceId, [
                'organization_id' => $orgId,
                'reason' => 'merged_into_' . $targetId,
            ]);

            jsonResponse(true, 'Vendors merged successfully', [
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'reassigned_expenses' => $reassigned,
            ]);

        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Throwable $e) {
    error_log('[vendor_handler] Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while processing the request');
}
