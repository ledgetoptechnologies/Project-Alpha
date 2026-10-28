<?php
/**
 * Reusable Document List Filter Component
 * 
 * Usage:
 * <?php
 * $filterConfig = [
 *     'page' => 'quote/quotes-list',  // Required: form action page
 *     'filters' => [
 *         'client' => ['type' => 'client_autocomplete', 'label' => 'Client', 'value' => $client_name, 'id_value' => $client_id],
 *         'status' => ['type' => 'select', 'label' => 'Status', 'value' => $status, 'options' => ['all' => 'All', 'approved' => 'Approved']],
 *         'start' => ['type' => 'date', 'label' => 'Start Date', 'value' => $start],
 *         'end' => ['type' => 'date', 'label' => 'End Date', 'value' => $end],
 *         'project_code' => ['type' => 'text', 'label' => 'Project ID', 'value' => $project_code, 'placeholder' => 'PA-2025'],
 *         'doc_number' => ['type' => 'number', 'label' => 'Doc #', 'value' => $doc_number],
 *         'min' => ['type' => 'number', 'label' => 'Min Total ($)', 'value' => $min, 'step' => '0.01'],
 *     ],
 *     'primary_count' => 4  // Optional: number of filters shown in the primary row
 * ];
 * require __DIR__ . '/../components/document_list_filter.php';
 * ?>
 */

if (!isset($filterConfig) || !is_array($filterConfig)) {
    throw new Exception('$filterConfig must be defined before including document_list_filter.php');
}

$page = $filterConfig['page'] ?? '';
$filters = $filterConfig['filters'] ?? [];
$primaryCount = max(1, (int)($filterConfig['primary_count'] ?? 4));

if (empty($page)) {
    throw new Exception('Filter config must include "page" parameter');
}

// Generate unique ID for this filter instance
$instanceId = 'filter_' . md5($page . microtime());

