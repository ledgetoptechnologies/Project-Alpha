<?php

require_once __DIR__ . '/../../utils/api_scopes.php';

if (!function_exists('api_keys_e')) {
    function api_keys_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('api_keys_require_admin')) {
    function api_keys_require_admin(): bool
    {
        $user = $_SESSION['user'] ?? [];
        if (($user['role'] ?? 'user') !== 'admin') {
            echo '<div class="alert alert-danger">Only admins can manage API keys.</div>';
            return false;
        }
        return true;
    }
}

if (!function_exists('api_keys_scope_labels')) {
    function api_keys_scope_labels(array $scopeOptions, array $selectedScopes): string
    {
        $selected = api_normalize_scopes($selectedScopes ?: ['full']);
        $labels = [];
        foreach ($selected as $scope) {
            $labels[] = (string)($scopeOptions[$scope]['label'] ?? $scope);
        }
        return implode(', ', $labels ?: ['Legacy broad API access']);
    }
}

if (!function_exists('api_keys_scope_checkboxes')) {
    function api_keys_scope_checkboxes(array $scopeOptions, array $selectedScopes, string $inputName, string $formId = ''): string
    {
        $selected = array_fill_keys(api_normalize_scopes($selectedScopes), true);
        if (!$selected) {
            $selected = ['full' => true];
        }
        $formAttr = $formId !== '' ? ' form="' . api_keys_e($formId) . '"' : '';
        $html = '<div class="api-scope-grid">';
        foreach ($scopeOptions as $scope => $option) {
            $id = preg_replace('/[^a-z0-9_-]+/i', '-', $inputName . '-' . $formId . '-' . $scope);
            $checked = isset($selected[$scope]) ? ' checked' : '';
            $html .= '<label class="api-scope-option" for="' . api_keys_e($id) . '">';
            $html .= '<input type="checkbox" id="' . api_keys_e($id) . '" name="' . api_keys_e($inputName) . '[]" value="' . api_keys_e($scope) . '"' . $checked . $formAttr . '>';
            $html .= '<span><strong>' . api_keys_e($option['label'] ?? $scope) . '</strong>';
            $html .= '<small>' . api_keys_e($option['description'] ?? '') . '</small></span>';
            $html .= '</label>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('api_keys_render_styles')) {
    function api_keys_render_styles(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        ?>
<style>
.api-key-page{max-width:1180px;margin:0 auto}.api-key-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.api-key-header h1{margin:0 0 6px;font-size:28px}.api-key-header p{margin:0;color:#64748b}.api-key-card{background:#fff;border:1px solid #d9e2ec;border-radius:8px;padding:18px;margin-bottom:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.api-key-card h2{margin:0 0 14px;font-size:18px}.api-key-form{display:grid;gap:14px}.api-key-form-grid{display:grid;grid-template-columns:minmax(180px,1fr) minmax(220px,1.2fr);gap:14px;align-items:start}.api-key-field label,.api-key-field>span{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#334155}.api-key-field input[type=text]{width:100%;min-height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px}.api-key-field small{display:block;margin-top:5px;color:#64748b}.api-scope-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px}.api-scope-option{display:flex;gap:8px;align-items:flex-start;min-height:64px;border:1px solid #d9e2ec;border-radius:8px;padding:10px;background:#f8fafc;cursor:pointer}.api-scope-option input{margin-top:3px}.api-scope-option strong{display:block;font-size:13px;color:#0f172a}.api-scope-option small{display:block;margin-top:3px;color:#64748b;line-height:1.3}.api-key-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px}.api-key-table{width:100%;border-collapse:collapse;font-size:14px;background:#fff}.api-key-table th,.api-key-table td{border-bottom:1px solid #e2e8f0;padding:10px;text-align:left;vertical-align:middle}.api-key-table tr:last-child td{border-bottom:0}.api-key-table th{color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.04em;background:#f8fafc}.api-key-prefix{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#0f172a;white-space:nowrap}.api-key-muted{color:#64748b}.api-key-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.api-key-empty{color:#64748b;padding:18px;text-align:center;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc}.api-key-secret{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;overflow-wrap:anywhere;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px;padding:12px}.api-key-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:12px;background:#e0f2fe;color:#075985;white-space:nowrap}.api-key-badge.revoked{background:#fee2e2;color:#991b1b}.api-key-form-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.alert{padding:12px 14px;border-radius:8px;margin-bottom:14px}.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}.alert-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.alert-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}@media(max-width:760px){.api-key-header,.api-key-form-grid{display:block}.api-key-field{margin-bottom:12px}.api-key-actions{min-width:150px}}
</style>
        <?php
    }
}
