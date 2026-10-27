<?php
// src/services/LinkResolverService.php

require_once __DIR__ . '/../utils/link_provider_config.php';
require_once __DIR__ . '/../utils/organization_link_strategy.php';

class LinkResolverService
{
    private PDO $pdo;
    private array $config;
    /** @var null|callable(string,array):object */
    private $providerFactory;

    public function __construct(PDO $pdo, ?callable $providerFactory = null)
    {
        $this->pdo = $pdo;
        $this->providerFactory = $providerFactory;
        $this->loadConfig();
        if ($this->config['enabled']) {
            $this->clearResolverExpirationDates();
        }
    }

    private function loadConfig(): void
    {
        $this->config = [
            'enabled' => false,
            'default_expiration_days' => 365,
            'scan_mode' => 'quick',
            'org_level_only' => false,
            'providers' => [],
        ];

        try {
            $stmt = $this->pdo->query("
                SELECT config_key, config_value
                FROM app_config
                WHERE organization_id = 0
                  AND config_key IN ('link_resolver_enabled', 'default_link_expiration_days', 'link_resolver_scan_mode', 'org_level_links_only')
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                switch ((string)$row['config_key']) {
                    case 'link_resolver_enabled':
                        $this->config['enabled'] = (string)$row['config_value'] === '1' || $row['config_value'] === 1 || $row['config_value'] === true;
                        break;
                    case 'default_link_expiration_days':
                        $this->config['default_expiration_days'] = max(1, (int)$row['config_value']);
                        break;
                    case 'link_resolver_scan_mode':
                        $mode = (string)$row['config_value'];
                        $this->config['scan_mode'] = in_array($mode, ['quick', 'full'], true) ? $mode : 'quick';
                        break;
                    case 'org_level_links_only':
                        $this->config['org_level_only'] = (string)$row['config_value'] === '1' || $row['config_value'] === 1 || $row['config_value'] === true;
                        break;
                }
            }

            foreach (pa_link_provider_best_rows($this->pdo) as $row) {
                if (empty($row['is_enabled'])) {
                    continue;
                }
                $credentials = !empty($row['credentials']) ? json_decode((string)$row['credentials'], true) : [];
                if (!is_array($credentials)) {
                    $credentials = [];
                }
                $this->config['providers'][(string)$row['provider']] = [
                    'credentials' => $credentials,
                    'default_expiration_days' => max(1, (int)($row['default_expiration_days'] ?? $this->config['default_expiration_days'])),
                ];
            }
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error loading config: ' . $e->getMessage());
        }
    }

