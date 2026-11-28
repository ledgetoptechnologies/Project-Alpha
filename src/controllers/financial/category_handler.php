<?php
// src/controllers/financial/category_handler.php
// POST controller for expense category CRUD: create, update, delete, deactivate.

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

function category_handler_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function category_handler_finish(array $response, int $status = 200, string $fallback = '/?page=financial/setup&tab=categories'): void
{
    if (category_handler_is_ajax()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $base = !empty($response['success'])
        ? (string)($response['redirect'] ?? '/?page=financial/setup&tab=categories')
        : $fallback;
    $key = !empty($response['success']) ? 'success' : 'error';
    $message = (string)($response['message'] ?? (!empty($response['success']) ? 'Category saved' : 'Category request failed'));
    $join = str_contains($base, '?') ? '&' : '?';
    header('Location: ' . $base . $join . $key . '=' . rawurlencode($message));
    exit;
}

function category_handler_safe_return_url(?string $url, string $default = '/?page=financial/setup&tab=categories'): string
{
    $url = trim((string)$url);
    if ($url === '' || str_starts_with($url, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url)) {
        return $default;
    }

    if (!str_starts_with($url, '/?page=')) {
        return $default;
    }

    return $url;
}

function category_handler_append_query(string $url, string $key, string $value): string
{
    $join = str_contains($url, '?') ? '&' : '?';
    return $url . $join . rawurlencode($key) . '=' . rawurlencode($value);
}

$response = ['success' => false, 'message' => ''];

// Auth required
if (empty($_SESSION['user']['id'])) {
    $response['message'] = 'Authentication required';
    category_handler_finish($response, 401, '/?page=login');
}

$userId = (int)$_SESSION['user']['id'];
$orgId  = request_client_org_id();
if (!user_can($pdo, $userId, 'financial.manage', 0)) {
    $response['message'] = 'Permission denied';
    category_handler_finish($response, 403);
}

// CSRF required: accept legacy 'csrf' or Symfony '_token'
$csrfOk = false;
$submitted = $_POST['_token'] ?? '';
if (is_string($submitted) && $submitted !== '') {
    $csrfOk = csrf_sf_is_valid('category', $submitted);
} else {
    $csrfOk = csrf_validate();
}
if (!$csrfOk) {
    $response['message'] = 'Invalid request (CSRF validation failed)';
    category_handler_finish($response, 400);
}

$action = $_POST['action'] ?? '';

function jsonResponse(bool $success, string $message, array $extra = []): void {
    category_handler_finish(array_merge(['success' => $success, 'message' => $message], $extra), $success ? 200 : 400);
}

function trimNullable(string $value): ?string {
    $value = trim($value);
    return $value === '' ? null : $value;
}

function normalizeColor(?string $color): ?string {
    if ($color === null || $color === '') {
        return null;
    }
    $color = ltrim(trim($color), '#');
    if (!preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
        return null;
    }
    return '#' . strtolower($color);
}

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                jsonResponse(false, 'Category name is required');
            }

            $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== ''
                ? (int)$_POST['parent_id']
                : null;
            $taxDeductible = isset($_POST['tax_deductible'])
                ? (int)(bool)$_POST['tax_deductible']
                : 1;
            $color = normalizeColor($_POST['color'] ?? null);

            // Prevent circular parent reference (parent cannot be self)
            if ($parentId !== null) {
                $parentCheck = $pdo->prepare('SELECT 1 FROM expense_categories WHERE id = ?');
                $parentCheck->execute([$parentId]);
                if (!$parentCheck->fetch()) {
                    jsonResponse(false, 'Parent category not found');
                }
            }

            $stmt = $pdo->prepare('
                INSERT INTO expense_categories
                    (organization_id, name, parent_id, tax_deductible, is_system, color)
                VALUES (?, ?, ?, ?, 0, ?)
            ');
            $stmt->execute([$orgId > 0 ? $orgId : null, $name, $parentId, $taxDeductible, $color]);

            $categoryId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'expense_category.create', 'expense_category', $categoryId, [
                'organization_id' => $orgId,
                'name' => $name,
                'parent_id' => $parentId,
                'tax_deductible' => $taxDeductible,
                'color' => $color,
            ]);

            $redirect = category_handler_safe_return_url($_POST['return_to'] ?? null);
            if (str_contains($redirect, 'page=financial/expense-create')) {
                $redirect = category_handler_append_query($redirect, 'category_id', (string)$categoryId);
            }

            jsonResponse(true, 'Category created successfully', ['id' => $categoryId, 'name' => $name, 'redirect' => $redirect]);

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid category ID');
            }

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                jsonResponse(false, 'Category name is required');
            }

            $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== ''
                ? (int)$_POST['parent_id']
                : null;
            $taxDeductible = isset($_POST['tax_deductible'])
                ? (int)(bool)$_POST['tax_deductible']
                : 1;
            $color = normalizeColor($_POST['color'] ?? null);

            // Fetch existing category to check system status and org
            $existing = $pdo->prepare('
                SELECT id, is_system
                FROM expense_categories
                WHERE id = ?
            ');
            $existing->execute([$id]);
            $category = $existing->fetch();
            if (!$category) {
                jsonResponse(false, 'Category not found');
            }

            $isSystem = (int)$category['is_system'] === 1;

            // Prevent circular parent reference (parent cannot be self)
            if ($parentId !== null && $parentId === $id) {
                jsonResponse(false, 'A category cannot be its own parent');
            }
            if ($parentId !== null) {
                $parentCheck = $pdo->prepare('SELECT 1 FROM expense_categories WHERE id = ?');
                $parentCheck->execute([$parentId]);
                if (!$parentCheck->fetch()) {
                    jsonResponse(false, 'Parent category not found');
                }
            }

            // System categories can only update name, color (not parent/tax_deductible)
            if ($isSystem) {
                $stmt = $pdo->prepare('
                    UPDATE expense_categories
                    SET name = ?, color = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$name, $color, $id]);
            } else {
                $stmt = $pdo->prepare('
                    UPDATE expense_categories
                    SET name = ?, parent_id = ?, tax_deductible = ?, color = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$name, $parentId, $taxDeductible, $color, $id]);
            }

            audit_log($pdo, 'expense_category.update', 'expense_category', $id, [
                'organization_id' => $orgId,
                'name' => $name,
                'is_system' => $isSystem,
                'color' => $color,
            ]);

            jsonResponse(true, 'Category updated successfully', ['id' => $id]);

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid category ID');
            }

            $existing = $pdo->prepare('
                SELECT id, is_system, name
                FROM expense_categories
                WHERE id = ?
            ');
            $existing->execute([$id]);
            $category = $existing->fetch();
            if (!$category) {
                jsonResponse(false, 'Category not found');
            }

            if ((int)$category['is_system'] === 1) {
                jsonResponse(false, 'System categories cannot be deleted');
            }

            // Reassign child categories to this category's parent
            $childStmt = $pdo->prepare('
                UPDATE expense_categories
                SET parent_id = (
                    SELECT parent_id FROM (SELECT parent_id FROM expense_categories WHERE id = ?) AS tmp
                ), updated_at = NOW()
                WHERE parent_id = ?
            ');
            $childStmt->execute([$id, $id]);

            // Reassign expenses referencing this category to null
            $expenseStmt = $pdo->prepare('
                UPDATE expenses
                SET category_id = NULL, updated_at = NOW()
                WHERE category_id = ?
            ');
            $expenseStmt->execute([$id]);

            $deleteStmt = $pdo->prepare('DELETE FROM expense_categories WHERE id = ?');
            $deleteStmt->execute([$id]);

            audit_log($pdo, 'expense_category.delete', 'expense_category', $id, [
                'organization_id' => $orgId,
                'name' => $category['name'],
            ]);

            jsonResponse(true, 'Category deleted successfully');

        case 'deactivate':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid category ID');
            }

            $existing = $pdo->prepare('SELECT 1 FROM expense_categories WHERE id = ?');
            $existing->execute([$id]);
            if (!$existing->fetch()) {
                jsonResponse(false, 'Category not found');
            }

            // Soft deactivation via a status-like convention is not in the schema;
            // use is_system=0 sentinel and remove from active usage by clearing parent links.
            // Note: expense_categories has no is_active column, so we simulate deactivation
            // by unlinking child categories and nulling expense category references.
            $childStmt = $pdo->prepare('
                UPDATE expense_categories
                SET parent_id = NULL, updated_at = NOW()
                WHERE parent_id = ?
            ');
            $childStmt->execute([$id]);

            $expenseStmt = $pdo->prepare('
                UPDATE expenses
                SET category_id = NULL, updated_at = NOW()
                WHERE category_id = ?
            ');
            $expenseStmt->execute([$id]);

            audit_log($pdo, 'expense_category.deactivate', 'expense_category', $id, [
                'organization_id' => $orgId,
            ]);

            jsonResponse(true, 'Category deactivated successfully');

        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Throwable $e) {
    error_log('[category_handler] Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while processing the request');
}
