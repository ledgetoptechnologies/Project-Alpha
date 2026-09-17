<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Builds the least-privilege Project Alpha projection consumed by an external
 * operations application.
 *
 * Pagination is applied independently to every collection. A caller continues
 * while has_more is true; collections that have been exhausted return an empty
 * array on subsequent pages.
 */
final class OpsSnapshotService
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 500;

    /**
     * @return array{
     *   generated_at:string,
     *   users:list<array<string,mixed>>,
     *   business_units:list<array<string,mixed>>,
     *   worker_business_units:list<array<string,mixed>>,
     *   clients:list<array<string,mixed>>,
     *   organizations:list<array<string,mixed>>,
     *   projects:list<array<string,mixed>>,
     *   project_assignments:list<array<string,mixed>>,
     *   service_locations:list<array<string,mixed>>,
     *   application_entitlements:list<array<string,mixed>>,
     *   operations:list<array<string,mixed>>,
     *   operation_assignments:list<array<string,mixed>>,
     *   tasks:list<array<string,mixed>>,
     *   task_assignments:list<array<string,mixed>>,
     *   calendar_events:list<array<string,mixed>>,
     *   has_more:bool,
     *   next_page:?int
     * }
     */
    public function snapshot(
        PDO $pdo,
        int $page = 1,
        int $limit = self::DEFAULT_LIMIT,
        ?DateTimeImmutable $generatedAt = null,
        ?string $applicationKey = null
    ): array {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be at least 1.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }

        try {
            $applicationKey = ExternalOpsIntegrationService::normalizeApplicationKey((string)$applicationKey);
        } catch (\DomainException $error) {
            throw new \InvalidArgumentException('Invalid external operations application key.');
        }
        $definitions = $this->definitions($applicationKey);
        $offset = ($page - 1) * $limit;
        $result = [];
        $hasMore = false;

        foreach ($definitions as $name => $definition) {
            $statement = $pdo->prepare($definition['sql'] . ' LIMIT :fetch_limit OFFSET :offset');
            foreach (($definition['parameters'] ?? []) as $parameter => $value) {
                $statement->bindValue(':' . $parameter, $value, PDO::PARAM_STR);
            }
            $statement->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > $limit) {
                $hasMore = true;
                $rows = array_slice($rows, 0, $limit);
            }
            $normalizedRows = array_map(
                fn(array $row): array => $this->normalizeRow($row, $definition),
                $rows
            );
            if ($name === 'application_entitlements') {
                foreach ($normalizedRows as &$row) {
                    $row['business_unit_ids'] = [];
                    $row['oversight_business_unit_ids'] = [];
                }
                unset($row);
            } elseif ($name === 'calendar_events') {
                foreach ($normalizedRows as &$row) {
                    $row['id'] = (string)$row['source_type'] . ':' . (int)$row['source_id'];
                }
                unset($row);
            }
            $result[$name] = $normalizedRows;
        }

        $timestamp = ($generatedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return [
            'generated_at' => $timestamp->format('Y-m-d\TH:i:s.u\Z'),
            'users' => $result['users'],
            'business_units' => $result['business_units'],
            'worker_business_units' => $result['worker_business_units'],
            'clients' => $result['clients'],
            'organizations' => $result['organizations'],
            'projects' => $result['projects'],
            'project_assignments' => $result['project_assignments'],
            'service_locations' => $result['service_locations'],
            'application_entitlements' => $result['application_entitlements'],
            'operations' => $result['operations'],
            'operation_assignments' => $result['operation_assignments'],
            'tasks' => $result['tasks'],
            'task_assignments' => $result['task_assignments'],
            'calendar_events' => $result['calendar_events'],
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
        ];
    }

    /**
     * @return array<string,array{sql:string,integers:list<string>,booleans:list<string>,parameters?:array<string,string>}>
     */
    private function definitions(string $applicationKey): array
    {
        return [
            'users' => [
                'sql' => 'SELECT u.id,u.email,u.username,u.role,u.is_disabled,u.deleted_at,u.created_at,u.updated_at,
                                 wp.id AS worker_profile_id,wp.display_name,wp.relationship_type,wp.status AS worker_status,
                                 ep.employment_status
                          FROM users u
                          JOIN application_entitlements selected_access
                           ON selected_access.user_id=u.id
                           AND selected_access.application_key=:application_key
                           AND selected_access.enabled=1
                           AND selected_access.manual_enabled=1
                          LEFT JOIN worker_profiles wp ON wp.user_id=u.id
                          LEFT JOIN employee_profiles ep ON ep.user_id=u.id
                          WHERE u.is_disabled=0 AND u.deleted_at IS NULL
                            AND (wp.id IS NULL OR wp.status=\'active\')
                            AND (ep.user_id IS NULL OR ep.employment_status=\'active\')
                          ORDER BY u.id',
                'integers' => ['id', 'worker_profile_id'],
                'booleans' => ['is_disabled'],
                'parameters' => ['application_key' => $applicationKey],
            ],
            'business_units' => [
                'sql' => 'SELECT id,name,code,description,is_active,created_by,created_at,updated_at
                          FROM business_units ORDER BY id',
                'integers' => ['id', 'created_by'],
                'booleans' => ['is_active'],
            ],
            'worker_business_units' => [
                'sql' => 'SELECT wbu.worker_profile_id,wp.user_id,wbu.business_unit_id,wbu.is_lead,
                                 wbu.assigned_by,wbu.assigned_at,wbu.ends_at
                          FROM worker_business_units wbu
                          JOIN worker_profiles wp ON wp.id=wbu.worker_profile_id
                          WHERE 1=0
                          ORDER BY wbu.worker_profile_id,wbu.business_unit_id',
                'integers' => ['worker_profile_id', 'user_id', 'business_unit_id', 'assigned_by'],
                'booleans' => ['is_lead'],
            ],
            'clients' => [
                'sql' => 'SELECT id,public_id,name,email,phone,address_line1,address_line2,city,state,postal_code,country,
                                 organization_id,client_type,archived,deleted_at,created_at,updated_at
                          FROM clients ORDER BY id',
                'integers' => ['id', 'organization_id'],
                'booleans' => ['archived'],
            ],
            'organizations' => [
                'sql' => 'SELECT id,public_id,name,address_line1,address_line2,city,state,postal_code,country,
                                 link_strategy,created_at,updated_at
                          FROM organizations ORDER BY id',
                'integers' => ['id'],
                'booleans' => [],
            ],
            'projects' => [
                'sql' => 'SELECT id,public_id,client_id,parent_id,organization_id,department_id,business_unit_id,manager_user_id,created_by,name,description,status,
                                 start_date,end_date,estimated_start,estimated_end,created_at,updated_at
                          FROM projects ORDER BY id',
                'integers' => ['id', 'client_id', 'parent_id', 'organization_id', 'department_id', 'business_unit_id', 'manager_user_id', 'created_by'],
                'booleans' => [],
            ],
            'project_assignments' => [
                'sql' => 'SELECT id,project_id,user_id,assigned_at,ends_at,created_by,created_at,updated_at
                          FROM project_assignments ORDER BY id',
                'integers' => ['id', 'project_id', 'user_id', 'created_by'],
                'booleans' => [],
            ],
            'service_locations' => [
                'sql' => 'SELECT id,organization_id,client_id,project_id,address_id,name,address_line1,address_line2,
                                 city,state,postal_code,country,archived,created_by,created_at,updated_at
                          FROM service_locations ORDER BY id',
                'integers' => ['id', 'organization_id', 'client_id', 'project_id', 'address_id', 'created_by'],
                'booleans' => ['archived'],
            ],
            'application_entitlements' => [
                'sql' => 'SELECT ae.id,ae.user_id,ae.application_key,ae.manual_enabled,0 AS automatic_enabled,0 AS oversight_enabled,
                                 CASE WHEN ae.enabled=1 AND ae.manual_enabled=1 AND u.is_disabled=0 AND u.deleted_at IS NULL
                                           AND (wp.id IS NULL OR wp.status=\'active\')
                                           AND (ep.user_id IS NULL OR ep.employment_status=\'active\') THEN 1 ELSE 0 END AS enabled,
                                 CASE WHEN u.role=\'admin\' THEN \'role-admin\' ELSE \'role-operator\' END AS role_key,
                                 ae.created_at,ae.updated_at
                          FROM application_entitlements ae
                          JOIN users u ON u.id=ae.user_id
                          LEFT JOIN worker_profiles wp ON wp.user_id=u.id
                          LEFT JOIN employee_profiles ep ON ep.user_id=u.id
                          WHERE ae.application_key=:application_key ORDER BY ae.id',
                'integers' => ['id', 'user_id'],
                'booleans' => ['enabled', 'manual_enabled', 'automatic_enabled', 'oversight_enabled'],
                'parameters' => ['application_key' => $applicationKey],
            ],
            'operations' => [
                'sql' => 'SELECT id,project_id,business_unit_id,title,status,scheduled_start_at,scheduled_end_at,
                                 location,notes,created_by,created_at,updated_at
                          FROM operations ORDER BY id',
                'integers' => ['id', 'project_id', 'business_unit_id', 'created_by'],
                'booleans' => [],
            ],
            'operation_assignments' => [
                'sql' => 'SELECT operation_id,user_id,assignment_role,assigned_by,assigned_at
                          FROM operation_assignments ORDER BY operation_id,user_id',
                'integers' => ['operation_id', 'user_id', 'assigned_by'],
                'booleans' => [],
            ],
            'tasks' => [
                'sql' => 'SELECT id,operation_id,project_id,business_unit_id,assignee_user_id,title,status,due_at,
                                 notes,created_by,created_at,updated_at
                          FROM tasks ORDER BY id',
                'integers' => ['id', 'operation_id', 'project_id', 'business_unit_id', 'assignee_user_id', 'created_by'],
                'booleans' => [],
            ],
            'task_assignments' => [
                'sql' => 'SELECT task_id,user_id,assigned_by,assigned_at
                          FROM task_assignments ORDER BY task_id,user_id',
                'integers' => ['task_id', 'user_id', 'assigned_by'],
                'booleans' => [],
            ],
            'calendar_events' => [
                'sql' => 'SELECT source_type,source_id,title,start_at,end_at,all_day,project_id,business_unit_id FROM (
                            SELECT \'operation\' AS source_type,id AS source_id,title,scheduled_start_at AS start_at,
                                   scheduled_end_at AS end_at,0 AS all_day,project_id,business_unit_id
                            FROM operations WHERE scheduled_start_at IS NOT NULL
                            UNION ALL
                            SELECT \'task\' AS source_type,id AS source_id,title,due_at AS start_at,due_at AS end_at,
                                   0 AS all_day,project_id,business_unit_id
                            FROM tasks WHERE due_at IS NOT NULL
                            UNION ALL
                            SELECT \'contract\' AS source_type,id AS source_id,\'Contract\' AS title,
                                   COALESCE(scheduled_date,start_date,end_date) AS start_at,end_date AS end_at,
                                   1 AS all_day,project_id,NULL AS business_unit_id
                            FROM contracts WHERE COALESCE(scheduled_date,start_date,end_date) IS NOT NULL
                            UNION ALL
                            SELECT \'invoice\' AS source_type,id AS source_id,\'Invoice due\' AS title,
                                   due_date AS start_at,due_date AS end_at,1 AS all_day,project_id,NULL AS business_unit_id
                            FROM invoices WHERE due_date IS NOT NULL
                          ) calendar_projection ORDER BY source_type,source_id',
                'integers' => ['source_id', 'project_id', 'business_unit_id'],
                'booleans' => ['all_day'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{sql:string,integers:list<string>,booleans:list<string>,parameters?:array<string,string>} $definition
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row, array $definition): array
    {
        // Migration 0062 assigns these once. Export the source-issued value,
        // never synthesize an identity or replace the existing numeric keys.
        if (array_key_exists('public_id', $row)
            && (!is_string($row['public_id']) || !preg_match('/^[0-9a-f]{32}$/D', $row['public_id']))
        ) {
            throw new \UnexpectedValueException('The operations source public ID is missing or invalid.');
        }
        foreach ($definition['integers'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int)$row[$field];
            }
        }
        foreach ($definition['booleans'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (bool)$row[$field];
            }
        }
        return $row;
    }
}
