<?php
declare(strict_types=1);
namespace Tests\Support;

use PDO;
use RuntimeException;

/** Opt-in, disposable-test-only capture. Never exports request credentials or headers. */
final class ArchiveReceiverFixtureExport
{
    private string $token;
    private array $identity;
    private array $phases = [];

    public function __construct(private PDO $pdo, private string $path)
    {
        if (getenv('ARCHIVE_HTTP_ALLOW') !== 'isolated-disposable-only'
            || !preg_match('/^pa_archive_http_[a-f0-9]{32}$/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn())
            || file_exists($path) || !is_dir(dirname($path))) {
            throw new RuntimeException('Fresh disposable export destination required.');
        }
        $this->token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO api_keys(name,key_prefix,key_hash,scopes,api_v2_application_id)
            SELECT ?,?,?,?,id FROM api_v2_applications WHERE application_id=?')
            ->execute(['Synthetic receiver read', substr($this->token, 0, 16), hash('sha256', $this->token),
                'api.capabilities.read,directory.inventory.read,directory.clients.read', '11111111-1111-4111-8111-111111111111']);
        $pdo->exec('INSERT IGNORE INTO api_v2_directory_authorization_state(application_pk,authorization_generation)
            SELECT id,0 FROM api_v2_applications');
        $this->identity = $pdo->query('SELECT source_instance_id,history_epoch FROM api_v2_history_identity WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
        $this->identity['application_id'] = '11111111-1111-4111-8111-111111111111';
    }

    public function capture(string $base, string $phase, bool $present): void
    {
        $inventory = $this->get($base, '/api/v2/directory/inventory?type=client&limit=100');
        $client = $this->get($base, '/api/v2/directory/clients/a1111111111111111111111111111111');
        if ($inventory['status'] !== 200 || $client['status'] !== ($present ? 200 : 409)) {
            throw new RuntimeException('Synthetic directory HTTP capture failed: '.$phase.' '.$inventory['status'].'/'.$client['status']);
        }
        $resources = array_values(array_filter($inventory['body']['resources'] ?? [],
            static fn(array $row): bool => $row['publicId'] === 'a1111111111111111111111111111111'));
        if (count($resources) !== 1 || $resources[0]['present'] !== $present) throw new RuntimeException('Synthetic inventory lifecycle mismatch.');
        $events = $this->pdo->query("SELECT o.delivery_id,o.workspace_public_id,o.source_sequence,o.delivery_kind,o.payload_json
            FROM portal_projection_outbox o JOIN portal_integration_profiles p ON p.id=o.integration_profile_id
            WHERE p.application_key='synthetic_archive_receiver' AND o.route_type='portal'
            ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as &$event) {
            $event['payload'] = json_decode($event['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            $event['payloadJson'] = $event['payload_json'];
            $event['payloadSha256'] = hash('sha256', $event['payload_json']);
            unset($event['payload_json']);
        }
        unset($event);
        $this->phases[] = ['phase' => $phase, 'directoryInventory' => $inventory, 'directoryClient' => $client, 'portalOutbox' => $events];
    }

    public function finish(): void
    {
        if (array_column($this->phases, 'phase') !== ['initial','archived','restored','rearchived','missing-policy-restore-refused']) {
            throw new RuntimeException('Incomplete synthetic lifecycle capture.');
        }
        $kinds = array_column(array_column($this->phases[1]['portalOutbox'], 'payload'), 'kind');
        if (!in_array('snapshot.page', $kinds, true) || !in_array('snapshot.activate', $kinds, true)
            || !in_array('event', $kinds, true)) throw new RuntimeException('Portal lifecycle capture missing.');
        $archiveEvents = array_column(array_column($this->phases[1]['portalOutbox'], 'payload'), 'event');
        if (!array_filter($archiveEvents, static fn(array $event): bool => ($event['resource'] ?? '') === 'workspace'
            && ($event['action'] ?? '') === 'tombstone')) throw new RuntimeException('Actual workspace tombstone missing.');
        if ($this->phases[3]['portalOutbox'] !== $this->phases[4]['portalOutbox']) {
            throw new RuntimeException('Refused restore unexpectedly emitted portal events.');
        }
        $initial = $this->phases[0]['portalOutbox'];
        $archived = $this->phases[1]['portalOutbox'];
        $restored = $this->phases[2]['portalOutbox'];
        $rearchived = $this->phases[3]['portalOutbox'];
        if (count($initial) !== 2 || count($archived) !== 3 || count($restored) !== 5 || count($rearchived) !== 6
            || array_slice($archived, 0, 2) !== $initial || array_slice($restored, 0, 3) !== $archived
            || array_slice($rearchived, 0, 5) !== $restored) {
            throw new RuntimeException('Synthetic portal lifecycle is not one ordered append-only stream.');
        }
        $workspaceId = $initial[0]['workspace_public_id'];
        $pair = static function (array $events) use ($workspaceId): array {
            $page = $events[0]['payload'];
            $activation = $events[1]['payload'];
            if (($page['kind'] ?? '') !== 'snapshot.page' || ($activation['kind'] ?? '') !== 'snapshot.activate'
                || ($page['pageNumber'] ?? null) !== 1 || ($page['pageCount'] ?? null) !== 1
                || ($activation['pageCount'] ?? null) !== 1
                || ($page['workspace']['rootType'] ?? '') !== 'standalone_client'
                || ($page['workspace']['rootPublicId'] ?? '') !== 'a1111111111111111111111111111111'
                || ($page['workspace']['publicId'] ?? '') !== $workspaceId
                || ($page['sourceGeneration'] ?? '') !== ($activation['sourceGeneration'] ?? '')
                || ($page['sourceSequence'] ?? null) !== ($activation['sourceSequence'] ?? null)
                || ($page['snapshotHash'] ?? '') !== ($activation['snapshotHash'] ?? '')
                || ($page['principals'] ?? null) !== [] || ($page['entitlements'] ?? null) !== []) {
                throw new RuntimeException('Synthetic snapshot and activation pair is inconsistent.');
            }
            return $page;
        };
        $first = $pair($initial);
        $second = $pair(array_slice($restored, 3));
        if ($first['sourceGeneration'] === $second['sourceGeneration']
            || $second['sourceSequence'] !== $first['sourceSequence'] + 2) {
            throw new RuntimeException('Restore did not produce a fresh ordered snapshot generation.');
        }
        foreach ([[$archived[2], $first], [$rearchived[5], $second]] as [$row, $previous]) {
            $event = $row['payload'];
            if (($event['kind'] ?? '') !== 'event' || ($event['event']['resource'] ?? '') !== 'workspace'
                || ($event['event']['action'] ?? '') !== 'tombstone'
                || ($event['event']['publicId'] ?? '') !== $workspaceId
                || ($event['sourceGeneration'] ?? '') !== $previous['sourceGeneration']
                || ($event['sourceSequence'] ?? null) !== $previous['sourceSequence'] + 1) {
                throw new RuntimeException('Archive did not produce the exact workspace tombstone.');
            }
        }
        foreach ($this->phases as $phase) {
            $inventory = $phase['directoryInventory']['body'];
            if (($inventory['sourceInstanceId'] ?? '') !== $this->identity['source_instance_id']
                || ($inventory['historyEpoch'] ?? '') !== $this->identity['history_epoch']
                || ($inventory['applicationId'] ?? '') !== $this->identity['application_id']) {
                throw new RuntimeException('Synthetic directory source identity changed during capture.');
            }
            foreach ($phase['portalOutbox'] as $row) {
                $payload = $row['payload'];
                if ($row['workspace_public_id'] !== $workspaceId || ($payload['workspaceId'] ?? '') !== $workspaceId
                    || ($payload['applicationKey'] ?? '') !== 'synthetic_archive_receiver'
                    || ($payload['schemaVersion'] ?? null) !== 3 || ($payload['deliveryId'] ?? '') !== $row['delivery_id']
                    || ($payload['sourceSequence'] ?? null) !== (int)$row['source_sequence']
                    || $row['payloadSha256'] !== hash('sha256', $row['payloadJson'])
                    || json_decode($row['payloadJson'], true, 64, JSON_THROW_ON_ERROR) !== $payload) {
                    throw new RuntimeException('Synthetic portal envelope identity or raw bytes changed.');
                }
            }
        }
        $json = json_encode(['schemaVersion' => 1, 'syntheticOnly' => true,
            'runtimeBaseCommit' => '5ac0fb731a04d5e8e9aefca4256143fff6cd8644',
            'identity' => $this->identity, 'clientPublicId' => 'a1111111111111111111111111111111',
            'portalApplicationKey' => 'synthetic_archive_receiver',
            'phases' => $this->phases], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (str_contains($json, $this->token) || strlen($json) > 2 * 1024 * 1024) throw new RuntimeException('Unsafe fixture export.');
        $file = fopen($this->path, 'x');
        if ($file === false) throw new RuntimeException('Fixture destination already exists or is unavailable.');
        try { chmod($this->path, 0600); if (fwrite($file, $json."\n") !== strlen($json) + 1) throw new RuntimeException('Incomplete fixture export.'); }
        finally { fclose($file); }
    }

    private function get(string $base, string $path): array
    {
        $headers = ['Authorization: Bearer '.$this->token,
            'X-PA-Source-Instance-ID: '.$this->identity['source_instance_id'],
            'X-PA-History-Epoch: '.$this->identity['history_epoch'],
            'X-PA-Application-ID: '.$this->identity['application_id']];
        $body = file_get_contents($base.$path, false, stream_context_create(['http' => ['method' => 'GET',
            'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]));
        if (!preg_match('/\s([0-9]{3})\s/', $http_response_header[0] ?? '', $status)) throw new RuntimeException('No synthetic API response.');
        return ['path' => $path, 'status' => (int)$status[1], 'rawBody' => (string)$body,
            'bodySha256' => hash('sha256', (string)$body), 'body' => json_decode((string)$body, true, 64, JSON_THROW_ON_ERROR)];
    }
}
