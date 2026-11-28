<?php
// src/utils/document_fields.php
// Helper functions for rendering custom document fields

/**
 * Render custom fields for a document type
 * 
 * @param PDO $pdo Database connection
 * @param string $documentType 'regular', 'long_term', or 'on_demand'
 * @param array $existingValues Optional array of existing field values (for edit mode)
 * @param string $idSuffix Optional suffix for field IDs to avoid conflicts (e.g., 'Co' for contracts)
 * @param string[] $exceptKeys Field keys rendered elsewhere on the page
 * @return string HTML for custom fields
 */
function renderDocumentCustomFields($pdo, $documentType, $existingValues = [], $idSuffix = '', array $exceptKeys = [])
{
    // Fetch enabled fields for this document type
    $stmt = $pdo->prepare('
        SELECT * FROM document_custom_fields 
        WHERE document_type = ? AND is_enabled = 1 
        ORDER BY display_order, id
    ');
    
    $stmt->execute([$documentType]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $fields = array_values(array_filter($fields, static fn (array $field): bool => !in_array($field['field_key'], $exceptKeys, true)));

    if (empty($fields)) {
        return '';
    }

    // Calculate grid columns (max 3 per row for readability)
    $fieldCount = count($fields);
    $columns = min($fieldCount, 3);

    $html = '<div id="customFieldsRow' . htmlspecialchars($idSuffix) . '" style="display:grid;gap:12px;grid-template-columns:repeat(' . $columns . ', 1fr)">';

    foreach ($fields as $field) {
        $fieldKey = $field['field_key'];
        $fieldLabel = htmlspecialchars($field['field_label']);
        $fieldType = $field['field_type'];
        $isRequired = $field['is_required'];
        $isBuiltin = $field['is_builtin'];

        // Get existing value if available
        $value = $existingValues[$fieldKey] ?? '';

        // Special handling for deposit field (composite field)
        if ($isBuiltin && $fieldKey === 'deposit') {
            $html .= renderDepositField($existingValues, $idSuffix);
            continue;
        }

        // Regular field rendering
        $html .= '<label id="' . htmlspecialchars($fieldKey) . 'Label' . htmlspecialchars($idSuffix) . '">';
        $html .= '<div>' . $fieldLabel;
        if ($isRequired) {
            $html .= ' <span style="color:#dc2626">*</span>';
        }
        $html .= '</div>';

        // Render input based on field type
        switch ($fieldType) {
            case 'date':
                $html .= '<input type="date" name="custom_field_' . htmlspecialchars($fieldKey) . '" ';
                $html .= 'id="customField_' . htmlspecialchars($fieldKey) . $idSuffix . '" ';
                $html .= 'value="' . htmlspecialchars($value) . '" ';
                if ($isRequired) $html .= 'required ';
                $html .= 'style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">';
                break;

            case 'number':
                $html .= '<input type="number" step="0.01" name="custom_field_' . htmlspecialchars($fieldKey) . '" ';
                $html .= 'id="customField_' . htmlspecialchars($fieldKey) . $idSuffix . '" ';
                $html .= 'value="' . htmlspecialchars($value) . '" ';
                if ($isRequired) $html .= 'required ';
                $html .= 'style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">';
                break;

            case 'textarea':
                $html .= '<textarea name="custom_field_' . htmlspecialchars($fieldKey) . '" ';
                $html .= 'id="customField_' . htmlspecialchars($fieldKey) . $idSuffix . '" ';
                if ($isRequired) $html .= 'required ';
                $html .= 'rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical">';
                $html .= htmlspecialchars($value);
                $html .= '</textarea>';
                break;

            case 'select':
                $options = json_decode($field['field_options'] ?? '[]', true);
                $html .= '<select name="custom_field_' . htmlspecialchars($fieldKey) . '" ';
                $html .= 'id="customField_' . htmlspecialchars($fieldKey) . $idSuffix . '" ';
                if ($isRequired) $html .= 'required ';
                $html .= 'style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">';
                $html .= '<option value="">-- Select --</option>';

                if (is_array($options)) {
                    foreach ($options as $option) {
                        $selected = ($value === $option) ? ' selected' : '';
                        $html .= '<option value="' . htmlspecialchars($option) . '"' . $selected . '>';
                        $html .= htmlspecialchars($option);
                        $html .= '</option>';
                    }
                }
                $html .= '</select>';
                break;

            case 'text':
            default:
                $html .= '<input type="text" name="custom_field_' . htmlspecialchars($fieldKey) . '" ';
                $html .= 'id="customField_' . htmlspecialchars($fieldKey) . $idSuffix . '" ';
                $html .= 'value="' . htmlspecialchars($value) . '" ';
                if ($isRequired) $html .= 'required ';
                $html .= 'style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">';
                break;
        }

        $html .= '</label>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Special rendering for deposit field (composite: type + value)
 */
function renderDepositField($existingValues, $idSuffix = '')
{
    $depositType = $existingValues['deposit_type'] ?? 'none';
    $depositValue = $existingValues['deposit_value'] ?? '0';

    $html = '<label id="depositTypeLabel' . htmlspecialchars($idSuffix) . '">';
    $html .= '<div>Deposit Required</div>';
    $html .= '<select id="depositType' . htmlspecialchars($idSuffix) . '" name="deposit_type" ';
    $html .= 'style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">';
    $html .= '<option value="none"' . ($depositType === 'none' ? ' selected' : '') . '>None</option>';
    $html .= '<option value="percent"' . ($depositType === 'percent' ? ' selected' : '') . '>Percent</option>';
    $html .= '<option value="fixed"' . ($depositType === 'fixed' ? ' selected' : '') . '>Fixed $</option>';
    $html .= '</select>';
    $html .= '</label>';

    $html .= '<label id="depositValueLabel' . htmlspecialchars($idSuffix) . '">';
    $html .= '<div>Deposit Value</div>';
    $html .= '<input id="depositValue' . htmlspecialchars($idSuffix) . '" type="number" step="0.01" ';
    $html .= 'name="deposit_value" value="' . htmlspecialchars($depositValue) . '" ';
    $html .= 'style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">';
    $html .= '</label>';

    return $html;
}

/**
 * Extract custom field values from POST data
 * 
 * @param array $postData $_POST data
 * @return array Associative array of field_key => value (only non-empty values)
 */
function extractCustomFieldValues($postData)
{
    $customFields = [];

    foreach ($postData as $key => $value) {
        // Check if this is a custom field
        if (strpos($key, 'custom_field_') === 0) {
            $fieldKey = substr($key, strlen('custom_field_'));

            // Only include non-empty values
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $customFields[$fieldKey] = $value;
                }
            } elseif (!empty($value)) {
                $customFields[$fieldKey] = $value;
            }
        }
    }

    return $customFields;
}

/**
 * Get custom field definitions for a document type
 * 
 * @param PDO $pdo Database connection
 * @param string $documentType Document type
 * @return array Array of field definitions
 */
function getCustomFields($pdo, $documentType)
{
    $stmt = $pdo->prepare('
        SELECT * FROM document_custom_fields 
        WHERE document_type = ? AND is_enabled = 1 
        ORDER BY display_order, id
    ');
    $stmt->execute([$documentType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render custom fields for display (read-only view)
 * 
 * @param PDO $pdo Database connection
 * @param string $documentType Document type
 * @param array $customFieldsData JSON decoded custom_fields data
 * @return string HTML for displaying custom fields
 */
function renderCustomFieldsDisplay($pdo, $documentType, $customFieldsData)
{
    if (empty($customFieldsData) || !is_array($customFieldsData)) {
        return '';
    }

    // Get field definitions to know labels and types
    $fields = getCustomFields($pdo, $documentType);
    $fieldsByKey = [];
    foreach ($fields as $field) {
        $fieldsByKey[$field['field_key']] = $field;
    }

    $html = '';

    foreach ($customFieldsData as $fieldKey => $value) {
        // Skip built-in fields (handled separately)
        if (in_array($fieldKey, ['deposit', 'deposit_type', 'deposit_value'])) {
            continue;
        }

        // Skip if field definition not found or value is empty
        if (!isset($fieldsByKey[$fieldKey]) || empty($value)) {
            continue;
        }

        $field = $fieldsByKey[$fieldKey];
        $fieldLabel = htmlspecialchars($field['field_label']);

        // Format value based on type
        $displayValue = htmlspecialchars($value);
        if ($field['field_type'] === 'date' && !empty($value)) {
            $displayValue = date('F j, Y', strtotime($value));
        } elseif ($field['field_type'] === 'number' && is_numeric($value)) {
            $displayValue = number_format((float)$value, 2);
        }

        $html .= '<div><strong>' . $fieldLabel . ':</strong> ' . $displayValue . '</div>';
    }

    return $html;
}
