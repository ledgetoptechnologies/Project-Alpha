<?php
declare(strict_types=1);

require_once __DIR__ . '/api_v2_project_lifecycle.php';
require_once __DIR__ . '/api_v2_project_sync.php';
require_once __DIR__ . '/invoice_notifications.php';

const PA_API_V2_FINANCIAL_CURSOR_MAX = '2147483647';

function api_v2_financial_cursor_valid(string $value): bool
{
    return preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1
        && (strlen($value) < 10 || strcmp($value, PA_API_V2_FINANCIAL_CURSOR_MAX) <= 0);
}

/** Use PA's existing app_config.app_host public-link origin and validator. */
function api_v2_financial_summary_base_url(PDO $pdo): string
{
    $statement = $pdo->prepare("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='app_host' LIMIT 1");
    $statement->execute();
    return invoice_notification_public_base(['app_host' => $statement->fetchColumn() ?: '']);
}

/**
 * Return URLs derived from one existing, presently usable public invoice link.
 * This is intentionally a direct read: do not call lifecycle helpers here,
 * because they can create, refresh, revoke, or terminalize links.
 *
 * @return array{invoicePublicUrl:?string,paymentPublicUrl:?string}
 */
function api_v2_financial_existing_public_invoice_urls(PDO $pdo, int $invoiceId, string $status, float $balanceDue, string $baseUrl): array
{
    $none = ['invoicePublicUrl' => null, 'paymentPublicUrl' => null];
    if ($invoiceId < 1 || !in_array(strtolower($status), ['sent', 'unpaid', 'partial', 'overdue'], true)) return $none;
    $statement = $pdo->prepare(
        'SELECT token FROM public_links
         WHERE document_type=\'invoice\' AND document_id=? AND revoked=0
           AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)
         ORDER BY created_at DESC,id DESC LIMIT 1'
    );
    $statement->execute([$invoiceId]);
    $token = $statement->fetchColumn();
    if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $token) !== 1) return $none;
    $encoded = rawurlencode($token);
    return [
        'invoicePublicUrl' => $baseUrl . '/?page=public-doc&type=invoice&token=' . $encoded,
        'paymentPublicUrl' => $balanceDue > 0.005
            ? $baseUrl . '/?page=stripe-checkout&token=' . $encoded
            : null,
    ];
}

/**
 * Read the deliberately small financial projection for one application-bound
 * directory resource.  It does not write receipts, audit rows, invoices, or
 * payment state; authentication/rate-limit accounting is intentionally owned
 * by the common API middleware.
 *
 * @return array{status:int,payload?:array}
 */
