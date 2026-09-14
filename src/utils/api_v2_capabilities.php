<?php
declare(strict_types=1);

function api_v2_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function api_v2_identity_is_valid(array $identity): bool
{
    foreach (['source_instance_id', 'application_id', 'history_epoch'] as $field) {
        if (!isset($identity[$field]) || !is_string($identity[$field])
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $identity[$field]) !== 1) {
            return false;
        }
    }
    return true;
}

function api_v2_capabilities_payload(array $identity, string $requestId): array
{
    return [
        'apiVersion' => '2',
        'sourceInstanceId' => (string)$identity['source_instance_id'],
        'applicationId' => (string)$identity['application_id'],
        'historyEpoch' => (string)$identity['history_epoch'],
        'requestId' => $requestId,
        'grantedCapabilities' => [['name' => 'api.capabilities.read']],
        'implementedEndpoints' => [[
            'method' => 'GET',
            'path' => '/api/v2/capabilities',
            'requiredCapability' => 'api.capabilities.read',
        ]],
    ];
}