    public function autoGenerateForClient(int $clientId, ?string $provider = null): array
    {
        if (!$this->config['enabled'] || !pa_link_resolver_enabled($this->pdo)) {
            return ['success' => false, 'message' => 'Link resolver is disabled'];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id, name, organization_id FROM clients WHERE id = ? LIMIT 1');
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                return ['success' => false, 'message' => 'Client not found'];
            }
            if (!empty($client['organization_id'])) {
                return ['success' => false, 'message' => 'Client belongs to an organization; use organization or department links to avoid accidental cross-contact sharing.'];
            }
            if ($this->entityIsIgnored('client', $clientId)) {
                return ['success' => false, 'message' => 'Client is marked manual-only'];
            }

            return $this->generateForContext([
                'entity_type' => 'client',
                'entity_id' => $clientId,
                'candidate_names' => [$client['name']],
                'parent_names' => [],
                'resolver_mode' => 'auto_attach',
                'title' => (string)$client['name'],
                'visibility_scope' => 'entity_only',
                'selected_department_ids' => [],
            ], $provider ? [$provider] : null);
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error generating links for client: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function autoGenerateForOrganization(int $orgId, ?string $provider = null): array
    {
        if (!$this->config['enabled'] || !pa_link_resolver_enabled($this->pdo)) {
            return ['success' => false, 'message' => 'Link resolver is disabled'];
        }

        try {
            $strategySelect = $this->tableHasColumn('organizations', 'link_strategy')
                ? 'link_strategy'
                : '"department_links_only" AS link_strategy';
            $stmt = $this->pdo->prepare("SELECT id, name, {$strategySelect} FROM organizations WHERE id = ? LIMIT 1");
            $stmt->execute([$orgId]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$org) {
                return ['success' => false, 'message' => 'Organization not found'];
            }
            if ($this->entityIsIgnored('organization', $orgId)) {
                return ['success' => false, 'message' => 'Organization is marked manual-only'];
            }

            $departments = $this->organizationDepartments($orgId);
            if ($departments) {
                $strategy = (string)($org['link_strategy'] ?? 'department_links_only');
                if (!in_array($strategy, ['department_links_only', 'overall_folder', 'shared_folder'], true)) {
                    $strategy = 'department_links_only';
                }

                if ($strategy === 'department_links_only') {
                    $this->deleteResolverOrgLinks($orgId);
                    return $this->autoGenerateDepartmentsForOrganization($departments, $provider);
                }

                $candidateNames = $strategy === 'shared_folder' ? ['_shared'] : [(string)$org['name']];
                $parentNames = $strategy === 'shared_folder' ? [(string)$org['name']] : [];
                $title = $strategy === 'shared_folder' ? 'Shared organization files' : (string)$org['name'];

                return $this->generateForContext([
                    'entity_type' => 'organization',
                    'entity_id' => $orgId,
                    'candidate_names' => $candidateNames,
                    'parent_names' => $parentNames,
                    'resolver_mode' => 'auto_attach',
                    'title' => $title,
                    'visibility_scope' => 'all_departments',
                    'selected_department_ids' => [],
                ], $provider ? [$provider] : null);
            }

            return $this->generateForContext([
                'entity_type' => 'organization',
                'entity_id' => $orgId,
                'candidate_names' => [$org['name']],
                'parent_names' => [],
                'resolver_mode' => 'auto_attach',
                'title' => (string)$org['name'],
                'visibility_scope' => 'entity_only',
                'selected_department_ids' => [],
            ], $provider ? [$provider] : null);
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error generating links for organization: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function autoGenerateDepartmentsForOrganization(array $departments, ?string $provider = null): array
    {
        $summary = [
            'success' => false,
            'generated' => [],
            'skipped' => [],
            'review_required' => [],
            'errors' => [],
            'tips' => [],
        ];

        foreach ($departments as $department) {
            $result = $this->autoGenerateForDepartment((int)$department['id'], $provider);
            if (!empty($result['success'])) {
                $summary['success'] = true;
                foreach ($result['generated'] ?? [] as $generatedProvider) {
                    $summary['generated'][] = $department['name'] . ' / ' . $generatedProvider;
                }
                foreach ($result['skipped'] ?? [] as $skippedProvider) {
                    $summary['skipped'][] = $department['name'] . ' / ' . $skippedProvider;
                }
            }
            foreach ($result['review_required'] ?? [] as $providerName => $message) {
                $summary['review_required'][$department['name'] . ' / ' . $providerName] = $message;
            }
            if (empty($result['success']) && empty($result['review_required'])) {
                $summary['errors'][(string)$department['name']] = (string)($result['message'] ?? 'No safe match');
            }
            foreach ($result['tips'] ?? [] as $providerName => $tip) {
                $summary['tips'][$department['name'] . ' / ' . $providerName] = $tip;
            }
        }

        $summary['generated'] = array_values(array_unique($summary['generated']));
        $summary['skipped'] = array_values(array_unique($summary['skipped']));
        $summary['message'] = $summary['success']
            ? 'Generated or reused department links. Resolver-created organization folder links were removed for department-only mode.'
            : 'No department links were generated.';
        return $summary;
    }

    private function organizationDepartments(int $orgId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id, name FROM organization_departments WHERE organization_id = ? ORDER BY name ASC');
            $stmt->execute([$orgId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function deleteResolverOrgLinks(int $orgId): void
    {
        if (!pa_link_resolver_enabled($this->pdo)) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare('
                DELETE FROM entity_links
                WHERE entity_type = "organization"
                  AND entity_id = ?
                  AND link_source = "resolver"
                  AND link_type LIKE "auto_%"
                  AND EXISTS (SELECT 1 FROM app_config WHERE organization_id = 0
                              AND config_key = "link_resolver_enabled" AND config_value = "1")
            ');
            $stmt->execute([$orgId]);
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error deleting resolver org links: ' . $e->getMessage());
        }
    }

    public function removeResolverOrganizationLinks(int $orgId): void
    {
        $this->deleteResolverOrgLinks($orgId);
    }

    public function autoGenerateForDepartment(int $departmentId, ?string $provider = null): array
    {
        if (!$this->config['enabled'] || !pa_link_resolver_enabled($this->pdo)) {
            return ['success' => false, 'message' => 'Link resolver is disabled'];
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT od.*, o.name AS organization_name
                FROM organization_departments od
                JOIN organizations o ON o.id = od.organization_id
                WHERE od.id = ?
                LIMIT 1
            ');
            $stmt->execute([$departmentId]);
            $department = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$department) {
                return ['success' => false, 'message' => 'Department not found'];
            }

            $mode = (string)($department['resolver_mode'] ?? 'manual_only');
            if ($mode === 'excluded') {
                return ['success' => false, 'message' => 'Department is excluded from resolver'];
            }
            if ($mode === 'manual_only') {
                return ['success' => false, 'message' => 'Department is set to manual links only'];
            }
            if ($this->entityIsIgnored('department', $departmentId)) {
                return ['success' => false, 'message' => 'Department is marked manual-only'];
            }

            $aliases = [];
            if (!empty($department['folder_aliases'])) {
                $decoded = json_decode((string)$department['folder_aliases'], true);
                if (is_array($decoded)) {
                    $aliases = array_values(array_filter(array_map('strval', $decoded), static fn($v) => trim($v) !== ''));
                }
            }

            $candidateNames = array_values(array_unique(array_filter(array_merge([
                (string)($department['folder_name'] ?: $department['name']),
                (string)$department['name'],
            ], $aliases), static fn($v) => trim((string)$v) !== '')));

            return $this->generateForContext([
                'entity_type' => 'department',
                'entity_id' => $departmentId,
                'candidate_names' => $candidateNames,
                'parent_names' => [(string)$department['organization_name']],
                'resolver_mode' => $mode,
                'title' => (string)$department['name'],
                'visibility_scope' => 'entity_only',
                'selected_department_ids' => [],
            ], $provider ? [$provider] : null);
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error generating links for department: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function generateForContext(array $context, ?array $onlyProviders = null): array
    {
        $providers = $this->config['providers'];
        if ($onlyProviders !== null) {
            $providers = array_intersect_key($providers, array_fill_keys($onlyProviders, true));
        }

        if (empty($providers)) {
            return ['success' => false, 'message' => 'No enabled link providers'];
        }

        $generated = [];
        $skipped = [];
        $review = [];
        $errors = [];
        $tips = [];

        foreach ($providers as $provider => $providerConfig) {
            $result = $this->generateLinkForProvider($provider, $context, $providerConfig);
            if (!empty($result['success'])) {
                if (!empty($result['skipped'])) {
                    $skipped[] = $provider;
                } else {
                    $generated[] = $provider;
                }
            } elseif (!empty($result['review_required'])) {
                $review[$provider] = $result['message'];
            } else {
                $errors[$provider] = $result['message'] ?? 'No safe match';
            }
            if (!empty($result['tip'])) {
                $tips[$provider] = (string)$result['tip'];
            }
        }

        $message = count($generated) > 0
            ? 'Resolver attached exact folder links.'
            : ($skipped ? 'Existing resolver links reused.' : ($review ? 'Resolver found matches that require review.' : 'No safe resolver matches found.'));
        if (!$generated && !$skipped && !$review && $errors) {
            $firstProvider = (string)array_key_first($errors);
            $message = $firstProvider . ': ' . $errors[$firstProvider];
        }

        return [
            'success' => count($generated) > 0 || count($skipped) > 0,
            'generated' => $generated,
            'skipped' => $skipped,
            'review_required' => $review,
            'errors' => $errors,
            'tips' => $tips,
            'tip' => $tips ? reset($tips) : null,
            'message' => $message,
        ];
    }

    private function generateLinkForProvider(string $provider, array $context, array $providerConfig): array
    {
        $typeMap = [
            'dropbox' => 'auto_dropbox',
            'gdrive' => 'auto_gdrive',
            's3' => 'auto_s3',
            'r2' => 'auto_r2',
        ];
        $linkType = $typeMap[$provider] ?? ('auto_' . preg_replace('/[^a-z0-9_]/i', '', $provider));
        if ($linkType === 'auto_') {
            return ['success' => false, 'message' => 'Invalid provider'];
        }

        try {
            $existingUrl = $this->existingResolverLinkUrl($context, $linkType);
            if (($this->config['scan_mode'] ?? 'quick') === 'quick' && $existingUrl !== null) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'url' => $existingUrl,
                    'message' => 'Existing resolver link reused.',
                ];
            }

            $resolver = $this->makeProvider($provider, $providerConfig['credentials'] ?? []);
            $diagnostics = [];
            $matches = $this->findSafeMatches($resolver, $context, $diagnostics);
            $mode = (string)($context['resolver_mode'] ?? 'auto_attach');

            if (!$matches) {
                if ($diagnostics) {
                    $this->logProviderIssue($provider, $context, $diagnostics[0]);
                    return [
                        'success' => false,
                        'message' => $diagnostics[0]['message'] ?? 'Provider lookup failed',
                        'tip' => $diagnostics[0]['tip'] ?? null,
                    ];
                }
                $markedUnavailable = ($this->config['scan_mode'] ?? 'quick') === 'full'
                    && $this->markResolverLinkUnavailable($context, $linkType);
                return [
                    'success' => false,
                    'message' => $markedUnavailable
                        ? 'No exact folder match found; the existing resolver link was marked unavailable.'
                        : 'No exact folder match found',
                ];
            }
            if ($mode === 'review') {
                return ['success' => false, 'review_required' => true, 'message' => 'Resolver mode is review; found ' . count($matches) . ' candidate(s).'];
            }
            if (count($matches) !== 1) {
                return ['success' => false, 'review_required' => true, 'message' => 'Ambiguous folder match; found ' . count($matches) . ' candidates.'];
            }

            $match = $matches[0];
            $publicLink = $resolver->generatePublicLink((string)$match['folder_id']);
            if (!$publicLink) {
                $lastError = method_exists($resolver, 'getLastError') ? $resolver->getLastError() : null;
                if (is_array($lastError)) {
                    $this->logProviderIssue($provider, $context, $lastError);
                    return [
                        'success' => false,
                        'message' => $lastError['message'] ?? 'Could not generate public link',
                        'tip' => $lastError['tip'] ?? null,
                    ];
                }
                return ['success' => false, 'message' => 'Could not generate public link'];
            }

            $this->upsertResolverLink($context, $linkType, $publicLink);

            return ['success' => true, 'url' => $publicLink];
        } catch (Throwable $e) {
            @error_log("[LinkResolverService] Error generating {$provider} link: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function logProviderIssue(string $provider, array $context, array $issue): void
    {
        $payload = [
            'provider' => $provider,
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'operation' => $issue['operation'] ?? null,
            'message' => $issue['message'] ?? null,
            'tip' => $issue['tip'] ?? null,
            'http_code' => $issue['http_code'] ?? null,
            'response' => $issue['response'] ?? null,
        ];

        if (function_exists('app_log')) {
            app_log('link_resolver', 'Provider issue', $payload);
        }
        @error_log('[LinkResolverService] Provider issue: ' . json_encode($payload));
    }

    private function makeProvider(string $provider, array $credentials): object
    {
        if ($this->providerFactory) {
            return ($this->providerFactory)($provider, $credentials);
        }

        $resolverFiles = [
            'dropbox' => 'dropbox_link_resolver.php',
            'gdrive' => 'google_drive_link_resolver.php',
            's3' => 's3_link_resolver.php',
            'r2' => 'r2_link_resolver.php',
        ];
        $resolverClasses = [
            'dropbox' => 'DropboxLinkResolver',
            'gdrive' => 'GdriveLinkResolver',
            's3' => 'S3LinkResolver',
            'r2' => 'R2LinkResolver',
        ];

        $resolverPath = __DIR__ . '/../link_resolvers/auto_resolver/' . ($resolverFiles[$provider] ?? "{$provider}_link_resolver.php");
        if (!is_file($resolverPath)) {
            throw new RuntimeException('Resolver not found');
        }
        require_once $resolverPath;

        $resolverClass = $resolverClasses[$provider] ?? ucfirst($provider) . 'LinkResolver';
        if (!class_exists($resolverClass)) {
            throw new RuntimeException('Resolver class not found');
        }

        if ($provider === 'dropbox') {
            return new $resolverClass($credentials, $this->pdo);
        }

        return new $resolverClass($credentials);
    }

    /**
     * @return list<array{folder_id:string,name:string,path:string}>
     */
    private function findSafeMatches(object $resolver, array $context, array &$diagnostics = []): array
    {
        $safe = [];
        $seen = [];
        $candidateNames = array_values(array_unique(array_filter(array_map('strval', $context['candidate_names'] ?? []), static fn($v) => trim($v) !== '')));
        $providerParentNames = array_values(array_unique(array_filter(array_map('strval', $context['parent_names'] ?? []), static fn($v) => trim($v) !== '')));
        $parentNames = array_values(array_unique(array_filter(array_map([$this, 'normalizeName'], $providerParentNames))));

        foreach ($candidateNames as $candidateName) {
            // Providers that support exact parent-aware lookup (notably S3/R2)
            // can avoid scanning an entire bucket. Dropbox and Google Drive also
            // accept this optional context while the checks below remain the
            // final safety boundary for every provider.
            $result = $resolver->searchFolder($candidateName, $providerParentNames);
            if (empty($result['success']) && !empty($result['message'])) {
                $message = (string)$result['message'];
                if (!in_array($message, ['Folder not found', 'Exact folder match not found'], true)) {
                    $lastError = method_exists($resolver, 'getLastError') ? $resolver->getLastError() : null;
                    $diagnostics[] = is_array($lastError) ? $lastError : [
                        'operation' => 'folder search',
                        'message' => $message,
                        'tip' => $result['tip'] ?? null,
                    ];
                }
            }
            $rawMatches = [];
            if (!empty($result['matches']) && is_array($result['matches'])) {
                $rawMatches = $result['matches'];
            } elseif (!empty($result['success'])) {
                $rawMatches = [$result];
            }

            foreach ($rawMatches as $match) {
                if (!is_array($match)) {
                    continue;
                }
                $folderId = (string)($match['folder_id'] ?? $match['id'] ?? '');
                $path = (string)($match['path'] ?? $match['path_lower'] ?? $folderId);
                $name = (string)($match['name'] ?? $this->pathBasename($path));
                if ($folderId === '') {
                    continue;
                }
                if ($this->normalizeName($name) !== $this->normalizeName($candidateName)) {
                    continue;
                }
                if ($parentNames) {
                    $parent = $this->normalizeName((string)($match['parent_name'] ?? $this->pathParentBasename($path)));
                    if ($parent === '' || !in_array($parent, $parentNames, true)) {
                        continue;
                    }
                }

                $key = strtolower($folderId);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $safe[] = [
                    'folder_id' => $folderId,
                    'name' => $name,
                    'path' => $path,
                ];
            }
        }

        return $safe;
    }

    private function upsertResolverLink(array $context, string $linkType, string $url): void
    {
        $entityType = (string)$context['entity_type'];
        $entityId = (int)$context['entity_id'];

        $stmt = $this->pdo->prepare('SELECT id FROM entity_links WHERE entity_type = ? AND entity_id = ? AND link_type = ? AND link_source = "resolver" LIMIT 1');
        $stmt->execute([$entityType, $entityId, $linkType]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $stmt = $this->pdo->prepare('
                UPDATE entity_links
                SET title = ?, url = ?, expiration_date = NULL, is_expired = 0, include_on_invoices = 1,
                    visibility_scope = ?, selected_department_ids = ?, resolver_mode = ?, last_verified = NOW(), updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([
                (string)($context['title'] ?? 'Content Folder'),
                $url,
                (string)($context['visibility_scope'] ?? 'entity_only'),
                !empty($context['selected_department_ids']) ? json_encode(array_values(array_map('intval', $context['selected_department_ids']))) : null,
                (string)($context['resolver_mode'] ?? 'auto_attach'),
                $existingId,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO entity_links
                (entity_type, entity_id, title, url, link_type, link_source, include_on_invoices, visibility_scope, selected_department_ids, resolver_mode, expiration_date, is_expired, last_verified)
            VALUES (?, ?, ?, ?, ?, "resolver", 1, ?, ?, ?, NULL, 0, NOW())
        ');
        $stmt->execute([
            $entityType,
            $entityId,
            (string)($context['title'] ?? 'Content Folder'),
            $url,
            $linkType,
            (string)($context['visibility_scope'] ?? 'entity_only'),
            !empty($context['selected_department_ids']) ? json_encode(array_values(array_map('intval', $context['selected_department_ids']))) : null,
            (string)($context['resolver_mode'] ?? 'auto_attach'),
        ]);
    }

    private function clearResolverExpirationDates(): void
    {
        try {
            $this->pdo->exec('
                UPDATE entity_links
                SET expiration_date = NULL
                WHERE link_source = "resolver" AND expiration_date IS NOT NULL
                  AND EXISTS (SELECT 1 FROM app_config WHERE organization_id = 0
                              AND config_key = "link_resolver_enabled" AND config_value = "1")
            ');
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error clearing resolver expiration dates: ' . $e->getMessage());
        }
    }

    private function markResolverLinkUnavailable(array $context, string $linkType): bool
    {
        try {
            $stmt = $this->pdo->prepare('
                UPDATE entity_links
                SET expiration_date = NULL, is_expired = 1, last_verified = NOW(), updated_at = NOW()
                WHERE entity_type = ?
                  AND entity_id = ?
                  AND link_type = ?
                  AND link_source = "resolver"
                  AND COALESCE(ignore_auto_generation, 0) = 0
                  AND COALESCE(is_expired, 0) = 0
            ');
            $stmt->execute([
                (string)$context['entity_type'],
                (int)$context['entity_id'],
                $linkType,
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error marking missing resolver link unavailable: ' . $e->getMessage());
            return false;
        }
    }

    private function existingResolverLinkUrl(array $context, string $linkType): ?string
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT url
                FROM entity_links
                WHERE entity_type = ?
                  AND entity_id = ?
                  AND link_type = ?
                  AND link_source = "resolver"
                  AND COALESCE(is_expired, 0) = 0
                  AND COALESCE(ignore_auto_generation, 0) = 0
                  AND url IS NOT NULL
                  AND url <> ""
                LIMIT 1
            ');
            $stmt->execute([
                (string)$context['entity_type'],
                (int)$context['entity_id'],
                $linkType,
            ]);
            $url = trim((string)($stmt->fetchColumn() ?: ''));
            return $url !== '' ? $url : null;
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error checking existing resolver link: ' . $e->getMessage());
            return null;
        }
    }

    private function entityIsIgnored(string $entityType, int $entityId): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT 1 FROM entity_links WHERE entity_type = ? AND entity_id = ? AND ignore_auto_generation = 1 LIMIT 1');
            $stmt->execute([$entityType, $entityId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . ':' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return $cache[$key] = false;
        }
        try {
            $stmt = $this->pdo->prepare('
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1
            ');
            $stmt->execute([$table, $column]);
            return $cache[$key] = $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }

    private function normalizeName(string $value): string
    {
        $value = trim(strtolower($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return $value;
    }

    private function pathBasename(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }
        $parts = explode('/', $path);
        return (string)end($parts);
    }

    private function pathParentBasename(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || !str_contains($path, '/')) {
            return '';
        }
        $parts = explode('/', $path);
        array_pop($parts);
        return (string)end($parts);
    }

    public function markAsIgnored(string $entityType, int $entityId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM entity_links WHERE entity_type = ? AND entity_id = ? LIMIT 1');
            $stmt->execute([$entityType, $entityId]);
            $existingId = (int)($stmt->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $stmt = $this->pdo->prepare('
                    UPDATE entity_links
                    SET ignore_auto_generation = 1
                    WHERE entity_type = ? AND entity_id = ?
                ');
                $stmt->execute([$entityType, $entityId]);
            } else {
                $stmt = $this->pdo->prepare('
                    INSERT INTO entity_links
                        (entity_type, entity_id, title, url, link_type, link_source, include_on_invoices, resolver_mode, ignore_auto_generation)
                    VALUES (?, ?, "Resolver disabled", "#", "resolver_blacklist", "resolver", 0, "manual_only", 1)
                ');
                $stmt->execute([$entityType, $entityId]);
            }
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error marking as ignored: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function unmarkAsIgnored(string $entityType, int $entityId): array
    {
        try {
            $stmt = $this->pdo->prepare('
                UPDATE entity_links
                SET ignore_auto_generation = 0
                WHERE entity_type = ? AND entity_id = ?
            ');
            $stmt->execute([$entityType, $entityId]);
            $stmt = $this->pdo->prepare('
                DELETE FROM entity_links
                WHERE entity_type = ? AND entity_id = ? AND link_type = "resolver_blacklist"
            ');
            $stmt->execute([$entityType, $entityId]);
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error unmarking as ignored: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function revokeLink(string $entityType, int $entityId, int $linkId, bool $blacklist = false): array
    {
        try {
            $stmt = $this->pdo->prepare('
                DELETE FROM entity_links
                WHERE id = ? AND entity_type = ? AND entity_id = ?
                LIMIT 1
            ');
            $stmt->execute([$linkId, $entityType, $entityId]);
            if ($stmt->rowCount() < 1) {
                return ['success' => false, 'message' => 'Link not found'];
            }
            if ($blacklist) {
                $ignored = $this->markAsIgnored($entityType, $entityId);
                if (empty($ignored['success'])) {
                    return $ignored;
                }
            }
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error revoking link: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function expireLinks(string $entityType, int $entityId): array
    {
        try {
            $stmt = $this->pdo->prepare('
                UPDATE entity_links
                SET is_expired = 1
                WHERE entity_type = ? AND entity_id = ?
            ');
            $stmt->execute([$entityType, $entityId]);
            return ['success' => true];
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Error expiring links: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function runProviderScan(string $provider): array
    {
        if (!in_array($provider, ['dropbox', 'gdrive', 's3', 'r2'], true)) {
            return ['success' => false, 'message' => 'Invalid provider'];
        }
        if (!$this->config['enabled'] || !pa_link_resolver_enabled($this->pdo)) {
            return ['success' => false, 'message' => 'Link resolver is disabled'];
        }
        if (empty($this->config['providers'][$provider])) {
            return ['success' => false, 'message' => ucfirst($provider) . ' is not enabled or configured'];
        }

        $summary = [
            'success' => true,
            'provider' => $provider,
            'scanned' => 0,
            'generated' => 0,
            'skipped' => 0,
            'review_required' => 0,
            'no_match' => 0,
            'errors' => 0,
            'details' => [],
        ];

        $record = function (string $label, array $result) use (&$summary): void {
            $summary['scanned']++;
            if (!empty($result['success'])) {
                if (!empty($result['skipped'])) {
                    $summary['skipped']++;
                } else {
                    $summary['generated']++;
                }
                return;
            }
            if (!empty($result['review_required'])) {
                $summary['review_required']++;
                if (count($summary['details']) < 6) {
                    $summary['details'][] = $label . ': review required';
                }
                return;
            }

            $message = (string)($result['message'] ?? 'No safe match');
            if (
                stripos($message, 'no exact') !== false
                || stripos($message, 'no safe') !== false
                || stripos($message, 'not found') !== false
                || stripos($message, 'manual-only') !== false
                || stripos($message, 'manual links only') !== false
                || stripos($message, 'excluded') !== false
                || stripos($message, 'belongs to an organization') !== false
            ) {
                $summary['no_match']++;
            } else {
                $summary['errors']++;
                if (count($summary['details']) < 6) {
                    $tip = !empty($result['tip']) ? ' Tip: ' . (string)$result['tip'] : '';
                    $summary['details'][] = $label . ': ' . $message . $tip;
                }
            }
        };

        try {
            $stmt = $this->pdo->query('SELECT id, name FROM organizations ORDER BY name');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $record('Organization ' . (string)$row['name'], $this->autoGenerateForOrganization((int)$row['id'], $provider));
            }

            $stmt = $this->pdo->query('SELECT id, name FROM organization_departments ORDER BY name');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $record('Department ' . (string)$row['name'], $this->autoGenerateForDepartment((int)$row['id'], $provider));
            }

            $stmt = $this->pdo->query('SELECT id, name FROM clients WHERE organization_id IS NULL OR organization_id = 0 ORDER BY name');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $record('Client ' . (string)$row['name'], $this->autoGenerateForClient((int)$row['id'], $provider));
            }
        } catch (Throwable $e) {
            @error_log('[LinkResolverService] Manual provider scan failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $summary['message'] = sprintf(
            'Scanned %d records. Generated %d, skipped %d, review %d, no match %d, errors %d.',
            $summary['scanned'],
            $summary['generated'],
            $summary['skipped'],
            $summary['review_required'],
            $summary['no_match'],
            $summary['errors']
        );
        return $summary;
    }

    public function refreshLinks(string $entityType, int $entityId): array
    {
        return match ($entityType) {
            'client' => $this->autoGenerateForClient($entityId),
            'organization' => $this->autoGenerateForOrganization($entityId),
            'department' => $this->autoGenerateForDepartment($entityId),
            default => ['success' => false, 'message' => 'Invalid entity type'],
        };
    }
}