function api_v2_financial_summary_read(PDO $pdo, string $resourceType, string $externalId, ?string $cursor, int $limit, int $apiKeyId, array $headers, string $requestId): array
{
    $projectPublicSelector = $resourceType === 'project_public';
    if (!in_array($resourceType, ['client', 'organization', 'project', 'project_public'], true)
        || ($projectPublicSelector
            ? preg_match('/^[0-9a-f]{32}$/D', $externalId) !== 1
            : !api_v2_project_external_id_valid($externalId))
        || ($cursor !== null && !api_v2_financial_cursor_valid($cursor))
        || $limit < 1 || $limit > 100 || $apiKeyId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Invalid financial summary request.');
    }

    $pdo->beginTransaction();
    try {
        $baseUrl = api_v2_financial_summary_base_url($pdo);
        $identity = api_v2_project_identity($pdo, $apiKeyId, $headers, false);
        if (!$identity) {
            $pdo->rollBack();
            return ['status' => 409];
        }
        $resolvedExternalId = $externalId;
        if ($resourceType === 'project' || $resourceType === 'project_public') {
            $binding = $pdo->prepare($projectPublicSelector
                ? 'SELECT external_id,project_public_id FROM api_v2_project_external_bindings WHERE application_pk=? AND project_public_id=?'
                : 'SELECT external_id,project_public_id FROM api_v2_project_external_bindings WHERE application_pk=? AND external_id=?');
            $binding->execute([(int)$identity['application_pk'], $externalId]);
            $bindingRow = $binding->fetch(PDO::FETCH_ASSOC);
            $publicId = is_array($bindingRow) ? ($bindingRow['project_public_id'] ?? null) : null;
            $resolvedExternalId = is_array($bindingRow) ? (string)($bindingRow['external_id'] ?? '') : '';
        } else {
            $binding = $pdo->prepare('SELECT public_id FROM api_v2_directory_external_bindings WHERE application_pk=? AND resource_type=? AND external_id=? AND status=\'active\'');
            $binding->execute([(int)$identity['application_pk'], $resourceType, $externalId]);
            $bindingRow = null;
            $publicId = $binding->fetchColumn();
        }
        if (!is_string($publicId) || preg_match('/^[0-9a-f]{32}$/D', $publicId) !== 1) {
            $pdo->commit();
            return ['status' => 404];
        }

        $isProject = $resourceType === 'project' || $resourceType === 'project_public';
        $sourceTable = match ($resourceType) { 'client' => 'clients', 'organization' => 'organizations', 'project', 'project_public' => 'projects' };
        $source = $pdo->prepare($isProject
            ? 'SELECT id,client_id,organization_id FROM projects WHERE public_id=?'
            : 'SELECT id FROM ' . $sourceTable . ' WHERE public_id=?');
        $source->execute([$publicId]);
        $sourceRow = $source->fetch(PDO::FETCH_ASSOC);
        if (!$sourceRow || !isset($sourceRow['id'])) {
            $pdo->rollBack();
            return ['status' => 409];
        }
        $sourceId = (int)$sourceRow['id'];

        // `can_view_invoice_links` is the PA project-client visibility grant
        // for invoice-linked presentation. A project selector carries no user
        // identity, so fail closed unless the project’s canonical client has
        // this explicit grant. Do not return amounts/statuses in that state.
        if ($isProject) {
            $visibility = $pdo->prepare('SELECT 1 FROM project_clients WHERE project_id=? AND client_id=? AND can_view_invoice_links=1');
            $visibility->execute([$sourceId, (int)($sourceRow['client_id'] ?? 0)]);
            if ($visibility->fetchColumn() === false) {
                $pdo->commit();
                return ['status' => 404];
            }
        }

        // An invoice must belong directly to the one resolved source resource.
        // Organization reads additionally require the linked client to be in
        // that same organization, preventing cross-tenant historical rows from
        // being surfaced through an organization-only filter.
        if ($resourceType === 'client') {
            $where = 'i.client_id=?';
            $params = [$sourceId];
        } elseif ($resourceType === 'organization') {
            $where = 'i.organization_id=? AND c.organization_id=?';
            $params = [$sourceId, $sourceId];
        } else {
            // A Project binding is the portal-safe selector. Never rely on
            // project_id alone: require its canonical customer and exact
            // organization relation too, so a malformed historical invoice
            // cannot cross a project/customer boundary.
            $projectClientId = isset($sourceRow['client_id']) ? (int)$sourceRow['client_id'] : 0;
            if ($projectClientId < 1) {
                $pdo->commit();
                return ['status' => 404];
            }
            $where = 'i.project_id=? AND i.client_id=?';
            $params = [$sourceId, $projectClientId];
            if ($sourceRow['organization_id'] === null) {
                $where .= ' AND i.organization_id IS NULL AND c.organization_id IS NULL';
            } else {
                $where .= ' AND i.organization_id=? AND c.organization_id=?';
                $params[] = (int)$sourceRow['organization_id'];
                $params[] = (int)$sourceRow['organization_id'];
            }
        }
        if ($cursor !== null) {
            $where .= ' AND i.id>?';
            $params[] = (int)$cursor;
        }
        $sql = 'SELECT i.id,i.doc_number,i.status,i.total,i.amount_paid,i.balance_due,i.due_date,i.document_date
                FROM invoices i JOIN clients c ON c.id=i.client_id
                WHERE ' . $where . ' AND i.status NOT IN (\'void\',\'cancelled\')
                ORDER BY i.id ASC LIMIT ' . ($limit + 1);
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);

        $invoices = [];
        $total = 0.0;
        $paid = 0.0;
        $outstanding = 0.0;
        foreach ($rows as $row) {
            $invoiceId = (int)$row['id'];
            $rowTotal = (float)$row['total'];
            $rowPaid = (float)$row['amount_paid'];
            $rowOutstanding = (float)$row['balance_due'];
            $total += $rowTotal;
            $paid += $rowPaid;
            $outstanding += $rowOutstanding;
            $publicUrls = api_v2_financial_existing_public_invoice_urls($pdo, $invoiceId, (string)$row['status'], $rowOutstanding, $baseUrl);
            $invoices[] = [
                'documentNumber' => $row['doc_number'] === null ? null : (int)$row['doc_number'],
                'status' => (string)$row['status'],
                'total' => number_format($rowTotal, 2, '.', ''),
                'amountPaid' => number_format($rowPaid, 2, '.', ''),
                'balanceDue' => number_format($rowOutstanding, 2, '.', ''),
                'dueDate' => $row['due_date'],
                'documentDate' => $row['document_date'],
                // Existing client links only; absent/revoked/expired/terminal
                // links are null. This route never mutates public_links.
                ...$publicUrls,
            ];
        }
        $nextCursor = $more && $rows !== [] ? (string)$rows[count($rows) - 1]['id'] : null;
        $payload = [
            'apiVersion' => '2',
            'sourceInstanceId' => $identity['source_instance_id'],
            'applicationId' => $identity['application_id'],
            'historyEpoch' => $identity['history_epoch'],
            'requestId' => $requestId,
            'resource' => [
                'type' => $isProject ? 'project' : $resourceType,
                'externalId' => $isProject ? $resolvedExternalId : $externalId,
                'publicId' => $publicId,
            ],
            // Totals cover this returned page only, never unseen cursor pages.
            'returnedPageTotals' => [
                'invoiceTotal' => number_format($total, 2, '.', ''),
                'amountPaid' => number_format($paid, 2, '.', ''),
                'balanceDue' => number_format($outstanding, 2, '.', ''),
            ],
            'invoices' => $invoices,
            'nextCursor' => $nextCursor,
        ];
        $pdo->commit();
        return ['status' => 200, 'payload' => $payload];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