if (!function_exists('document_list_filter_field')) {
    function document_list_filter_field(string $name, array $config, string $instanceId, bool $compact): string
    {
        $type = $config['type'] ?? 'text';
        $label = $config['label'] ?? ucfirst($name);
        $value = $config['value'] ?? '';
        $placeholder = $config['placeholder'] ?? '';
        $step = $config['step'] ?? '';
        $width = $config['width'] ?? ($type === 'client_autocomplete' ? '240px' : ($type === 'date' ? '150px' : ($type === 'number' ? '130px' : '170px')));
        $fieldStyle = 'display:flex;flex-direction:column;gap:6px;';
        if ($compact) {
            $fieldStyle .= 'flex:0 1 ' . $width . ';min-width:min(' . $width . ',100%);';
        }
        if (in_array($type, ['client_autocomplete', 'project_autocomplete'], true)) {
            $fieldStyle .= 'position:relative;';
        }

        ob_start();
        ?>
        <div style="<?php echo htmlspecialchars($fieldStyle); ?>">
            <label for="<?php echo htmlspecialchars($instanceId . '_' . $name); ?>" style="font-size:13px;font-weight:600;color:#374151">
                <?php echo htmlspecialchars($label); ?>
            </label>

            <?php if ($type === 'client_autocomplete'): ?>
                <?php $idValue = $config['id_value'] ?? 0; ?>
                <input type="hidden" name="client_id" id="<?php echo htmlspecialchars($instanceId); ?>_client_id" value="<?php echo (int)$idValue; ?>">
                <input
                    type="text"
                    name="client"
                    id="<?php echo htmlspecialchars($instanceId); ?>_client_input"
                    value="<?php echo htmlspecialchars($value); ?>"
                    placeholder="<?php echo htmlspecialchars($placeholder ?: 'Type client name...'); ?>"
                    style="padding:9px 10px;border-radius:8px;border:1px solid #ddd;width:100%"
                >
                <div id="<?php echo htmlspecialchars($instanceId); ?>_client_suggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>

            <?php elseif ($type === 'project_autocomplete'): ?>
                <input
                    type="text"
                    name="q"
                    id="<?php echo htmlspecialchars($instanceId); ?>_project_input"
                    value="<?php echo htmlspecialchars($value); ?>"
                    placeholder="<?php echo htmlspecialchars($placeholder ?: 'Type project name...'); ?>"
                    style="padding:9px 10px;border-radius:8px;border:1px solid #ddd;width:100%"
                >
                <div id="<?php echo htmlspecialchars($instanceId); ?>_project_suggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>

            <?php elseif ($type === 'select'): ?>
                <?php $options = $config['options'] ?? []; ?>
                <select name="<?php echo htmlspecialchars($name); ?>" id="<?php echo htmlspecialchars($instanceId . '_' . $name); ?>" style="padding:9px 10px;border-radius:8px;border:1px solid #ddd;width:100%">
                    <?php foreach ($options as $optValue => $optLabel): ?>
                        <option value="<?php echo htmlspecialchars($optValue); ?>" <?php echo $value === $optValue ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($optLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($type === 'date'): ?>
                <input
                    type="date"
                    name="<?php echo htmlspecialchars($name); ?>"
                    id="<?php echo htmlspecialchars($instanceId . '_' . $name); ?>"
                    value="<?php echo htmlspecialchars($value); ?>"
                    style="padding:9px 10px;border-radius:8px;border:1px solid #ddd;width:100%"
                >

            <?php elseif ($type === 'number'): ?>
                <input
                    type="number"
                    name="<?php echo htmlspecialchars($name); ?>"
                    id="<?php echo htmlspecialchars($instanceId . '_' . $name); ?>"
                    value="<?php echo htmlspecialchars($value); ?>"
                    placeholder="<?php echo htmlspecialchars($placeholder); ?>"
                    <?php echo $step ? 'step="' . htmlspecialchars($step) . '"' : ''; ?>
                    style="padding:9px 10px;border-radius:8px;border:1px solid #ddd;width:100%"
                >

            <?php else: ?>
                <input
                    type="text"
                    name="<?php echo htmlspecialchars($name); ?>"
                    id="<?php echo htmlspecialchars($instanceId . '_' . $name); ?>"
                    value="<?php echo htmlspecialchars($value); ?>"
                    placeholder="<?php echo htmlspecialchars($placeholder); ?>"
                    style="padding:9px 10px;border-radius:8px;border:1px solid #ddd;width:100%"
                >
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

$advancedFilters = array_slice($filters, $primaryCount, null, true);
$hasAdvancedFilters = count($advancedFilters) > 0;
$storageKey = 'document-filter:' . $page;
?>

<form method="get" action="/" style="display:flex;flex-wrap:wrap;gap:12px 14px;align-items:flex-end;margin:16px 0 22px;position:relative">
    <input type="hidden" name="page" value="<?php echo htmlspecialchars($page); ?>">

    <?php $i = 0; foreach ($filters as $name => $config): $i++; ?>
        <?php if ($i <= $primaryCount): ?>
            <?php echo document_list_filter_field((string)$name, $config, $instanceId, true); ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <button type="submit" style="padding:9px 14px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer;white-space:nowrap">
            Filter
        </button>
        <a href="/?page=<?php echo htmlspecialchars($page); ?>" style="padding:9px 14px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block;font-size:small;text-align:center;text-decoration:none;color:inherit;white-space:nowrap">
            Reset
        </a>
        <?php if ($hasAdvancedFilters): ?>
            <button
                type="button"
                data-filter-toggle="<?php echo htmlspecialchars($instanceId); ?>_advanced"
                data-filter-storage-key="<?php echo htmlspecialchars($storageKey); ?>"
                aria-expanded="false"
                style="padding:9px 14px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer;white-space:nowrap"
            >
                More filters
            </button>
        <?php endif; ?>
    </div>

    <?php if ($hasAdvancedFilters): ?>
        <div
            id="<?php echo htmlspecialchars($instanceId); ?>_advanced"
            data-filter-panel
            data-filter-storage-key="<?php echo htmlspecialchars($storageKey); ?>"
            style="display:none;flex-basis:100%;padding:12px 0 0;border-top:1px solid #eef0f2"
        >
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px 14px;align-items:end">
                <?php foreach ($advancedFilters as $name => $config): ?>
                    <?php echo document_list_filter_field((string)$name, $config, $instanceId, false); ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</form>

<?php
// Generate JavaScript for client autocomplete if needed
$hasClientAutocomplete = false;
$hasProjectAutocomplete = false;
foreach ($filters as $name => $config) {
    if (($config['type'] ?? '') === 'client_autocomplete') {
        $hasClientAutocomplete = true;
    }
    if (($config['type'] ?? '') === 'project_autocomplete') {
        $hasProjectAutocomplete = true;
    }
}

if ($hasClientAutocomplete || $hasProjectAutocomplete):
?>
<script>
(function() {
    <?php if ($hasClientAutocomplete): ?>
    // Client autocomplete
    var clientInput = document.getElementById('<?php echo e($instanceId); ?>_client_input');
    var clientHid = document.getElementById('<?php echo e($instanceId); ?>_client_id');
    var clientSug = document.getElementById('<?php echo e($instanceId); ?>_client_suggest');
    
    if (clientInput && clientHid && clientSug) {
        clientInput.addEventListener('input', function() {
            clientHid.value = '';
            var term = this.value.trim();
            
            if (!term) {
                clientSug.style.display = 'none';
                clientSug.innerHTML = '';
                return;
            }
            
            fetch('/?page=clients-search&term=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(list => {
                    if (!Array.isArray(list) || list.length === 0) {
                        clientSug.style.display = 'none';
                        clientSug.innerHTML = '';
                        return;
                    }
                    
                    clientSug.replaceChildren();
                    list.forEach(x => {
                        const el = document.createElement('div');
                        el.style.cssText = 'padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6';
                        el.textContent = String(x.name ?? '');
                        el.addEventListener('click', function() {
                            clientInput.value = String(x.name ?? '');
                            clientHid.value = String(x.id ?? '');
                            clientSug.style.display = 'none';
                        });
                        
                        el.addEventListener('mouseenter', function() {
                            this.style.background = '#f3f4f6';
                        });
                        
                        el.addEventListener('mouseleave', function() {
                            this.style.background = '#fff';
                        });
                        clientSug.appendChild(el);
                    });
                    
                    clientSug.style.display = 'block';
                })
                .catch(() => {
                    clientSug.style.display = 'none';
                });
        });
    }
    <?php endif; ?>
    
    <?php if ($hasProjectAutocomplete): ?>
    // Project autocomplete
    var projectInput = document.getElementById('<?php echo e($instanceId); ?>_project_input');
    var projectSug = document.getElementById('<?php echo e($instanceId); ?>_project_suggest');
    
    if (projectInput && projectSug) {
        projectInput.addEventListener('input', function() {
            var term = this.value.trim();
            
            if (!term) {
                projectSug.style.display = 'none';
                projectSug.innerHTML = '';
                return;
            }
            
            fetch('/?page=projects-search-autocomplete&term=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(list => {
                    if (!Array.isArray(list) || list.length === 0) {
                        projectSug.style.display = 'none';
                        projectSug.innerHTML = '';
                        return;
                    }
                    
                    projectSug.replaceChildren();
                    list.forEach(x => {
                        const el = document.createElement('div');
                        el.style.cssText = 'padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6';
                        el.textContent = String(x.name ?? '') +
                            (x.client_name ? ' · ' + x.client_name : '') +
                            (x.organization_name ? ' · ' + x.organization_name : '');
                        el.addEventListener('click', function() {
                            projectInput.value = String(x.name ?? '');
                            projectSug.style.display = 'none';
                        });
                        
                        el.addEventListener('mouseenter', function() {
                            this.style.background = '#f3f4f6';
                        });
                        
                        el.addEventListener('mouseleave', function() {
                            this.style.background = '#fff';
                        });
                        projectSug.appendChild(el);
                    });
                    
                    projectSug.style.display = 'block';
                })
                .catch(() => {
                    projectSug.style.display = 'none';
                });
        });
    }
    <?php endif; ?>
    
    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
        <?php if ($hasClientAutocomplete): ?>
        if (clientSug && !clientSug.contains(e.target) && e.target !== clientInput) {
            clientSug.style.display = 'none';
        }
        <?php endif; ?>
        <?php if ($hasProjectAutocomplete): ?>
        if (projectSug && !projectSug.contains(e.target) && e.target !== projectInput) {
            projectSug.style.display = 'none';
        }
        <?php endif; ?>
    });
})();
</script>
<?php endif; ?>
